<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvisionOrder extends Model
{
    protected $fillable = [
        'order_no', 'customer_id', 'status', 'total_amount',
        'remark', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProvisionOrderItem::class, 'order_id');
    }

    public function sparkOrders(): HasMany
    {
        return $this->hasMany(SparkOrder::class, 'provision_order_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'provision_order_id');
    }

    public static function generateOrderNo(): string
    {
        return 'PO' . date('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
