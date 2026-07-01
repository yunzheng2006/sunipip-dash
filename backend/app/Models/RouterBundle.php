<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouterBundle extends Model
{
    protected $fillable = [
        'name', 'router_model_id', 'ap_model_id',
        'bundle_price', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bundle_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function routerModel(): BelongsTo
    {
        return $this->belongsTo(RouterModel::class);
    }

    public function apModel(): BelongsTo
    {
        return $this->belongsTo(ApModel::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(RouterDevice::class, 'bundle_id');
    }
}
