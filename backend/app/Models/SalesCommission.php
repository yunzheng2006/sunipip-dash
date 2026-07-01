<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommission extends Model
{
    protected $fillable = [
        'user_id', 'customer_id', 'level', 'trigger_type', 'trigger_id',
        'trigger_amount', 'commission_rate', 'commission_amount',
        'status', 'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'trigger_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'credited_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
