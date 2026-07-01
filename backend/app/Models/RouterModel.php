<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouterModel extends Model
{
    protected $fillable = [
        'name', 'cpu', 'ram_mb', 'storage_gb', 'ports',
        'cost_price', 'sell_price', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ram_mb' => 'integer',
            'storage_gb' => 'integer',
            'ports' => 'integer',
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(RouterDevice::class);
    }

    public function bundles(): HasMany
    {
        return $this->hasMany(RouterBundle::class);
    }

    public function specsLabel(): string
    {
        $parts = [];
        if ($this->cpu) $parts[] = $this->cpu;
        if ($this->ram_mb) $parts[] = ($this->ram_mb >= 1024 ? round($this->ram_mb / 1024) . 'GB' : $this->ram_mb . 'MB') . ' RAM';
        if ($this->storage_gb) $parts[] = $this->storage_gb . 'GB SSD';
        if ($this->ports) $parts[] = $this->ports . '网口';
        return implode(' / ', $parts);
    }
}
