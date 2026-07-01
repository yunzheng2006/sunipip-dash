<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = [
        'refund_no', 'payment_order_id', 'customer_id', 'subscription_id',
        'gateway_id', 'gateway_type', 'amount', 'status', 'reason',
        'provider_refund_no', 'provider_response', 'error_message',
        'operated_by', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_response' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operated_by');
    }

    public static function generateRefundNo(): string
    {
        return 'REF' . date('YmdHis') . bin2hex(random_bytes(4));
    }
}
