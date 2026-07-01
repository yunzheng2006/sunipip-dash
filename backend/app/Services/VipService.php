<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\VipTier;

class VipService
{
    /**
     * 重新计算客户VIP等级（在每次消费/充值后调用）
     */
    public function recalculate(Customer $customer): void
    {
        // 重新统计累计消费（所有负金额交易的绝对值之和）
        $totalSpent = abs((float) Transaction::where('customer_id', $customer->id)
            ->where('amount', '<', 0)
            ->whereNotIn('type', Transaction::SPENDING_EXCLUDE_TYPES)
            ->sum('amount'));

        $customer->total_spent = $totalSpent;

        // 解析最高等级
        $tier = VipTier::resolveForCustomer($customer);
        $customer->vip_tier_id = $tier?->id;
        $customer->save();
    }

    /**
     * 记录单次充值金额（检查是否达到VIP门槛）
     */
    public function recordTopup(Customer $customer, float $amount): void
    {
        if ($amount > (float) $customer->max_single_topup) {
            $customer->max_single_topup = $amount;
            $customer->save();
        }
        $this->recalculate($customer);
    }

    /**
     * 获取客户当前折扣百分比
     */
    public static function getDiscount(Customer $customer): int
    {
        if (!$customer->vip_tier_id) return 100; // 无折扣
        $tier = VipTier::find($customer->vip_tier_id);
        return $tier ? $tier->discount_percent : 100;
    }

    /**
     * 应用折扣到价格
     */
    public static function applyDiscount(Customer $customer, float $price): float
    {
        $discount = self::getDiscount($customer);
        if ($discount >= 100) return $price;
        return round($price * $discount / 100, 2);
    }
}
