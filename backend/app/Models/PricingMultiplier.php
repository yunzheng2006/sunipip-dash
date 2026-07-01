<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingMultiplier extends Model
{
    protected $fillable = [
        'scope', 'priority', 'country_code', 'area_code', 'city_code', 'product_id',
        'cost_match', 'multiplier', 'min_price', 'fixed_price',
        'sales_multiplier', 'sales_fixed_price',
        'is_active', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'cost_match' => 'decimal:2',
            'multiplier' => 'decimal:2',
            'min_price' => 'decimal:2',
            'fixed_price' => 'decimal:2',
            'sales_multiplier' => 'decimal:2',
            'sales_fixed_price' => 'decimal:2',
            'is_active' => 'integer',
        ];
    }

    /**
     * 计算对客售价
     */
    public static function calcSalePrice(array $product): ?float
    {
        $rule = self::matchRule($product);
        if (!$rule) return null;

        $costPrice = (float) ($product['cost_price'] ?? 0);

        if ($rule->fixed_price !== null && (float) $rule->fixed_price > 0) {
            return (float) $rule->fixed_price;
        }
        if ($costPrice <= 0) return null;

        $price = round($costPrice * (float) $rule->multiplier, 2);
        if ($rule->min_price !== null && $price < (float) $rule->min_price) {
            $price = (float) $rule->min_price;
        }
        return $price;
    }

    /**
     * 计算销售底价
     *
     * 如果最高优先级命中规则没有配销售价，向下找第一条配了销售价的规则。
     */
    public static function calcSalesPrice(array $product): ?float
    {
        $costPrice = (float) ($product['cost_price'] ?? 0);

        // 先用最高优先级的规则
        $rule = self::matchRule($product);
        if ($rule) {
            $result = self::applySalesRule($rule, $costPrice);
            if ($result !== null) return $result;
        }

        // 回退：找第一条配了销售价且能匹配该产品的规则
        $hasSalesConfig = function ($q) {
            $q->where(function ($q2) {
                $q2->whereNotNull('sales_fixed_price')->where('sales_fixed_price', '>', 0);
            })->orWhere(function ($q2) {
                $q2->whereNotNull('sales_multiplier')->where('sales_multiplier', '>', 0);
            });
        };

        $salesRule = self::buildMatchQuery($product)
            ->where($hasSalesConfig)
            ->first();

        return $salesRule ? self::applySalesRule($salesRule, $costPrice) : null;
    }

    private static function applySalesRule(self $rule, float $costPrice): ?float
    {
        if ($rule->sales_fixed_price !== null && (float) $rule->sales_fixed_price > 0) {
            return (float) $rule->sales_fixed_price;
        }
        if ($rule->sales_multiplier !== null && (float) $rule->sales_multiplier > 0 && $costPrice > 0) {
            return round($costPrice * (float) $rule->sales_multiplier, 2);
        }
        return null;
    }

    /**
     * 统一匹配：收集所有能匹配该产品的规则，按 priority DESC → scope 粒度 → cost_match 精确度 → id DESC 排序。
     */
    public static function matchRule(array $product): ?self
    {
        return self::buildMatchQuery($product)->first();
    }

    /**
     * 构建匹配查询（不含 first()），可复用于 matchRule 和 calcSalesPrice 回退。
     */
    private static function buildMatchQuery(array $product)
    {
        $countryCode = self::norm($product['country_code'] ?? '');
        $areaCode    = self::norm($product['area_code']    ?? '');
        $cityCode    = self::norm($product['city_code']    ?? '');
        $productId   = self::norm($product['product_id']   ?? '');
        $costPrice   = isset($product['cost_price']) ? (float) $product['cost_price'] : null;

        $query = static::where('is_active', 1)
            // scope 匹配
            ->where(function ($q) use ($countryCode, $areaCode, $cityCode, $productId) {
                $q->where('scope', 'global');
                if ($countryCode) {
                    $q->orWhere(fn ($q2) => $q2->where('scope', 'country')->where('country_code', $countryCode));
                }
                if ($countryCode && $areaCode) {
                    $q->orWhere(fn ($q2) => $q2->where('scope', 'area')->where('country_code', $countryCode)->where('area_code', $areaCode));
                }
                if ($countryCode && $cityCode) {
                    $q->orWhere(fn ($q2) => $q2->where('scope', 'city')->where('country_code', $countryCode)->where('city_code', $cityCode));
                }
                if ($productId) {
                    $q->orWhere(fn ($q2) => $q2->where('scope', 'product')->where('product_id', $productId));
                }
            })
            // cost_match 匹配：null（通配）或精确
            ->where(function ($q) use ($costPrice) {
                $q->whereNull('cost_match');
                if ($costPrice !== null) {
                    $q->orWhereBetween('cost_match', [$costPrice - 0.01, $costPrice + 0.01]);
                }
            });

        // 排序：priority DESC → scope 粒度 → cost_match 精确 → id DESC
        return $query
            ->orderByDesc('priority')
            ->orderByRaw("FIELD(scope, 'product', 'city', 'area', 'country', 'global')")
            ->orderByRaw("CASE WHEN cost_match IS NOT NULL THEN 0 ELSE 1 END")
            ->orderByDesc('id');
    }

    /** trim + '' → null */
    private static function norm($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    public static function globalMultiplier(): float
    {
        $global = static::where('scope', 'global')->where('is_active', 1)->first();
        return $global ? (float) $global->multiplier : 2.0;
    }
}
