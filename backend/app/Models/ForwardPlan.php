<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForwardPlan extends Model
{
    protected $fillable = [
        'name', 'type', 'panel_id', 'device_group_id', 'speed_limit_mbps',
        'device_limit', 'display_host', 'pricing_mode',
        'base_price', 'cost_price', 'hard_cost_price', 'included_traffic_gb',
        'overage_price_per_gb', 'is_active', 'description', 'module',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'hard_cost_price' => 'decimal:2',
            'overage_price_per_gb' => 'decimal:2',
            'speed_limit_mbps' => 'integer',
            'device_limit' => 'integer',
            'included_traffic_gb' => 'integer',
            'is_active' => 'integer',
        ];
    }

    public function isFixedPricing(): bool
    {
        return $this->pricing_mode === 'fixed'
            || in_array($this->module, ['live_mobile', 'live_pc']);
    }
}
