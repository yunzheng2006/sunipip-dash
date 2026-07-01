<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApModel extends Model
{
    protected $fillable = [
        'name', 'band', 'specs', 'cost_price', 'sell_price',
        'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
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
}
