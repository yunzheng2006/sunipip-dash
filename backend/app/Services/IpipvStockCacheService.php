<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IpipvStockCacheService
{
    private const KEY_RAW = 'ipipv:products:raw';
    private const KEY_BY_COUNTRY = 'ipipv:products:by_country';
    private const KEY_REFRESHED = 'ipipv:products:refreshed';
    private const TTL = 600;

    public static function refresh(): array
    {
        $api = app(IpipvApiService::class);
        if (!$api->isConfigured()) {
            Log::info('IpipvStockCache: IPIPV 未配置，跳过刷新');
            return ['count' => 0, 'by_country_count' => 0];
        }

        $allProducts = [];

        try {
            $data = $api->getProducts(['proxyType' => [103]]);
            $products = $data['data'] ?? $data;
            if (is_array($products)) {
                $allProducts = array_merge($allProducts, $products);
            }
        } catch (\Throwable $e) {
            Log::warning('IpipvStockCache refresh failed: ' . $e->getMessage());
        }

        $normalized = collect($allProducts)->map(function ($p) {
            $rawCountry = $p['countryCode'] ?? '';
            $resolved = CountryMapper::resolve($rawCountry);

            $unit = (int) ($p['unit'] ?? 3);
            $duration = (int) ($p['duration'] ?? 1);

            $costPrice = isset($p['price']) ? (float) $p['price'] : null;
            $monthlyEquivalent = $costPrice;
            if ($costPrice !== null && $unit === 1 && $duration > 0) {
                $monthlyEquivalent = round($costPrice * 30 / $duration, 2);
            }

            $productNo = $p['productNo'] ?? null;
            return [
                'product_no' => $productNo,
                'product_id' => $productNo,
                'product_name' => $p['productName'] ?? '',
                'country_code' => $resolved['iso3'] ?? strtoupper(substr($rawCountry, 0, 3)),
                'country_name' => $resolved['cn'] ?? '',
                'area_code' => '',
                'city_code' => $p['cityCode'] ?? '',
                'proxy_type' => (int) ($p['proxyType'] ?? 103),
                'protocol' => $p['protocol'] ?? '1',
                'cost_price' => $costPrice,
                'monthly_cost' => $monthlyEquivalent,
                'unit' => $unit,
                'duration' => $duration,
                'inventory' => (int) ($p['inventory'] ?? 0),
                'ip_count' => (int) ($p['ipCount'] ?? 0),
                'ip_type' => (int) ($p['ipType'] ?? 1),
                'isp_type' => (int) ($p['ispType'] ?? 0),
                'net_type' => (int) ($p['netType'] ?? 0),
                'flow_total' => $p['flowTotal'] ?? null,
                'supplier_code' => $p['supplierCode'] ?? null,
                'cidr_blocks' => $p['cidrBlocks'] ?? [],
                'source' => 'ipipv',
            ];
        })->values()->all();

        $byCountry = [];
        foreach ($normalized as $p) {
            $code = $p['country_code'];
            if (!$code) continue;
            if (!isset($byCountry[$code])) {
                $byCountry[$code] = [
                    'stock' => 0,
                    'product_count' => 0,
                    'min_cost' => null,
                    'avg_cost' => null,
                    '_cost_sum' => 0,
                    '_cost_count' => 0,
                    'country_name' => $p['country_name'],
                ];
            }
            $byCountry[$code]['stock'] += $p['inventory'];
            $byCountry[$code]['product_count']++;
            $mc = $p['monthly_cost'];
            if ($mc !== null && $mc > 0) {
                $byCountry[$code]['_cost_sum'] += $mc;
                $byCountry[$code]['_cost_count']++;
                if ($byCountry[$code]['min_cost'] === null || $mc < $byCountry[$code]['min_cost']) {
                    $byCountry[$code]['min_cost'] = $mc;
                }
            }
        }
        foreach ($byCountry as $code => &$info) {
            $info['avg_cost'] = $info['_cost_count'] > 0
                ? round($info['_cost_sum'] / $info['_cost_count'], 2)
                : null;
            unset($info['_cost_sum'], $info['_cost_count']);
        }

        Cache::put(self::KEY_RAW, $normalized, self::TTL);
        Cache::put(self::KEY_BY_COUNTRY, $byCountry, self::TTL);
        Cache::put(self::KEY_REFRESHED, now()->toIso8601String(), self::TTL);

        SparkStockCacheService::recordStockedProducts($normalized, 'ipipv');

        return [
            'count' => count($normalized),
            'by_country_count' => count($byCountry),
        ];
    }

    public static function products(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            self::refresh();
        }
        $products = Cache::get(self::KEY_RAW);
        if ($products === null) {
            self::refresh();
            $products = Cache::get(self::KEY_RAW, []);
        }
        return $products;
    }

    public static function stockByCountry(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            self::refresh();
        }
        $data = Cache::get(self::KEY_BY_COUNTRY);
        if ($data === null) {
            self::refresh();
            $data = Cache::get(self::KEY_BY_COUNTRY, []);
        }
        return $data;
    }

    public static function lastRefreshedAt(): ?string
    {
        return Cache::get(self::KEY_REFRESHED);
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY_RAW);
        Cache::forget(self::KEY_BY_COUNTRY);
        Cache::forget(self::KEY_REFRESHED);
    }
}
