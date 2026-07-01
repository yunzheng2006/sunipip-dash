<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpGroup extends Model
{
    protected $fillable = [
        'name', 'slug', 'country_code', 'country_name', 'city',
        'isp_type', 'net_type', 'spark_isp_type', 'spark_net_type',
        'display_name', 'sort_order', 'description', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    public function proxyIps(): HasMany
    {
        return $this->hasMany(ProxyIp::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function availableIpCount(): int
    {
        return $this->proxyIps()->where('status', 'available')->count();
    }

    public function totalIpCount(): int
    {
        return $this->proxyIps()->count();
    }
}
