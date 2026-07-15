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

    /** 从快照行重算汇总（与实时计算的 summary 字段一致） */
    private function summaryFromRows($rows): array
    {
        return [
            'total_customers' => $rows->count(),
            'total_spending' => round($rows->sum('spending'), 2),
            'total_net_performance' => round($rows->sum('net_performance'), 2),
            'total_commission' => round($rows->sum('commission'), 2),
            'total_sales_cost' => round($rows->sum('sales_cost'), 2),
            'total_ip_hard_cost' => round($rows->sum('ip_hard_cost'), 2),
            'total_fwd_hard_cost' => round($rows->sum('fwd_hard_cost'), 2),
            'total_hard_cost' => round($rows->sum('hard_cost'), 2),
            'total_profit' => round($rows->sum('profit'), 2),
            'total_balance' => round($rows->sum('balance'), 2),
            'total_ip_only' => $rows->sum('ip_only_count'),
            'total_forward_ip' => $rows->sum('forward_ip_count'),
            'total_test_ip' => $rows->sum('test_ip_count'),
            'total_unpaid_ip' => $rows->sum('unpaid_ip_count'),
        ];
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

        // 历史整月查询优先走固化快照（stats:snapshot 每月固化），避免退款/续费/改价导致历史报表漂移
        // 传 ?live=1 可强制实时计算
        if (!$request->boolean('live')
            && $periodStart->day === 1
            && $periodEnd->isSameDay($periodStart->copy()->endOfMonth())
            && $periodEnd->isPast()
        ) {
            $period = $periodStart->format('Y-m');
            $snapshotRows = DB::table('sales_stats_snapshots')
                ->where('period', $period)
                ->whereIn('customer_id', $customerIds)
                ->orderBy('id')
                ->get();
            if ($snapshotRows->isNotEmpty()) {
                $rows = $snapshotRows->map(fn ($r) => json_decode($r->data, true))
                    ->sortByDesc('spending')->values();
                return $this->success([
                    'customers' => $rows,
                    'summary' => $this->summaryFromRows($rows),
                    'sales_persons' => $this->salesPersonList(),
                    'period' => [
                        'start' => $periodStart->toDateString(),
                        'end' => $periodEnd->toDateString(),
                    ],
                    'from_snapshot' => true,
                    'snapshotted_at' => (string) $snapshotRows->first()->snapshotted_at,
                ]);
            }
        }

        // 预查：balance_deducted=false 但有实际扣款交易的订阅ID（含续费）
        $extraDeductedSubIds = DB::table('subscriptions as s')
            ->where('s.balance_deducted', false)
            ->where('s.is_test', false)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('transactions as t')
                  ->whereColumn('t.related_id', 's.id')
                  ->where('t.related_type', 'like', '%Subscription')
                  ->whereIn('t.type', ['purchase', 'deduction', 'renew'])
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

        // 1a+: 管理员测试转正（TYPE_DEDUCTION + "测试转正"描述），started_at 保留测试日期，通过交易日期匹配
        $convertSubIds = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_DEDUCTION)
            ->where('transactions.description', 'like', '测试转正%')
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->pluck('subscriptions.id');

        $newSubIds = $newSubIds->merge($convertSubIds)->unique();

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
                // deleted 也算：IP 到期清理会把规则标 deleted，否则历史月份"带中转IP数"会缩水
                ->whereIn('forward_rules.status', ['active', 'deleted'])
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
        // 退订剔除的边界：批量开通是一笔交易挂在首条订阅上（如 ¥160 开 2 条），
        // 首条退订时若整笔剔除，会把存活订阅的那份钱一起吞掉（真实案例：洪子
        // 7/13 少算 ¥80）。因此金额明显大于关联订阅 price 的"批量交易"不剔除，
        // 其退款也保留冲减，让批量对（+160 − 80）自然净出存活部分。
        $spendingSubFilter = function ($q) {
            $q->whereNull('subscriptions.id')
              ->orWhere(function ($q2) {
                  $q2->where('subscriptions.status', '!=', 'refunded')
                     ->orWhere('subscriptions.keep_performance', true);
              })
              ->orWhereRaw('ABS(transactions.amount) > subscriptions.price + 0.01');
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

        // 3b. 退款扣减。保留业绩（keep_performance）的退订属于人工干预：业绩/成本完全保留，
        // 退款只是资金操作，不冲减业绩。未保留业绩的退订，其退款仅当无 1:1 对应支出交易时
        // 才冲减（批量交易场景，与上面的批量支出配对）；有 1:1 支出的两侧同时剔除，净零。
        $refundSubFilter = function ($q) {
            $q->whereNull('subscriptions.id')
              ->orWhere('subscriptions.status', '!=', 'refunded')
              ->orWhere(function ($q2) {
                  $q2->where('subscriptions.keep_performance', false)
                     ->whereRaw("NOT EXISTS (
                            SELECT 1 FROM transactions tp
                            WHERE tp.related_id = subscriptions.id
                              AND tp.related_type = 'App\\\\Models\\\\Subscription'
                              AND tp.amount < 0
                              AND tp.type IN ('purchase', 'deduction', 'renew')
                              AND ABS(tp.amount) <= subscriptions.price + 0.01
                        )");
              });
        };
        $refundRows = DB::table('transactions')
            ->leftJoin('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->whereIn('transactions.customer_id', $customerIds)
            ->whereIn('transactions.type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
            ->where('transactions.amount', '>', 0)
            ->where($refundSubFilter)
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

        // 4a+: 管理员测试转正的 sales_cost（started_at 保留测试日期，通过交易日期匹配）
        // 排除 started_at 已在本期内的订阅（已被 4a 统计），避免重复计算
        if ($convertSubIds->isNotEmpty()) {
            $convertCostRows = DB::table('subscriptions')
                ->where(fn($q) => $costCustWhereInShort($q, $customerIds))
                ->whereIn('id', $convertSubIds)
                ->where('is_test', false)
                ->where(fn($q) => $costSubFilter($q))
                ->whereNot(function ($q) use ($periodStart, $periodEnd) {
                    $q->where('started_at', '>=', $periodStart)
                      ->where('started_at', '<=', $periodEnd);
                })
                ->select(DB::raw("{$costCustExprShort} as customer_id"), DB::raw("SUM(COALESCE(sales_cost, 0) * {$initialMonthsExpr}) as ip_cost"))
                ->groupBy(DB::raw($costCustExprShort))
                ->get();
            foreach ($convertCostRows as $row) {
                $newCostData[$row->customer_id] = ($newCostData[$row->customer_id] ?? 0) + (float) $row->ip_cost;
            }
        }

        // 4b. 新开中转成本：cost_price * 实际中转月数（中转可能晚于IP开通，按中转创建到首期到期日算）
        // 用首期到期日（started_at + 首期时长）而非当前 expires_at：续费会推后 expires_at，
        // 若用当前值，续费月数会被算进新开成本，与 4d 续费段重复，且历史报表随续费漂移
        $newFwdCostData = [];
        $initialDaysExpr = "(COALESCE(subscriptions.initial_duration, subscriptions.duration) * CASE COALESCE(subscriptions.initial_unit, subscriptions.unit)
            WHEN 1 THEN 1
            WHEN 2 THEN 7
            WHEN 3 THEN 30
            WHEN 4 THEN 365
            ELSE 30
        END)";
        $initialEndExpr = "DATE_ADD(subscriptions.started_at, INTERVAL {$initialDaysExpr} DAY)";
        // 中转计费终点：active 规则按首期到期日；deleted 规则（到期清理/降级）按删除时间封顶——
        // 到期后被清理的规则删除时间晚于首期到期日，成本不受影响；中途降级的按实际使用折算。
        // 不能只查 active：IP 到期清理会把规则标 deleted，历史月份的中转成本会凭空消失
        $fwdEndExpr = "CASE WHEN forward_rules.status = 'deleted'
            THEN LEAST({$initialEndExpr}, forward_rules.updated_at)
            ELSE {$initialEndExpr} END";
        $fwdMonthsExpr = "GREATEST(DATEDIFF({$fwdEndExpr}, GREATEST(forward_rules.created_at, subscriptions.started_at)) / 30.0, 0.1)";
        $newFwdCostRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhereIn($q, $customerIds))
            ->where('subscriptions.is_test', false)
            ->whereIn('forward_rules.status', ['active', 'deleted'])
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('subscriptions.started_at', '>=', $periodStart)
            ->where('subscriptions.started_at', '<=', $periodEnd)
            // 规则必须在时段结束前已存在：订阅在本期开通但后来（时段外）换开的
            // 新中转，其成本不能倒灌进本期（真实案例：老八 7/13 升级手机直播专线，
            // 成本 ¥234 被算进 7/11~7/12 的报表，只有成本没有消费，利润 -257）
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->select(DB::raw("{$costCustExpr} as customer_id"), DB::raw("SUM(COALESCE(forward_plans.cost_price, 0) * {$fwdMonthsExpr}) as fwd_cost"))
            ->groupBy(DB::raw($costCustExpr))
            ->get();
        foreach ($newFwdCostRows as $row) {
            $newFwdCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        // 4b+: 管理员测试转正的中转 sales_cost（started_at 不在本期，通过 convertSubIds 匹配）
        if ($convertSubIds->isNotEmpty()) {
            $convertFwdCostRows = DB::table('forward_rules')
                ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
                ->where(fn($q) => $costCustWhereIn($q, $customerIds))
                ->whereIn('subscriptions.id', $convertSubIds)
                ->where('subscriptions.is_test', false)
                ->whereIn('forward_rules.status', ['active', 'deleted'])
                ->where(fn($q) => $costSubFilterJoined($q))
                ->whereNot(function ($q) use ($periodStart, $periodEnd) {
                    $q->where('subscriptions.started_at', '>=', $periodStart)
                      ->where('subscriptions.started_at', '<=', $periodEnd);
                })
                ->where('forward_rules.created_at', '<=', $periodEnd)
                ->select(DB::raw("{$costCustExpr} as customer_id"), DB::raw("SUM(COALESCE(forward_plans.cost_price, 0) * {$fwdMonthsExpr}) as fwd_cost"))
                ->groupBy(DB::raw($costCustExpr))
                ->get();
            foreach ($convertFwdCostRows as $row) {
                $newFwdCostData[$row->customer_id] = ($newFwdCostData[$row->customer_id] ?? 0) + (float) $row->fwd_cost;
            }
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
                    // 规则须在续费前已存在；deleted 规则仅当删除发生在续费之后才算（续费时中转还在）
                    ->whereColumn('forward_rules.created_at', '<=', 'transactions.created_at')
                    ->where(function ($q) {
                        $q->where('forward_rules.status', 'active')
                          ->orWhere(function ($q2) {
                              $q2->where('forward_rules.status', 'deleted')
                                 ->whereColumn('forward_rules.updated_at', '>', 'transactions.created_at');
                          });
                    });
            })
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
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
        // 月数优先用升级扣款金额 ÷ 单月转发费反推（冻结值）；无扣款记录时回退到
        // expires_at - 创建日（后者会随续费漂移，但续费部分已由 4d 覆盖，仅少数免费升级走此路径）
        $upgradeFwdMonthsExpr = "COALESCE(
            (SELECT SUM(ABS(t.amount)) FROM transactions t
             WHERE t.related_id = subscriptions.id
               AND t.related_type = 'App\\\\Models\\\\Subscription'
               AND t.type = 'deduction' AND t.amount < 0
               AND t.created_at >= forward_rules.created_at - INTERVAL 10 MINUTE
               AND t.created_at <= forward_rules.created_at + INTERVAL 10 MINUTE
            ) / NULLIF(forward_rules.forward_fee, 0),
            GREATEST(DATEDIFF(subscriptions.expires_at, forward_rules.created_at) / 30.0, 0.1)
        )";
        $upgradeFwdCostData = [];
        $upgradeFwdCostRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhereIn($q, $customerIds))
            ->where('subscriptions.is_test', false)
            ->whereIn('forward_rules.status', ['active', 'deleted'])
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('forward_rules.created_at', '>=', $periodStart)
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->where('subscriptions.started_at', '<', $periodStart)
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                DB::raw("SUM(COALESCE(forward_plans.cost_price, 0) * {$upgradeFwdMonthsExpr}) as fwd_cost")
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

        // 6a+: 管理员测试转正的硬成本（started_at 保留测试日期，通过 convertSubIds 匹配）
        if ($convertSubIds->isNotEmpty()) {
            $existingNewIds = $newSubs->pluck('id')->all();
            $convertSubs = DB::table('subscriptions')
                ->where(fn($q) => $costCustWhereInShort($q, $customerIds))
                ->whereIn('id', $convertSubIds)
                ->whereNotIn('id', $existingNewIds)
                ->where('is_test', false)
                ->where(fn($q) => $costSubFilter($q))
                ->select('id', 'customer_id', 'transferred_from_customer_id', 'proxy_ip_id', 'hard_cost', 'sales_cost', 'duration', 'unit', 'initial_duration', 'initial_unit')
                ->get();
            $newSubs = $newSubs->merge($convertSubs);
            $allRelevantSubs = $allRelevantSubs->merge($convertSubs);
        }

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
            ->whereIn('forward_rules.status', ['active', 'deleted'])
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('subscriptions.started_at', '>=', $periodStart)
            ->where('subscriptions.started_at', '<=', $periodEnd)
            // 同 4b：时段外新换开的中转成本不倒灌本期
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->select(DB::raw("{$costCustExpr} as customer_id"), DB::raw("SUM({$fwdHardExpr} * {$fwdMonthsExpr}) as fwd_cost"))
            ->groupBy(DB::raw($costCustExpr))
            ->get();
        foreach ($newFwdHardCostRows as $row) {
            $newFwdHardCostData[$row->customer_id] = (float) $row->fwd_cost;
        }

        // 6c+: 管理员测试转正的中转硬成本（started_at 不在本期，通过 convertSubIds 匹配）
        if ($convertSubIds->isNotEmpty()) {
            $convertFwdHardCostRows = DB::table('forward_rules')
                ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
                ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
                ->where(fn($q) => $costCustWhereIn($q, $customerIds))
                ->whereIn('subscriptions.id', $convertSubIds)
                ->where('subscriptions.is_test', false)
                ->whereIn('forward_rules.status', ['active', 'deleted'])
                ->where(fn($q) => $costSubFilterJoined($q))
                ->whereNot(function ($q) use ($periodStart, $periodEnd) {
                    $q->where('subscriptions.started_at', '>=', $periodStart)
                      ->where('subscriptions.started_at', '<=', $periodEnd);
                })
                ->where('forward_rules.created_at', '<=', $periodEnd)
                ->select(DB::raw("{$costCustExpr} as customer_id"), DB::raw("SUM({$fwdHardExpr} * {$fwdMonthsExpr}) as fwd_cost"))
                ->groupBy(DB::raw($costCustExpr))
                ->get();
            foreach ($convertFwdHardCostRows as $row) {
                $newFwdHardCostData[$row->customer_id] = ($newFwdHardCostData[$row->customer_id] ?? 0) + (float) $row->fwd_cost;
            }
        }

        $renewFwdHardCostData = [];
        $renewFwdHardCostSub = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->join('forward_rules', function ($join) {
                $join->on('forward_rules.subscription_id', '=', 'subscriptions.id')
                    // 同 4d：规则须先于续费存在；deleted 仅当删除晚于续费才算
                    ->whereColumn('forward_rules.created_at', '<=', 'transactions.created_at')
                    ->where(function ($q) {
                        $q->where('forward_rules.status', 'active')
                          ->orWhere(function ($q2) {
                              $q2->where('forward_rules.status', 'deleted')
                                 ->whereColumn('forward_rules.updated_at', '>', 'transactions.created_at');
                          });
                    });
            })
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->whereIn('transactions.customer_id', $customerIds)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.created_at', '>=', $periodStart)
            ->where('transactions.created_at', '<=', $periodEnd)
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
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
            ->whereIn('forward_rules.status', ['active', 'deleted'])
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where('forward_rules.created_at', '>=', $periodStart)
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->where('subscriptions.started_at', '<', $periodStart)
            ->select(
                DB::raw("{$costCustExpr} as customer_id"),
                DB::raw("SUM({$fwdHardExpr} * {$upgradeFwdMonthsExpr}) as fwd_cost")
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

    /**
     * GET /billing/sales-stats-new/customer-detail
     * 单客户业绩/成本逐行明细（业绩页点击客户名弹窗用），与 index() 各汇总项同口径：
     *   transactions（消费/退款）、new_subs（新开IP成本 4a/6a + 测试转正）、
     *   new_fwd_rules（新开中转 4b/6c）、renewals（续费 4c/4d）、
     *   upgrade_fwd_rules（升级中转 4e）、commissions（返佣 4f）、manual_entries（手动 5）
     */
    public function customerDetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date',
        ]);
        $cid = (int) $data['customer_id'];
        $periodStart = \Illuminate\Support\Carbon::parse($data['date_from'])->startOfDay();
        $periodEnd = \Illuminate\Support\Carbon::parse($data['date_to'])->endOfDay();

        $customer = DB::table('customers')->where('id', $cid)
            ->first(['id', 'customer_name', 'sales_person', 'balance']);

        // ── 口径表达式（与 index() 一致） ──
        $monthsExpr = self::durationToMonthsExpr('subscriptions.duration', 'subscriptions.unit');
        $initialMonthsExpr = self::durationToMonthsExpr(
            'COALESCE(subscriptions.initial_duration, subscriptions.duration)',
            'COALESCE(subscriptions.initial_unit, subscriptions.unit)'
        );
        $initialDaysExpr = "(COALESCE(subscriptions.initial_duration, subscriptions.duration) * CASE COALESCE(subscriptions.initial_unit, subscriptions.unit)
            WHEN 1 THEN 1 WHEN 2 THEN 7 WHEN 3 THEN 30 WHEN 4 THEN 365 ELSE 30 END)";
        $initialEndExpr = "DATE_ADD(subscriptions.started_at, INTERVAL {$initialDaysExpr} DAY)";
        $fwdEndExpr = "CASE WHEN forward_rules.status = 'deleted'
            THEN LEAST({$initialEndExpr}, forward_rules.updated_at)
            ELSE {$initialEndExpr} END";
        $fwdMonthsExpr = "GREATEST(DATEDIFF({$fwdEndExpr}, GREATEST(forward_rules.created_at, subscriptions.started_at)) / 30.0, 0.1)";
        $upgradeFwdMonthsExpr = "COALESCE(
            (SELECT SUM(ABS(t.amount)) FROM transactions t
             WHERE t.related_id = subscriptions.id
               AND t.related_type = 'App\\\\Models\\\\Subscription'
               AND t.type = 'deduction' AND t.amount < 0
               AND t.created_at >= forward_rules.created_at - INTERVAL 10 MINUTE
               AND t.created_at <= forward_rules.created_at + INTERVAL 10 MINUTE
            ) / NULLIF(forward_rules.forward_fee, 0),
            GREATEST(DATEDIFF(subscriptions.expires_at, forward_rules.created_at) / 30.0, 0.1)
        )";
        $costCustWhere = function ($q) use ($cid) {
            $q->where(function ($q2) use ($cid) {
                $q2->where('subscriptions.customer_id', $cid)
                   ->orWhere('subscriptions.transferred_from_customer_id', $cid);
            });
        };
        $extraDeductedSubIds = DB::table('subscriptions as s')
            ->where(function ($q) use ($cid) {
                $q->where('s.customer_id', $cid)->orWhere('s.transferred_from_customer_id', $cid);
            })
            ->where('s.balance_deducted', false)
            ->where('s.is_test', false)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('transactions as t')
                  ->whereColumn('t.related_id', 's.id')
                  ->where('t.related_type', 'like', '%Subscription')
                  ->whereIn('t.type', ['purchase', 'deduction', 'renew'])
                  ->where('t.amount', '<', 0);
            })
            ->pluck('s.id')->all();
        $costSubFilterJoined = function ($q) use ($extraDeductedSubIds) {
            $q->where(function ($q2) use ($extraDeductedSubIds) {
                $q2->where('subscriptions.balance_deducted', true);
                if (!empty($extraDeductedSubIds)) {
                    $q2->orWhereIn('subscriptions.id', $extraDeductedSubIds);
                }
            })->where(function ($q2) {
                $q2->where('subscriptions.status', '!=', 'refunded')
                   ->orWhere('subscriptions.keep_performance', true);
            });
        };

        // ── 1. 交易流水（全部列出，标注每笔对"消费"的贡献） ──
        $txnRows = DB::table('transactions as t')
            ->leftJoin('subscriptions as s', function ($join) {
                $join->on('t.related_id', '=', 's.id')
                    ->where('t.related_type', 'App\\Models\\Subscription');
            })
            ->where('t.customer_id', $cid)
            ->whereBetween('t.created_at', [$periodStart, $periodEnd])
            ->orderBy('t.id')
            ->get(['t.id', 't.type', 't.amount', 't.balance_after', 't.description', 't.created_at',
                   's.id as sub_id', 's.status as sub_status', 's.keep_performance', 's.price as sub_price']);
        // 退订订阅是否存在 1:1 支出交易（批量交易剔除边界，与 index() 的 3/3b 同口径）
        $refundedSubIds = $txnRows->where('sub_status', 'refunded')->pluck('sub_id')->filter()->unique()->values();
        $oneToOnePaid = [];
        if ($refundedSubIds->isNotEmpty()) {
            $oneToOnePaid = DB::table('transactions as tp')
                ->join('subscriptions as s2', 's2.id', '=', 'tp.related_id')
                ->where('tp.related_type', 'App\\Models\\Subscription')
                ->whereIn('tp.related_id', $refundedSubIds)
                ->whereIn('tp.type', ['purchase', 'deduction', 'renew'])
                ->where('tp.amount', '<', 0)
                ->whereRaw('ABS(tp.amount) <= s2.price + 0.01')
                ->pluck('tp.related_id')->flip()->all();
        }
        $transactions = $txnRows->map(function ($t) use ($oneToOnePaid) {
            $subOk = !$t->sub_id || $t->sub_status !== 'refunded' || $t->keep_performance;
            $effect = 0.0;
            if ((float) $t->amount < 0 && !in_array($t->type, Transaction::SPENDING_EXCLUDE_TYPES)) {
                // 批量交易（金额>关联订阅price）即使订阅已退订也计入，与 3-过滤一致
                if ($subOk || abs((float) $t->amount) > (float) $t->sub_price + 0.01) {
                    $effect = abs((float) $t->amount); // 计入消费
                }
            } elseif (in_array($t->type, [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
                && (float) $t->amount > 0) {
                // 保留业绩的退订退款不冲减（人工干预，业绩完全保留），与 index() 3b 同口径
                $keptRefund = $t->sub_id && $t->sub_status === 'refunded' && $t->keep_performance;
                if (!$keptRefund && ($subOk || !isset($oneToOnePaid[$t->sub_id]))) {
                    $effect = -(float) $t->amount; // 退款冲减消费
                }
            }
            return [
                'id' => $t->id, 'type' => $t->type, 'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after, 'description' => $t->description,
                'created_at' => (string) $t->created_at, 'sub_id' => $t->sub_id,
                'spending_effect' => round($effect, 2),
            ];
        })->values();

        // ── 转正订阅 ID（1a+ 同口径） ──
        $convertSubIds = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->where('transactions.customer_id', $cid)
            ->where('transactions.type', Transaction::TYPE_DEDUCTION)
            ->where('transactions.description', 'like', '测试转正%')
            ->where('transactions.amount', '<', 0)
            ->whereBetween('transactions.created_at', [$periodStart, $periodEnd])
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->pluck('subscriptions.id');

        // ── 2. 新开订阅 IP 成本（4a/6a + 转正 6a+） ──
        $newSubRows = DB::table('subscriptions')
            ->leftJoin('proxy_ips', 'proxy_ips.id', '=', 'subscriptions.proxy_ip_id')
            ->where(fn($q) => $costCustWhere($q))
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where(function ($q) use ($periodStart, $periodEnd, $convertSubIds) {
                $q->whereBetween('subscriptions.started_at', [$periodStart, $periodEnd]);
                if ($convertSubIds->isNotEmpty()) {
                    $q->orWhereIn('subscriptions.id', $convertSubIds);
                }
            })
            ->select('subscriptions.id', 'proxy_ips.ip_address', 'subscriptions.started_at',
                'subscriptions.price', 'subscriptions.status',
                DB::raw("COALESCE(subscriptions.sales_cost, 0) as sales_cost_m"),
                'subscriptions.hard_cost', 'subscriptions.proxy_ip_id',
                DB::raw("{$initialMonthsExpr} as months"))
            ->get();

        // IP 硬成本解析（同 index() 的 resolveIpHardCost 简化版）
        $ipHardResolver = $this->buildIpHardCostResolver($newSubRows->pluck('proxy_ip_id')->filter()->all());

        $isConvert = $convertSubIds->flip();
        $newSubs = $newSubRows->map(function ($s) use ($ipHardResolver, $isConvert) {
            $hardM = $ipHardResolver($s);
            return [
                'sub_id' => $s->id, 'ip' => $s->ip_address, 'started_at' => (string) $s->started_at,
                'status' => $s->status, 'price' => (float) $s->price,
                'months' => (float) $s->months, 'is_convert' => isset($isConvert[$s->id]),
                'sales_cost_m' => (float) $s->sales_cost_m, 'hard_cost_m' => $hardM,
                'sales_subtotal' => round((float) $s->sales_cost_m * (float) $s->months, 2),
                'hard_subtotal' => round($hardM * (float) $s->months, 2),
            ];
        })->values();

        // ── 3. 新开/转正订阅的中转成本（4b/6c + 4b+/6c+） ──
        $newFwdRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('proxy_ips', 'proxy_ips.id', '=', 'subscriptions.proxy_ip_id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhere($q))
            ->where('subscriptions.is_test', false)
            ->whereIn('forward_rules.status', ['active', 'deleted'])
            ->where(fn($q) => $costSubFilterJoined($q))
            ->where(function ($q) use ($periodStart, $periodEnd, $convertSubIds) {
                $q->whereBetween('subscriptions.started_at', [$periodStart, $periodEnd]);
                if ($convertSubIds->isNotEmpty()) {
                    $q->orWhereIn('subscriptions.id', $convertSubIds);
                }
            })
            ->where('forward_rules.created_at', '<=', $periodEnd)
            ->select('forward_rules.id as rule_id', 'subscriptions.id as sub_id', 'proxy_ips.ip_address',
                'forward_plans.name as plan_name', 'forward_rules.status', 'forward_rules.forward_fee',
                'forward_rules.created_at', 'forward_rules.updated_at',
                DB::raw("COALESCE(forward_plans.cost_price, 0) as cost_m"),
                DB::raw("COALESCE(forward_plans.hard_cost_price, forward_plans.cost_price, 0) as hard_m"),
                DB::raw("ROUND({$fwdMonthsExpr}, 4) as months"))
            ->get();
        $newFwdRules = $newFwdRows->map(fn($r) => [
            'rule_id' => $r->rule_id, 'sub_id' => $r->sub_id, 'ip' => $r->ip_address,
            'plan' => $r->plan_name, 'status' => $r->status, 'fee' => (float) $r->forward_fee,
            'created_at' => (string) $r->created_at,
            'deleted_at' => $r->status === 'deleted' ? (string) $r->updated_at : null,
            'months' => (float) $r->months,
            'cost_m' => (float) $r->cost_m, 'hard_m' => (float) $r->hard_m,
            'sales_subtotal' => round((float) $r->cost_m * (float) $r->months, 2),
            'hard_subtotal' => round((float) $r->hard_m * (float) $r->months, 2),
        ])->values();

        // ── 4. 续费成本（4c IP + 4d 中转，按订阅聚合） ──
        $renewIpRows = DB::table('transactions')
            ->join('subscriptions', function ($join) {
                $join->on('transactions.related_id', '=', 'subscriptions.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription');
            })
            ->leftJoin('proxy_ips', 'proxy_ips.id', '=', 'subscriptions.proxy_ip_id')
            ->where('transactions.customer_id', $cid)
            ->where('transactions.type', Transaction::TYPE_RENEW)
            ->where('transactions.amount', '<', 0)
            ->whereBetween('transactions.created_at', [$periodStart, $periodEnd])
            ->where('subscriptions.is_test', false)
            ->where(fn($q) => $costSubFilterJoined($q))
            ->select('subscriptions.id as sub_id', 'proxy_ips.ip_address',
                DB::raw("COALESCE(subscriptions.sales_cost, 0) as sales_cost_m"),
                'subscriptions.hard_cost', 'subscriptions.proxy_ip_id',
                DB::raw('ABS(SUM(transactions.amount)) as txn_total'),
                DB::raw("GREATEST(ROUND(ABS(SUM(transactions.amount)) * {$monthsExpr} / NULLIF(subscriptions.price, 0)), 1) as renew_months"))
            ->groupBy('subscriptions.id', 'proxy_ips.ip_address', 'subscriptions.sales_cost',
                'subscriptions.hard_cost', 'subscriptions.proxy_ip_id',
                'subscriptions.duration', 'subscriptions.unit', 'subscriptions.price')
            ->get();

        // 续费时挂着的中转（4d 条件：规则先于续费存在，deleted 须晚于续费删除）
        $renewFwdMap = [];
        if ($renewIpRows->isNotEmpty()) {
            $renewFwdRows = DB::table('transactions')
                ->join('subscriptions', function ($join) {
                    $join->on('transactions.related_id', '=', 'subscriptions.id')
                        ->where('transactions.related_type', 'App\\Models\\Subscription');
                })
                ->join('forward_rules', function ($join) {
                    $join->on('forward_rules.subscription_id', '=', 'subscriptions.id')
                        ->whereColumn('forward_rules.created_at', '<=', 'transactions.created_at')
                        ->where(function ($q) {
                            $q->where('forward_rules.status', 'active')
                              ->orWhere(function ($q2) {
                                  $q2->where('forward_rules.status', 'deleted')
                                     ->whereColumn('forward_rules.updated_at', '>', 'transactions.created_at');
                              });
                        });
                })
                ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
                ->where('transactions.customer_id', $cid)
                ->where('transactions.type', Transaction::TYPE_RENEW)
                ->where('transactions.amount', '<', 0)
                ->whereBetween('transactions.created_at', [$periodStart, $periodEnd])
                ->where('subscriptions.is_test', false)
                ->where(fn($q) => $costSubFilterJoined($q))
                ->select('subscriptions.id as sub_id', 'forward_plans.name as plan_name',
                    DB::raw("COALESCE(forward_plans.cost_price, 0) as cost_m"),
                    DB::raw("COALESCE(forward_plans.hard_cost_price, forward_plans.cost_price, 0) as hard_m"))
                ->groupBy('subscriptions.id', 'forward_plans.name', 'forward_plans.cost_price', 'forward_plans.hard_cost_price')
                ->get();
            foreach ($renewFwdRows as $r) {
                $renewFwdMap[$r->sub_id] = $r;
            }
        }

        $renewHardResolver = $this->buildIpHardCostResolver($renewIpRows->pluck('proxy_ip_id')->filter()->all());
        $renewals = $renewIpRows->map(function ($s) use ($renewFwdMap, $renewHardResolver) {
            $fwd = $renewFwdMap[$s->sub_id] ?? null;
            $hardM = $renewHardResolver($s);
            $months = (int) $s->renew_months;
            return [
                'sub_id' => $s->sub_id, 'ip' => $s->ip_address,
                'txn_total' => (float) $s->txn_total, 'renew_months' => $months,
                'ip_sales_cost_m' => (float) $s->sales_cost_m, 'ip_hard_cost_m' => $hardM,
                'ip_sales_subtotal' => round((float) $s->sales_cost_m * $months, 2),
                'ip_hard_subtotal' => round($hardM * $months, 2),
                'fwd_plan' => $fwd->plan_name ?? null,
                'fwd_cost_m' => $fwd ? (float) $fwd->cost_m : 0,
                'fwd_hard_m' => $fwd ? (float) $fwd->hard_m : 0,
                'fwd_sales_subtotal' => $fwd ? round((float) $fwd->cost_m * $months, 2) : 0,
                'fwd_hard_subtotal' => $fwd ? round((float) $fwd->hard_m * $months, 2) : 0,
            ];
        })->values();

        // ── 5. 中途升级中转（4e：订阅非本期新开、规则本期创建） ──
        $upgradeRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('proxy_ips', 'proxy_ips.id', '=', 'subscriptions.proxy_ip_id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where(fn($q) => $costCustWhere($q))
            ->where('subscriptions.is_test', false)
            ->whereIn('forward_rules.status', ['active', 'deleted'])
            ->where(fn($q) => $costSubFilterJoined($q))
            ->whereBetween('forward_rules.created_at', [$periodStart, $periodEnd])
            ->where('subscriptions.started_at', '<', $periodStart)
            ->select('forward_rules.id as rule_id', 'subscriptions.id as sub_id', 'proxy_ips.ip_address',
                'forward_plans.name as plan_name', 'forward_rules.status', 'forward_rules.forward_fee',
                'forward_rules.created_at',
                DB::raw("COALESCE(forward_plans.cost_price, 0) as cost_m"),
                DB::raw("COALESCE(forward_plans.hard_cost_price, forward_plans.cost_price, 0) as hard_m"),
                DB::raw("ROUND({$upgradeFwdMonthsExpr}, 4) as months"))
            ->get();
        $upgradeFwdRules = $upgradeRows->map(fn($r) => [
            'rule_id' => $r->rule_id, 'sub_id' => $r->sub_id, 'ip' => $r->ip_address,
            'plan' => $r->plan_name, 'status' => $r->status, 'fee' => (float) $r->forward_fee,
            'created_at' => (string) $r->created_at, 'months' => (float) $r->months,
            'cost_m' => (float) $r->cost_m, 'hard_m' => (float) $r->hard_m,
            'sales_subtotal' => round((float) $r->cost_m * (float) $r->months, 2),
            'hard_subtotal' => round((float) $r->hard_m * (float) $r->months, 2),
        ])->values();

        // ── 6. 返佣（4f） ──
        $commissions = DB::table('referral_commissions')
            ->where('referee_id', $cid)
            ->whereIn('status', ['pending', 'credited'])
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderBy('id')
            ->get(['id', 'referrer_id', 'commission_amount', 'status', 'trigger_type', 'created_at']);

        // ── 7. 手动条目（5） ──
        $manualEntries = DB::table('manual_stat_entries')
            ->where('customer_id', $cid)
            ->whereBetween('entry_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('entry_date')
            ->get(['id', 'entry_date', 'spending', 'sales_cost', 'hard_cost', 'note']);
        $oldManual = DB::table('manual_performances')
            ->where('customer_id', $cid)
            ->whereBetween('performance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('performance_date')
            ->get(['id', 'performance_date', 'amount', 'profit', 'note']);

        // ── 汇总（供前端与列表行核对） ──
        $summary = [
            'spending' => round($transactions->sum('spending_effect')
                + $manualEntries->sum('spending') + $oldManual->sum('amount'), 2),
            'commission' => round($commissions->sum('commission_amount'), 2),
            'new_ip_sales_cost' => round($newSubs->sum('sales_subtotal'), 2),
            'new_ip_hard_cost' => round($newSubs->sum('hard_subtotal'), 2),
            'new_fwd_sales_cost' => round($newFwdRules->sum('sales_subtotal'), 2),
            'new_fwd_hard_cost' => round($newFwdRules->sum('hard_subtotal'), 2),
            'renew_ip_sales_cost' => round($renewals->sum('ip_sales_subtotal'), 2),
            'renew_ip_hard_cost' => round($renewals->sum('ip_hard_subtotal'), 2),
            'renew_fwd_sales_cost' => round($renewals->sum('fwd_sales_subtotal'), 2),
            'renew_fwd_hard_cost' => round($renewals->sum('fwd_hard_subtotal'), 2),
            'upgrade_fwd_sales_cost' => round($upgradeFwdRules->sum('sales_subtotal'), 2),
            'upgrade_fwd_hard_cost' => round($upgradeFwdRules->sum('hard_subtotal'), 2),
            'manual_spending' => round($manualEntries->sum('spending') + $oldManual->sum('amount'), 2),
            'manual_cost' => round($manualEntries->sum('sales_cost')
                + ($oldManual->sum('amount') - $oldManual->sum('profit')), 2),
            'manual_hard_cost' => round($manualEntries->sum('hard_cost'), 2),
        ];

        return $this->success([
            'customer' => $customer,
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'transactions' => $transactions,
            'new_subs' => $newSubs,
            'new_fwd_rules' => $newFwdRules,
            'renewals' => $renewals,
            'upgrade_fwd_rules' => $upgradeFwdRules,
            'commissions' => $commissions,
            'manual_entries' => $manualEntries,
            'old_manual_entries' => $oldManual,
            'summary' => $summary,
        ]);
    }

    /**
     * 构建 IP 硬成本解析闭包（index() 内 resolveIpHardCost 的可复用版）：
     * 优先 subscriptions.hard_cost，其次上游产品目录 cost_price，最后回退 sales_cost
     */
    private function buildIpHardCostResolver(array $proxyIpIds): \Closure
    {
        $sparkCosts = [];
        try {
            foreach (\App\Services\SparkStockCacheService::allProducts() as $p) {
                if (isset($p['product_id'], $p['cost_price']) && (float) $p['cost_price'] > 0) {
                    $sparkCosts[$p['product_id']] = (float) $p['cost_price'];
                }
            }
            foreach (\App\Services\SparkStockCacheService::everStockedProducts() as $pid => $p) {
                if (!isset($sparkCosts[$pid]) && isset($p['cost_price']) && (float) $p['cost_price'] > 0) {
                    $sparkCosts[$pid] = (float) $p['cost_price'];
                }
            }
        } catch (\Throwable) {}
        $ipipvCosts = [];
        try {
            foreach (\App\Services\IpipvStockCacheService::products() as $p) {
                $pno = $p['product_no'] ?? $p['productNo'] ?? null;
                $price = $p['cost_price'] ?? $p['unitPrice'] ?? null;
                if ($pno && $price !== null && (float) $price > 0) {
                    $ipipvCosts[$pno] = (float) $price;
                }
            }
        } catch (\Throwable) {}

        $sparkProductMap = [];
        $ipipvProductMap = [];
        if (!empty($proxyIpIds)) {
            foreach (DB::table('spark_instances')
                ->join('spark_orders', 'spark_orders.id', '=', 'spark_instances.spark_order_id')
                ->whereIn('spark_instances.proxy_ip_id', $proxyIpIds)
                ->select('spark_instances.proxy_ip_id', 'spark_orders.product_id')->get() as $row) {
                $sparkProductMap[$row->proxy_ip_id] = $row->product_id;
            }
            foreach (DB::table('proxy_ips')
                ->join('ipipv_instances', 'ipipv_instances.instance_no', '=', 'proxy_ips.ipipv_instance_id')
                ->join('ipipv_orders', 'ipipv_orders.id', '=', 'ipipv_instances.ipipv_order_id')
                ->whereIn('proxy_ips.id', $proxyIpIds)
                ->whereNotNull('proxy_ips.ipipv_instance_id')
                ->select('proxy_ips.id as proxy_ip_id', 'ipipv_orders.product_no')->get() as $row) {
                $ipipvProductMap[$row->proxy_ip_id] = $row->product_no;
            }
        }
        $ipipvOverride = \App\Models\SystemConfig::get('cost.ipipv_hard_cost_override');

        return function ($sub) use ($sparkCosts, $ipipvCosts, $sparkProductMap, $ipipvProductMap, $ipipvOverride): float {
            if ($sub->hard_cost && (float) $sub->hard_cost > 0) {
                return (float) $sub->hard_cost;
            }
            $pid = $sub->proxy_ip_id;
            if ($pid && isset($sparkProductMap[$pid], $sparkCosts[$sparkProductMap[$pid]])) {
                return $sparkCosts[$sparkProductMap[$pid]];
            }
            if ($pid && isset($ipipvProductMap[$pid])) {
                if ($ipipvOverride !== null && (float) $ipipvOverride > 0) {
                    return (float) $ipipvOverride;
                }
                if (isset($ipipvCosts[$ipipvProductMap[$pid]])) {
                    return $ipipvCosts[$ipipvProductMap[$pid]];
                }
            }
            if (isset($sub->sales_cost_m) && (float) $sub->sales_cost_m > 0) {
                return (float) $sub->sales_cost_m;
            }
            return 0;
        };
    }

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
