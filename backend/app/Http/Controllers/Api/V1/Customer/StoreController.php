<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\ForwardPlan;
use App\Models\PricingMultiplier;
use App\Models\SparkCountry;
use App\Models\SystemConfig;
use App\Models\UpstreamProvider;
use App\Services\CountryMapper;
use App\Services\Customer\CheckoutService;
use App\Services\IpipvStockCacheService;
use App\Services\SparkStockCacheService;
use App\Support\SparkContinents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 客户商店 v3 — 完全基于 Spark API 产品 + 倍率定价
 *
 * 售价 = Spark 成本 × 倍率（全局/国家/州/城市/产品级）
 * 产品结构直接映射 Spark GetProductStock 的返回
 */
class StoreController extends Controller
{
    public function __construct(protected CheckoutService $checkout) {}

    /**
     * GET /customer/store/products
     * 返回所有有定价且有库存的产品（合并 Spark + IPIPV）
     */
    public function products(Request $request): JsonResponse
    {
        $ispLabels = [1 => '单ISP', 2 => '双ISP', 3 => '原生ISP', 4 => '机房'];
        $netLabels = [1 => '原生', 2 => '广播'];

        // 合并 Spark + IPIPV 当前库存产品
        $liveProducts = [];
        $sparkProducts = SparkStockCacheService::products();
        foreach ($sparkProducts as &$sp) {
            $sp['source'] = $sp['source'] ?? 'spark';
        }
        $liveProducts = array_merge($liveProducts, $sparkProducts);

        $ipipvPublicSale = UpstreamProvider::where('driver', 'ipipv')
            ->where('is_active', true)
            ->where('public_sale', true)
            ->exists();
        if ($ipipvPublicSale) {
            $ipipvProducts = IpipvStockCacheService::products();
            $liveProducts = array_merge($liveProducts, $ipipvProducts);
        }

        // 以 product_id 为 key 的当前库存 map
        $liveMap = [];
        foreach ($liveProducts as $p) {
            $pid = $p['product_id'] ?? $p['product_no'] ?? null;
            if ($pid) $liveMap[$pid] = $p;
        }

        // 合并：当前有库存的 + 曾经有过库存但现在没有的（售罄）
        $everStocked = SparkStockCacheService::everStockedProducts();
        $allProducts = $liveProducts;
        foreach ($everStocked as $pid => $historyProduct) {
            if (!isset($liveMap[$pid]) && $historyProduct) {
                $historyProduct['inventory'] = 0;
                $allProducts[] = $historyProduct;
            }
        }

        // 预加载国家信息（Spark 国家表）
        $countryCodes = collect($allProducts)->pluck('country_code')->unique();
        $countries = SparkCountry::whereIn('code', $countryCodes)
            ->get(['code', 'cname', 'name', 'continent_id'])
            ->keyBy('code');

        // 预加载州和城市中文名
        $areaCodes = collect($allProducts)->pluck('area_code')->unique()->filter();
        $areaNames = \App\Models\SparkState::whereIn('code_full', $areaCodes)
            ->pluck('cname', 'code_full')->toArray();
        $cityCodes = collect($allProducts)->pluck('city_code')->unique()->filter();
        $cityNames = \App\Models\SparkCity::whereIn('code_full', $cityCodes)
            ->pluck('cname', 'code_full')->toArray();

        $result = [];
        foreach ($allProducts as $p) {
            $source = $p['source'] ?? 'spark';

            // 从未有过库存的产品 → 隐藏
            $pid = $p['product_id'] ?? $p['product_no'] ?? null;
            $stock = $p['inventory'] ?? 0;
            if ($pid && $stock <= 0 && !isset($everStocked[$pid])) continue;

            $salePrice = PricingMultiplier::calcSalePrice($p);
            if ($salePrice === null) continue;

            $customerId = $request->user()?->id;
            $specialPrice = null;
            $specialForwardPrices = null;
            $discountPercentStatic = null;
            $discountPercentVideo = null;
            if ($customerId) {
                // 静态IP折扣
                $staticTrace = \App\Models\CustomerSpecialPrice::findPriceTrace($customerId, $p, 'static');
                if ($staticTrace['price'] !== null) {
                    $specialPrice = (float) $staticTrace['price'];
                } elseif ($staticTrace['discount_percent'] !== null) {
                    $specialPrice = round($salePrice * (float) $staticTrace['discount_percent'] / 100, 2);
                    $discountPercentStatic = (float) $staticTrace['discount_percent'];
                }
                // 视频专线折扣
                $videoTrace = \App\Models\CustomerSpecialPrice::findPriceTrace($customerId, $p, 'video');
                $discountPercentVideo = $videoTrace['discount_percent'] !== null ? (float) $videoTrace['discount_percent'] : null;

                $fwdVideo = $videoTrace['forward_price'];
                $fwdLiveMobile = \App\Models\CustomerSpecialPrice::findForwardPrice($customerId, $p, 'live_mobile');
                $fwdLivePc = \App\Models\CustomerSpecialPrice::findForwardPrice($customerId, $p, 'live_pc');
                if ($fwdVideo !== null || $fwdLiveMobile !== null || $fwdLivePc !== null) {
                    $specialForwardPrices = [
                        'video' => $fwdVideo !== null ? (float) $fwdVideo : null,
                        'live_mobile' => $fwdLiveMobile !== null ? (float) $fwdLiveMobile : null,
                        'live_pc' => $fwdLivePc !== null ? (float) $fwdLivePc : null,
                    ];
                }
            }

            $hasStaticSpecial = $specialPrice !== null;
            $displayPrice = $specialPrice ?? $salePrice;

            $customer = $request->user();
            $vipDiscount = \App\Services\VipService::getDiscount($customer);
            // Don't apply VIP discount when special pricing is active
            $vipPrice = (!$hasStaticSpecial && $vipDiscount < 100) ? round($displayPrice * $vipDiscount / 100, 2) : null;

            $code = $p['country_code'] ?? '';
            $c = $countries->get($code);

            // 国家名：Spark 表查到用 Spark 的，否则走 CountryMapper
            $countryName = $c?->cname ?: ($c?->name ?: '');
            if (!$countryName && $source === 'ipipv') {
                $countryName = $p['country_name'] ?? CountryMapper::toCn($code) ?? '';
            }

            $continent = SparkContinents::CONTINENTS[$c?->continent_id] ?? ['name' => '其他'];
            // IPIPV 产品没有 continent_id，用 CountryMapper 兜底
            $continentId = $c?->continent_id;
            $continentName = $continent['name'];

            $areaCode = $p['area_code'] ?? '';
            $cityCode = $p['city_code'] ?? '';
            $areaName = $areaNames[$areaCode] ?? '';
            $cityName = $cityNames[$cityCode] ?? '';

            if (!$areaName && !$cityName) {
                $pName = $p['product_name'] ?? '';
                if (preg_match('/[-@]([A-Za-z\s]+)$/', $pName, $m)) {
                    $extracted = trim($m[1]);
                    $countryEn = $c?->name ?? '';
                    if ($extracted && strcasecmp($extracted, $countryEn) !== 0) {
                        $cityName = $extracted;
                    }
                }
            }

            $regionParts = array_filter([$countryName, $areaName, $cityName]);
            $regionDisplay = implode(' · ', $regionParts);

            $result[] = [
                'product_id' => $p['product_id'] ?? $p['product_no'] ?? null,
                'product_name' => $p['product_name'] ?? '',
                'source' => $source,
                'country_code' => $code,
                'country_name' => $countryName,
                'continent_id' => $continentId,
                'continent' => $continentName,
                'area_code' => $areaCode,
                'area_name' => $areaName,
                'city_code' => $cityCode,
                'city_name' => $cityName,
                'region_display' => $regionDisplay,
                'isp_type' => $p['isp_type'] ?? 0,
                'isp_label' => $ispLabels[$p['isp_type'] ?? 0] ?? '',
                'net_type' => $p['net_type'] ?? 0,
                'net_label' => $netLabels[$p['net_type'] ?? 0] ?? '',
                'isp' => $p['isp'] ?? null,
                'monthly_price' => $displayPrice,
                'original_price' => ($specialPrice !== null && $specialPrice < $salePrice) ? $salePrice : null,
                'has_special_price' => $specialPrice !== null && $specialPrice < $salePrice,
                'special_forward_prices' => $specialForwardPrices,
                'discount_percent_static' => $discountPercentStatic,
                'discount_percent_video' => $discountPercentVideo,
                'vip_price' => $vipPrice,
                'vip_discount' => $vipDiscount < 100 ? $vipDiscount : null,
                'stock' => $p['inventory'] ?? 0,
                'cidr_blocks' => $p['cidr_blocks'] ?? [],
            ];
        }

        $publicResult = collect($result)->map(function ($item) {
            unset($item['cost_price']);
            return $item;
        })->values();

        $publicResult = $publicResult->sortByDesc('stock')->values();

        $ispTypes = $publicResult->pluck('isp_type')->unique()->filter()->sort()->values()
            ->map(fn($t) => ['value' => $t, 'label' => $ispLabels[$t] ?? "类型{$t}"])->values();

        $storeCustomer = $request->user();
        $storeCustomer?->load('vipTier');
        $vipTierInfo = $storeCustomer?->vipTier ? [
            'name' => $storeCustomer->vipTier->name,
            'discount_percent' => $storeCustomer->vipTier->discount_percent,
            'badge_color' => $storeCustomer->vipTier->badge_color,
        ] : null;

        $forwardEnabled = (bool) SystemConfig::get('store.forward_enabled', false);
        $customerForwardCertified = (bool) $request->user()?->forward_certified;
        $forwardPlans = [];
        $forwardPlansByModule = [];
        if ($forwardEnabled && $customerForwardCertified) {
            $forwardPlans = ForwardPlan::where('is_active', 1)
                ->select('id', 'name', 'type', 'speed_limit_mbps', 'base_price', 'included_traffic_gb', 'overage_price_per_gb', 'description', 'module', 'pricing_mode')
                ->orderBy('base_price')
                ->get();
            $forwardPlansByModule = [
                'video' => $forwardPlans->where('module', 'video')->values()->first(),
                'live_mobile' => $forwardPlans->where('module', 'live_mobile')->values()->first(),
                'live_pc' => $forwardPlans->where('module', 'live_pc')->values()->first(),
            ];
        }

        $lastRefreshed = SparkStockCacheService::lastRefreshedAt();
        $ipipvRefreshed = IpipvStockCacheService::lastRefreshedAt();
        if ($ipipvRefreshed && (!$lastRefreshed || $ipipvRefreshed > $lastRefreshed)) {
            $lastRefreshed = $ipipvRefreshed;
        }

        return $this->success([
            'products' => $publicResult,
            'isp_types' => $ispTypes,
            'continents' => SparkContinents::CONTINENTS,
            'last_refreshed_at' => $lastRefreshed,
            'total_products' => $publicResult->count(),
            'total_stock' => $publicResult->sum('stock'),
            'forward_certified' => $customerForwardCertified,
            'forward_enabled' => $forwardEnabled,
            'forward_plans' => $forwardPlans,
            'forward_plans_by_module' => $forwardPlansByModule,
            'vip_tier' => $vipTierInfo,
        ]);
    }

