<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparkPricingRule extends Model
{
    protected $fillable = [
        'name', 'monthly_price', 'cost_price', 'sales_price', 'description',
        'country_codes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'sales_price' => 'decimal:2',
            'country_codes' => 'array',
            'is_active' => 'integer',
        ];
    }

    /**
     * 根据 country_code 查找启用中的定价规则
     */
    public static function findByCountry(string $countryCode): ?self
    {
        $code = strtoupper($countryCode);
        return static::where('is_active', 1)
            ->whereJsonContains('country_codes', $code)
            ->first();
    }

    /**
     * 返回当前所有已被启用规则占用的国家代码 → rule_id 映射
     */
    public static function boundCountryMap(): array
    {
        $map = [];
        foreach (static::where('is_active', 1)->get(['id', 'country_codes']) as $rule) {
            foreach ($rule->country_codes ?? [] as $code) {
                $map[$code] = $rule->id;
            }
        }
        return $map;
    }
}
