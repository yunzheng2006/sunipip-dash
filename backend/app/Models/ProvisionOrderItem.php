<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisionOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'asset_group_id', 'country_code', 'country_name',
        'city', 'quantity', 'duration', 'unit', 'unit_price',
        'subtotal', 'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'duration' => 'integer',
            'unit' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProvisionOrder::class, 'order_id');
    }

    public function assetGroup(): BelongsTo
    {
        return $this->belongsTo(IpAssetGroup::class, 'asset_group_id');
    }
}