    /**
     * POST /customer/store/checkout
     * 下单 — 直接按 Spark product_id 购买
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.product_id' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1|max:10',
            'items.*.cidr' => 'nullable|string',
            'duration' => 'required|integer|min:1|max:12',
            'auto_renew' => 'nullable|boolean',
            'forward_plan_id' => 'nullable|integer|exists:forward_plans,id',
            'module' => 'nullable|string|in:static,video,live_mobile,live_pc',
        ]);

        $customer = $request->user();

        // Validate forward plan permission
        $forwardPlanId = $data['forward_plan_id'] ?? null;
        $module = $data['module'] ?? 'static';

        // Auto-resolve forward plan from module if not explicitly provided
        if (!$forwardPlanId && in_array($module, ['video', 'live_mobile', 'live_pc'])) {
            $forwardEnabled = (bool) SystemConfig::get('store.forward_enabled', false);
            if (!$forwardEnabled || !$customer->forward_certified) {
                return $this->error('您暂无权限购买专线服务', 403);
            }
            $plan = ForwardPlan::where('module', $module)->where('is_active', 1)->orderBy('base_price')->first();
            if (!$plan) {
                return $this->error('所选专线套餐不存在或已下架', 422);
            }
            $forwardPlanId = $plan->id;
        } elseif ($forwardPlanId) {
            $forwardEnabled = (bool) SystemConfig::get('store.forward_enabled', false);
            if (!$forwardEnabled || !$customer->forward_certified) {
                return $this->error('您暂无权限购买直连服务', 403);
            }
            $plan = ForwardPlan::where('id', $forwardPlanId)->where('is_active', 1)->first();
            if (!$plan) {
                return $this->error('所选直连套餐不存在或已下架', 422);
            }
        }

        $result = $this->checkout->purchaseByProducts(
            $customer,
            $data['items'],
            (int) $data['duration'],
            (bool) ($data['auto_renew'] ?? false),
            $forwardPlanId,
        );

        return $this->success($result, '购买成功');
    }
}
