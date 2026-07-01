<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IpAssetGroup;
use App\Models\IpGroup;
use App\Models\ProxyIp;
use App\Models\SparkCity;
use App\Models\SparkCountry;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\SparkState;
use App\Models\Subscription;
use App\Services\SparkApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SparkController extends Controller
{
    private SparkApiService $spark;

    public function __construct(SparkApiService $spark)
    {
        $this->spark = $spark;
    }

    /**
     * 浏览 Spark 产品库存（读缓存，5 分钟刷新一次；传 ?force=1 触发实时拉取）
     * GET /spark/products
     */
    public function products(Request $request): JsonResponse
    {
        try {
            $force = $request->boolean('force');
            $products = \App\Services\SparkStockCacheService::products($force);
            $refreshedAt = \App\Services\SparkStockCacheService::lastRefreshedAt();

            // 可选过滤
            if ($request->filled('countryCode')) {
                $cc = strtoupper($request->input('countryCode'));
                $products = array_filter($products, fn($p) => strtoupper($p['country_code'] ?? '') === $cc);
            }
            if ($request->filled('proxyType')) {
                $pt = (int) $request->input('proxyType');
                $products = array_filter($products, fn($p) => $p['proxy_type'] === $pt);
            }

            $products = array_values($products);

            // 没有 pricing.view_cost 权限的用户看不到成本价
            $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;
            $isSalesRole = $request->user()?->hasAnyRole(['sales', 'staff']) && !$request->user()?->hasAnyRole(['super_admin', 'ops_admin', 'manager']);

            // 预加载州/城市中文名
            $areaCodes = collect($products)->pluck('area_code')->unique()->filter();
            $areaNames = $areaCodes->isNotEmpty()
                ? SparkState::whereIn('code_full', $areaCodes)->pluck('cname', 'code_full')->toArray()
                : [];
            $cityCodes = collect($products)->pluck('city_code')->unique()->filter();
            $cityNames = $cityCodes->isNotEmpty()
                ? SparkCity::whereIn('code_full', $cityCodes)->pluck('cname', 'code_full')->toArray()
                : [];

            return $this->success([
                'total' => count($products),
                'current_page' => 1,
                'products' => collect($products)->map(function ($p) use ($canViewCost, $isSalesRole, $areaNames, $cityNames) {
                    $p['proxy_type_label'] = config('proxy.proxy_types')[$p['proxy_type']] ?? '未知';
                    $p['sale_price_ref'] = \App\Models\PricingMultiplier::calcSalePrice($p);
                    $p['sales_price'] = \App\Models\PricingMultiplier::calcSalesPrice($p);
                    $p['area_name'] = $areaNames[$p['area_code'] ?? ''] ?? '';
                    $p['city_name'] = $cityNames[$p['city_code'] ?? ''] ?? '';
                    if (!$canViewCost) {
                        unset($p['cost_price']);
                    }
                    return $p;
                })->values(),
                'last_refreshed_at' => $refreshedAt,
                'from_cache' => !$force,
                'can_view_cost' => $canViewCost,
                'is_sales_role' => $isSalesRole,
            ]);
        } catch (\Exception $e) {
            return $this->error('获取Spark产品失败: ' . $e->getMessage());
        }
    }

    /**
     * 按国家聚合的库存（客户自助面板 / Spark 定价规则绑定用）
     * 包含每个国家的：总库存 / 产品数 / 最低成本 / 平均成本 / 已配置的对客售价
     * GET /spark/stock-by-country
     */
    public function stockByCountry(Request $request): JsonResponse
    {
        $force = $request->boolean('force');
        $byCountry = \App\Services\SparkStockCacheService::stockByCountry($force);
        $refreshedAt = \App\Services\SparkStockCacheService::lastRefreshedAt();
        $canViewCost = $request->user()?->can('pricing.view_cost') ?? false;

        // 关联 area_country 取中文名 + 洲别
        $codes = array_keys($byCountry);
        $countries = \App\Models\SparkCountry::whereIn('code', $codes)
            ->get(['code', 'cname', 'name', 'continent_id'])
            ->keyBy('code');

        // 获取所有产品用于按国家计算倍率售价
        $allProducts = \App\Services\SparkStockCacheService::products();

        $result = [];
        foreach ($byCountry as $code => $info) {
            $c = $countries->get($code);

            // 取该国家的第一个产品来匹配倍率规则计算售价
            $sampleProduct = collect($allProducts)->first(fn($p) => strtoupper($p['country_code'] ?? '') === $code);
            $salePrice = $sampleProduct ? \App\Models\PricingMultiplier::calcSalePrice($sampleProduct) : null;
            $salesPrice = $sampleProduct ? \App\Models\PricingMultiplier::calcSalesPrice($sampleProduct) : null;

            $continent = \App\Support\SparkContinents::CONTINENTS[$c?->continent_id] ?? ['name' => '其他'];

            $item = [
                'country_code' => $code,
                'country_name' => $c?->cname ?: ($c?->name ?: $code),
                'continent_id' => $c?->continent_id,
                'continent' => $continent['name'],
                'stock' => $info['stock'],
                'product_count' => $info['product_count'],
                'sale_price' => $salePrice,
                'sales_price' => $salesPrice,
                'has_price' => $salePrice !== null,
            ];
            if ($canViewCost) {
                $item['min_cost'] = $info['min_cost'];
                $item['avg_cost'] = $info['avg_cost'];
            }
            $result[] = $item;
        }

        // 按库存降序
        usort($result, fn($a, $b) => $b['stock'] <=> $a['stock']);

        return $this->success([
            'items' => $result,
            'total_stock' => array_sum(array_column($byCountry, 'stock')),
            'total_countries' => count($result),
            'priced_countries' => count(array_filter($result, fn($r) => $r['has_price'])),
            'last_refreshed_at' => $refreshedAt,
        ]);
    }

    /**
     * 通过 Spark 开通IP
     * POST /spark/provision
     * 在创建订单流程中，如果资产组是 spark_api 类型，调用此接口开通
     */
    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|string',
            'product_name' => 'nullable|string', // 产品名（用于构建资产名）
            'country_code' => 'nullable|string',  // 国家代码
            'country_cn' => 'nullable|string',    // 中文国家名
            'sale_price' => 'nullable|numeric|min:0', // 售价(元/条)，已含转发费用
            'quantity' => 'required|integer|min:1|max:50',
            'duration' => 'required|integer|min:1',
            'unit' => 'required|integer|in:1,2,3,4',
            'asset_group_id' => 'required|exists:ip_asset_groups,id',
            'ip_group_id' => 'nullable|exists:ip_groups,id',
            'customer_id' => 'nullable|exists:customers,id',
            // NY 端口转发（可选，与 xui_forward 二选一）
            'forward' => 'nullable|array',
            'forward.device_group_id' => 'required_with:forward|integer|exists:ny_device_groups,id',
            'forward.speed_limit_mbps' => 'nullable|integer|min:1|max:10000',
            'forward.forward_fee' => 'required_with:forward|numeric|min:0',
            // 3x-ui 转发（可选，与 forward 二选一）
            'xui_forward' => 'nullable|array',
            'xui_forward.xui_panel_id' => 'required_with:xui_forward|integer|exists:xui_panels,id',
            // 中转套餐（可选，与 forward / xui_forward 互斥）
            'forward_plan_id' => 'nullable|integer|exists:forward_plans,id',
            // IP 段选择（可选）
            'cidr_blocks' => 'nullable|array',
            'cidr_blocks.*.cidr' => 'required_with:cidr_blocks|string',
            'cidr_blocks.*.count' => 'required_with:cidr_blocks|integer|min:1',
            // 测试模式（自动回收+API释放+删除转发）
            'is_test' => 'nullable|boolean',
            'test_hours' => 'nullable|integer|in:12,23',
            'payment_method' => 'nullable|in:offline,balance',
        ]);

        $assetGroup = IpAssetGroup::findOrFail($data['asset_group_id']);
        if ($assetGroup->source_type !== 'spark_api') {
            return $this->error('该资产组不是 Spark API 类型');
        }

        $paymentMethod = $data['payment_method'] ?? 'offline';

        if ($paymentMethod === 'balance' && !empty($data['customer_id']) && !empty($data['sale_price'])) {
            $durationMonths = \App\Support\DurationHelper::toMonths($data['duration'], $data['unit']);
            $deductAmount = (float) $data['sale_price'] * (int) $data['quantity'] * max($durationMonths, 1);
            if ($deductAmount > 0) {
                DB::beginTransaction();
                try {
                    $customer = \App\Models\Customer::lockForUpdate()->findOrFail($data['customer_id']);
                    if ((float) $customer->balance < $deductAmount) {
                        DB::rollBack();
                        return $this->error("客户余额不足（当前 ¥{$customer->balance}，需要 ¥{$deductAmount}）", 422);
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
                        'operated_by'    => $request->user()?->id,
                    ]);
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    return $this->error($e->getMessage(), 422);
                }
            }
        }

        try {
            $provision = app(\App\Services\SparkProvisionService::class);
            $data['created_by'] = $request->user()?->id;
            $result = $provision->createOrder($data);

            if (isset($purchaseTxn) && !empty($result['subscription_ids'])) {
                $purchaseTxn->update([
                    'related_type' => \App\Models\Subscription::class,
                    'related_id' => $result['subscription_ids'][0],
                ]);
            }

            // payment_method is passed via $data into SparkProvisionService->createOrder()
            // and stored in spark_orders.request_data, so processInstances can set
            // balance_deducted=true on subscriptions when payment_method=balance

            // Admin provisioning: trigger commission only when paid via balance (skip offline + test orders)
            if ($paymentMethod === 'balance' && !empty($data['customer_id']) && !empty($data['sale_price']) && empty($data['is_test'])) {
                $durationMonths2 = \App\Support\DurationHelper::toMonths($data['duration'], $data['unit']);
                $totalAmount = (float) $data['sale_price'] * (int) $data['quantity'] * max($durationMonths2, 1);
                if ($totalAmount > 0) {
                    try {
                        $customer = \App\Models\Customer::find($data['customer_id']);
                        if ($customer) {
                            $sparkProduct = collect(\App\Services\SparkStockCacheService::allProducts())->firstWhere('product_id', $data['product_id']);
                            $listPricePerMonth = $sparkProduct ? \App\Models\PricingMultiplier::calcSalePrice($sparkProduct) : 0;
                            $totalListPrice = $listPricePerMonth > 0 ? round($listPricePerMonth * (int) $data['quantity'] * max($durationMonths2, 1), 2) : 0;
                            $referralService = app(\App\Services\ReferralService::class);
                            $subId = $result['subscription_ids'][0] ?? null;
                            $referralService->processCommission($customer, 'purchase', $totalAmount, $subId, $totalListPrice ?: $totalAmount);
                        }
                    } catch (\Throwable $e) {
                        Log::error('Commission processing failed (admin provision)', [
                            'customer_id' => $data['customer_id'],
                            'amount' => $totalAmount,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return $this->success([
                'spark_order' => $result['spark_order'],
                'status' => $result['status'],
                'message' => $result['message'],
            ], '下单成功');

        } catch (\Exception $e) {
            Log::error('Spark provision failed', ['error' => $e->getMessage(), 'data' => $data]);

            $msg = $e->getMessage();
            if (stripos($msg, 'total paid is less than total price') !== false) {
                return $this->error('Spark 账户余额不足，无法完成下单。请联系 Spark 运营充值预付款。');
            }
            if (stripos($msg, 'inventory') !== false || stripos($msg, 'stock') !== false) {
                return $this->error('Spark 产品库存不足，请减少数量或选择其他产品。');
            }
            if (stripos($msg, 'not found') !== false) {
                return $this->error('Spark 产品不存在或已下架，请刷新产品列表重试。');
            }

            return $this->error('Spark 开通失败: ' . $msg);
        }
    }

    /**
     * 查询 Spark 订单状态并同步实例
     * POST /spark/sync-order/{sparkOrder}
     */
    public function syncOrder(SparkOrder $sparkOrder): JsonResponse
    {
        try {
            $result = $this->spark->getOrder(
                $sparkOrder->req_order_no,
                $sparkOrder->spark_order_no
            );

            $sparkOrder->update([
                'status' => (int) ($result['status'] ?? $sparkOrder->status),
                'cost_amount' => $result['amount'] ?? $sparkOrder->cost_amount,
                'response_data' => $result,
            ]);

            if (!empty($result['ipInfo'])) {
                app(\App\Services\SparkProvisionService::class)->processInstances(
                    $sparkOrder,
                    $result['ipInfo'],
                    $sparkOrder->request_data ?? []
                );
            }

            return $this->success($sparkOrder->load('instances.proxyIp'), '同步完成');

        } catch (\Exception $e) {
            return $this->error('同步失败: ' . $e->getMessage());
        }
    }

    /**
     * 通过 Spark 续费实例
     * POST /spark/renew
     */
    public function renew(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'duration' => 'required|integer|min:1',
            'unit' => 'required|integer|in:1,2,3,4',
        ]);

        $subscription = Subscription::with('proxyIp')->findOrFail($data['subscription_id']);
        $proxyIp = $subscription->proxyIp;

        if (!$proxyIp->spark_instance_id) {
            return $this->error('该IP不是 Spark 实例');
        }

        try {
            $reqOrderNo = SparkOrder::generateReqOrderNo();

            $result = $this->spark->renewProxy($reqOrderNo, [[
                'instanceId' => $proxyIp->spark_instance_id,
                'duration' => $data['duration'],
                'unit' => $data['unit'],
            ]]);

            // 记录续费订单
            SparkOrder::create([
                'req_order_no' => $reqOrderNo,
                'spark_order_no' => $result['orderNo'] ?? null,
                'method' => 'RenewProxy',
                'product_id' => '',
                'amount' => 1,
                'duration' => $data['duration'],
                'unit' => $data['unit'],
                'cost_amount' => $result['amount'] ?? null,
                'status' => (int) ($result['status'] ?? 1),
                'request_data' => $data,
                'response_data' => $result,
            ]);

            return $this->success($result, 'Spark 续费请求已提交');

        } catch (\Exception $e) {
            return $this->error('Spark 续费失败: ' . $e->getMessage());
        }
    }

    /**
     * 释放 Spark 实例
     * POST /spark/release
     */
    public function release(Request $request): JsonResponse
    {
        $data = $request->validate([
            'proxy_ip_ids' => 'required|array|min:1',
            'proxy_ip_ids.*' => 'exists:proxy_ips,id',
        ]);

        $proxyIps = ProxyIp::whereIn('id', $data['proxy_ip_ids'])
            ->whereNotNull('spark_instance_id')
            ->get();

        if ($proxyIps->isEmpty()) {
            return $this->error('没有找到关联的 Spark 实例');
        }

        $instanceIds = $proxyIps->pluck('spark_instance_id')->toArray();

        try {
            $reqOrderNo = SparkOrder::generateReqOrderNo();
            $result = $this->spark->delProxy($reqOrderNo, $instanceIds);

            // 记录释放订单
            SparkOrder::create([
                'req_order_no' => $reqOrderNo,
                'spark_order_no' => $result['orderNo'] ?? null,
                'method' => 'DelProxy',
                'product_id' => '',
                'amount' => count($instanceIds),
                'duration' => 0,
                'unit' => 0,
                'status' => (int) ($result['status'] ?? 1),
                'request_data' => $data,
                'response_data' => $result,
            ]);

            return $this->success(null, 'Spark 释放请求已提交');

        } catch (\Exception $e) {
            return $this->error('Spark 释放失败: ' . $e->getMessage());
        }
    }

    /**
     * Spark 回调通知
     * GET /spark/notify (公开接口)
     */
    public function notify(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $no = $request->input('no');

        Log::info("Spark callback: type={$type}, no={$no}");

        try {
            if ($type === 'order') {
                // 订单状态变化 -> 同步订单
                $sparkOrder = SparkOrder::where('req_order_no', $no)->first();
                if ($sparkOrder) {
                    $this->syncOrder($sparkOrder);
                }
            } elseif ($type === 'instance') {
                // 实例状态变化 -> 更新实例
                $instance = SparkInstance::where('instance_id', $no)->first();
                if ($instance) {
                    $this->syncInstance($instance);
                }
            }
            // product 类型暂不处理

            return response()->json(['code' => 200, 'msg' => 'success']);
        } catch (\Exception $e) {
            Log::error("Spark callback error: {$e->getMessage()}");
            return response()->json(['code' => 200, 'msg' => 'success']); // 返回200避免重试
        }
    }

    /**
     * 查看 Spark 订单列表
     * GET /spark/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $query = SparkOrder::with('instances')
            ->orderByDesc('id');

        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }

    /**
     * Spark 调试 - 原始请求测试
     * GET /spark/debug?proxyType=103
     */
    public function debug(Request $request): JsonResponse
    {
        $tests = [
            '测试1: proxyType=103 (静态住宅)' => ['proxyType' => 103, 'page' => 1, 'pageSize' => 10],
            '测试2: proxyType=104 (动态住宅)' => ['proxyType' => 104, 'page' => 1, 'pageSize' => 10],
            '测试3: 只传 page/pageSize' => ['page' => 1, 'pageSize' => 10],
            '测试4: proxyType=103 + countryCode=US' => ['proxyType' => 103, 'countryCode' => 'US', 'page' => 1, 'pageSize' => 10],
            '测试5: proxyType=103 + countryCode=USA' => ['proxyType' => 103, 'countryCode' => 'USA', 'page' => 1, 'pageSize' => 10],
        ];

        $results = [];
        foreach ($tests as $name => $params) {
            try {
                $data = $this->spark->getProductStock($params);
                $results[$name] = [
                    'status' => 'success',
                    'params' => $params,
                    'total' => $data['total'] ?? 0,
                    'products_count' => count($data['products'] ?? []),
                    'sample' => $data['products'][0] ?? null,
                ];
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 'error',
                    'params' => $params,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success($results);
    }

    // ========== Spark 匹配与同步 ==========

    /**
     * 单条IP匹配Spark实例
     * POST /spark/match
     * 用 IP 地址或认证用户名去 Spark 查询，找到则关联 instanceId
     */
    public function matchInstance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'proxy_ip_id' => 'required|exists:proxy_ips,id',
        ]);

        $proxyIp = ProxyIp::findOrFail($data['proxy_ip_id']);

        if ($proxyIp->spark_instance_id) {
            return $this->success([
                'already_matched' => true,
                'instance_id' => $proxyIp->spark_instance_id,
            ], '该IP已关联Spark实例');
        }

        try {
            // 优先用 IP 地址查询
            $result = null;
            if ($proxyIp->ip_address) {
                $result = $this->spark->getInstance(['ip' => $proxyIp->ip_address]);
            }
            // 如果IP查不到，尝试用认证用户名
            if (empty($result) && $proxyIp->auth_username) {
                $result = $this->spark->getInstance(['username' => $proxyIp->auth_username]);
            }

            if (empty($result) || empty($result['instanceId'])) {
                return $this->error('在Spark平台未找到匹配的实例');
            }

            // 关联并更新
            DB::transaction(function () use ($proxyIp, $result) {
                $proxyIp->update([
                    'spark_instance_id' => $result['instanceId'],
                    'upstream_expires_at' => isset($result['expireAt']) ? date('Y-m-d H:i:s', $result['expireAt']) : null,
                ]);

                // 创建 spark_instance 记录（如果不存在）
                SparkInstance::firstOrCreate(
                    ['instance_id' => $result['instanceId']],
                    [
                        'spark_order_id' => 0, // 历史数据无订单
                        'proxy_ip_id' => $proxyIp->id,
                        'ip' => $result['ip'] ?? $proxyIp->ip_address,
                        'port' => $result['port'] ?? $proxyIp->port,
                        'username' => $result['username'] ?? $proxyIp->auth_username,
                        'password' => $result['password'] ?? $proxyIp->auth_password,
                        'type' => $result['type'] ?? 1,
                        'use_type' => $result['useType'] ?? 1,
                        'status' => $result['status'] ?? 2,
                        'flow' => $result['flow'] ?? null,
                        'balance_flow' => $result['balanceFlow'] ?? null,
                        'expire_at' => isset($result['expireAt']) ? date('Y-m-d H:i:s', $result['expireAt']) : null,
                    ]
                );
            });

            return $this->success([
                'matched' => true,
                'instance_id' => $result['instanceId'],
                'status' => $result['status'] ?? null,
                'expire_at' => isset($result['expireAt']) ? date('Y-m-d H:i:s', $result['expireAt']) : null,
            ], '匹配成功，已关联Spark实例');

        } catch (\Exception $e) {
            return $this->error('Spark匹配失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量匹配：对所有来自Spark但未关联instanceId的IP进行匹配
     * POST /spark/bulk-match
     */
    public function bulkMatch(Request $request): JsonResponse
    {
        $unmatchedIps = ProxyIp::whereNull('spark_instance_id')
            ->where('source_name', 'like', '%帕克%') // 斯帕克
            ->whereNull('deleted_at')
            ->get();

        if ($unmatchedIps->isEmpty()) {
            return $this->success(['total' => 0, 'matched' => 0, 'failed' => 0], '没有需要匹配的IP');
        }

        $matched = 0;
        $failed = 0;
        $errors = [];

        foreach ($unmatchedIps as $ip) {
            try {
                $result = null;

                if ($ip->ip_address) {
                    $result = $this->spark->getInstance(['ip' => $ip->ip_address]);
                }
                if (empty($result) && $ip->auth_username) {
                    $result = $this->spark->getInstance(['username' => $ip->auth_username]);
                }

                if (!empty($result) && !empty($result['instanceId'])) {
                    DB::transaction(function () use ($ip, $result) {
                        $ip->update([
                            'spark_instance_id' => $result['instanceId'],
                            'upstream_expires_at' => isset($result['expireAt']) ? date('Y-m-d H:i:s', $result['expireAt']) : null,
                        ]);

                        SparkInstance::firstOrCreate(
                            ['instance_id' => $result['instanceId']],
                            [
                                'spark_order_id' => 0,
                                'proxy_ip_id' => $ip->id,
                                'ip' => $result['ip'] ?? $ip->ip_address,
                                'port' => $result['port'] ?? $ip->port,
                                'username' => $result['username'] ?? $ip->auth_username,
                                'password' => $result['password'] ?? $ip->auth_password,
                                'type' => $result['type'] ?? 1,
                                'use_type' => $result['useType'] ?? 1,
                                'status' => $result['status'] ?? 2,
                                'expire_at' => isset($result['expireAt']) ? date('Y-m-d H:i:s', $result['expireAt']) : null,
                            ]
                        );
                    });
                    $matched++;
                } else {
                    $failed++;
                    $errors[] = "{$ip->ip_address}: 未找到";
                }

                // 避免API限流，每次请求间隔200ms
                usleep(200000);

            } catch (\Exception $e) {
                $failed++;
                $errors[] = "{$ip->ip_address}: {$e->getMessage()}";
            }
        }

        return $this->success([
            'total' => $unmatchedIps->count(),
            'matched' => $matched,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 20), // 最多返回20条错误
        ], "匹配完成: 成功{$matched}条, 失败{$failed}条");
    }

    /**
     * 同步所有已关联的Spark实例状态（更新到期时间、密码等）
     * POST /spark/sync-all
     */
    public function syncAll(Request $request): JsonResponse
    {
        $instances = SparkInstance::whereNotNull('instance_id')
            ->whereIn('status', [1, 2]) // 只同步开通中和正常使用的
            ->get();

        if ($instances->isEmpty()) {
            return $this->success(['total' => 0], '没有需要同步的实例');
        }

        $synced = 0;
        $expired = 0;
        $errors = [];

        foreach ($instances as $instance) {
            try {
                $data = $this->spark->getInstance(['instanceId' => $instance->instance_id]);

                $instance->update([
                    'ip' => $data['ip'] ?? $instance->ip,
                    'port' => $data['port'] ?? $instance->port,
                    'username' => $data['username'] ?? $instance->username,
                    'password' => $data['password'] ?? $instance->password,
                    'status' => $data['status'] ?? $instance->status,
                    'expire_at' => isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : $instance->expire_at,
                ]);

                // 同步到 proxy_ip：补全缺失的凭证 + 更新变化的字段
                if ($instance->proxyIp) {
                    $pip = $instance->proxyIp;
                    $updates = [
                        'upstream_expires_at' => $instance->expire_at,
                    ];

                    $credChanged = false;
                    if ($instance->ip && !$pip->ip_address) {
                        $updates['ip_address'] = $instance->ip;
                        $credChanged = true;
                    }
                    if ($instance->port && !$pip->port) {
                        $updates['port'] = $instance->port;
                        $credChanged = true;
                    }
                    if ($instance->username && !$pip->auth_username) {
                        $updates['auth_username'] = $instance->username;
                        $credChanged = true;
                    }
                    if (($data['password'] ?? '') !== '' && ($data['password'] !== $instance->getOriginal('password') || !$pip->auth_password)) {
                        $updates['auth_password'] = $data['password'];
                        $credChanged = true;
                    }

                    if ($credChanged) {
                        $ip   = $updates['ip_address'] ?? $pip->ip_address;
                        $port = $updates['port'] ?? $pip->port;
                        $user = $updates['auth_username'] ?? $pip->auth_username;
                        $pass = $updates['auth_password'] ?? $pip->auth_password;
                        $updates['socks5_info'] = implode(':', array_filter([$ip, $port, $user, $pass]));
                    }

                    $pip->update($updates);
                }

                // 如果 Spark 侧已释放
                if (($data['status'] ?? 0) >= 3) {
                    $expired++;
                    if ($instance->proxyIp) {
                        $instance->proxyIp->update(['status' => 'expired']);
                        Subscription::where('proxy_ip_id', $instance->proxy_ip_id)
                            ->where('status', 'active')
                            ->update(['status' => 'expired']);
                    }
                }

                $synced++;
                usleep(200000);

            } catch (\Exception $e) {
                $errors[] = "{$instance->instance_id}: {$e->getMessage()}";
            }
        }

        return $this->success([
            'total' => $instances->count(),
            'synced' => $synced,
            'expired' => $expired,
            'errors' => array_slice($errors, 0, 20),
        ], "同步完成: {$synced}条, 其中{$expired}条已过期/释放");
    }

    // ========== 地区查询接口 ==========

    /**
     * 获取所有国家列表
     * GET /spark/areas/countries
     */
    public function countries(Request $request): JsonResponse
    {
        $query = SparkCountry::query();

        if ($request->filled('keyword')) {
            $kw = $request->keyword;
            $query->where(function ($q) use ($kw) {
                $q->where('code', 'like', "%{$kw}%")
                  ->orWhere('name', 'like', "%{$kw}%")
                  ->orWhere('cname', 'like', "%{$kw}%");
            });
        }

        $countries = $query->select('id', 'code', 'name', 'cname', 'full_cname', 'continent_id')
            ->orderBy('code')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'cname' => $c->cname,
                'label' => $c->cname ? "{$c->cname} ({$c->code})" : "{$c->name} ({$c->code})",
            ]);

        return $this->success($countries);
    }

    /**
     * 获取指定国家的州/省列表
     * GET /spark/areas/states?country_code=USA
     */
    public function states(Request $request): JsonResponse
    {
        $request->validate(['country_code' => 'required|string']);

        $country = SparkCountry::where('code', $request->country_code)->first();
        if (!$country) {
            return $this->success([]);
        }

        $states = SparkState::where('country_id', $country->id)
            ->select('id', 'code', 'name', 'cname', 'code_full')
            ->orderBy('code_full')
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'code_full' => $s->code_full,
                'name' => $s->name,
                'cname' => $s->cname,
                'label' => $s->cname ?: $s->name ?: $s->code_full,
            ]);

        return $this->success($states);
    }

    /**
     * 获取指定州的城市列表
     * GET /spark/areas/cities?country_code=USA&state_code=USA0CA
     */
    public function cities(Request $request): JsonResponse
    {
        $query = SparkCity::query();

        if ($request->filled('country_code')) {
            $query->where('country_code', $request->country_code);
        }
        if ($request->filled('state_code')) {
            $state = SparkState::where('code_full', $request->state_code)->first();
            if ($state) {
                $query->where('state_id', $state->id);
            }
        }

        $cities = $query->select('id', 'code', 'name', 'cname', 'code_full', 'country_code')
            ->orderBy('code_full')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'code_full' => $c->code_full,
                'country_code' => $c->country_code,
                'name' => $c->name,
                'cname' => $c->cname,
                'label' => $c->cname ? "{$c->cname} ({$c->code})" : "{$c->name} ({$c->code})",
            ]);

        return $this->success($cities);
    }

    /**
     * 批量翻译国家代码 → 中文名
     * POST /spark/areas/translate
     * Body: { "codes": ["USA", "BRA", "MEX"] }
     */
    public function translateCountries(Request $request): JsonResponse
    {
        $codes = $request->input('codes', []);
        if (empty($codes)) {
            return $this->success([]);
        }

        $map = SparkCountry::whereIn('code', $codes)
            ->pluck('cname', 'code')
            ->toArray();

        return $this->success($map);
    }

    // ========== 内部方法 ==========

    private function syncInstance(SparkInstance $instance): void
    {
        try {
            $data = $this->spark->getInstance(['instanceId' => $instance->instance_id]);

            $instance->update([
                'ip' => $data['ip'] ?? $instance->ip,
                'port' => $data['port'] ?? $instance->port,
                'username' => $data['username'] ?? $instance->username,
                'password' => $data['password'] ?? $instance->password,
                'status' => $data['status'] ?? $instance->status,
                'expire_at' => isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : $instance->expire_at,
            ]);

            // 如果实例已释放(status=4)，标记IP为过期
            if (($data['status'] ?? 0) == 4 && $instance->proxyIp) {
                $instance->proxyIp->update(['status' => 'expired']);

                // 同时过期关联的活跃订阅
                Subscription::where('proxy_ip_id', $instance->proxy_ip_id)
                    ->where('status', 'active')
                    ->update(['status' => 'expired']);
            }

        } catch (\Exception $e) {
            Log::error("Sync instance failed: {$e->getMessage()}", ['instance_id' => $instance->instance_id]);
        }
    }

    // ========== Spark 余额与密码管理 ==========

    /**
     * GET /spark/balance
     * 获取 Spark 账户余额（实时调 API）
     */
    public function balance(): JsonResponse
    {
        try {
            $data = $this->spark->getBalance();

            // Also calculate our spending stats
            $totalSpent = \App\Models\SparkOrder::where('status', 2)
                ->whereNotNull('cost_amount')
                ->sum('cost_amount');
            $monthSpent = \App\Models\SparkOrder::where('status', 2)
                ->whereNotNull('cost_amount')
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('cost_amount');
            $activeInstances = SparkInstance::where('status', 2)->count();

            return $this->success([
                'spark_balance' => $data,
                'our_stats' => [
                    'total_spent' => round((float) $totalSpent, 2),
                    'month_spent' => round((float) $monthSpent, 2),
                    'active_instances' => $activeInstances,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取余额失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /spark/reset-password
     * 重置代理密码
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_ids' => 'required|array|min:1',
            'instance_ids.*' => 'string',
        ]);

        try {
            $this->spark->resetProxyPassword($data['instance_ids']);

            // Refresh instance info to get new passwords
            $updated = [];
            foreach ($data['instance_ids'] as $instanceId) {
                try {
                    $info = $this->spark->getInstance(['instanceId' => $instanceId]);
                    if (!empty($info['password'])) {
                        // Update local records
                        $sparkInstance = SparkInstance::where('instance_id', $instanceId)->first();
                        if ($sparkInstance) {
                            $sparkInstance->update(['password' => $info['password']]);
                            // Also update ProxyIp
                            if ($sparkInstance->proxy_ip_id) {
                                $proxyIp = ProxyIp::find($sparkInstance->proxy_ip_id);
                                if ($proxyIp) {
                                    $oldSocks5 = $proxyIp->socks5_info;
                                    $proxyIp->update([
                                        'auth_password' => $info['password'],
                                        'socks5_info' => implode(':', array_filter([
                                            $proxyIp->ip_address, $proxyIp->port,
                                            $proxyIp->auth_username, $info['password'],
                                        ])),
                                    ]);
                                }
                            }
                        }
                        $updated[] = ['instance_id' => $instanceId, 'new_password' => $info['password']];
                    }
                } catch (\Throwable $e) {
                    $updated[] = ['instance_id' => $instanceId, 'error' => $e->getMessage()];
                }
            }

            return $this->success($updated, '密码已重置');
        } catch (\Throwable $e) {
            return $this->error('重置失败: ' . $e->getMessage(), 500);
        }
    }

    // ========== IP 段分析 ==========

    /**
     * GET /spark/ip-segments
     * 从 Spark 产品库存中获取 IP 段信息
     */
    public function ipSegments(Request $request): JsonResponse
    {
        $products = \App\Services\SparkStockCacheService::products();

        if ($request->filled('country_code')) {
            $cc = strtoupper($request->input('country_code'));
            $products = array_values(array_filter($products, fn($p) => strtoupper($p['country_code'] ?? '') === $cc));
        }

        // Collect products that have CIDR blocks
        $withCidr = collect($products)->filter(fn($p) => !empty($p['cidr_blocks']))->values();

        // Also group all products by country for the overview
        $byCountry = collect($products)->groupBy('country_code')->map(function ($items, $code) {
            $allCidrs = $items->pluck('cidr_blocks')->flatten(1)->filter();
            $cInfo = \App\Models\SparkCountry::where('code', $code)->first();
            return [
                'country_code' => $code,
                'country_name' => $cInfo?->cname ?: ($cInfo?->name ?: $code),
                'product_count' => $items->count(),
                'total_stock' => $items->sum('inventory'),
                'cidr_count' => $allCidrs->count(),
                'cidrs' => $allCidrs->take(50)->values(), // Limit per country
            ];
        })->sortByDesc('total_stock')->values();

        return $this->success([
            'countries' => $byCountry,
            'products_with_cidr' => $withCidr->count(),
            'total_products' => count($products),
        ]);
    }
}
