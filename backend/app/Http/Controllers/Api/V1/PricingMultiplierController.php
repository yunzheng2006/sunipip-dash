<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PricingMultiplier;
use App\Services\SparkStockCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingMultiplierController extends Controller
{
    /**
     * GET /pricing-multipliers
     * 列出所有倍率规则
     */
    public function index(): JsonResponse
    {
        $rules = PricingMultiplier::orderByDesc('priority')
            ->orderByRaw("FIELD(scope, 'product', 'city', 'area', 'country', 'global')")
            ->orderBy('country_code')
            ->orderBy('area_code')
            ->get();

        return $this->success($rules);
    }

    /**
     * POST /pricing-multipliers
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => 'required|string|in:global,country,area,city,product',
            'priority' => 'nullable|integer|min:0|max:9999',
            'country_code' => 'nullable|string|max:10',
            'area_code' => 'nullable|string|max:50',
            'city_code' => 'nullable|string|max:50',
            'product_id' => 'nullable|string|max:100',
            'cost_match' => 'nullable|numeric|min:0',
            'multiplier' => 'required|numeric|min:0.1|max:99',
            'min_price' => 'nullable|numeric|min:0',
            'fixed_price' => 'nullable|numeric|min:0',
            'sales_multiplier' => 'nullable|numeric|min:0.1|max:99',
            'sales_fixed_price' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string|max:255',
        ]);

        // 规范化：大写国家代码，空串转 null
        if (isset($data['country_code'])) {
            $data['country_code'] = $data['country_code'] !== '' ? strtoupper($data['country_code']) : null;
        }
        foreach (['area_code', 'city_code', 'product_id'] as $k) {
            if (isset($data[$k]) && $data[$k] === '') $data[$k] = null;
        }

        $rule = PricingMultiplier::create($data);
        return $this->success($rule, '创建成功');
    }

    /**
     * PUT /pricing-multipliers/{pricingMultiplier}
     */
    public function update(Request $request, PricingMultiplier $pricingMultiplier): JsonResponse
    {
        $data = $request->validate([
            'priority' => 'nullable|integer|min:0|max:9999',
            'country_code' => 'nullable|string|max:10',
            'area_code' => 'nullable|string|max:50',
            'city_code' => 'nullable|string|max:50',
            'product_id' => 'nullable|string|max:100',
            'cost_match' => 'nullable|numeric|min:0',
            'multiplier' => 'sometimes|numeric|min:0.1|max:99',
            'min_price' => 'nullable|numeric|min:0',
            'fixed_price' => 'nullable|numeric|min:0',
            'sales_multiplier' => 'nullable|numeric|min:0.1|max:99',
            'sales_fixed_price' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string|max:255',
        ]);

        // 规范化：国家代码统一大写；空串转 null（避免 DB 存空串导致后续匹配困惑）
        if (isset($data['country_code'])) {
            $data['country_code'] = $data['country_code'] !== '' ? strtoupper($data['country_code']) : null;
        }
        foreach (['area_code', 'city_code', 'product_id'] as $k) {
            if (isset($data[$k]) && $data[$k] === '') $data[$k] = null;
        }

        $pricingMultiplier->update($data);
        return $this->success($pricingMultiplier->fresh(), '更新成功');
    }

    /**
     * DELETE /pricing-multipliers/{pricingMultiplier}
     */
    public function destroy(PricingMultiplier $pricingMultiplier): JsonResponse
    {
        if ($pricingMultiplier->scope === 'global') {
            return $this->error('全局默认倍率不可删除，只能编辑', 422);
        }
        $pricingMultiplier->delete();
        return $this->success(null, '已删除');
    }

    /**
     * POST /pricing-multipliers/batch-set
     * 批量设置某些国家的倍率
     */
    public function batchSet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_codes' => 'required|array|min:1',
            'country_codes.*' => 'string|max:10',
            'multiplier' => 'required|numeric|min:0.1|max:99',
            'min_price' => 'nullable|numeric|min:0',
        ]);

        $created = 0;
        $updated = 0;
        foreach ($data['country_codes'] as $code) {
            $code = strtoupper(trim($code));
            if ($code === '') continue;
            $existing = PricingMultiplier::where('scope', 'country')
                ->where('country_code', $code)
                ->first();

            $values = ['multiplier' => $data['multiplier'], 'is_active' => 1];
            if (isset($data['min_price'])) $values['min_price'] = $data['min_price'];

            if ($existing) {
                $existing->update($values);
                $updated++;
            } else {
                PricingMultiplier::create(array_merge($values, [
                    'scope' => 'country',
                    'country_code' => $code,
                ]));
                $created++;
            }
        }

        return $this->success(['created' => $created, 'updated' => $updated],
            "批量设置完成：新建 {$created}，更新 {$updated}");
    }

    /**
     * GET /pricing-multipliers/preview
     * 预览：用当前倍率配置计算所有 Spark 产品的售价
     */
    public function preview(Request $request): JsonResponse
    {
        $products = SparkStockCacheService::products();

        $countryFilter = $request->input('country_code');
        if ($countryFilter) {
            $products = array_values(array_filter($products,
                fn($p) => strtoupper($p['country_code'] ?? '') === strtoupper($countryFilter)));
        }

        // 按国家→州→城市聚合并计算售价
        $result = collect($products)->map(function ($p) {
            $salePrice = PricingMultiplier::calcSalePrice($p);
            return array_merge($p, [
                'sale_price' => $salePrice,
                'profit' => $salePrice !== null ? round($salePrice - ($p['cost_price'] ?? 0), 2) : null,
            ]);
        });

        // 按国家分组统计
        $byCountry = $result->groupBy('country_code')->map(function ($items, $code) {
            $cInfo = \App\Models\SparkCountry::where('code', $code)->first();
            return [
                'country_code' => $code,
                'country_name' => $cInfo?->cname ?: ($cInfo?->name ?: $code),
                'products' => $items->count(),
                'total_stock' => $items->sum('inventory'),
                'min_cost' => $items->min('cost_price'),
                'max_cost' => $items->max('cost_price'),
                'min_sale' => $items->min('sale_price'),
                'max_sale' => $items->max('sale_price'),
                'has_pricing' => $items->contains(fn($p) => $p['sale_price'] !== null),
            ];
        })->sortByDesc('total_stock')->values();

        return $this->success([
            'countries' => $byCountry,
            'total_products' => $result->count(),
            'priced_products' => $result->whereNotNull('sale_price')->count(),
            'global_multiplier' => PricingMultiplier::globalMultiplier(),
        ]);
    }

    /**
     * GET /pricing-multipliers/debug-match
     * 诊断：对某个产品查"实际命中的是哪条规则"，帮助管理员确认优先级
     *
     * 参数：product_id 或 (country_code + area_code + city_code)
     */
    public function debugMatch(Request $request): JsonResponse
    {
        $productId = $request->input('product_id');
        $product = null;

        if ($productId) {
            $all = SparkStockCacheService::products();
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
                'cost_price'   => (float) $request->input('cost_price', 0),
            ];
        }

        $rule = PricingMultiplier::matchRule($product);
        $price = PricingMultiplier::calcSalePrice($product);

        return $this->success([
            'product_query' => [
                'product_id'   => $product['product_id']   ?? null,
                'country_code' => $product['country_code'] ?? null,
                'area_code'    => $product['area_code']    ?? null,
                'city_code'    => $product['city_code']    ?? null,
                'cost_price'   => $product['cost_price']   ?? null,
            ],
            'matched_rule' => $rule ? [
                'id'           => $rule->id,
                'scope'        => $rule->scope,
                'country_code' => $rule->country_code,
                'area_code'    => $rule->area_code,
                'city_code'    => $rule->city_code,
                'product_id'   => $rule->product_id,
                'multiplier'   => (float) $rule->multiplier,
                'fixed_price'  => $rule->fixed_price !== null ? (float) $rule->fixed_price : null,
                'min_price'    => $rule->min_price !== null ? (float) $rule->min_price : null,
                'remark'       => $rule->remark,
            ] : null,
            'computed_sale_price' => $price,
        ]);
    }

    /**
     * GET /pricing-multipliers/product-list
     * 查看某个国家的产品详细售价
     */
    public function productList(Request $request): JsonResponse
    {
        $code = strtoupper($request->input('country_code', ''));
        if (!$code) return $this->error('请指定国家代码', 422);

        $products = SparkStockCacheService::products();
        $products = array_values(array_filter($products,
            fn($p) => strtoupper($p['country_code'] ?? '') === $code));

        $ispLabels = [1 => '单ISP', 2 => '双ISP', 3 => '原生ISP', 4 => '机房'];
        $netLabels = [1 => '原生', 2 => '广播'];

        $result = collect($products)->map(function ($p) use ($ispLabels, $netLabels) {
            $salePrice = PricingMultiplier::calcSalePrice($p);
            $salesPrice = PricingMultiplier::calcSalesPrice($p);
            return [
                'product_id' => $p['product_id'],
                'product_name' => $p['product_name'],
                'area_code' => $p['area_code'] ?? '',
                'city_code' => $p['city_code'] ?? '',
                'isp_type' => $p['isp_type'] ?? 0,
                'isp_label' => $ispLabels[$p['isp_type'] ?? 0] ?? '未知',
                'net_type' => $p['net_type'] ?? 0,
                'net_label' => $netLabels[$p['net_type'] ?? 0] ?? '',
                'isp' => $p['isp'] ?? null,
                'cost_price' => $p['cost_price'],
                'sale_price' => $salePrice,
                'sales_price' => $salesPrice,
                'profit' => $salePrice !== null ? round($salePrice - ($p['cost_price'] ?? 0), 2) : null,
                'inventory' => $p['inventory'] ?? 0,
                'cidr_blocks' => $p['cidr_blocks'] ?? [],
            ];
        })->sortByDesc('inventory')->values();

        $cInfo = \App\Models\SparkCountry::where('code', $code)->first();

        return $this->success([
            'country_code' => $code,
            'country_name' => $cInfo?->cname ?: ($cInfo?->name ?: $code),
            'products' => $result,
        ]);
    }
}
