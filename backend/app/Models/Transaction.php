<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    // ── 交易类型常量 ──
    const TYPE_PURCHASE            = 'purchase';
    const TYPE_RENEW               = 'renew';
    const TYPE_DEDUCTION           = 'deduction';
    const TYPE_TOPUP               = 'topup';
    const TYPE_REFUND              = 'refund';
    const TYPE_GATEWAY_REFUND      = 'gateway_refund';
    const TYPE_WITHDRAWAL          = 'withdrawal';
    const TYPE_ADJUSTMENT_IN       = 'adjustment_in';
    const TYPE_ADJUSTMENT_OUT      = 'adjustment_out';
    const TYPE_COMMISSION          = 'commission';
    const TYPE_COMMISSION_TRANSFER = 'commission_transfer';
    const TYPE_COMMISSION_REVERSAL = 'commission_reversal';

    // ── 查询分组 ──

    /** 成交收入: 新开 + 续费 + 扣费(中转升级等) */
    const REVENUE_TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_RENEW,
        self::TYPE_DEDUCTION,
    ];

    /** 计算客户消费时需排除的类型 */
    const SPENDING_EXCLUDE_TYPES = [
        self::TYPE_WITHDRAWAL,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_REFUND,
        self::TYPE_GATEWAY_REFUND,
        self::TYPE_COMMISSION_REVERSAL,
    ];

    /** 充值类 */
    const TOPUP_TYPES = [
        self::TYPE_TOPUP,
        self::TYPE_ADJUSTMENT_IN,
    ];

    /** 退款类 */
    const REFUND_TYPES = [
        self::TYPE_REFUND,
        self::TYPE_GATEWAY_REFUND,
    ];

    protected $fillable = [
        'customer_id', 'type', 'amount', 'balance_before',
        'balance_after', 'related_type', 'related_id',
        'description', 'operated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operated_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
