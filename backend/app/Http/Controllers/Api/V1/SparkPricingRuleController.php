<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SparkCountry;
use App\Models\SparkPricingRule;
use App\Services\SparkStockCacheService;
use App\Support\SparkContinents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SparkPricingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SparkPricingRule::query()->orderByDesc('id');
        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->is_active);
        }
        $rules = $query->get();

        // 额外附加：每条规则覆盖的国家中文名 + 当前库存数（来自 Spark 缓存）
        $stockByCountry = SparkStockCacheService::stockByCountry();
        $countryMap = SparkCountry::whereIn('code', $rules->pluck('country_codes')->flatten()->unique()->values())
            ->pluck('cname', 'code')->toArray();

        $rules->transform(function ($rule) use ($countryMap, $stockByCountry) {
            $data = $rule->toArray();
            $data['countries'] = collect($rule->country_codes ?? [])->map(fn($code) => [
                'code' => $code,
                'name' => $countryMap[$code] ?? $code,
                'stock' => $stockByCountry[$code]['stock'] ?? 0,
            ])->values();
            $data['total_stock'] = collect($data['countries'])->sum('stock');
            return $data;
        });

        return $this->success($rules);
    }

    public function show(SparkPricingRule $sparkPricingRule): JsonResponse
    {
        return $this->success($sparkPricingRule);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->validateNoConflict($data['country_codes']);

        $rule = SparkPricingRule::create($data);
        return $this->success($rule, 'Spark 定价规则创建成功');
    }

    public function update(Request $request, SparkPricingRule $sparkPricingRule): JsonResponse
    {
        $data = $this->validated($request);
        $this->validateNoConflict($data['country_codes'], $sparkPricingRule->id);

        $sparkPricingRule->update($data);
        return $this->success($sparkPricingRule->fresh(), '更新成功');
    }

    public function destroy(SparkPricingRule $sparkPricingRule): JsonResponse
    {
        $sparkPricingRule->delete();
        return $this->success(null, '已删除');
    }

    /**
     * 返回所有国家（带库存数、洲别、已绑定的规则 id）
     * 给前端的国家选择器用
     *
     * GET /spark-pricing/countries
     */
    public function countries(): JsonResponse
    {
        $stock = SparkStockCacheService::stockByCountry(); // code => {stock, cost_price, ...}
        $bound = SparkPricingRule::boundCountryMap();      // code => rule_id

        // 从 area_country 拉全部国家
        $countries = SparkCountry::orderBy('continent_id')
            ->orderBy('code')
            ->get(['code', 'name', 'cname', 'continent_id'])
            ->map(function ($c) use ($stock, $bound) {
                $continent = SparkContinents::CONTINENTS[$c->continent_id] ?? ['name' => '其他'];
                return [
                    'code' => $c->code,
                    'name' => $c->cname ?: $c->name,
                    'continent_id' => $c->continent_id,
                    'continent' => $continent['name'],
                    'stock' => $stock[$c->code]['stock'] ?? 0,
                    'min_cost' => $stock[$c->code]['min_cost'] ?? null,
                    'bound_rule_id' => $bound[$c->code] ?? null,
                ];
            });

        return $this->success([
            'continents' => SparkContinents::CONTINENTS,
            'presets' => SparkContinents::PRESETS,
            'countries' => $countries,
            'last_refreshed_at' => SparkStockCacheService::lastRefreshedAt(),
        ]);
    }

    /**
     * 按国家代码查价格（供未来客户自助面板使用）
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['country_code' => 'required|string']);
        $rule = SparkPricingRule::findByCountry($request->country_code);
        if (!$rule) {
            return $this->error('该国家暂未配置定价', 404);
        }
        return $this->success([
            'rule_id' => $rule->id,
            'name' => $rule->name,
            'monthly_price' => $rule->monthly_price,
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'monthly_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'sales_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|integer|in:0,1',
            'country_codes' => 'required|array|min:1',
            'country_codes.*' => 'string|max:10',
        ]);

        // 统一转大写 + 去重
        $data['country_codes'] = array_values(array_unique(array_map('strtoupper', $data['country_codes'])));
        $data['is_active'] = $data['is_active'] ?? 1;
        return $data;
    }

    /**
     * 校验同一个国家代码不能被两条启用中的规则同时绑定
     */
    private function validateNoConflict(array $countryCodes, ?int $excludeRuleId = null): void
    {
        $codes = array_map('strtoupper', $countryCodes);
        $query = SparkPricingRule::where('is_active', 1);
        if ($excludeRuleId) {
            $query->where('id', '!=', $excludeRuleId);
        }
        $conflicts = [];
        foreach ($query->get(['id', 'name', 'country_codes']) as $rule) {
            $ruleCodes = $rule->country_codes ?? [];
            $overlap = array_intersect($codes, $ruleCodes);
            if (!empty($overlap)) {
                $conflicts[] = sprintf('「%s」已占用: %s', $rule->name, implode(',', $overlap));
            }
        }
        if (!empty($conflicts)) {
            abort(422, '国家代码冲突：' . implode('; ', $conflicts));
        }
    }
}
