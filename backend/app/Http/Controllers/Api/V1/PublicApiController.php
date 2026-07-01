<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\PricingMultiplier;
use App\Models\SparkCountry;
use App\Models\VipTier;
use App\Services\SparkStockCacheService;
use App\Support\SparkContinents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 对外开放的公开 API（需 X-API-Key Header）
 * 用途：给分销商、合作方提供商店数据，价格可按 key 设置加成
 */
class PublicApiController extends Controller
{
    /**
     * GET /public/v1/products
     * 返回所有产品列表（价格按 api_key.price_markup 倍数加成）
     */
    public function products(Request $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $markup = (float) ($apiKey->price_markup ?: 1.00);

        $sparkProducts = SparkStockCacheService::products();

        $countryCodes = collect($sparkProducts)->pluck('country_code')->unique();
        $countries = SparkCountry::whereIn('code', $countryCodes)
            ->get(['code', 'cname', 'name', 'continent_id'])
            ->keyBy('code');

        $ispLabels = [1 => '单ISP', 2 => '双ISP', 3 => '原生ISP', 4 => '机房'];
        $netLabels = [1 => 'native', 2 => 'broadcast'];

        $result = [];
        foreach ($sparkProducts as $p) {
            $salePrice = PricingMultiplier::calcSalePrice($p);
            if ($salePrice === null) continue;

            $finalPrice = round($salePrice * $markup, 2);

            $code = $p['country_code'] ?? '';
            $c = $countries->get($code);
            $continent = SparkContinents::CONTINENTS[$c?->continent_id] ?? ['name' => 'Other'];

            $result[] = [
                'product_id' => $p['product_id'],
                'country_code' => $code,
                'country_name' => $c?->cname ?: $code,
                'country_en' => $c?->name,
                'continent' => $continent['name'],
                'isp_type' => $p['isp_type'] ?? 0,
                'isp_label' => $ispLabels[$p['isp_type'] ?? 0] ?? '',
                'net_type' => $p['net_type'] ?? 0,
                'net_label' => $netLabels[$p['net_type'] ?? 0] ?? '',
                'monthly_price' => $finalPrice,
                'currency' => 'CNY',
                'stock' => (int) ($p['inventory'] ?? 0),
                'in_stock' => ($p['inventory'] ?? 0) > 0,
            ];
        }

        return $this->success([
            'total' => count($result),
            'products' => $result,
            'updated_at' => SparkStockCacheService::lastRefreshedAt(),
        ]);
    }

    /**
     * GET /public/v1/stock-by-country
     * 按国家聚合库存和最低价
     */
    public function stockByCountry(Request $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $markup = (float) ($apiKey->price_markup ?: 1.00);

        $sparkProducts = SparkStockCacheService::products();
        $countryCodes = collect($sparkProducts)->pluck('country_code')->unique();
        $countries = SparkCountry::whereIn('code', $countryCodes)
            ->get(['code', 'cname', 'name', 'continent_id'])
            ->keyBy('code');

        $byCountry = [];
        foreach ($sparkProducts as $p) {
            $salePrice = PricingMultiplier::calcSalePrice($p);
            if ($salePrice === null) continue;

            $code = $p['country_code'] ?? '';
            if (!isset($byCountry[$code])) {
                $c = $countries->get($code);
                $continent = SparkContinents::CONTINENTS[$c?->continent_id] ?? ['name' => 'Other'];
                $byCountry[$code] = [
                    'country_code' => $code,
                    'country_name' => $c?->cname ?: $code,
                    'country_en' => $c?->name,
                    'continent' => $continent['name'],
                    'stock' => 0,
                    'min_price' => null,
                    'max_price' => null,
                ];
            }
            $price = round($salePrice * $markup, 2);
            $byCountry[$code]['stock'] += (int) ($p['inventory'] ?? 0);
            if ($byCountry[$code]['min_price'] === null || $price < $byCountry[$code]['min_price']) {
                $byCountry[$code]['min_price'] = $price;
            }
            if ($byCountry[$code]['max_price'] === null || $price > $byCountry[$code]['max_price']) {
                $byCountry[$code]['max_price'] = $price;
            }
        }

        return $this->success([
            'total' => count($byCountry),
            'countries' => array_values($byCountry),
            'updated_at' => SparkStockCacheService::lastRefreshedAt(),
        ]);
    }

    /**
     * GET /public/v1/vip-tiers
     * 返回平台 VIP 等级表，供分销商展示"充值送折扣"
     */
    public function vipTiers(Request $request): JsonResponse
    {
        $tiers = VipTier::where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'spending_threshold', 'topup_threshold',
                   'discount_percent', 'badge_color', 'description', 'sort_order']);

        $result = $tiers->map(fn($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'spending_threshold' => (float) $t->spending_threshold,
            'topup_threshold' => $t->topup_threshold !== null ? (float) $t->topup_threshold : null,
            'discount_percent' => (int) $t->discount_percent,
            'save_percent' => 100 - (int) $t->discount_percent,
            'badge_color' => $t->badge_color,
            'description' => $t->description,
            'sort_order' => (int) $t->sort_order,
        ])->values();

        return $this->success([
            'total' => $result->count(),
            'tiers' => $result,
            'currency' => 'CNY',
        ]);
    }
}
