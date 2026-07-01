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

    protected static function booted(): void
    {
        static::saved(fn() => self::clearCache());
        static::deleted(fn() => self::clearCache());
    }
}
