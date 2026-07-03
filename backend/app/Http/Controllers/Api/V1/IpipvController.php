<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IpipvOrder;
use App\Services\IpipvApiService;
use App\Services\IpipvProvisionService;
use App\Services\IpipvStockCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IpipvController extends Controller
{
    // ========== 产品查询 ==========

    public function products(Request $request, IpipvApiService $api): JsonResponse
    {
        $data = $api->getProducts($request->only([
            'proxyType', 'productNo', 'countryCode', 'cityCode', 'unit', 'ispType', 'duration',
        ]));

        $products = [];
        if (is_array($data)) {
            $products = isset($data[0]) ? $data : ($data['list'] ?? $data['rows'] ?? []);
            if (!isset($products[0]) && !empty($products)) {
                $products = array_values($products);
            }
        }

        $products = array_filter($products, fn ($p) => is_array($p) && !empty($p['productNo']));
        $products = array_values($products);

        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;
        $isSalesRole = $request->user()?->hasAnyRole(['sales', 'staff'])
            && !$request->user()?->hasAnyRole(['super_admin', 'ops_admin', 'manager']);

        $products = array_map(function ($p) use ($canViewCost, $isSalesRole) {
            $costPrice = (float) ($p['costPrice'] ?? $p['cost'] ?? 0);
            $normalized = [
                'country_code' => $p['countryCode'] ?? '',
                'area_code'    => $p['stateCode'] ?? '',
                'city_code'    => $p['cityCode'] ?? '',
                'product_id'   => $p['productNo'] ?? '',
                'cost_price'   => $costPrice,
            ];
            $p['sales_price'] = \App\Models\PricingMultiplier::calcSalesPrice($normalized);

            if (!$canViewCost) {
                unset($p['costPrice'], $p['cost']);
            }

            return $p;
        }, $products);

        return response()->json([
            'success' => true,
            'data'    => [
                'products' => $products,
                'count'    => count($products),
                'can_view_cost' => $canViewCost,
                'is_sales_role' => $isSalesRole,
            ],
        ]);
    }

    // ========== 开通下单 ==========

    public function provision(Request $request, IpipvProvisionService $svc): JsonResponse
    {
        $request->validate([
            'product_no'     => 'required|string',
            'quantity'       => 'required|integer|min:1|max:20',
            'duration'       => 'required|integer|min:1',
            'unit'           => 'required|integer|in:1,2,3,4',
            'cycle_times'    => 'integer|min:1',
            'asset_group_id' => 'nullable|integer|exists:ip_asset_groups,id',
            'ip_group_id'    => 'nullable|integer|exists:ip_groups,id',
            'customer_id'    => 'nullable|integer|exists:customers,id',
            'sale_price'     => 'nullable|numeric|min:0',
            'forward_plan_id' => 'nullable|integer|exists:forward_plans,id',
            'is_test'        => 'nullable|boolean',
            'test_hours'     => 'nullable|integer|in:12,23',
            'payment_method' => 'nullable|in:offline,balance',
        ]);

        $paymentMethod = $request->input('payment_method', 'offline');

        if ($paymentMethod === 'balance' && $request->customer_id && $request->sale_price) {
            $durationMonths = \App\Support\DurationHelper::toMonths((int) $request->duration, (int) $request->unit);
            $deductAmount = (float) $request->sale_price * (int) $request->quantity * max($durationMonths, 1);
            if ($deductAmount > 0) {
                \Illuminate\Support\Facades\DB::beginTransaction();
                try {
                    $customer = \App\Models\Customer::lockForUpdate()->findOrFail($request->customer_id);
                    if ((float) $customer->balance < $deductAmount) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json(['success' => false, 'message' => "客户余额不足（当前 ¥{$customer->balance}，需要 ¥{$deductAmount}）"], 422);
                    }
                    $balanceBefore = (float) $customer->balance;
                    $customer->decrement('balance', $deductAmount);

                    $purchaseTxn = \App\Models\Transaction::create([
                        'customer_id'    => $customer->id,
                        'type'           => \App\Models\Transaction::TYPE_PURCHASE,
                        'amount'         => -$deductAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after'  => bcsub($balanceBefore, $deductAmount, 2),
                        'description'    => "开通订单扣费 ¥{$deductAmount}",
                        'operated_by'    => auth()->id(),
                    ]);
                    \Illuminate\Support\Facades\DB::commit();
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\DB::rollBack();
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
                }
            }
        }

        $params = $request->all() + ['created_by' => auth()->id()];

        if (empty($params['asset_group_id'])) {
            $group = \App\Models\IpAssetGroup::where('source_type', 'third_party_api')
                ->where('source_name', 'LIKE', '%ipipv%')
                ->first();
            if (!$group) {
                $group = \App\Models\IpAssetGroup::firstOrCreate(
                    ['source_type' => 'third_party_api', 'source_name' => 'IPIPV'],
                    ['name' => 'IPIPV API', 'status' => 1, 'created_by' => auth()->id()]
                );
            }
            $params['asset_group_id'] = $group->id;
        }

        try {
            $result = $svc->createOrder($params);
        } catch (\Exception $e) {
            if (isset($purchaseTxn)) {
                $deductAmount = abs((float) $purchaseTxn->amount);
                $customer = \App\Models\Customer::find($purchaseTxn->customer_id);
                if ($customer) {
                    $customer->increment('balance', $deductAmount);
                    \App\Models\Transaction::create([
                        'customer_id'    => $customer->id,
                        'type'           => \App\Models\Transaction::TYPE_REFUND,
                        'amount'         => $deductAmount,
                        'balance_before' => (float) $customer->balance - $deductAmount,
                        'balance_after'  => (float) $customer->balance,
                        'description'    => "开通失败自动退款 ¥{$deductAmount}",
                        'operated_by'    => auth()->id(),
                    ]);
                }
            }
            Log::error('IPIPV provision failed', ['error' => $e->getMessage(), 'params' => $params]);
            return response()->json(['success' => false, 'message' => '开通失败: ' . $e->getMessage()], 500);
        }

        if (isset($purchaseTxn) && !empty($result['subscription_ids'])) {
            $purchaseTxn->update([
                'related_type' => \App\Models\Subscription::class,
                'related_id'   => $result['subscription_ids'][0],
            ]);
        }

        if ($paymentMethod === 'balance' && !empty($params['customer_id']) && !empty($params['sale_price']) && empty($params['is_test'])) {
            $durationMonths2 = \App\Support\DurationHelper::toMonths((int) ($params['duration'] ?? 1), (int) ($params['unit'] ?? 3));
            $totalAmount = (float) $params['sale_price'] * (int) $params['quantity'] * max($durationMonths2, 1);
            if ($totalAmount > 0) {
                try {
                    $customer = \App\Models\Customer::find($params['customer_id']);
                    if ($customer) {
                        $ipipvProduct = collect(\App\Services\IpipvStockCacheService::products())
                            ->first(fn($p) => ($p['product_no'] ?? $p['productNo'] ?? null) === ($params['product_no'] ?? ''));
                        $costPrice = $ipipvProduct ? (float) ($ipipvProduct['cost_price'] ?? $ipipvProduct['unitPrice'] ?? 0) : 0;
                        $listPricePerMonth = $costPrice > 0 ? \App\Models\PricingMultiplier::calcSalePrice(['cost_price' => $costPrice, 'source' => 'ipipv']) : 0;
                        $totalListPrice = $listPricePerMonth > 0 ? round($listPricePerMonth * (int) $params['quantity'] * max($durationMonths2, 1), 2) : 0;
                        $referralService = app(\App\Services\ReferralService::class);
                        $subId = $result['subscription_ids'][0] ?? null;
                        $referralService->processCommission($customer, 'purchase', $totalAmount, $subId, $totalListPrice ?: $totalAmount);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Commission processing failed (admin ipipv provision)', [
                        'customer_id' => $params['customer_id'],
                        'amount' => $totalAmount,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    // ========== 同步订单 ==========

    public function syncOrder(IpipvOrder $ipipvOrder, IpipvProvisionService $svc): JsonResponse
    {
        $result = $svc->syncOrder($ipipvOrder);

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    // ========== 续费 ==========

    public function renew(Request $request, IpipvApiService $api): JsonResponse
    {
        $request->validate([
            'instances'              => 'required|array|min:1',
            'instances.*.instanceNo' => 'required|string',
            'instances.*.duration'   => 'integer|min:1',
            'instances.*.cycleTimes' => 'integer|min:1',
        ]);

        $appOrderNo = IpipvOrder::generateAppOrderNo();
        $result = $api->renewProxy($appOrderNo, $request->input('instances'));

        IpipvOrder::create([
            'app_order_no'   => $appOrderNo,
            'ipipv_order_no' => $result['orderNo'] ?? null,
            'method'         => 'renew',
            'cost_amount'    => $result['amount'] ?? null,
            'status'         => 1,
            'request_data'   => $request->all(),
            'response_data'  => $result,
        ]);

        return response()->json(['success' => true, 'data' => $result]);
    }

    // ========== 释放 ==========

    public function release(Request $request, IpipvApiService $api): JsonResponse
    {
        $request->validate([
            'instances' => 'required|array|min:1',
            'instances.*' => 'required|string',
        ]);

        $orderNo = IpipvOrder::generateAppOrderNo();
        $result = $api->releaseProxy($orderNo, $request->input('instances'));

        IpipvOrder::create([
            'app_order_no'   => $orderNo,
            'ipipv_order_no' => $result['orderNo'] ?? null,
            'method'         => 'release',
            'cost_amount'    => $result['amount'] ?? null,
            'status'         => 1,
            'request_data'   => $request->all(),
            'response_data'  => $result,
        ]);

        return response()->json(['success' => true, 'data' => $result]);
    }

    // ========== 订单列表 ==========

    public function orders(Request $request): JsonResponse
    {
        $query = IpipvOrder::with('instances')
            ->orderByDesc('id');

        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }

    // ========== 账户余额 ==========

    public function balance(IpipvApiService $api): JsonResponse
    {
        $info = $api->getAppInfo();
        return response()->json(['success' => true, 'data' => $info]);
    }

    // ========== 库存缓存 ==========

    public function stockByCountry(Request $request): JsonResponse
    {
        $force = $request->boolean('refresh');
        $data = IpipvStockCacheService::stockByCountry($force);

        return response()->json([
            'success' => true,
            'data' => [
                'by_country' => $data,
                'country_count' => count($data),
                'total_stock' => array_sum(array_column($data, 'stock')),
                'last_refreshed_at' => IpipvStockCacheService::lastRefreshedAt(),
            ],
        ]);
    }

    // ========== 地区查询 ==========

    public function areas(IpipvApiService $api): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $api->getAreas()]);
    }

    public function cities(IpipvApiService $api): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $api->getCities()]);
    }

    // ========== 回调（公开端点，无需认证） ==========

    public function callback(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $no   = $request->query('no');
        $op   = $request->query('op');

        Log::channel('daily')->info('IPIPV Callback', compact('type', 'no', 'op'));

        try {
            if ($type === 'order' && $no) {
                $this->handleOrderCallback($no, (int) $op);
            } elseif ($type === 'instance' && $no) {
                $this->handleInstanceCallback($no);
            }
        } catch (\Throwable $e) {
            Log::error('IPIPV callback processing failed', [
                'type'  => $type,
                'no'    => $no,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['code' => 'success', 'msg' => '']);
    }

    private function handleOrderCallback(string $orderNo, int $op): void
    {
        $order = IpipvOrder::where('ipipv_order_no', $orderNo)
            ->orWhere('app_order_no', $orderNo)
            ->first();

        if (!$order) {
            Log::warning("IPIPV callback: order not found", ['orderNo' => $orderNo]);
            return;
        }

        $svc = app(IpipvProvisionService::class);
        $result = $svc->syncOrder($order);

        if (!empty($result['subscription_ids'])) {
            $customerId = $order->request_data['customer_id'] ?? null;
            if ($customerId) {
                \App\Models\Transaction::where('customer_id', $customerId)
                    ->where('type', \App\Models\Transaction::TYPE_PURCHASE)
                    ->where('description', 'like', '开通订单扣费%')
                    ->where(function ($q) {
                        $q->whereNull('related_id')->orWhere('related_id', 0);
                    })
                    ->where('created_at', '>=', $order->created_at->subSeconds(2))
                    ->where('created_at', '<=', $order->created_at->addSeconds(2))
                    ->first()
                    ?->update([
                        'related_type' => \App\Models\Subscription::class,
                        'related_id'   => $result['subscription_ids'][0],
                    ]);
            }
        }
    }

    private function handleInstanceCallback(string $instanceNo): void
    {
        $api = app(IpipvApiService::class);
        try {
            $instances = $api->getInstance([$instanceNo]);
            if (empty($instances)) return;

            $inst = is_array($instances) && isset($instances[0]) ? $instances[0] : $instances;
            $ipipvInst = \App\Models\IpipvInstance::where('instance_no', $instanceNo)->first();
            if ($ipipvInst) {
                $ipipvInst->update([
                    'ip'           => $inst['ip'] ?? $ipipvInst->ip,
                    'port'         => $inst['port'] ?? $ipipvInst->port,
                    'username'     => $inst['username'] ?? $ipipvInst->username,
                    'password'     => $inst['pwd'] ?? $ipipvInst->password,
                    'status'       => $inst['status'] ?? $ipipvInst->status,
                    'flow_total'   => $inst['flowTotal'] ?? $ipipvInst->flow_total,
                    'flow_balance' => $inst['flowBalance'] ?? $ipipvInst->flow_balance,
                    'expire_at'    => isset($inst['userExpired']) && $inst['userExpired'] > 0
                        ? \Carbon\Carbon::createFromTimestamp($inst['userExpired'])
                        : $ipipvInst->expire_at,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('IPIPV instance callback failed', [
                'instanceNo' => $instanceNo,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
