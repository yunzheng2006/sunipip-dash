<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPricing extends Model
{
    protected $table = 'product_pricing';

    protected $fillable = [
        'country_code', 'country_name', 'ip_group_id', 'access_type',
        'monthly_price', 'cost_price', 'sales_price', 'own_stock', 'max_shared_users',
        'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'sales_price' => 'decimal:2',
            'is_active' => 'integer',
            'own_stock' => 'integer',
            'max_shared_users' => 'integer',
        ];
    }

    public function ipGroup(): BelongsTo
    {
        return $this->belongsTo(IpGroup::class);
    }

    /**
     * 查找定价：先精确匹配(国家+IP组+接入方式)，再回退到默认定价(ip_group_id=NULL)
     */
    public static function findPrice(string $countryCode, ?int $ipGroupId = null, string $accessType = 'dedicated'): ?self
    {
        $code = strtoupper($countryCode);

        // Exact match: country + ip_group + access_type
        if ($ipGroupId !== null) {
            $exact = static::where('country_code', $code)
                ->where('ip_group_id', $ipGroupId)
                ->where('access_type', $accessType)
                ->where('is_active', 1)
                ->first();

            if ($exact) {
                return $exact;
            }
        }

        // Fallback: country + ip_group_id=NULL + access_type (default pricing)
        return static::where('country_code', $code)
            ->whereNull('ip_group_id')
            ->where('access_type', $accessType)
            ->where('is_active', 1)
            ->first();
    }
}
