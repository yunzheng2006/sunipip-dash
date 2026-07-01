<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisionApproval extends Model
{
    protected $fillable = [
        'order_no', 'type', 'submitted_by', 'customer_id', 'order_data',
        'total_amount', 'status', 'reviewed_by', 'reviewed_at',
        'review_comment', 'executed_at', 'execution_result',
    ];

    protected function casts(): array
    {
        return [
            'order_data' => 'array',
            'execution_result' => 'array',
            'total_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public static function generateOrderNo(): string
    {
        return 'APR' . date('YmdHis') . str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
