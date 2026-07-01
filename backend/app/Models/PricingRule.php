<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    protected $fillable = [
        'ip_group_id', 'country_code', 'country_name', 'ip_type', 'nature',
        'net_type', 'duration', 'unit', 'price', 'cost_price', 'is_active',
    ];

    public function ipGroup()
    {
        return $this->belongsTo(IpGroup::class);
    }

    protected function casts(): array
    {
        return [
            'ip_group_id' => 'integer',
            'duration' => 'integer',
            'unit' => 'integer',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_active' => 'integer',
        ];
    }

    public static function lookup(string $countryCode, string $ipType = 'residential', string $nature = 'static', int $duration = 1, int $unit = 3): ?self
    {
        return static::where('country_code', $countryCode)
            ->where('ip_type', $ipType)
            ->where('nature', $nature)
            ->where('duration', $duration)
            ->where('unit', $unit)
            ->where('is_active', 1)
            ->first();
    }

    public function getUnitLabel(): string
    {
        return match ($this->unit) {
            1 => '天', 2 => '周', 3 => '月', 4 => '年', default => '',
        };
    }
}
