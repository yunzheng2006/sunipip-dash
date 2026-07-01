<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VipTier extends Model
{
    protected $fillable = [
        'name', 'spending_threshold', 'topup_threshold', 'discount_percent',
        'sort_order', 'is_active', 'description', 'badge_color',
    ];

    protected function casts(): array
    {
        return [
            'spending_threshold' => 'decimal:2',
            'topup_threshold' => 'decimal:2',
            'discount_percent' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'integer',
        ];
    }

    /**
     * 根据客户的消费和充值情况，计算应享受的最高等级
     */
    public static function resolveForCustomer(Customer $customer): ?self
    {
        $tiers = static::where('is_active', 1)->orderByDesc('sort_order')->get();

        foreach ($tiers as $tier) {
            $qualifies = false;

            // 条件1：累计消费达标（门槛必须 > 0）
            if ((float) $tier->spending_threshold > 0
                && (float) $customer->total_spent >= (float) $tier->spending_threshold) {
                $qualifies = true;
            }

            // 条件2（或）：单次充值达标（门槛必须 > 0 且非 null）
            if (!$qualifies
                && $tier->topup_threshold !== null
                && (float) $tier->topup_threshold > 0
                && (float) $customer->max_single_topup >= (float) $tier->topup_threshold) {
                $qualifies = true;
            }

            if ($qualifies) return $tier;
        }

        return null;
    }

    /**
     * 应用折扣到价格
     */
    public function applyDiscount(float $price): float
    {
        if ($this->discount_percent >= 100 || $this->discount_percent <= 0) return $price;
        return round($price * $this->discount_percent / 100, 2);
    }
}
