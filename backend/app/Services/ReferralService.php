<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\SystemConfig;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReferralService
{
    /**
     * Generate referral code for a customer
     */
    public function generateCode(Customer $customer): string
    {
        if (!$customer->referral_code) {
            $customer->referral_code = strtoupper(Str::random(8));
            $customer->save();
        }
        return $customer->referral_code;
    }

    /**
     * Process referral commission when a referred customer makes a purchase
     *
     * @param float $amount    实际成交金额（扣款金额）
     * @param float $listPrice 原价（折扣前），用于差价返佣计算；0 时按 amount 处理
     * @param array $productContext 产品上下文 ['country_code'=>..., 'product_id'=>...] 用于查询双方特批价
     * @return float The commission amount actually created (0 if none)
     */
    public function processCommission(Customer $customer, string $triggerType, float $amount, ?int $triggerId = null, float $listPrice = 0, array $productContext = []): float
    {
        if (!$this->isEnabled()) return 0;
        if ($amount <= 0) return 0;

        $referrerId = $customer->referred_by_customer;
        if (!$referrerId) return 0;

        $referrer = Customer::find($referrerId);
        if (!$referrer || (int) $referrer->status !== 1) return 0;

        $result = $this->calculateEffectiveRate(
            $customer, $referrer, $amount, $listPrice, $triggerType, $productContext
        );

        $effectiveRate = $result['rate'];
        $commissionBase = $result['base'];

        Log::info('Commission calculated', [
            'referee' => $customer->id, 'referrer' => $referrerId,
            'type' => $triggerType, 'amount' => $amount, 'listPrice' => $listPrice,
            'rate' => $effectiveRate, 'base' => $commissionBase,
            'configured_rate' => $this->getCommissionRate($triggerType),
        ]);

        if ($effectiveRate <= 0) return 0;

        $commission = round($commissionBase * $effectiveRate / 100, 2);
        if ($commission < 0.01) return 0;

        $record = ReferralCommission::create([
            'referrer_id' => $referrerId,
            'referee_id' => $customer->id,
            'trigger_type' => $triggerType,
            'trigger_id' => $triggerId,
            'trigger_amount' => $amount,
            'commission_rate' => $effectiveRate,
            'commission_amount' => $commission,
            'status' => 'pending',
        ]);

        if ($this->isAutoCredit()) {
            $this->creditCommission($record);
        }

        return $commission;
    }

    private function calculateEffectiveRate(
        Customer $buyer,
        Customer $referrer,
        float $amountPaid,
        float $listPrice,
        string $triggerType,
        array $productContext
    ): array {
        $module = $productContext['module'] ?? 'static';
        $standardRate = $this->getCommissionRate($triggerType);

        if ($listPrice > 0) {
            $thresholdPercent = $this->getThresholdByModule($module);
            $floorRate = $this->getFloorRateByModule($module, $triggerType);

            if ($amountPaid >= $listPrice * $thresholdPercent / 100) {
                return ['rate' => $standardRate, 'base' => $amountPaid];
            }
            return ['rate' => $floorRate, 'base' => $listPrice];
        }

        return ['rate' => $standardRate, 'base' => $amountPaid];
    }

    /**
     * 查询客户对指定产品的有效折扣率（0~1，代表实际支付占原价的比例）。
     * 返回 null 表示该客户没有特批价。
     */
    private function getEffectiveDiscountRate(int $customerId, array $productContext, float $listPrice): ?float
    {
        $specialPrice = CustomerSpecialPrice::findPrice($customerId, $productContext);
        if ($specialPrice !== null && $listPrice > 0) {
            return $specialPrice / $listPrice;
        }

        $module = $productContext['module'] ?? 'static';
        $discountPercent = CustomerSpecialPrice::findDiscountPercent($customerId, $productContext, $module);
        if ($discountPercent !== null) {
            return $discountPercent / 100;
        }

        return null;
    }

    public function getFloorRate(): float
    {
        return (float) SystemConfig::get('referral.floor_rate',
            SystemConfig::get('referral.rate_special', 5));
    }

    public function getThresholdDiscount(): float
    {
        return (float) SystemConfig::get('referral.threshold_discount', 80);
    }

    public function getFloorRateByModule(string $module, string $triggerType = 'purchase'): float
    {
        $specific = SystemConfig::get("referral.{$module}.floor_rate_{$triggerType}");
        if ($specific !== null) return (float) $specific;

        $moduleDefault = SystemConfig::get("referral.{$module}.floor_rate");
        if ($moduleDefault !== null) return (float) $moduleDefault;

        return $this->getFloorRate();
    }

    public function getThresholdByModule(string $module): float
    {
        $val = SystemConfig::get("referral.{$module}.threshold");
        if ($val !== null) return (float) $val;

        return $this->getThresholdDiscount();
    }

    public function getSpecialCommissionRate(): float
    {
        return $this->getFloorRate();
    }

    public function getSalesSpecialCommissionRate(): float
    {
        return (float) SystemConfig::get('sales_commission.rate_special', 5);
    }

    /**
     * Credit a pending commission to the referrer's commission_balance (返佣余额)
     */
    public function creditCommission(ReferralCommission $commission): bool
    {
        if ($commission->status !== 'pending') return false;

        $referrer = Customer::find($commission->referrer_id);
        if (!$referrer) return false;

        $balanceBefore = (float) $referrer->commission_balance;
        $referrer->increment('commission_balance', $commission->commission_amount);

        Transaction::create([
            'customer_id' => $referrer->id,
            'type' => Transaction::TYPE_COMMISSION,
            'amount' => $commission->commission_amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore + $commission->commission_amount,
            'description' => sprintf('推荐返佣进入返佣余额 (推荐客户 #%d %s消费 ¥%.2f)',
                $commission->referee_id, $commission->trigger_type, $commission->trigger_amount),
            'operated_by' => null,
        ]);

        $commission->update(['status' => 'credited', 'credited_at' => now()]);

        return true;
    }

    /**
     * Reverse all commissions (referral + sales) for a refunded subscription
     */
    public function reverseCommissions(int $customerId, ?int $subscriptionId): void
    {
        // 1. Reverse referral commissions (all types tied to this subscription)
        $refCommissions = ReferralCommission::where('referee_id', $customerId)
            ->when($subscriptionId, fn($q) => $q->where('trigger_id', $subscriptionId))
            ->whereIn('status', ['pending', 'credited'])
            ->get();

        foreach ($refCommissions as $rc) {
            if ($rc->status === 'credited') {
                $referrer = Customer::find($rc->referrer_id);
                if ($referrer) {
                    $balanceBefore = (float) $referrer->commission_balance;
                    $deduct = min($rc->commission_amount, $balanceBefore);
                    if ($deduct > 0) {
                        $referrer->decrement('commission_balance', $deduct);
                        Transaction::create([
                            'customer_id' => $referrer->id,
                            'type' => Transaction::TYPE_COMMISSION_REVERSAL,
                            'amount' => -$deduct,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $balanceBefore - $deduct,
                            'description' => sprintf('推荐返佣回收 (客户 #%d 退款，订阅 #%s)',
                                $customerId, $subscriptionId ?? '-'),
                            'operated_by' => null,
                        ]);
                    }
                }
            }
            $rc->update(['status' => 'reversed']);
        }

        // 2. Reverse sales commissions
        $salesCommissions = SalesCommission::where('customer_id', $customerId)
            ->where('trigger_type', 'purchase')
            ->when($subscriptionId, fn($q) => $q->where('trigger_id', $subscriptionId))
            ->whereIn('status', ['pending', 'credited'])
            ->get();

        foreach ($salesCommissions as $sc) {
            if ($sc->status === 'credited') {
                $user = User::find($sc->user_id);
                if ($user) {
                    $deduct = min($sc->commission_amount, (float) ($user->commission_balance ?? 0));
                    if ($deduct > 0) {
                        $user->decrement('commission_balance', $deduct);
                    }
                }
            }
            $sc->update(['status' => 'reversed']);
        }

        Log::info('Commissions reversed', [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'referral_reversed' => $refCommissions->count(),
            'sales_reversed' => $salesCommissions->count(),
        ]);
    }

    private function hasSpecialPricing(Customer $customer): bool
    {
        return CustomerSpecialPrice::where('customer_id', $customer->id)
            ->where('is_active', 1)
            ->exists();
    }

    public function isEnabled(): bool
    {
        return (bool) SystemConfig::get('referral.enabled', false);
    }

    public function isAutoCredit(): bool
    {
        return (bool) SystemConfig::get('referral.auto_credit', true);
    }

    public function getCommissionRate(string $type = 'purchase'): float
    {
        $rate = SystemConfig::get("referral.rate_{$type}");
        if ($rate !== null) {
            return (float) $rate;
        }
        return (float) SystemConfig::get('referral.rate', 5);
    }

    /**
     * Get referral stats for a customer
     */
    public function getStats(Customer $customer): array
    {
        $referralCount = Customer::where('referred_by_customer', $customer->id)->count();
        $totalCommission = ReferralCommission::where('referrer_id', $customer->id)
            ->where('status', 'credited')->sum('commission_amount');
        $pendingCommission = ReferralCommission::where('referrer_id', $customer->id)
            ->where('status', 'pending')->sum('commission_amount');

        return [
            'referral_code' => $customer->referral_code,
            'referral_count' => $referralCount,
            'total_commission' => (float) $totalCommission,
            'pending_commission' => (float) $pendingCommission,
        ];
    }

    /**
     * Get staff invite stats
     */
    public function getStaffStats(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) return [];

        $customerCount = Customer::where('invited_by', $userId)->count();
        $totalSpent = Customer::where('invited_by', $userId)->sum('total_spent');

        $totalCommission = SalesCommission::where('user_id', $userId)
            ->where('status', 'credited')->sum('commission_amount');
        $pendingCommission = SalesCommission::where('user_id', $userId)
            ->where('status', 'pending')->sum('commission_amount');

        return [
            'invite_code' => $user->invite_code,
            'customer_count' => $customerCount,
            'total_customer_spent' => (float) $totalSpent,
            'total_commission' => (float) $totalCommission,
            'pending_commission' => (float) $pendingCommission,
            'commission_balance' => (float) ($user->commission_balance ?? 0),
        ];
    }

    /**
     * Process sales commission (2-level):
     * Level 1: customer.invited_by → that sales user gets commission
     * Level 2: customer.referred_by_customer → referrer.invited_by → that sales user also gets commission
     *
     * @param float $referralDeduction Amount already paid as referral commission, deducted from sales performance base
     */
    public function processSalesCommission(Customer $customer, string $triggerType, float $amount, ?int $triggerId = null, float $referralDeduction = 0): void
    {
        if (!$this->isSalesCommissionEnabled()) return;
        if ($amount <= 0) return;

        $isSpecial = $this->hasSpecialPricing($customer);

        $performanceBase = round($amount - $referralDeduction, 2);
        if ($performanceBase <= 0) return;

        $processed = [];

        // Level 1: direct sales person
        $salesUserId = $customer->invited_by;
        if ($salesUserId) {
            $salesUser = User::find($salesUserId);
            if ($salesUser && (int) $salesUser->status === 1) {
                $rate = $isSpecial ? $this->getSalesSpecialCommissionRate() : $this->getSalesCommissionRate($triggerType, 1);
                if ($rate > 0) {
                    $commission = round($performanceBase * $rate / 100, 2);
                    if ($commission >= 0.01) {
                        $record = SalesCommission::create([
                            'user_id' => $salesUserId,
                            'customer_id' => $customer->id,
                            'level' => 1,
                            'trigger_type' => $triggerType,
                            'trigger_id' => $triggerId,
                            'trigger_amount' => $performanceBase,
                            'commission_rate' => $rate,
                            'commission_amount' => $commission,
                            'status' => 'pending',
                        ]);
                        if ($this->isSalesAutoCredit()) {
                            $this->creditSalesCommission($record);
                        }
                        $processed[] = $salesUserId;
                    }
                }
            }
        }

        // Level 2: if customer was referred by another customer, that referrer's sales person gets L2 commission
        $referrerId = $customer->referred_by_customer;
        if ($referrerId) {
            $referrer = Customer::find($referrerId);
            if ($referrer && $referrer->invited_by) {
                $l2SalesUserId = $referrer->invited_by;
                if (!in_array($l2SalesUserId, $processed)) {
                    $l2SalesUser = User::find($l2SalesUserId);
                    if ($l2SalesUser && (int) $l2SalesUser->status === 1) {
                        $rate = $isSpecial ? $this->getSalesSpecialCommissionRate() : $this->getSalesCommissionRate($triggerType, 2);
                        if ($rate > 0) {
                            $commission = round($performanceBase * $rate / 100, 2);
                            if ($commission >= 0.01) {
                                $record = SalesCommission::create([
                                    'user_id' => $l2SalesUserId,
                                    'customer_id' => $customer->id,
                                    'level' => 2,
                                    'trigger_type' => $triggerType,
                                    'trigger_id' => $triggerId,
                                    'trigger_amount' => $performanceBase,
                                    'commission_rate' => $rate,
                                    'commission_amount' => $commission,
                                    'status' => 'pending',
                                ]);
                                if ($this->isSalesAutoCredit()) {
                                    $this->creditSalesCommission($record);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function creditSalesCommission(SalesCommission $commission): bool
    {
        if ($commission->status !== 'pending') return false;

        $user = User::find($commission->user_id);
        if (!$user) return false;

        $user->increment('commission_balance', $commission->commission_amount);

        $commission->update(['status' => 'credited', 'credited_at' => now()]);

        return true;
    }

    public function isSalesCommissionEnabled(): bool
    {
        return (bool) SystemConfig::get('sales_commission.enabled', false);
    }

    public function isSalesAutoCredit(): bool
    {
        return (bool) SystemConfig::get('sales_commission.auto_credit', true);
    }

    public function getSalesCommissionRate(string $type = 'purchase', int $level = 1): float
    {
        $key = "sales_commission.rate_l{$level}_{$type}";
        $fallback = "sales_commission.rate_l{$level}";
        return (float) SystemConfig::get($key, SystemConfig::get($fallback, $level === 1 ? 10 : 5));
    }
}
