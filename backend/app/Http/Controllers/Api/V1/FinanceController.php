<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function overview(): JsonResponse
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        // 各类交易汇总
        $allTopup = (float) Transaction::where('type', Transaction::TYPE_TOPUP)->sum('amount');
        $monthTopup = (float) Transaction::where('type', Transaction::TYPE_TOPUP)->where('created_at', '>=', $monthStart)->sum('amount');
        $lastMonthTopup = (float) Transaction::where('type', Transaction::TYPE_TOPUP)->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->sum('amount');

        $revenueTypes = Transaction::REVENUE_TYPES;
        $allPurchase = abs((float) Transaction::whereIn('type', $revenueTypes)->sum('amount'));
        $monthPurchase = abs((float) Transaction::whereIn('type', $revenueTypes)->where('created_at', '>=', $monthStart)->sum('amount'));

        $allRefund = abs((float) Transaction::whereIn('type', Transaction::REFUND_TYPES)->sum('amount'));
        $monthRefund = abs((float) Transaction::whereIn('type', Transaction::REFUND_TYPES)->where('created_at', '>=', $monthStart)->sum('amount'));

        $totalCustomerBalance = (float) Customer::sum('balance');
        $activeCustomers = Customer::where('status', 1)->count();
        $activeSubscriptions = Subscription::where('status', 'active')->count();

        // 按类型分布
        $breakdown = Transaction::selectRaw("type, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income, SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as expense, COUNT(*) as count")
            ->groupBy('type')->get();

        return $this->success([
            'topup' => ['all' => $allTopup, 'month' => $monthTopup, 'last_month' => $lastMonthTopup],
            'purchase' => ['all' => $allPurchase, 'month' => $monthPurchase],
            'refund' => ['all' => $allRefund, 'month' => $monthRefund],
            'total_customer_balance' => $totalCustomerBalance,
            'active_customers' => $activeCustomers,
            'active_subscriptions' => $activeSubscriptions,
            'breakdown' => $breakdown,
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        $revenueTypes = Transaction::REVENUE_TYPES;
        $days = min(90, max(7, (int) $request->input('days', 30)));
        $startDate = now()->subDays($days)->startOfDay();

        $daily = Transaction::selectRaw("DATE(created_at) as date, type, SUM(amount) as total")
            ->where('created_at', '>=', $startDate)
            ->groupByRaw("DATE(created_at), type")
            ->orderBy('date')
            ->get();

        // 转为前端需要的格式
        $dates = [];
        $topups = [];
        $purchases = [];
        $refunds = [];

        $grouped = $daily->groupBy('date');
        $current = $startDate->copy();
        while ($current <= now()) {
            $d = $current->format('Y-m-d');
            $dates[] = $current->format('m-d');
            $dayData = $grouped->get($d, collect());
            $topups[] = abs((float) $dayData->where('type', Transaction::TYPE_TOPUP)->sum('total'));
            $purchases[] = abs((float) $dayData->whereIn('type', $revenueTypes)->sum('total'));
            $refunds[] = abs((float) $dayData->where('type', Transaction::TYPE_REFUND)->sum('total'));
            $current->addDay();
        }

        return $this->success([
            'dates' => $dates,
            'topups' => $topups,
            'purchases' => $purchases,
            'refunds' => $refunds,
        ]);
    }

    public function ranking(Request $request): JsonResponse
    {
        $limit = min(50, max(10, (int) $request->input('limit', 20)));

        $customers = Customer::select('id', 'customer_name', 'sales_person', 'balance')
            ->get()
            ->map(function ($c) {
                $spent = abs((float) Transaction::where('customer_id', $c->id)->where('amount', '<', 0)->whereNotIn('type', Transaction::SPENDING_EXCLUDE_TYPES)->sum('amount'));
                $topup = (float) Transaction::where('customer_id', $c->id)->where('type', Transaction::TYPE_TOPUP)->sum('amount');
                return [
                    'id' => $c->id,
                    'customer_name' => $c->customer_name,
                    'sales_person' => $c->sales_person,
                    'balance' => (float) $c->balance,
                    'total_spent' => $spent,
                    'total_topup' => $topup,
                ];
            })
            ->sortByDesc('total_spent')
            ->take($limit)
            ->values();

        return $this->success($customers);
    }
}
