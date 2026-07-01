<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpipvOrder extends Model
{
    protected $fillable = [
        'provision_order_id', 'app_order_no', 'ipipv_order_no',
        'method', 'product_no', 'amount', 'duration', 'unit',
        'cycle_times', 'cost_amount', 'status',
        'request_data', 'response_data',
    ];

    protected $casts = [
        'request_data'  => 'array',
        'response_data' => 'array',
        'cost_amount'   => 'decimal:2',
    ];

    public function provisionOrder(): BelongsTo
    {
        return $this->belongsTo(ProvisionOrder::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(IpipvInstance::class);
    }

    public static function generateAppOrderNo(): string
    {
        return 'IV' . date('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
