<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SparkOrder extends Model
{
    protected $fillable = [
        'provision_order_id', 'req_order_no', 'spark_order_no',
        'method', 'product_id', 'amount', 'duration', 'unit',
        'cost_amount', 'status', 'request_data', 'response_data',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'duration' => 'integer',
            'unit' => 'integer',
            'cost_amount' => 'decimal:2',
            'status' => 'integer',
            'request_data' => 'array',
            'response_data' => 'array',
        ];
    }

    public function provisionOrder(): BelongsTo
    {
        return $this->belongsTo(ProvisionOrder::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(SparkInstance::class);
    }

    public static function generateReqOrderNo(): string
    {
        return 'SP' . date('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
