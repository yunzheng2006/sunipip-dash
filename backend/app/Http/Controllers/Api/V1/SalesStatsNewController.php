<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ManualStatEntry;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesStatsNewController extends Controller
{
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
            ->get();

        $customerIds = $customers->pluck('id')->all();
        if (empty($customerIds)) {
            return $this->success([
                'customers' => [],
                'summary' => $this->emptySummary(),
                'sales_persons' => $this->salesPersonList(),
                'period' => null,
            ]);
        }

        $now = now();
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $periodStart = \Carbon\Carbon::parse($request->input('date_from'))->startOfDay();
            $periodEnd = \Carbon\Carbon::parse($request->input('date_to'))->endOfDay();
        } else {
            $periodStart = $now->copy()->startOfDay();
            $periodEnd = $now->copy()->endOfDay();
        }

        // 预查：balance_deducted=false 但有实际扣款交易的订阅ID
        $extraDeductedSubIds = DB::table('subscriptions as s')
            ->where('s.balance_deducted', false)
            ->where('s.is_test', false)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.related_id', 's.id')
                  ->where('t.related_type', 'like', '%Subscription')
                  ->whereIn('t.type', ['purchase', 'deduction'])
                  ->where('t.amount', '<', 0);
            })
            ->pluck('s.id')
            ->all();

        $costSubFilter = function ($q) use ($extraDeductedSubIds) {
            $q->where(function ($q2) use ($extraDeductedSubIds) {
                $q2->where('balance_deducted', true);
                if (!empty($extraDeductedSubIds)) {
                    $q2->orWhereIn('id', $extraDeductedSubIds);
                }
            })
            ->where(function ($q2) {
                $q2->where('status', '!=', 'refunded')
                   ->orWhere('keep_performance', true);
            });
        };
        $costSubFilterJoined = function ($q) use ($extraDeductedSubIds) {
            $q->where(function ($q2) use ($extraDeductedSubIds) {
                $q2->where('subscriptions.balance_deducted', true);
                if (!empty($extraDeductedSubIds)) {
                    $q2->orWhereIn('subscriptions.id', $extraDeductedSubIds);
                }
            })
            ->where(function ($q2) {
                $q2->where('subscriptions.status', '!=', 'refunded')
                   ->orWhere('subscriptions.keep_performance', true);
            });
        };

        $monthsExpr = self::durationToMonthsExpr('subscriptions.duration', 'subscriptions.unit');
        $initialMonthsExpr = self::durationToMonthsExpr(
            'COALESCE(subscriptions.initial_duration, subscriptions.duration)',
            'COALESCE(subscriptions.initial_unit, subscriptions.unit)'
        );
        // 续费月数推算：txn_amount * duration_months / price，但不超过 duration_months（防止降级改价后膨胀）
        $renewMonthsExpr = "LEAST(GREATEST(ROUND(ABS(transactions.amount) * {$monthsExpr} / NULLIF(subscriptions.price, 0)), 1), {$monthsExpr})";

        $costCustExpr = "COALESCE(subscriptions.transferred_from_customer_id, subscriptions.customer_id)";
        $costCustExprShort = "COALESCE(transferred_from_customer_id, customer_id)";
        $costCustWhereIn = function ($q, $ids) {
            $q->where(function ($q2) use ($ids) {
                $q2->whereIn('subscriptions.customer_id', $ids)
                   ->orWhereIn('subscriptions.transferred_from_customer_id', $ids);
            });
        };
        $costCustWhereInShort = function ($q, $ids) {
            $q->where(function ($q2) use ($ids) {
                $q2->whereIn('customer_id', $ids)
                   ->orWhereIn('transferred_from_customer_id', $ids);
            });
        };

        // ── 1. IP数：选定时段内新开或续费的非测试订阅（去重） ──
        // 1a. 新开订阅 ID（仅实际扣款的）
        $newSubIds = DB::table('subscriptions')
            ->whereIn('customer_id', $customerIds)
            ->where('is_test', false)
            ->where(fn($q) => $costSubFilter($q))
            ->whereRaw('COALESCE(started_at, created_at) >= ?', [$periodStart])
            ->whereRaw('COALESCE(started_at, created_at) <= ?', [$periodEnd])
            ->pluck('id');

        // 1b. 续费订阅 ID（通过 transactions.type=renew，含线下已付 amount=0）
        $renewSubIds = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<=', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(function ($q) {
                $q->where('subscriptions.status', '!=', 'refunded')->orWhere('subscriptions.keep_performance', true);
            })
            ->pluck('subscriptions.id');

        $activeSubIds = $newSubIds->merge($renewSubIds)->unique();

        $ipCountData = [];
        if ($activeSubIds->isNotEmpty()) {
            $ipCountRows = DB::table('subscriptions')
                ->whereIn('id', $activeSubIds)
                ->select('customer_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('customer_id')
                ->get();
            foreach ($ipCountRows as $row) {
                $ipCountData[$row->customer_id] = (int) $row->cnt;
            }
        }

        // 1c. 未扣款的新开订阅数（单独提示）
        $unpaidIpData = [];
        $unpaidSubRows = DB::table('subscriptions')
            ->whereIn('customer_id', $customerIds)
            ->where('is_test', false)
            ->where('balance_deducted', false)
            ->where(function ($q) {
                $q->where('status', '!=', 'refunded')->orWhere('keep_performance', true);
            })
            ->whereRaw('COALESCE(started_at, created_at) >= ?', [$periodStart])
            ->whereRaw('COALESCE(started_at, created_at) <= ?', [$periodEnd])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.related_id', 'subscriptions.id')
                  ->where('t.related_type', 'like', '%Subscription')
                  ->whereIn('t.type', ['purchase', 'deduction'])
                  ->where('t.amount', '<', 0);
            })
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('customer_id')
            ->get();
        foreach ($unpaidSubRows as $row) {
            $unpaidIpData[$row->customer_id] = (int) $row->cnt;
        }

        // ── 2. 带中转IP数：上述订阅中有转发规则的 ──
        $forwardData = [];
        if ($activeSubIds->isNotEmpty()) {
            $forwardRows = DB::table('forward_rules')
                ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->whereIn('subscriptions.id', $activeSubIds)
                ->where('forward_rules.status', 'active')
                ->select('subscriptions.customer_id', DB::raw('COUNT(DISTINCT subscriptions.id) as cnt'))
                ->groupBy('subscriptions.customer_id')
                ->get();
            foreach ($forwardRows as $row) {
                $forwardData[$row->customer_id] = (int) $row->cnt;
            }
        }

        // ── 2b. 测试回收IP数：时段内开通的测试订阅（免费测试，自动回收） ──
        $testIpData = [];
        $testIpRows = DB::table('subscriptions')
            ->whereIn('customer_id', $customerIds)
            ->where('is_test', true)
            ->whereRaw('COALESCE(started_at, created_at) >= ?', [$periodStart])
            ->whereRaw('COALESCE(started_at, created_at) <= ?', [$periodEnd])
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('customer_id')
            ->get();
        foreach ($testIpRows as $row) {
            $testIpData[$row->customer_id] = (int) $row->cnt;
        }

        // ── 3. 消费：时段内余额支出 - 退款 ──
        // 只要交易 amount<0 就是实际扣了余额，不再依赖 balance_deducted 标记
        $spendingSubFilter = function ($q) {
            $q->whereNull('subscriptions.id')
              ->orWhere(function ($q2) {
                  $q2->where('subscriptions.status', '!=', 'refunded')
                     ->orWhere('subscriptions.keep_performance', true);
              });
        };
        $spendingData = [];
        $spendingRows = DB::table('transactions')
            ->leftJoin('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.amount', '<', 0)
            ->whereNotIn('transactions.type', Transaction::SPENDING_EXCLUDE_TYPES)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where($spendingSubFilter)
            ->select('transactions.customer_id', DB::raw('ABS(SUM(transactions.amount)) as total'))
            ->groupBy('transactions.customer_id')
            ->get();
        foreach ($spendingRows as $row) {
            $spendingData[$row->customer_id] = (float) $row->total;
        }

        // 3b. 退款扣减（同样的过滤条件）
        $refundRows = DB::table('transactions')
            ->leftJoin('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->whereIn('transactions.type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
            ->where('transactions.amount', '>', 0)
            ->where($spendingSubFilter)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->select('transactions.customer_id', DB::raw('SUM(transactions.amount) as total'))
            ->groupBy('transactions.customer_id')
            ->get();
        foreach ($refundRows as $row) {
            $spendingData[$row->customer_id] = ($spendingData[$row->customer_id] ?? 0) - (float) $row->total;
        }

        // ── 4. 销售成本 ──
        // 4a. 新开 IP 成本：sales_cost * 月数
        $newCostData = [];
        $newCostRows = DB::table('subscriptions')
            ->where(fn($q) => $costCustWhereInShort($q, $customerIds))
            ->where('is_test', false)
            ->where(fn($q) => $costSubFilter($q))
            ->where('started_at', '>=', $periodStart)
            ->where('started_at', '<=', $periodEnd)
            ->select(DB::raw("{$costCustExprShort} as customer_id"), DB::raw("SUM(COALESCE(sales_cost, 0) * {$initialMonthsExpr}) as ip_cost"))
            ->groupBy(DB::raw($costCustExprShort))
            ->get();
        foreach ($newCostRows as $row) {
            $newCostData[$row->customer_id] = (float) $row->ip_cost;
        }

        // 4b. 新开中转成本：cost_price * 月数（仅 active 规则，降级/删除的不算）
        $newFwdCostData = [];
        $newFwdCostRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhereIn($q, $customerIds))
            ->where('subscriptions.is_test', false)
            ->where('forward_rules.status', 'active')
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('subscriptions.started_at', '>=', $periodStart)
            ->where('subscriptions.started_at', '<=', $periodEnd)
            ->select(DB::raw("{$costCustExpr} as customer_id"), DB::raw("SUM(COALESCE(forward_plans.cost_price, 0) * {$initialMonthsExpr}) as fwd_cost"))
            ->groupBy(DB::raw($costCustExpr))
            ->get();
        foreach ($newFwdCostRows as $row) {
            $newFwdCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        // 4c. 续费 IP 成本：先按订阅聚合交易金额再算月数，避免逐笔 round 误差
        $renewCostData = [];
        $renewCostSub = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where(fn($q) => $q->where('subscriptions.started_at', '<', $periodStart)->orWhereNull('subscriptions.started_at'))
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                'subscriptions.id as sub_id',
                DB::raw("COALESCE(subscriptions.sales_cost, 0) as sales_cost"),
                DB::raw("GREATEST(ROUND(SUM(ABS(transactions.amount)) * {$monthsExpr} / NULLIF(subscriptions.price, 0)), 1) as renew_months")
            )
            ->groupBy(DB::raw($costCustExpr), 'subscriptions.id', 'subscriptions.sales_cost', 'subscriptions.duration', 'subscriptions.unit', 'subscriptions.price');
        $renewCostRows = DB::table(DB::raw("({$renewCostSub->toSql()}) as per_sub"))
            ->mergeBindings($renewCostSub)
            ->select('customer_id', DB::raw('SUM(sales_cost * renew_months) as ip_cost'))
            ->groupBy('customer_id')
            ->get();
        foreach ($renewCostRows as $row) {
            $renewCostData[$row->customer_id] = (float) $row->ip_cost;
        }

        // 4d. 续费中转成本（仅 active 规则，先按订阅聚合交易金额再算月数）
        $renewFwdCostData = [];
        $renewFwdCostSub = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->join('forward_rules', function ($join) {
                $join->on('forward_rules.subscription_id', '=', 'subscriptions.id')
                    ->where('forward_rules.status', 'active');
            })
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where(fn($q) => $q->where('subscriptions.started_at', '<', $periodStart)->orWhereNull('subscriptions.started_at'))
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                'subscriptions.id as sub_id',
                DB::raw("COALESCE(forward_plans.cost_price, 0) as fwd_cost_price"),
                DB::raw("GREATEST(ROUND(SUM(ABS(transactions.amount)) * {$monthsExpr} / NULLIF(subscriptions.price, 0)), 1) as renew_months")
            )
            ->groupBy(DB::raw($costCustExpr), 'subscriptions.id', 'forward_plans.cost_price', 'subscriptions.duration', 'subscriptions.unit', 'subscriptions.price');
        $renewFwdCostRows = DB::table(DB::raw("({$renewFwdCostSub->toSql()}) as per_sub"))
            ->mergeBindings($renewFwdCostSub)
            ->select('customer_id', DB::raw('SUM(fwd_cost_price * renew_months) as fwd_cost'))
            ->groupBy('customer_id')
            ->get();
        foreach ($renewFwdCostRows as $row) {
            $renewFwdCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        // 4e. 中途升级中转成本（订阅非本期新开，但中转规则本期创建，仅 active 规则）
        $upgradeFwdCostData = [];
        $upgradeFwdCostRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhereIn($q, $customerIds))
            ->where('subscriptions.is_test', false)
            ->where('forward_rules.status', 'active')
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('forward_rules.created_at', '>=', $periodStart)
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->where('subscriptions.started_at', '<', $periodStart)
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                DB::raw('SUM(COALESCE(forward_plans.cost_price, 0) * GREATEST(DATEDIFF(subscriptions.expires_at, forward_rules.created_at) / 30.0, 0.1)) as fwd_cost')
            )
            ->groupBy(DB::raw($costCustExpr))
            ->get();
        foreach ($upgradeFwdCostRows as $row) {
            $upgradeFwdCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        // ── 4f. 返佣扣减：时段内该客户触发的推荐返佣（支付给代理/推荐人的佣金） ──
        $commissionData = [];
        $commissionRows = DB::table('referral_commissions')
            ->whereIn('referee_id', $customerIds)
            ->whereIn('status', ['pending', 'credited'])
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->select('referee_id', DB::raw('SUM(commission_amount) as total_commission'))
            ->groupBy('referee_id')
            ->get();
        foreach ($commissionRows as $row) {
            $commissionData[$row->referee_id] = (float) $row->total_commission;
        }

        // ── 5. 手动条目（新表 + 旧表兼容） ──
        $manualSpending = [];
        $manualCost = [];
        $manualHardCost = [];
        $manualRows = DB::table('manual_stat_entries')
            ->whereIn('customer_id', $customerIds)
            ->where('entry_date', '>=', $periodStart->toDateString())
            ->where('entry_date', '<=', $periodEnd->toDateString())
            ->select('customer_id', DB::raw('SUM(spending) as total_spending'), DB::raw('SUM(sales_cost) as total_cost'), DB::raw('SUM(hard_cost) as total_hard_cost'))
            ->groupBy('customer_id')
            ->get();
        foreach ($manualRows as $row) {
            $manualSpending[$row->customer_id] = (float) $row->total_spending;
            $manualCost[$row->customer_id] = (float) $row->total_cost;
            $manualHardCost[$row->customer_id] = (float) $row->total_hard_cost;
        }

        // 旧 manual_performances 兼容
        $oldManualRows = DB::table('manual_performances')
            ->whereIn('customer_id', $customerIds)
            ->where('performance_date', '>=', $periodStart->toDateString())
            ->where('performance_date', '<=', $periodEnd->toDateString())
            ->select('customer_id', DB::raw('SUM(amount) as total_amount'), DB::raw('SUM(profit) as total_profit'))
            ->groupBy('customer_id')
            ->get();
        foreach ($oldManualRows as $row) {
            $amt = (float) $row->total_amount;
            $profit = (float) $row->total_profit;
            $manualSpending[$row->customer_id] = ($manualSpending[$row->customer_id] ?? 0) + $amt;
            $manualCost[$row->customer_id] = ($manualCost[$row->customer_id] ?? 0) + ($amt - $profit);
        }

        // ── 6. IP 硬成本（上游产品目录 cost_price，PHP 侧解析） ──
        // 预加载产品目录成本映射
        // 使用 allProducts()（含零库存产品）+ everStockedProducts()（历史快照）
        // 避免产品售罄后 cost_price 查不到导致硬成本为 0
        $sparkCosts = [];
        try {
            foreach (\App\Services\SparkStockCacheService::allProducts() as $p) {
                if (isset($p['product_id'], $p['cost_price']) && (float) $p['cost_price'] > 0) {
                    $sparkCosts[$p['product_id']] = (float) $p['cost_price'];
                }
            }
            // 补充历史快照中的产品（已从 API 完全消失的）
            foreach (\App\Services\SparkStockCacheService::everStockedProducts() as $pid => $p) {
                if (!isset($sparkCosts[$pid]) && isset($p['cost_price']) && (float) $p['cost_price'] > 0) {
                    $sparkCosts[$pid] = (float) $p['cost_price'];
                }
            }
        } catch (\Throwable $e) {}
        $ipipvCosts = [];
        try {
            foreach (\App\Services\IpipvStockCacheService::products() as $p) {
                $pno = $p['product_no'] ?? $p['productNo'] ?? null;
                $price = $p['cost_price'] ?? $p['unitPrice'] ?? null;
                if ($pno && $price !== null && (float) $price > 0) {
                    $ipipvCosts[$pno] = (float) $price;
                }
            }
        } catch (\Throwable $e) {}

        // 收集时段内所有相关订阅的 proxy_ip_id
        $allRelevantSubs = collect();

        // 6a. 新开订阅硬成本（仅实际扣款的）
        $newSubs = DB::table('subscriptions')
            ->where(fn($q) => $costCustWhereInShort($q, $customerIds))
            ->where('is_test', false)
            ->where(fn($q) => $costSubFilter($q))
            ->where('started_at', '>=', $periodStart)
            ->where('started_at', '<=', $periodEnd)
            ->select('id', 'customer_id', 'transferred_from_customer_id', 'proxy_ip_id', 'hard_cost', 'sales_cost', 'duration', 'unit', 'initial_duration', 'initial_unit')
            ->get();
        $allRelevantSubs = $allRelevantSubs->merge($newSubs);

        // 6b. 续费交易对应的订阅（含 amount=0 线下续费，硬成本也要算）
        // 排除本期新开的订阅（已在 6a 统计），避免重复计算
        $renewSubRows = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where(fn($q) => $q->where('subscriptions.started_at', '<', $periodStart)->orWhereNull('subscriptions.started_at'))
            ->select(
                'subscriptions.id', 'subscriptions.customer_id', 'subscriptions.transferred_from_customer_id', 'subscriptions.proxy_ip_id',
                'subscriptions.hard_cost', 'subscriptions.sales_cost', 'subscriptions.duration', 'subscriptions.unit',
                'subscriptions.price',
                DB::raw('ABS(transactions.amount) as txn_amount')
            )
            ->get();
        $allRelevantSubs = $allRelevantSubs->merge($renewSubRows);

        // 批量查询 proxy_ip_id → product_id 映射
        $proxyIpIds = $allRelevantSubs->pluck('proxy_ip_id')->filter()->unique()->values()->all();
        $sparkProductMap = [];
        $ipipvProductMap = [];
        if (!empty($proxyIpIds)) {
            $chunks = array_chunk($proxyIpIds, 1000);
            foreach ($chunks as $chunk) {
                $sparkRows = DB::table('spark_instances')
                    ->join('spark_orders', 'spark_orders.id', '=', 'spark_instances.spark_order_id')
                    ->whereIn('spark_instances.proxy_ip_id', $chunk)
                    ->select('spark_instances.proxy_ip_id', 'spark_orders.product_id')
                    ->get();
                foreach ($sparkRows as $row) {
                    $sparkProductMap[$row->proxy_ip_id] = $row->product_id;
                }

                $ipipvRows = DB::table('proxy_ips')
                    ->join('ipipv_instances', 'ipipv_instances.instance_no', '=', 'proxy_ips.ipipv_instance_id')
                    ->join('ipipv_orders', 'ipipv_orders.id', '=', 'ipipv_instances.ipipv_order_id')
                    ->whereIn('proxy_ips.id', $chunk)
                    ->whereNotNull('proxy_ips.ipipv_instance_id')
                    ->select('proxy_ips.id as proxy_ip_id', 'ipipv_orders.product_no')
                    ->get();
                foreach ($ipipvRows as $row) {
                    $ipipvProductMap[$row->proxy_ip_id] = $row->product_no;
                }
            }
        }

        $ipipvHardCostOverride = \App\Models\SystemConfig::get('cost.ipipv_hard_cost_override');

        $resolveIpHardCost = function ($sub) use ($sparkCosts, $ipipvCosts, $sparkProductMap, $ipipvProductMap, $ipipvHardCostOverride): float {
            if ($sub->hard_cost && (float) $sub->hard_cost > 0) {
                return (float) $sub->hard_cost;
            }
            $pid = $sub->proxy_ip_id;
            if ($pid && isset($sparkProductMap[$pid], $sparkCosts[$sparkProductMap[$pid]])) {
                return $sparkCosts[$sparkProductMap[$pid]];
            }
            if ($pid && isset($ipipvProductMap[$pid])) {
                if ($ipipvHardCostOverride !== null && (float) $ipipvHardCostOverride > 0) {
                    return (float) $ipipvHardCostOverride;
                }
                if (isset($ipipvCosts[$ipipvProductMap[$pid]])) {
                    return $ipipvCosts[$ipipvProductMap[$pid]];
                }
            }
            if (isset($sub->sales_cost) && (float) $sub->sales_cost > 0) {
                return (float) $sub->sales_cost;
            }
            return 0;
        };

        // 6a 计算：新开 IP 硬成本
        $newIpHardCostData = [];
        foreach ($newSubs as $sub) {
            $months = max(\App\Support\DurationHelper::toMonths($sub->initial_duration ?: $sub->duration ?: 1, $sub->initial_unit ?: $sub->unit ?: 3), 1);
            $cost = $resolveIpHardCost($sub);
            $cid = $sub->transferred_from_customer_id ?: $sub->customer_id;
            $newIpHardCostData[$cid] = ($newIpHardCostData[$cid] ?? 0) + round($cost * $months, 4);
        }

        // 6b 计算：续费 IP 硬成本（先按订阅聚合交易金额再算月数）
        $renewIpHardCostData = [];
        $renewBySubId = [];
        foreach ($renewSubRows as $row) {
            $sid = $row->id;
            if (!isset($renewBySubId[$sid])) {
                $renewBySubId[$sid] = (object) [
                    'id' => $row->id, 'customer_id' => $row->transferred_from_customer_id ?: $row->customer_id,
                    'proxy_ip_id' => $row->proxy_ip_id, 'hard_cost' => $row->hard_cost,
                    'sales_cost' => $row->sales_cost, 'duration' => $row->duration,
                    'unit' => $row->unit, 'price' => $row->price, 'total_txn' => 0,
                ];
            }
            $renewBySubId[$sid]->total_txn += (float) $row->txn_amount;
        }
        foreach ($renewBySubId as $row) {
            $cost = $resolveIpHardCost($row);
            $durationMonths = max(\App\Support\DurationHelper::toMonths($row->duration ?: 1, $row->unit ?: 3), 1);
            if ($row->total_txn == 0) {
                $renewMonths = $durationMonths;
            } else {
                $monthlyPrice = (float) $row->price > 0 ? (float) $row->price / $durationMonths : 0;
                $renewMonths = $monthlyPrice > 0 ? max(round($row->total_txn / $monthlyPrice), 1) : $durationMonths;
            }
            $renewIpHardCostData[$row->customer_id] = ($renewIpHardCostData[$row->customer_id] ?? 0) + round($cost * $renewMonths, 4);
        }

        // 6c. 中转硬成本（SQL，forward_plans 有 hard_cost_price 列，回退 cost_price）
        $fwdHardExpr = 'COALESCE(forward_plans.hard_cost_price, forward_plans.cost_price, 0)';

        $newFwdHardCostData = [];
        $newFwdHardCostRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhereIn($q, $customerIds))
            ->where('subscriptions.is_test', false)
            ->where('forward_rules.status', 'active')
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('subscriptions.started_at', '>=', $periodStart)
            ->where('subscriptions.started_at', '<=', $periodEnd)
            ->select(DB::raw("{$costCustExpr} as customer_id"), DB::raw("SUM({$fwdHardExpr} * {$initialMonthsExpr}) as fwd_cost"))
            ->groupBy(DB::raw($costCustExpr))
            ->get();
        foreach ($newFwdHardCostRows as $row) {
            $newFwdHardCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        $renewFwdHardCostData = [];
        $renewFwdHardCostSub = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->join('forward_rules', function ($join) {
                $join->on('forward_rules.subscription_id', '=', 'subscriptions.id')
                    ->where('forward_rules.status', 'active');
            })
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where(fn($q) => $q->where('subscriptions.started_at', '<', $periodStart)->orWhereNull('subscriptions.started_at'))
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                'subscriptions.id as sub_id',
                DB::raw("{$fwdHardExpr} as fwd_unit_cost"),
                DB::raw("GREATEST(ROUND(SUM(ABS(transactions.amount)) * {$monthsExpr} / NULLIF(subscriptions.price, 0)), 1) as renew_months")
            )
            ->groupBy(DB::raw($costCustExpr), 'subscriptions.id', 'forward_plans.hard_cost_price', 'forward_plans.cost_price', 'subscriptions.duration', 'subscriptions.unit', 'subscriptions.price');
        $renewFwdHardCostRows = DB::table(DB::raw("({$renewFwdHardCostSub->toSql()}) as per_sub"))
            ->mergeBindings($renewFwdHardCostSub)
            ->select('customer_id', DB::raw('SUM(fwd_unit_cost * renew_months) as fwd_cost'))
            ->groupBy('customer_id')
            ->get();
        foreach ($renewFwdHardCostRows as $row) {
            $renewFwdHardCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        $upgradeFwdHardCostData = [];
        $upgradeFwdHardCostRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhereIn($q, $customerIds))
            ->where('subscriptions.is_test', false)
            ->where('forward_rules.status', 'active')
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('forward_rules.created_at', '>=', $periodStart)
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->where('subscriptions.started_at', '<', $periodStart)
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                DB::raw("SUM({$fwdHardExpr} * GREATEST(DATEDIFF(subscriptions.expires_at, forward_rules.created_at) / 30.0, 0.1)) as fwd_cost")
            )
            ->groupBy(DB::raw($costCustExpr))
            ->get();
        foreach ($upgradeFwdHardCostRows as $row) {
            $upgradeFwdHardCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        // ── 组装 ──
        $result = $customers->map(function ($c) use ($ipCountData, $forwardData, $testIpData, $unpaidIpData, $spendingData, $commissionData, $newCostData, $newFwdCostData, $renewCostData, $renewFwdCostData, $upgradeFwdCostData, $manualSpending, $manualCost, $manualHardCost, $newIpHardCostData, $renewIpHardCostData, $newFwdHardCostData, $renewFwdHardCostData, $upgradeFwdHardCostData) {
            $spending = ($spendingData[$c->id] ?? 0) + ($manualSpending[$c->id] ?? 0);
            $commission = $commissionData[$c->id] ?? 0;
            $salesCost = ($newCostData[$c->id] ?? 0)
                + ($newFwdCostData[$c->id] ?? 0)
                + ($renewCostData[$c->id] ?? 0)
                + ($renewFwdCostData[$c->id] ?? 0)
                + ($upgradeFwdCostData[$c->id] ?? 0)
                + ($manualCost[$c->id] ?? 0);
            $ipHardCost = ($newIpHardCostData[$c->id] ?? 0)
                + ($renewIpHardCostData[$c->id] ?? 0);
            $fwdHardCost = ($newFwdHardCostData[$c->id] ?? 0)
                + ($renewFwdHardCostData[$c->id] ?? 0)
                + ($upgradeFwdHardCostData[$c->id] ?? 0);
            $manualHC = $manualHardCost[$c->id] ?? 0;
            $netPerformance = round($spending - $commission, 2);
            $profit = round($spending - $commission - $salesCost, 2);

            $hasManual = ($manualSpending[$c->id] ?? 0) != 0 || ($manualCost[$c->id] ?? 0) != 0 || $manualHC != 0;

            $totalIp = $ipCountData[$c->id] ?? 0;
            $fwdIp = $forwardData[$c->id] ?? 0;

            return [
                'id' => $c->id,
                'customer_name' => $c->customer_name,
                'sales_person' => $c->sales_person,
                'ip_only_count' => max(0, $totalIp - $fwdIp),
                'forward_ip_count' => $fwdIp,
                'test_ip_count' => $testIpData[$c->id] ?? 0,
                'unpaid_ip_count' => $unpaidIpData[$c->id] ?? 0,
                'spending' => round($spending, 2),
                'net_performance' => $netPerformance,
                'commission' => round($commission, 2),
                'sales_cost' => round($salesCost, 2),
                'ip_hard_cost' => round($ipHardCost, 2),
                'fwd_hard_cost' => round($fwdHardCost, 2),
                'manual_hard_cost' => round($manualHC, 2),
                'hard_cost' => round($ipHardCost + $fwdHardCost + $manualHC, 2),
                'profit' => $profit,
                'balance' => (float) $c->balance,
                'has_manual' => $hasManual,
            ];
        })->sortByDesc('spending')->values();

        $summary = [
            'total_customers' => $result->count(),
            'total_spending' => round($result->sum('spending'), 2),
            'total_net_performance' => round($result->sum('net_performance'), 2),
            'total_commission' => round($result->sum('commission'), 2),
            'total_sales_cost' => round($result->sum('sales_cost'), 2),
            'total_ip_hard_cost' => round($result->sum('ip_hard_cost'), 2),
            'total_fwd_hard_cost' => round($result->sum('fwd_hard_cost'), 2),
            'total_hard_cost' => round($result->sum('hard_cost'), 2),
            'total_profit' => round($result->sum('profit'), 2),
            'total_balance' => round($result->sum('balance'), 2),
            'total_ip_only' => $result->sum('ip_only_count'),
            'total_forward_ip' => $result->sum('forward_ip_count'),
            'total_test_ip' => $result->sum('test_ip_count'),
            'total_unpaid_ip' => $result->sum('unpaid_ip_count'),
        ];

        return $this->success([
            'customers' => $result,
            'summary' => $summary,
            'sales_persons' => $this->salesPersonList(),
            'period' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ],
        ]);
    }

    // ── 手动条目 CRUD ──

    public function manualEntries(Request $request): JsonResponse
    {
        $query = ManualStatEntry::with(['customer:id,customer_name', 'creator:id,name']);

        if ($request->filled('sales_person')) {
            $query->where('sales_person', $request->input('sales_person'));
        }
        if ($request->filled('date_from')) {
            $query->where('entry_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('entry_date', '<=', $request->input('date_to'));
        }

        return $this->success($query->orderByDesc('entry_date')->orderByDesc('id')->get());
    }

    public function storeManualEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'spending' => 'nullable|numeric|min:0',
            'sales_cost' => 'nullable|numeric|min:0',
            'hard_cost' => 'nullable|numeric|min:0',
            'entry_date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        if (empty($data['spending']) && empty($data['sales_cost']) && empty($data['hard_cost'])) {
            return $this->error('消费、销售成本、硬成本至少填一项', 422);
        }

        $customer = Customer::findOrFail($data['customer_id']);

        $entry = ManualStatEntry::create([
            'customer_id' => $customer->id,
            'sales_person' => $customer->sales_person,
            'spending' => $data['spending'] ?? 0,
            'sales_cost' => $data['sales_cost'] ?? 0,
            'hard_cost' => $data['hard_cost'] ?? 0,
            'entry_date' => $data['entry_date'],
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $entry->load(['customer:id,customer_name', 'creator:id,name']);

        return $this->success($entry, '添加成功');
    }

    public function destroyManualEntry(ManualStatEntry $manualStatEntry): JsonResponse
    {
        $manualStatEntry->delete();
        return $this->success(null, '已删除');
    }

    private function emptySummary(): array
    {
        return [
            'total_customers' => 0,
            'total_spending' => 0,
            'total_commission' => 0,
            'total_sales_cost' => 0,
            'total_profit' => 0,
            'total_balance' => 0,
            'total_ip_only' => 0,
            'total_forward_ip' => 0,
            'total_test_ip' => 0,
            'total_ip_hard_cost' => 0,
            'total_fwd_hard_cost' => 0,
            'total_hard_cost' => 0,
        ];
    }

    private function salesPersonList()
    {
        return Customer::whereNotNull('sales_person')
            ->where('sales_person', '!=', '')
            ->distinct()->pluck('sales_person');
    }
}
