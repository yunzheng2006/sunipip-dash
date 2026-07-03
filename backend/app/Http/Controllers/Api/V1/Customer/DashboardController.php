<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $activeSubs = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->count();

        $expiringSoon = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(7))
            ->count();

        $expiringIn3 = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(3))
            ->count();

        $activeIps = ProxyIp::where('assigned_customer_id', $customer->id)
            ->where('status', 'assigned')
            ->count();

        // 本月消费（负数交易累加）
        $monthSpent = (float) Transaction::where('customer_id', $customer->id)
            ->where('amount', '<', 0)
            ->whereNotIn('type', Transaction::SPENDING_EXCLUDE_TYPES)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        // 续费失败：开了自动续费但已过期的（余额不足导致）
        $renewFailed = Subscription::where('customer_id', $customer->id)
            ->where('status', 'expired')
            ->where('auto_renew', 1)
            ->where('expires_at', '>=', now()->subDays(30))
            ->count();

        // 本月充值
        $monthTopup = (float) Transaction::where('customer_id', $customer->id)
            ->where('type', Transaction::TYPE_TOPUP)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $recentTx = Transaction::where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'type', 'amount', 'balance_after', 'description', 'created_at']);

        // 下次续费预估：所有开启自动续费的活跃订阅月费总和
        $autoRenewSubs = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where('auto_renew', 1)
            ->get(['id', 'price', 'expires_at']);

        $nextRenewalCost = $autoRenewSubs->sum(fn($s) => (float) $s->price);
        $nextRenewalDate = $autoRenewSubs->min('expires_at');
        $balanceShortfall = max(0, $nextRenewalCost - (float) $customer->balance);

        return $this->success([
            'balance' => (float) $customer->balance,
            'commission_balance' => (float) $customer->commission_balance,
            'active_subscriptions' => $activeSubs,
            'expiring_7d' => $expiringSoon,
            'expiring_3d' => $expiringIn3,
            'active_ips' => $activeIps,
            'month_spent' => abs($monthSpent),
            'month_topup' => $monthTopup,
            'renew_failed' => $renewFailed,
            'total_spent' => (float) $customer->total_spent,
            'recent_transactions' => $recentTx,
            'auto_renew_count' => $autoRenewSubs->count(),
            'next_renewal_cost' => round($nextRenewalCost, 2),
            'next_renewal_date' => $nextRenewalDate,
            'balance_shortfall' => round($balanceShortfall, 2),
        ]);
    }
}
