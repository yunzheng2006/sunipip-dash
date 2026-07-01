<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ForwardRule;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\ManualPerformance;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesStatsController extends Controller
{
    /**
     * duration+unit → 月数 SQL 表达式
     */
    private static function durationToMonthsExpr(string $durationCol = 'duration', string $unitCol = 'unit'): string
    {
        return "GREATEST(CASE {$unitCol}
            WHEN 1 THEN CEIL({$durationCol} / 30.0)
            WHEN 2 THEN CEIL({$durationCol} * 7 / 30.0)
            WHEN 3 THEN {$durationCol}
            WHEN 4 THEN {$durationCol} * 12
            ELSE 1
        END, 1)";
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Customer::query();
        if (!$user->can('customer.view_all')) {
            $query->where('sales_person', $user->name);
        }

        if ($request->filled('sales_person')) {
            $query->where('sales_person', $request->input('sales_person'));
        }

        $customers = $query->select('id', 'customer_name', 'sales_person', 'balance', 'created_at')
            ->withCount(['subscriptions as active_subs' => fn($q) => $q->where('status', 'active')->where('is_test', false)])
            ->get();

        $customerIds = $customers->pluck('id')->all();

        $now = now();

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $periodStart = \Carbon\Carbon::parse($request->input('date_from'))->startOfDay();
            $periodEnd = \Carbon\Carbon::parse($request->input('date_to'))->endOfDay();
            $periodDays = $periodStart->diffInDays($periodEnd) + 1;
            $compareStart = $periodStart->copy()->subDays($periodDays);
            $compareEnd = $periodStart->copy()->subSecond();
        } else {
            $periodStart = $now->copy()->startOfDay();
            $periodEnd = $now->copy()->endOfDay();
            $compareStart = $now->copy()->subDay()->startOfDay();
            $compareEnd = $now->copy()->subDay()->endOfDay();
        }

        // ── 国家分布 ──
        $countryData = [];
        if (!empty($customerIds)) {
            $hasDateFilter = $request->filled('date_from') && $request->filled('date_to');

            if ($hasDateFilter) {
                $countryRows = DB::table('transactions')
                    ->join('subscriptions', function ($join) {
                        $join->on('transactions.related_id', '=', 'subscriptions.id')
                            ->where('transactions.related_type', 'App\\Models\\Subscription');
                    })
                    ->join('proxy_ips', 'subscriptions.proxy_ip_id', '=', 'proxy_ips.id')
                    ->whereIn('transactions.customer_id', $customerIds)
                    ->whereIn('transactions.type', [Transaction::TYPE_PURCHASE, Transaction::TYPE_RENEW, Transaction::TYPE_DEDUCTION])
                    ->where('transactions.amount', '<', 0)
                    ->where('transactions.created_at', '>=', $periodStart)
                    ->where('transactions.created_at', '<=', $periodEnd)
                    ->where('subscriptions.is_test', false)
                    ->select(
                        'transactions.customer_id',
                        'proxy_ips.country_code',
                        'proxy_ips.country_name',
                        DB::raw('COUNT(DISTINCT subscriptions.id) as count')
                    )
                    ->groupBy('transactions.customer_id', 'proxy_ips.country_code', 'proxy_ips.country_name')
                    ->get();
            } else {
                $countryRows = DB::table('subscriptions')
                    ->join('proxy_ips', 'subscriptions.proxy_ip_id', '=', 'proxy_ips.id')
                    ->whereIn('subscriptions.customer_id', $customerIds)
                    ->where('subscriptions.status', 'active')
                    ->where('subscriptions.is_test', false)
                    ->select(
                        'subscriptions.customer_id',
                        'proxy_ips.country_code',
                        'proxy_ips.country_name',
                        DB::raw('COUNT(*) as count')
                    )
                    ->groupBy('subscriptions.customer_id', 'proxy_ips.country_code', 'proxy_ips.country_name')
                    ->get();
            }

            foreach ($countryRows as $row) {
                $countryData[$row->customer_id][] = [
                    'country_code' => $row->country_code,
                    'country_name' => $row->country_name,
                    'count' => $row->count,
                ];
            }
        }

        // ── 中转数 ──
        $forwardData = [];
        if (!empty($customerIds)) {
            $forwardRows = DB::table('forward_rules')
                ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->whereIn('subscriptions.customer_id', $customerIds)
                ->where('subscriptions.status', 'active')
                ->where('subscriptions.is_test', false)
                ->select('subscriptions.customer_id', DB::raw('COUNT(*) as forward_count'))
                ->groupBy('subscriptions.customer_id')
                ->get();
            foreach ($forwardRows as $row) {
                $forwardData[$row->customer_id] = $row->forward_count;
            }
        }

        // ── IP 数 ──
        $ipCounts = [];
        if (!empty($customerIds)) {
            $ipRows = DB::table('proxy_ips')
                ->whereIn('assigned_customer_id', $customerIds)
                ->whereNull('deleted_at')
                ->select('assigned_customer_id', DB::raw('COUNT(*) as ip_count'))
                ->groupBy('assigned_customer_id')
                ->get();
            foreach ($ipRows as $row) {
                $ipCounts[$row->assigned_customer_id] = $row->ip_count;
            }
        }

        // ── 返佣扣减 ──
        $referralDeductionData = [];
        $periodReferralData = [];
        $compareReferralData = [];
        if (!empty($customerIds)) {
            $refRows = DB::table('referral_commissions')
                ->whereIn('referee_id', $customerIds)
                ->whereIn('status', ['credited', 'pending'])
                ->select('referee_id', DB::raw('SUM(commission_amount) as total'))
                ->groupBy('referee_id')
                ->get();
            foreach ($refRows as $row) {
                $referralDeductionData[$row->referee_id] = (float) $row->total;
            }

            $periodRefRows = DB::table('referral_commissions')
                ->whereIn('referee_id', $customerIds)
                ->whereIn('status', ['credited', 'pending'])
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->select('referee_id', DB::raw('SUM(commission_amount) as total'))
                ->groupBy('referee_id')
                ->get();
            foreach ($periodRefRows as $row) {
                $periodReferralData[$row->referee_id] = (float) $row->total;
            }

            $compareRefRows = DB::table('referral_commissions')
                ->whereIn('referee_id', $customerIds)
                ->whereIn('status', ['credited', 'pending'])
                ->where('created_at', '>=', $compareStart)
                ->where('created_at', '<=', $compareEnd)
                ->select('referee_id', DB::raw('SUM(commission_amount) as total'))
                ->groupBy('referee_id')
                ->get();
            foreach ($compareRefRows as $row) {
                $compareReferralData[$row->referee_id] = (float) $row->total;
            }
        }

        // ══════════════════════════════════════════════
        //  成交价 + 销售成本 + 利润
        //
        //  核心规则:
        //  - 成交价 = 时段内所有 purchase/renew/deduction - 退款
        //  - 退款归属到订阅 started_at 所在时段
        //  - keep_performance=true 的退订不扣退款（保留业绩）
        //  - 不按订阅当前状态排除（钱花了就是花了）
        //  - 成本用 duration/unit 算月数（不用 DATEDIFF）
        //  - 只排除 refunded + keep_performance=false 的订阅成本
        // ══════════════════════════════════════════════

        $subRevenueData = [];
        $subSalesCostData = [];
        $periodProfitRevenue = [];
        $periodProfitCost = [];
        $compareProfitRevenue = [];
        $compareProfitCost = [];
        $periodProfitForward = [];
        $compareProfitForward = [];

        if (!empty($customerIds)) {
            $subTxnTypes = Transaction::REVENUE_TYPES;

            // ── 成交价: 时段内所有 revenue 交易，不按订阅状态排除 ──
            $revenueQuery = fn($start, $end) => DB::table('transactions')
                ->whereIn('customer_id', $customerIds)
                ->whereIn('type', $subTxnTypes)
                ->where('amount', '<', 0)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<=', $end)
                ->select('customer_id', DB::raw('ABS(SUM(amount)) as total'))
                ->groupBy('customer_id')
                ->get();

            foreach ($revenueQuery($periodStart, $periodEnd) as $row) {
                $subRevenueData[$row->customer_id] = (float) $row->total;
                $periodProfitRevenue[$row->customer_id] = (float) $row->total;
            }
            foreach ($revenueQuery($compareStart, $compareEnd) as $row) {
                $compareProfitRevenue[$row->customer_id] = (float) $row->total;
            }

            // ── 退款扣减: 归属到订阅 started_at 所在时段 ──
            // keep_performance=true 的不扣
            $refundTypes = [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND];
            $refundQuery = fn($start, $end) => DB::table('transactions')
                ->leftJoin('subscriptions', function ($join) {
                    $join->on('transactions.related_id', '=', 'subscriptions.id')
                        ->where('transactions.related_type', 'App\\Models\\Subscription');
                })
                ->whereIn('transactions.customer_id', $customerIds)
                ->whereIn('transactions.type', $refundTypes)
                ->where('transactions.amount', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('subscriptions.keep_performance')
                      ->orWhere('subscriptions.keep_performance', false);
                })
                ->whereRaw('COALESCE(subscriptions.started_at, transactions.created_at) >= ?', [$start])
                ->whereRaw('COALESCE(subscriptions.started_at, transactions.created_at) <= ?', [$end])
                ->select('transactions.customer_id', DB::raw('SUM(transactions.amount) as total'))
                ->groupBy('transactions.customer_id')
                ->get();

            foreach ($refundQuery($periodStart, $periodEnd) as $row) {
                $refundAmt = (float) $row->total;
                $subRevenueData[$row->customer_id] = ($subRevenueData[$row->customer_id] ?? 0) - $refundAmt;
                $periodProfitRevenue[$row->customer_id] = ($periodProfitRevenue[$row->customer_id] ?? 0) - $refundAmt;
            }
            foreach ($refundQuery($compareStart, $compareEnd) as $row) {
                $compareProfitRevenue[$row->customer_id] = ($compareProfitRevenue[$row->customer_id] ?? 0) - (float) $row->total;
            }

            // ── 退订不退款扣减: 订阅已退但无退款交易，仍需扣除原始收入 ──
            $noRefundTxQuery = fn($start, $end) => DB::table('transactions as t')
                ->join('subscriptions as s', function ($join) {
                    $join->on('t.related_id', '=', 's.id')
                        ->where('t.related_type', 'App\\Models\\Subscription');
                })
                ->whereIn('t.customer_id', $customerIds)
                ->whereIn('t.type', $subTxnTypes)
                ->where('t.amount', '<', 0)
                ->where('s.status', 'refunded')
                ->where(function ($q) {
                    $q->whereNull('s.keep_performance')
                      ->orWhere('s.keep_performance', false);
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('transactions as rt')
                      ->whereColumn('rt.related_id', 's.id')
                      ->where('rt.related_type', 'App\\Models\\Subscription')
                      ->whereIn('rt.type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND]);
                })
                ->whereRaw('COALESCE(s.started_at, t.created_at) >= ?', [$start])
                ->whereRaw('COALESCE(s.started_at, t.created_at) <= ?', [$end])
                ->select('t.customer_id', DB::raw('ABS(SUM(t.amount)) as total'))
                ->groupBy('t.customer_id')
                ->get();

            foreach ($noRefundTxQuery($periodStart, $periodEnd) as $row) {
                $amt = (float) $row->total;
                $subRevenueData[$row->customer_id] = ($subRevenueData[$row->customer_id] ?? 0) - $amt;
                $periodProfitRevenue[$row->customer_id] = ($periodProfitRevenue[$row->customer_id] ?? 0) - $amt;
            }
            foreach ($noRefundTxQuery($compareStart, $compareEnd) as $row) {
                $compareProfitRevenue[$row->customer_id] = ($compareProfitRevenue[$row->customer_id] ?? 0) - (float) $row->total;
            }

            // ── 成本: 排除 refunded+!keep_performance，且只算实际收了钱的(balance_deducted=true) ──
            $costSubFilter = function ($q, $statusCol = 'status') {
                $q->where('balance_deducted', true)
                  ->where(function ($q2) use ($statusCol) {
                    $q2->where($statusCol, '!=', 'refunded')
                       ->orWhere('keep_performance', true);
                });
            };
            $costSubFilterJoined = function ($q, $statusCol = 'subscriptions.status') {
                $q->where('subscriptions.balance_deducted', true)
                  ->where(function ($q2) use ($statusCol) {
                    $q2->where($statusCol, '!=', 'refunded')
                       ->orWhere('subscriptions.keep_performance', true);
                });
            };

            // 月数表达式: 用 duration/unit 而非 DATEDIFF
            $monthsExpr = self::durationToMonthsExpr('subscriptions.duration', 'subscriptions.unit');

            // ── 新开订阅成本 ──
            $newSubCostQuery = fn($start, $end) => DB::table('subscriptions')
                ->whereIn('customer_id', $customerIds)
                ->where(fn($q) => $costSubFilter($q))
                ->where('is_test', false)
                ->where('started_at', '>=', $start)
                ->where('started_at', '<=', $end)
                ->select(
                    'customer_id',
                    DB::raw("SUM(COALESCE(sales_cost, 0) * {$monthsExpr}) as cost")
                )
                ->groupBy('customer_id')
                ->get();

            foreach ($newSubCostQuery($periodStart, $periodEnd) as $row) {
                $periodProfitCost[$row->customer_id] = (float) $row->cost;
                $subSalesCostData[$row->customer_id] = (float) $row->cost;
            }
            foreach ($newSubCostQuery($compareStart, $compareEnd) as $row) {
                $compareProfitCost[$row->customer_id] = (float) $row->cost;
            }

            // ── 续费成本: renew 交易在时段内，但订阅 started_at < 时段起（避免和新开重复）──
            $renewCostQuery = fn($start, $end) => DB::table('transactions')
                ->join('subscriptions', function ($join) {
                    $join->on('transactions.related_id', '=', 'subscriptions.id')
                        ->where('transactions.related_type', 'App\\Models\\Subscription');
                })
                ->whereIn('transactions.customer_id', $customerIds)
                ->where('transactions.type', Transaction::TYPE_RENEW)
                ->where('transactions.amount', '<', 0)
                ->where('transactions.created_at', '>=', $start)
                ->where('transactions.created_at', '<=', $end)
                ->where('subscriptions.is_test', false)
                ->where(fn($q) => $costSubFilterJoined($q))
                ->where(function ($q) use ($start) {
                    $q->where('subscriptions.started_at', '<', $start)
                      ->orWhereNull('subscriptions.started_at');
                })
                ->select(
                    'transactions.customer_id',
                    DB::raw('SUM(COALESCE(subscriptions.sales_cost, 0) * GREATEST(ROUND(ABS(transactions.amount) * ' . self::durationToMonthsExpr('subscriptions.duration', 'subscriptions.unit') . ' / NULLIF(subscriptions.price, 0)), 1)) as cost')
                )
                ->groupBy('transactions.customer_id')
                ->get();

            foreach ($renewCostQuery($periodStart, $periodEnd) as $row) {
                $periodProfitCost[$row->customer_id] = ($periodProfitCost[$row->customer_id] ?? 0) + (float) $row->cost;
                $subSalesCostData[$row->customer_id] = ($subSalesCostData[$row->customer_id] ?? 0) + (float) $row->cost;
            }
            foreach ($renewCostQuery($compareStart, $compareEnd) as $row) {
                $compareProfitCost[$row->customer_id] = ($compareProfitCost[$row->customer_id] ?? 0) + (float) $row->cost;
            }

            // ── 中转成本: 新开订阅 ──
            $fwdMonthsExpr = self::durationToMonthsExpr('subscriptions.duration', 'subscriptions.unit');
            $fwdCostQuery = fn($start, $end) => DB::table('forward_rules')
                ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
                ->whereIn('subscriptions.customer_id', $customerIds)
                ->where(fn($q) => $costSubFilterJoined($q))
                ->where('subscriptions.is_test', false)
                ->where('subscriptions.started_at', '>=', $start)
                ->where('subscriptions.started_at', '<=', $end)
                ->select(
                    'subscriptions.customer_id',
                    DB::raw("SUM(COALESCE(forward_plans.cost_price, 0) * {$fwdMonthsExpr}) as forward_cost")
                )
                ->groupBy('subscriptions.customer_id')
                ->get();

            foreach ($fwdCostQuery($periodStart, $periodEnd) as $row) {
                $periodProfitForward[$row->customer_id] = (float) $row->forward_cost;
            }
            foreach ($fwdCostQuery($compareStart, $compareEnd) as $row) {
                $compareProfitForward[$row->customer_id] = (float) $row->forward_cost;
            }

            // ── 中转成本: 续费 ──
            $renewFwdCostQuery = fn($start, $end) => DB::table('transactions')
                ->join('subscriptions', function ($join) {
                    $join->on('transactions.related_id', '=', 'subscriptions.id')
                        ->where('transactions.related_type', 'App\\Models\\Subscription');
                })
                ->join('forward_rules', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
                ->whereIn('transactions.customer_id', $customerIds)
                ->where('transactions.type', Transaction::TYPE_RENEW)
                ->where('transactions.amount', '<', 0)
                ->where('transactions.created_at', '>=', $start)
                ->where('transactions.created_at', '<=', $end)
                ->where('subscriptions.is_test', false)
                ->where(fn($q) => $costSubFilterJoined($q))
                ->where(function ($q) use ($start) {
                    $q->where('subscriptions.started_at', '<', $start)
                      ->orWhereNull('subscriptions.started_at');
                })
                ->select(
                    'transactions.customer_id',
                    DB::raw('SUM(COALESCE(forward_plans.cost_price, 0) * GREATEST(ROUND(ABS(transactions.amount) * ' . self::durationToMonthsExpr('subscriptions.duration', 'subscriptions.unit') . ' / NULLIF(subscriptions.price, 0)), 1)) as forward_cost')
                )
                ->groupBy('transactions.customer_id')
                ->get();

            foreach ($renewFwdCostQuery($periodStart, $periodEnd) as $row) {
                $periodProfitForward[$row->customer_id] = ($periodProfitForward[$row->customer_id] ?? 0) + (float) $row->forward_cost;
            }
            foreach ($renewFwdCostQuery($compareStart, $compareEnd) as $row) {
                $compareProfitForward[$row->customer_id] = ($compareProfitForward[$row->customer_id] ?? 0) + (float) $row->forward_cost;
            }

            // ── 中转成本: 中途升级（订阅非本期新开，但中转规则本期创建）──
            $upgradeFwdCostQuery = fn($start, $end) => DB::table('forward_rules')
                ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
                ->whereIn('subscriptions.customer_id', $customerIds)
                ->where(fn($q) => $costSubFilterJoined($q))
                ->where('subscriptions.is_test', false)
                ->where('forward_rules.created_at', '>=', $start)
                ->where('forward_rules.created_at', '<=', $end)
                ->where('subscriptions.started_at', '<', $start)
                ->select(
                    'subscriptions.customer_id',
                    DB::raw('SUM(COALESCE(forward_plans.cost_price, 0) * GREATEST(DATEDIFF(subscriptions.expires_at, forward_rules.created_at) / 30.0, 0.1)) as forward_cost')
                )
                ->groupBy('subscriptions.customer_id')
                ->get();

            foreach ($upgradeFwdCostQuery($periodStart, $periodEnd) as $row) {
                $periodProfitForward[$row->customer_id] = ($periodProfitForward[$row->customer_id] ?? 0) + (float) $row->forward_cost;
            }
            foreach ($upgradeFwdCostQuery($compareStart, $compareEnd) as $row) {
                $compareProfitForward[$row->customer_id] = ($compareProfitForward[$row->customer_id] ?? 0) + (float) $row->forward_cost;
            }
        }

        // ── 消费（实际余额支出）──
        $periodSpentData = [];
        $compareSpentData = [];
        if (!empty($customerIds)) {
            $spendingQuery = fn($q) => $q
                ->where('amount', '<', 0)
                ->whereNotIn('type', Transaction::SPENDING_EXCLUDE_TYPES);

            $periodRows = DB::table('transactions')
                ->whereIn('customer_id', $customerIds)
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<=', $periodEnd)
                ->tap($spendingQuery)
                ->select('customer_id', DB::raw('SUM(amount) as total'))
                ->groupBy('customer_id')
                ->get();
            foreach ($periodRows as $row) {
                $periodSpentData[$row->customer_id] = abs((float) $row->total);
            }

            // 消费的退款扣减: 始终扣（不管 keep_performance，因为钱确实退了）
            $spentRefundQuery = fn($start, $end) => DB::table('transactions')
                ->leftJoin('subscriptions', function ($join) {
                    $join->on('transactions.related_id', '=', 'subscriptions.id')
                        ->where('transactions.related_type', 'App\\Models\\Subscription');
                })
                ->whereIn('transactions.customer_id', $customerIds)
                ->whereIn('transactions.type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
                ->where('transactions.amount', '>', 0)
                ->whereRaw('COALESCE(subscriptions.started_at, transactions.created_at) >= ?', [$start])
                ->whereRaw('COALESCE(subscriptions.started_at, transactions.created_at) <= ?', [$end])
                ->select('transactions.customer_id', DB::raw('SUM(transactions.amount) as total'))
                ->groupBy('transactions.customer_id')
                ->get();

            foreach ($spentRefundQuery($periodStart, $periodEnd) as $row) {
                $periodSpentData[$row->customer_id] = ($periodSpentData[$row->customer_id] ?? 0) - (float) $row->total;
            }

            $compareRows = DB::table('transactions')
                ->whereIn('customer_id', $customerIds)
                ->where('created_at', '>=', $compareStart)
                ->where('created_at', '<=', $compareEnd)
                ->tap($spendingQuery)
                ->select('customer_id', DB::raw('SUM(amount) as total'))
                ->groupBy('customer_id')
                ->get();
            foreach ($compareRows as $row) {
                $compareSpentData[$row->customer_id] = abs((float) $row->total);
            }
            foreach ($spentRefundQuery($compareStart, $compareEnd) as $row) {
                $compareSpentData[$row->customer_id] = ($compareSpentData[$row->customer_id] ?? 0) - (float) $row->total;
            }
        }

        // ── 分类明细 ──
        $periodBreakdownData = [];
        if (!empty($customerIds)) {
            $breakdownRows = DB::table('transactions')
                ->leftJoin('subscriptions', function ($join) {
                    $join->on('transactions.related_id', '=', 'subscriptions.id')
                        ->where('transactions.related_type', 'App\\Models\\Subscription');
                })
                ->whereIn('transactions.customer_id', $customerIds)
                ->whereIn('transactions.type', Transaction::REVENUE_TYPES)
                ->where('transactions.amount', '<', 0)
                ->where('transactions.created_at', '>=', $periodStart)
                ->where('transactions.created_at', '<=', $periodEnd)
                ->select(
                    'transactions.customer_id',
                    DB::raw("CASE
                        WHEN subscriptions.purchased_module = 'video' THEN 'video'
                        WHEN subscriptions.purchased_module IN ('live_mobile', 'live_pc') THEN 'live'
                        ELSE 'ip'
                    END as product_type"),
                    DB::raw('ABS(SUM(transactions.amount)) as total'),
                    DB::raw('COUNT(DISTINCT transactions.id) as txn_count')
                )
                ->groupBy('transactions.customer_id', DB::raw("CASE
                    WHEN subscriptions.purchased_module = 'video' THEN 'video'
                    WHEN subscriptions.purchased_module IN ('live_mobile', 'live_pc') THEN 'live'
                    ELSE 'ip'
                END"))
                ->get();

            foreach ($breakdownRows as $row) {
                $periodBreakdownData[$row->customer_id][$row->product_type] = [
                    'amount' => round((float) $row->total, 2),
                    'count' => (int) $row->txn_count,
                ];
            }
        }

        // ── 手动业绩 ──
        $periodManualAmount = [];
        $periodManualProfit = [];
        $compareManualAmount = [];
        $compareManualProfit = [];
        if (!empty($customerIds)) {
            $periodManualRows = DB::table('manual_performances')
                ->whereIn('customer_id', $customerIds)
                ->where('performance_date', '>=', $periodStart->toDateString())
                ->where('performance_date', '<=', $periodEnd->toDateString())
                ->select('customer_id', DB::raw('SUM(amount) as total_amount'), DB::raw('SUM(profit) as total_profit'))
                ->groupBy('customer_id')
                ->get();
            foreach ($periodManualRows as $row) {
                $periodManualAmount[$row->customer_id] = (float) $row->total_amount;
                $periodManualProfit[$row->customer_id] = (float) $row->total_profit;
            }

            $compareManualRows = DB::table('manual_performances')
                ->whereIn('customer_id', $customerIds)
                ->where('performance_date', '>=', $compareStart->toDateString())
                ->where('performance_date', '<=', $compareEnd->toDateString())
                ->select('customer_id', DB::raw('SUM(amount) as total_amount'), DB::raw('SUM(profit) as total_profit'))
                ->groupBy('customer_id')
                ->get();
            foreach ($compareManualRows as $row) {
                $compareManualAmount[$row->customer_id] = (float) $row->total_amount;
                $compareManualProfit[$row->customer_id] = (float) $row->total_profit;
            }
        }

        // ── 组装结果 ──
        $result = $customers->map(function ($c) use ($countryData, $forwardData, $ipCounts, $subRevenueData, $subSalesCostData, $periodProfitRevenue, $periodProfitCost, $periodProfitForward, $compareProfitRevenue, $compareProfitCost, $compareProfitForward, $periodSpentData, $compareSpentData, $referralDeductionData, $periodReferralData, $compareReferralData, $periodManualAmount, $periodManualProfit, $compareManualAmount, $compareManualProfit, $periodBreakdownData) {
            $countries = $countryData[$c->id] ?? [];
            $countrySummary = collect($countries)->sortByDesc('count')->values()->all();

            $periodRefDeduction = $periodReferralData[$c->id] ?? 0;
            $compareRefDeduction = $compareReferralData[$c->id] ?? 0;

            $pManualAmt = $periodManualAmount[$c->id] ?? 0;
            $pManualProfit = $periodManualProfit[$c->id] ?? 0;
            $cManualAmt = $compareManualAmount[$c->id] ?? 0;
            $cManualProfit = $compareManualProfit[$c->id] ?? 0;

            $periodSpent = $periodSpentData[$c->id] ?? 0;
            $compareSpent = $compareSpentData[$c->id] ?? 0;

            $periodRevenue = ($subRevenueData[$c->id] ?? 0) + $pManualAmt - $periodRefDeduction;

            $pManualCost = $pManualAmt - $pManualProfit;
            $cManualCost = $cManualAmt - $cManualProfit;

            $periodCost = ($subSalesCostData[$c->id] ?? 0) + ($periodProfitForward[$c->id] ?? 0) + $pManualCost;
            $compareCost = ($compareProfitCost[$c->id] ?? 0) + ($compareProfitForward[$c->id] ?? 0) + $cManualCost;

            $periodProfit = round($periodRevenue - $periodCost, 2);
            $compareRevenue = ($compareProfitRevenue[$c->id] ?? 0) + $cManualAmt - $compareRefDeduction;
            $compareProfit = round($compareRevenue - $compareCost, 2);

            return [
                'id' => $c->id,
                'customer_name' => $c->customer_name,
                'sales_person' => $c->sales_person,
                'balance' => (float) $c->balance,
                'active_subs' => $c->active_subs,
                'period_spent' => round($periodSpent + $pManualAmt, 2),
                'compare_spent' => round($compareSpent + $cManualAmt, 2),
                'ip_count' => $ipCounts[$c->id] ?? 0,
                'countries' => $countrySummary,
                'forward_count' => $forwardData[$c->id] ?? 0,
                'sub_revenue' => round($periodRevenue, 2),
                'referral_deduction' => $periodRefDeduction,
                'sub_sales_cost' => round($periodCost, 2),
                'period_profit' => $periodProfit,
                'compare_profit' => $compareProfit,
                'has_manual' => ($pManualAmt != 0 || $pManualProfit != 0),
                'manual_amount' => $pManualAmt,
                'manual_profit' => $pManualProfit,
                'period_breakdown' => $periodBreakdownData[$c->id] ?? [],
                'created_at' => $c->created_at,
            ];
        })->sortByDesc('period_spent')->values();

        $summary = [
            'total_customers' => $result->count(),
            'period_spent' => $result->sum('period_spent'),
            'compare_spent' => $result->sum('compare_spent'),
            'period_profit' => round($result->sum('period_profit'), 2),
            'compare_profit' => round($result->sum('compare_profit'), 2),
            'total_balance' => $result->sum('balance'),
        ];

        $salesPersons = Customer::whereNotNull('sales_person')
            ->where('sales_person', '!=', '')
            ->distinct()->pluck('sales_person');

        return $this->success([
            'customers' => $result,
            'summary' => $summary,
            'sales_persons' => $salesPersons,
            'period' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ],
            'compare' => [
                'start' => $compareStart->toDateString(),
                'end' => $compareEnd->toDateString(),
            ],
        ]);
    }
}
