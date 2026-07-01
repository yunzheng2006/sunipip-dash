<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentOrder extends Model
{
    protected $fillable = [
        'order_no', 'customer_id', 'gateway_id', 'gateway_type',
        'method', 'amount', 'refunded_amount', 'currency', 'status',
        'provider_trade_no', 'provider_payload', 'client_ip', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'provider_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function getRefundableAmountAttribute(): float
    {
        return round((float) $this->amount - (float) $this->refunded_amount, 2);
    }

    public static function generateOrderNo(): string
    {
        return 'PAY' . date('YmdHis') . bin2hex(random_bytes(4));
    }
}
