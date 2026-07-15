<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SparkProductBlock extends Model
{
    protected $fillable = [
        'product_id', 'cidr', 'product_name', 'country_code',
        'reason', 'blocked_by',
    ];

    private const CACHE_KEY = 'spark:blocked_cidrs_by_product';

    /**
     * @return array<string, string[]> product_id => [cidr, cidr, ...]
     */
    public static function blockedCidrsByProduct(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, function () {
            $rows = self::select('product_id', 'cidr')->get();
            $grouped = [];
            foreach ($rows as $r) {
                $grouped[$r->product_id][] = $r->cidr;
            }
            return $grouped;
        });
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * 为"随机"下单分配可售 IP 段（已排除屏蔽段），按余量从大到小拆分数量。
     * 产品有屏蔽段时上游随机分配可能落进屏蔽段，必须显式限定可售段下单。
     *
     * @return array<int, array{cidr: string, count: int}>
     * @throws \Exception 可售段库存不足时
     */
    public static function allocateAllowedCidrs(string $productId, int $quantity): array
    {
        // 库存缓存里的 cidr_blocks 已在刷新时剔除屏蔽段（SparkStockCacheService::refresh）
        $product = collect(\App\Services\SparkStockCacheService::products())
            ->firstWhere('product_id', $productId);
        $cidrBlocks = $product['cidr_blocks'] ?? [];

        usort($cidrBlocks, fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        $result = [];
        $remaining = $quantity;
        foreach ($cidrBlocks as $block) {
            if ($remaining <= 0) break;
            $take = min($remaining, (int) ($block['count'] ?? 0));
            if ($take <= 0 || empty($block['cidr'])) continue;
            $result[] = ['cidr' => $block['cidr'], 'count' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $available = $quantity - $remaining;
            throw new \Exception("该产品可售 IP 段库存不足（已排除屏蔽段）：需要 {$quantity} 条，当前可用 {$available} 条");
        }

        return $result;
    }

    protected static function booted(): void
    {
        static::saved(fn() => self::clearCache());
        static::deleted(fn() => self::clearCache());
    }
}
