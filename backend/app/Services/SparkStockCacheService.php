<?php

namespace App\Services;

use App\Models\SparkProductBlock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spark 库存缓存服务
 *
 * 策略：
 *   - `spark:products:raw`        原始产品列表（扁平），TTL 10 分钟
 *   - `spark:products:by_country` 按国家聚合的库存 + 最低成本，TTL 10 分钟
 *   - `spark:products:refreshed`  最后刷新时间戳
 *
 * 更新方式：
 *   1. `refresh()`  主动拉取 Spark → 写入三个缓存键（一般由定时任务调用）
 *   2. 任意读取方法在缓存缺失时自动触发一次 refresh（fallback）
 *
 * 定时任务在 routes/console.php 注册，每 5 分钟跑一次。
 */
class SparkStockCacheService
{
    private const KEY_RAW = 'spark:products:raw';
    private const KEY_ALL = 'spark:products:all';
    private const KEY_BY_COUNTRY = 'spark:products:by_country';
    private const KEY_REFRESHED = 'spark:products:refreshed';
    private const TTL = 600;

    /**
     * 主动拉取 Spark 全部产品并写入缓存
     *
     * @return array{count: int, by_country_count: int}
     */
    public static function refresh(): array
    {
        $spark = app(SparkApiService::class);
        $allProducts = [];

        foreach ([103, 104] as $proxyType) {
            try {
                $page = 1;
                $fetched = 0; // 当前类型已拉取数量
                do {
                    $data = $spark->getProductStock([
                        'proxyType' => $proxyType,
                        'page' => $page,
                        'pageSize' => 100,
                    ]);
                    $products = $data['products'] ?? [];
                    if (empty($products)) {
                        break;
                    }
                    $allProducts = array_merge($allProducts, $products);
                    $fetched += count($products);
                    $total = (int) ($data['total'] ?? 0);
                    // 当前类型拉完了就停
                    if ($fetched >= $total || count($products) < 100) {
                        break;
                    }
                    $page++;
                } while ($page <= 50); // 上限 5000 条/类型
            } catch (\Throwable $e) {
                Log::warning("SparkStockCache refresh failed for proxyType={$proxyType}: " . $e->getMessage());
            }
        }

        // 归一化字段名
        $normalized = collect($allProducts)->map(fn($p) => [
            'product_id' => $p['productId'] ?? null,
            'product_name' => $p['productName'] ?? '',
            'country_code' => $p['countryCode'] ?? '',
            'area_code' => $p['areaCode'] ?? '',
            'city_code' => $p['cityCode'] ?? '',
            'proxy_type' => (int) ($p['proxyType'] ?? 0),
            'protocol' => $p['protocol'] ?? null,
            'cost_price' => isset($p['costPrice']) ? (float) $p['costPrice'] : null,
            'unit' => (int) ($p['unit'] ?? 3),
            'duration' => (int) ($p['duration'] ?? 1),
            'inventory' => (int) ($p['inventory'] ?? 0),
            'ip_type' => (int) ($p['ipType'] ?? 1),
            'isp_type' => (int) ($p['ispType'] ?? 0),
            'net_type' => (int) ($p['netType'] ?? 0),
            'bandwidth_type' => $p['bandWidthType'] ?? null,
            'bandwidth' => $p['bandWidth'] ?? null,
            'sell_limit' => $p['sellLimit'] ?? null,
            'use_limit' => $p['useLimit'] ?? null,
            'use_type' => $p['useType'] ?? null,
            'isp' => $p['isp'] ?? null,
            'cidr_blocks' => $p['cidrBlocks'] ?? [],
        ])->values()->all();

        // 修正 Spark 将港台产品归入 CHN 的问题：按产品名拆分
        $normalized = array_map(function ($p) {
            if (($p['country_code'] ?? '') === 'CHN') {
                $name = strtoupper($p['product_name'] ?? '');
                if (str_contains($name, 'TAIWAN') || str_contains($name, '台湾') || str_contains($name, '台灣')) {
                    $p['country_code'] = 'TWN';
                } elseif (str_contains($name, 'HONGKONG') || str_contains($name, 'HONG KONG') || str_contains($name, '香港') || str_contains($name, 'HK-')) {
                    $p['country_code'] = 'HKG';
                }
            }
            return $p;
        }, $normalized);

        Cache::put(self::KEY_ALL, $normalized, self::TTL);

        // 按 CIDR 粒度过滤已屏蔽段，调整库存
        $blockedByProduct = SparkProductBlock::blockedCidrsByProduct();
        if ($blockedByProduct) {
            foreach ($normalized as &$p) {
                $pid = $p['product_id'] ?? '';
                if (!isset($blockedByProduct[$pid])) continue;
                $blockedSet = array_flip($blockedByProduct[$pid]);
                $p['cidr_blocks'] = array_values(array_filter(
                    $p['cidr_blocks'] ?? [],
                    fn($c) => !isset($blockedSet[$c['cidr'] ?? ''])
                ));
                $p['inventory'] = array_sum(array_column($p['cidr_blocks'], 'count'));
            }
            unset($p);
            $normalized = array_values(array_filter($normalized, fn($p) => ($p['inventory'] ?? 0) > 0));
        }

        // 按国家聚合
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
                ];
            }
            $byCountry[$code]['stock'] += $p['inventory'];
            $byCountry[$code]['product_count']++;
            if ($p['cost_price'] !== null && $p['cost_price'] > 0) {
                $byCountry[$code]['_cost_sum'] += $p['cost_price'];
                $byCountry[$code]['_cost_count']++;
                if ($byCountry[$code]['min_cost'] === null || $p['cost_price'] < $byCountry[$code]['min_cost']) {
                    $byCountry[$code]['min_cost'] = $p['cost_price'];
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

        self::recordStockedProducts($normalized, 'spark');

        return [
            'count' => count($normalized),
            'by_country_count' => count($byCountry),
        ];
    }

    /**
     * 读取归一化的产品列表，缓存缺失时自动 refresh
     */
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

    /**
     * 按国家代码聚合的库存数据
     * 返回格式：[ 'USA' => ['stock' => 145, 'product_count' => 12, 'min_cost' => 18.5, 'avg_cost' => 22.3], ... ]
     */
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

    /**
     * 全部产品（含已屏蔽），仅管理后台使用
     */
    public static function allProducts(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            self::refresh();
        }
        $all = Cache::get(self::KEY_ALL);
        if ($all === null) {
            self::refresh();
            $all = Cache::get(self::KEY_ALL, []);
        }
        return $all;
    }

    public static function lastRefreshedAt(): ?string
    {
        return Cache::get(self::KEY_REFRESHED);
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY_RAW);
        Cache::forget(self::KEY_ALL);
        Cache::forget(self::KEY_BY_COUNTRY);
        Cache::forget(self::KEY_REFRESHED);
    }

    public static function recordStockedProducts(array $products, string $source = 'spark'): void
    {
        $stocked = collect($products)
            ->filter(fn($p) => ($p['inventory'] ?? 0) > 0 && !empty($p['product_id']));

        if ($stocked->isEmpty()) return;

        $now = now();
        $rows = $stocked->map(fn($p) => [
            'product_id' => $p['product_id'],
            'source' => $source,
            'product_data' => json_encode($p, JSON_UNESCAPED_UNICODE),
            'first_stocked_at' => $now,
            'last_stocked_at' => $now,
        ])->values()->all();

        try {
            DB::table('product_stock_history')->upsert(
                $rows,
                ['product_id'],
                ['product_data', 'last_stocked_at']
            );
            Cache::forget('product_stock_history:all');
        } catch (\Throwable $e) {
            Log::debug('recordStockedProducts failed: ' . $e->getMessage());
        }
    }

    /**
     * 返回所有曾经有过库存的产品（product_data 快照，inventory 设为 0）
     * 用于在商店中显示"售罄"状态
     */
    public static function everStockedProducts(): array
    {
        return Cache::remember('product_stock_history:all', 300, function () {
            try {
                return DB::table('product_stock_history')
                    ->whereNotNull('product_data')
                    ->pluck('product_data', 'product_id')
                    ->map(fn($json) => json_decode($json, true))
                    ->filter()
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }
}
