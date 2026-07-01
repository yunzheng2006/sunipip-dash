<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductPricing;
use App\Models\IpGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductPricingController extends Controller
{
    /**
     * GET /product-pricing
     * List all pricing rules with spark cost info
     */
    public function index(Request $request): JsonResponse
    {
        $query = QueryBuilder::for(ProductPricing::class)
            ->with('ipGroup:id,name,display_name,spark_isp_type,spark_net_type')
            ->allowedFilters([
                AllowedFilter::exact('country_code'),
                AllowedFilter::exact('ip_group_id'),
                AllowedFilter::exact('access_type'),
                AllowedFilter::exact('is_active'),
                AllowedFilter::partial('country_name'),
            ])
            ->allowedSorts(['id', 'country_code', 'country_name', 'monthly_price', 'created_at'])
            ->defaultSort('country_code');

        $paginated = $query->paginate($request->input('per_page', 50));

        // Enrich with Spark cost data
        $sparkStock = \App\Services\SparkStockCacheService::stockByCountry();
        $sparkProducts = \App\Services\SparkStockCacheService::products();

        $items = collect($paginated->items())->map(function ($item) use ($sparkStock, $sparkProducts) {
            $arr = $item->toArray();
            $countryStock = $sparkStock[$item->country_code] ?? null;
            $arr['spark_min_cost'] = $countryStock['min_cost'] ?? null;
            $arr['spark_avg_cost'] = $countryStock['avg_cost'] ?? null;

            // Calculate spark stock for this specific ip_group
            $arr['spark_stock'] = $this->calcSparkStock($item->country_code, $item->ipGroup, $sparkProducts);

            // Own available stock from proxy_ips
            $ownQuery = \App\Models\ProxyIp::where('status', 'available')
                ->where('country_code', $item->country_code);
            if ($item->ip_group_id) {
                $ownQuery->where('ip_group_id', $item->ip_group_id);
            }
            $arr['own_available_stock'] = $ownQuery->count();
            $arr['total_stock'] = $arr['spark_stock'] + $arr['own_available_stock'];

            return $arr;
        });

        // Replace items in paginator
        $result = $paginated->toArray();
        $result['data'] = $items->values();

        return $this->success([
            'items' => $items->values(),
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page' => $result['last_page'],
            ],
        ]);
    }

    /**
     * POST /product-pricing
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => 'required|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'ip_group_id' => 'nullable|integer|exists:ip_groups,id',
            'access_type' => 'nullable|string|in:dedicated,shared',
            'monthly_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'sales_price' => 'nullable|numeric|min:0',
            'own_stock' => 'nullable|integer|min:0',
            'max_shared_users' => 'nullable|integer|min:1|max:100',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
        ]);

        $data['access_type'] = $data['access_type'] ?? 'dedicated';

        // Check unique
        $exists = ProductPricing::where('country_code', $data['country_code'])
            ->where('ip_group_id', $data['ip_group_id'] ?? null)
            ->where('access_type', $data['access_type'])
            ->exists();
        if ($exists) {
            return $this->error('该国家+IP组+类型的定价已存在', 422);
        }

        $pricing = ProductPricing::create($data);
        return $this->success($pricing, '定价创建成功');
    }

    /**
     * GET /product-pricing/{productPricing}
     */
    public function show(ProductPricing $productPricing): JsonResponse
    {
        $productPricing->load('ipGroup');
        return $this->success($productPricing);
    }

    /**
     * PUT /product-pricing/{productPricing}
     */
    public function update(Request $request, ProductPricing $productPricing): JsonResponse
    {
        $data = $request->validate([
            'country_code' => 'sometimes|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'ip_group_id' => 'nullable|integer|exists:ip_groups,id',
            'access_type' => 'nullable|string|in:dedicated,shared',
            'monthly_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'sales_price' => 'nullable|numeric|min:0',
            'own_stock' => 'nullable|integer|min:0',
            'max_shared_users' => 'nullable|integer|min:1|max:100',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
        ]);

        $productPricing->update($data);
        return $this->success($productPricing->fresh(), '定价更新成功');
    }

    /**
     * DELETE /product-pricing/{productPricing}
     */
    public function destroy(ProductPricing $productPricing): JsonResponse
    {
        $productPricing->delete();
        return $this->success(null, '定价已删除');
    }

    /**
     * GET /product-pricing/country/{code}
     * 获取某个国家的全部定价（通用 + 各IP组）
     */
    public function countryPricing(string $code): JsonResponse
    {
        $code = strtoupper($code);
        $country = \App\Models\SparkCountry::where('code', $code)->first();

        $pricings = ProductPricing::where('country_code', $code)
            ->with('ipGroup:id,name,display_name,spark_isp_type,spark_net_type,sort_order')
            ->orderByRaw('ip_group_id IS NULL DESC') // 通用在前
            ->orderBy('ip_group_id')
            ->get();

        $ipGroups = IpGroup::where('status', 1)
            ->whereNotNull('spark_isp_type')
            ->where('slug', '!=', 'legacy')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'display_name', 'spark_isp_type', 'spark_net_type', 'sort_order']);

        // Spark cost per ip_group
        $sparkProducts = \App\Services\SparkStockCacheService::products();
        $groupCosts = [];
        foreach ($ipGroups as $g) {
            $filtered = collect($sparkProducts)->filter(function ($p) use ($code, $g) {
                if (strtoupper($p['country_code'] ?? '') !== $code) return false;
                if ($g->spark_isp_type && ($p['isp_type'] ?? null) != $g->spark_isp_type) return false;
                if ($g->spark_net_type !== null && ($p['net_type'] ?? null) != $g->spark_net_type) return false;
                return true;
            });
            $groupCosts[$g->id] = [
                'stock' => $filtered->sum('inventory'),
                'min_cost' => $filtered->min('cost_price'),
            ];
        }

        // Total spark stock (all types)
        $allSparkStock = collect($sparkProducts)
            ->where('country_code', $code)
            ->sum('inventory');
        $allMinCost = collect($sparkProducts)
            ->where('country_code', $code)
            ->min('cost_price');

        return $this->success([
            'country_code' => $code,
            'country_name' => $country?->cname ?: ($country?->name ?: $code),
            'pricings' => $pricings,
            'ip_groups' => $ipGroups,
            'group_costs' => $groupCosts,
            'total_spark_stock' => $allSparkStock,
            'total_min_cost' => $allMinCost,
        ]);
    }

    /**
     * POST /product-pricing/save-country
     * 一次性保存某个国家的全部定价（通用 + 各IP组）
     */
    public function saveCountryPricing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => 'required|string|max:10',
            'country_name' => 'nullable|string|max:100',
            'access_type' => 'nullable|string|in:dedicated,shared',
            'items' => 'required|array',
            'items.*.ip_group_id' => 'nullable|integer',
            'items.*.monthly_price' => 'required|numeric|min:0',
            'items.*.sales_price' => 'nullable|numeric|min:0',
            'items.*.is_active' => 'required|integer|in:0,1',
        ]);

        $code = strtoupper($data['country_code']);
        $countryName = $data['country_name'] ?: (
            \App\Models\SparkCountry::where('code', $code)->value('cname') ?: $code
        );
        $accessType = $data['access_type'] ?? 'dedicated';

        $created = 0;
        $updated = 0;

        foreach ($data['items'] as $item) {
            $ipGroupId = $item['ip_group_id'] ?: null;

            $existing = ProductPricing::where('country_code', $code)
                ->where('access_type', $accessType)
                ->where(fn($q) => $ipGroupId ? $q->where('ip_group_id', $ipGroupId) : $q->whereNull('ip_group_id'))
                ->first();

            $values = [
                'monthly_price' => $item['monthly_price'],
                'is_active' => $item['is_active'],
            ];
            if (array_key_exists('sales_price', $item)) {
                $values['sales_price'] = $item['sales_price'];
            }

            if ($existing) {
                $existing->update($values);
                $updated++;
            } else {
                if ($item['monthly_price'] > 0) {
                    ProductPricing::create(array_merge($values, [
                        'country_code' => $code,
                        'country_name' => $countryName,
                        'ip_group_id' => $ipGroupId,
                        'access_type' => $accessType,
                    ]));
                    $created++;
                }
            }
        }

        return $this->success([
            'created' => $created,
            'updated' => $updated,
        ], "保存成功：新建 {$created}，更新 {$updated}");
    }

    /**
     * POST /product-pricing/batch-set
     * Batch create/update pricing for multiple countries
     */
    public function batchSet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_codes' => 'required|array|min:1',
            'country_codes.*' => 'string|max:10',
            'ip_group_id' => 'nullable|integer|exists:ip_groups,id',
            'access_type' => 'nullable|string|in:dedicated,shared',
            'monthly_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|integer|in:0,1',
        ]);

        $accessType = $data['access_type'] ?? 'dedicated';
        $ipGroupId = $data['ip_group_id'] ?? null;
        $updated = 0;
        $created = 0;

        // Lookup country names
        $countryNames = \App\Models\SparkCountry::whereIn('code', $data['country_codes'])
            ->pluck('cname', 'code')->toArray();

        foreach ($data['country_codes'] as $code) {
            $existing = ProductPricing::where('country_code', $code)
                ->where('ip_group_id', $ipGroupId)
                ->where('access_type', $accessType)
                ->first();

            $values = [
                'monthly_price' => $data['monthly_price'],
                'is_active' => $data['is_active'] ?? 1,
            ];
            if (isset($data['cost_price'])) $values['cost_price'] = $data['cost_price'];

            if ($existing) {
                $existing->update($values);
                $updated++;
            } else {
                ProductPricing::create(array_merge($values, [
                    'country_code' => $code,
                    'country_name' => $countryNames[$code] ?? '',
                    'ip_group_id' => $ipGroupId,
                    'access_type' => $accessType,
                ]));
                $created++;
            }
        }

        return $this->success([
            'created' => $created,
            'updated' => $updated,
        ], "批量设置完成：新建 {$created}，更新 {$updated}");
    }

    /**
     * POST /product-pricing/sync-spark-cost
     * Refresh Spark cost prices for all pricing rules
     */
    public function syncSparkCost(): JsonResponse
    {
        $sparkStock = \App\Services\SparkStockCacheService::stockByCountry(true);
        $updated = 0;

        $pricings = ProductPricing::where('is_active', 1)->get();
        foreach ($pricings as $pricing) {
            $countryData = $sparkStock[$pricing->country_code] ?? null;
            if ($countryData && isset($countryData['min_cost'])) {
                $pricing->update(['cost_price' => $countryData['min_cost']]);
                $updated++;
            }
        }

        return $this->success([
            'updated' => $updated,
            'last_refreshed_at' => \App\Services\SparkStockCacheService::lastRefreshedAt(),
        ], "已同步 {$updated} 条定价的 Spark 成本");
    }

    /**
     * GET /product-pricing/countries-overview
     * Overview: all countries with pricing status + stock
     */
    public function countriesOverview(): JsonResponse
    {
        $sparkStock = \App\Services\SparkStockCacheService::stockByCountry();
        $sparkProducts = \App\Services\SparkStockCacheService::products();

        // All countries from SparkCountry
        $countries = \App\Models\SparkCountry::orderBy('cname')->get()
            ->map(fn($c) => [
                'code' => $c->code,
                'name' => $c->cname ?: $c->name,
                'continent_id' => $c->continent_id,
            ])->keyBy('code');

        // Existing pricing
        $pricings = ProductPricing::where('is_active', 1)
            ->get()
            ->groupBy('country_code');

        $result = [];
        foreach ($countries as $code => $country) {
            $stock = $sparkStock[$code] ?? null;
            $countryPricings = $pricings[$code] ?? collect();

            $result[] = [
                'code' => $code,
                'name' => $country['name'],
                'continent_id' => $country['continent_id'],
                'spark_stock' => $stock['stock'] ?? 0,
                'spark_min_cost' => $stock['min_cost'] ?? null,
                'has_pricing' => $countryPricings->isNotEmpty(),
                'pricing_count' => $countryPricings->count(),
                'default_price' => $countryPricings->whereNull('ip_group_id')->first()?->monthly_price,
            ];
        }

        // Sort: has stock first, then by stock desc
        usort($result, function ($a, $b) {
            if ($a['spark_stock'] > 0 && $b['spark_stock'] == 0) return -1;
            if ($a['spark_stock'] == 0 && $b['spark_stock'] > 0) return 1;
            return $b['spark_stock'] <=> $a['spark_stock'];
        });

        return $this->success($result);
    }

    /**
     * Calculate Spark stock for a specific country + ip_group combination
     */
    private function calcSparkStock(string $countryCode, ?IpGroup $ipGroup, array $sparkProducts): int
    {
        $filtered = collect($sparkProducts)->filter(function ($p) use ($countryCode, $ipGroup) {
            if ($p['country_code'] !== $countryCode) return false;
            if (!$ipGroup) return true; // No group filter = all products
            if ($ipGroup->spark_isp_type && $p['isp_type'] != $ipGroup->spark_isp_type) return false;
            if ($ipGroup->spark_net_type !== null && $p['net_type'] != $ipGroup->spark_net_type) return false;
            return true;
        });

        return $filtered->sum('inventory');
    }
}
