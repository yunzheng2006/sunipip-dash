<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerSpecialPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerSpecialPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerSpecialPrice::with(['customer:id,customer_name,phone', 'approver:id,name']);

        $user = $request->user();
        if ($user && $user->can('pricing.set_discount') && !$user->can('pricing.manage')) {
            $myCustomerIds = \App\Models\Customer::where('sales_person', $user->name)->pluck('id');
            $query->whereIn('customer_id', $myCustomerIds);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }
        if ($request->filled('country_code')) {
            $query->where('country_code', strtoupper($request->input('country_code')));
        }
        if ($request->filled('keyword')) {
            $kw = $request->input('keyword');
            $query->whereHas('customer', function ($q) use ($kw) {
                $q->where('customer_name', 'like', "%{$kw}%")
                  ->orWhere('phone', 'like', "%{$kw}%")
                  ->orWhere('username', 'like', "%{$kw}%");
            });
        }

        return $this->success($query->orderBy('customer_id')->orderByDesc('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSalesDiscount = $user->can('pricing.set_discount') && !$user->can('pricing.manage');

        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'country_code' => 'nullable|string|max:10',
            'area_code' => 'nullable|string|max:50',
            'city_code' => 'nullable|string|max:50',
            'product_id' => 'nullable|string|max:100',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'string|max:100',
            'special_price' => 'nullable|numeric|min:0',
            'forward_price_video' => 'nullable|numeric|min:0',
            'forward_price_live_mobile' => 'nullable|numeric|min:0',
            'forward_price_live_pc' => 'nullable|numeric|min:0',
            'discount_percent_static' => 'nullable|numeric|min:1|max:99',
            'discount_percent_video' => 'nullable|numeric|min:1|max:99',
            'remark' => 'nullable|string|max:500',
            'is_active' => 'nullable|integer|in:0,1',
        ]);

        if ($isSalesDiscount) {
            $customer = \App\Models\Customer::find($data['customer_id']);
            if (!$customer || $customer->sales_person !== $user->name) {
                return $this->error('只能为自己名下的客户设置特批价', 403);
            }

            $data['special_price'] = null;
            $data['forward_price_video'] = null;
            $data['forward_price_live_mobile'] = null;
            $data['forward_price_live_pc'] = null;

            $maxDiscount = $this->getRoleMaxDiscount($user);
            if (!$maxDiscount) {
                return $this->error('当前角色未配置最大折扣限制，请联系管理员', 403);
            }

            foreach (['discount_percent_static', 'discount_percent_video'] as $field) {
                if (isset($data[$field]) && $data[$field] < $maxDiscount) {
                    return $this->error("折扣不能低于 {$maxDiscount} 折（当前角色最大折扣限制）", 422);
                }
            }
        }

        if (!$this->hasAnyPrice($data)) {
            return $this->error('请至少填写一项价格或折扣', 422);
        }

        $data = $this->normalizeCodes($data);
        $data['approved_by'] = $user?->id;

        $productIds = $data['product_ids'] ?? null;
        unset($data['product_ids']);

        if (!empty($productIds) && is_array($productIds)) {
            $created = [];
            foreach ($productIds as $pid) {
                $row = $data;
                $row['product_id'] = trim($pid) ?: null;
                $created[] = CustomerSpecialPrice::create($row);
            }
            return $this->success(
                CustomerSpecialPrice::with(['customer:id,customer_name', 'approver:id,name'])
                    ->whereIn('id', collect($created)->pluck('id'))
                    ->get(),
                "已创建 " . count($created) . " 条特批价"
            );
        }

        $price = CustomerSpecialPrice::create($data);
        return $this->success($price->load(['customer:id,customer_name', 'approver:id,name']), '特批价已创建');
    }

    public function update(Request $request, CustomerSpecialPrice $customerSpecialPrice): JsonResponse
    {
        $data = $request->validate([
            'country_code' => 'nullable|string|max:10',
            'area_code' => 'nullable|string|max:50',
            'city_code' => 'nullable|string|max:50',
            'product_id' => 'nullable|string|max:100',
            'special_price' => 'nullable|numeric|min:0',
            'forward_price_video' => 'nullable|numeric|min:0',
            'forward_price_live_mobile' => 'nullable|numeric|min:0',
            'forward_price_live_pc' => 'nullable|numeric|min:0',
            'discount_percent_static' => 'nullable|numeric|min:1|max:99',
            'discount_percent_video' => 'nullable|numeric|min:1|max:99',
            'remark' => 'nullable|string|max:500',
            'is_active' => 'nullable|integer|in:0,1',
        ]);

        $data = $this->normalizeCodes($data);
        $customerSpecialPrice->update($data);

        $fresh = $customerSpecialPrice->fresh();
        if ($fresh->special_price === null
            && $fresh->forward_price_video === null
            && $fresh->forward_price_live_mobile === null
            && $fresh->forward_price_live_pc === null
            && $fresh->discount_percent_static === null
            && $fresh->discount_percent_video === null) {
            return $this->error('保存失败：所有价格字段不能同时为空', 422);
        }

        return $this->success($fresh, '更新成功');
    }

    public function destroy(CustomerSpecialPrice $customerSpecialPrice): JsonResponse
    {
        $customerSpecialPrice->delete();
        return $this->success(null, '已删除');
    }

    public function debugMatch(Request $request): JsonResponse
    {
        $customerId = (int) $request->input('customer_id');
        if (!$customerId) return $this->error('需要 customer_id', 422);

        $productId = $request->input('product_id');
        $module = $request->input('module', 'static');
        $product = null;

        if ($productId) {
            $all = \App\Services\SparkStockCacheService::products();
            foreach ($all as $p) {
                if (($p['product_id'] ?? null) === $productId) {
                    $product = $p;
                    break;
                }
            }
            if (!$product) return $this->error('产品未找到', 404);
        } else {
            $product = [
                'country_code' => $request->input('country_code'),
                'area_code'    => $request->input('area_code'),
                'city_code'    => $request->input('city_code'),
                'product_id'   => $request->input('product_id'),
            ];
        }

        $trace = CustomerSpecialPrice::findPriceTrace($customerId, $product, $module);

        $rows = CustomerSpecialPrice::where('customer_id', $customerId)->get([
            'id', 'country_code', 'area_code', 'city_code', 'product_id',
            'special_price', 'forward_price_video', 'forward_price_live_mobile', 'forward_price_live_pc',
            'discount_percent_static', 'discount_percent_video', 'is_active', 'remark',
        ]);

        return $this->success([
            'customer_id'         => $customerId,
            'product_query'       => [
                'product_id'   => $product['product_id']   ?? null,
                'country_code' => $product['country_code'] ?? null,
                'area_code'    => $product['area_code']    ?? null,
                'city_code'    => $product['city_code']    ?? null,
            ],
            'module'              => $module,
            'matched_price'       => $trace['price'],
            'matched_forward'     => $trace['forward_price'],
            'matched_rule_id'     => $trace['rule_id'],
            'matched_scope'       => $trace['hit_scope'],
            'scope_trace'         => $trace['trace'],
            'all_rules_for_customer' => $rows,
        ]);
    }

    private function hasAnyPrice(array $data): bool
    {
        return isset($data['special_price'])
            || isset($data['forward_price_video'])
            || isset($data['forward_price_live_mobile'])
            || isset($data['forward_price_live_pc'])
            || isset($data['discount_percent_static'])
            || isset($data['discount_percent_video']);
    }

    private function getRoleMaxDiscount($user): ?int
    {
        $roles = $user->roles;
        $maxDiscount = null;
        foreach ($roles as $role) {
            $settings = is_string($role->settings) ? json_decode($role->settings, true) : ($role->settings ?? []);
            $roleMax = $settings['max_discount_percent'] ?? null;
            if ($roleMax !== null) {
                $maxDiscount = $maxDiscount === null ? $roleMax : min($maxDiscount, $roleMax);
            }
        }
        return $maxDiscount;
    }

    private function normalizeCodes(array $data): array
    {
        foreach (['country_code', 'area_code', 'city_code', 'product_id'] as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
            $data[$k] = ($v === '' || $v === null) ? null : $v;
        }
        if (!empty($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }
        return $data;
    }
}
