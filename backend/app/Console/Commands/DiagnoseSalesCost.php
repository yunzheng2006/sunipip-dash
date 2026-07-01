<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseSalesCost extends Command
{
    protected $signature = 'diagnose:sales-cost {--month= : YYYY-MM format, default current month}';
    protected $description = '诊断销售成本缺失的原因';

    public function handle()
    {
        $month = $this->option('month') ?: now()->format('Y-m');
        $start = "{$month}-01 00:00:00";
        $end = \Carbon\Carbon::parse($start)->endOfMonth()->toDateTimeString();

        $this->info("诊断时段: {$start} ~ {$end}");
        $this->newLine();

        // 1. 有消费但无成本的客户
        $this->info('=== 1. 有消费但销售成本为0的客户（前20） ===');
        $rows = DB::select("
            SELECT c.id, c.customer_name, c.sales_person,
                ABS(SUM(t.amount)) as spending,
                (SELECT COUNT(*) FROM subscriptions s
                 WHERE s.customer_id = c.id AND s.is_test = 0
                 AND s.started_at >= ? AND s.started_at <= ?
                ) as new_subs,
                (SELECT COUNT(*) FROM subscriptions s
                 WHERE s.customer_id = c.id AND s.is_test = 0
                 AND s.started_at >= ? AND s.started_at <= ?
                 AND s.balance_deducted = 1
                ) as new_subs_deducted,
                (SELECT COUNT(*) FROM subscriptions s
                 WHERE s.customer_id = c.id AND s.is_test = 0
                 AND s.started_at >= ? AND s.started_at <= ?
                 AND s.sales_cost IS NOT NULL AND s.sales_cost > 0
                ) as new_subs_with_cost
            FROM customers c
            JOIN transactions t ON t.customer_id = c.id
                AND t.amount < 0
                AND t.type NOT IN ('refund', 'gateway_refund', 'admin_deduct', 'deduction')
                AND t.created_at >= ? AND t.created_at <= ?
            GROUP BY c.id, c.customer_name, c.sales_person
            HAVING spending > 50
            ORDER BY spending DESC
            LIMIT 20
        ", [$start, $end, $start, $end, $start, $end, $start, $end]);

        $this->table(
            ['ID', '客户', '业务员', '消费', '新订阅数', '已标记扣费', '有成本价'],
            collect($rows)->map(fn($r) => [
                $r->id, $r->customer_name, $r->sales_person,
                number_format($r->spending, 2),
                $r->new_subs, $r->new_subs_deducted, $r->new_subs_with_cost,
            ])
        );

        // 2. balance_deducted 分布
        $this->newLine();
        $this->info('=== 2. 时段内新订阅 balance_deducted 分布 ===');
        $dist = DB::select("
            SELECT
                balance_deducted,
                COUNT(*) as cnt,
                SUM(CASE WHEN sales_cost IS NULL OR sales_cost = 0 THEN 1 ELSE 0 END) as no_cost_cnt,
                SUM(CASE WHEN admin_set_price IS NOT NULL THEN 1 ELSE 0 END) as admin_cnt
            FROM subscriptions
            WHERE is_test = 0
              AND started_at >= ? AND started_at <= ?
            GROUP BY balance_deducted
        ", [$start, $end]);
        $this->table(
            ['balance_deducted', '总数', '无sales_cost', '管理员下单'],
            collect($dist)->map(fn($r) => [$r->balance_deducted, $r->cnt, $r->no_cost_cnt, $r->admin_cnt])
        );

        // 3. balance_deducted=true 但 sales_cost 为空的订阅（成本本身缺失）
        $this->newLine();
        $this->info('=== 3. balance_deducted=true 但 sales_cost=NULL/0 的订阅（前20） ===');
        $noCost = DB::select("
            SELECT s.id, s.customer_id, c.customer_name, s.price, s.sales_cost,
                   s.admin_set_price, s.remark, s.created_by, s.started_at
            FROM subscriptions s
            JOIN customers c ON c.id = s.customer_id
            WHERE s.is_test = 0
              AND s.balance_deducted = 1
              AND (s.sales_cost IS NULL OR s.sales_cost = 0)
              AND s.started_at >= ? AND s.started_at <= ?
            ORDER BY s.started_at DESC
            LIMIT 20
        ", [$start, $end]);
        $this->table(
            ['SubID', 'CustID', '客户', '售价', 'sales_cost', 'admin_price', 'remark', 'created_by', 'started_at'],
            collect($noCost)->map(fn($r) => [
                $r->id, $r->customer_id, $r->customer_name,
                $r->price, $r->sales_cost ?? 'NULL',
                $r->admin_set_price ?? '-', mb_substr($r->remark ?? '', 0, 15),
                $r->created_by, $r->started_at,
            ])
        );

        // 4. balance_deducted=false 的管理员订阅，看看是否有对应余额扣费交易
        $this->newLine();
        $this->info('=== 4. balance_deducted=false 的管理员订阅，检查是否有匹配的扣费交易（前20） ===');
        $missed = DB::select("
            SELECT s.id, s.customer_id, c.customer_name, s.price, s.sales_cost,
                   s.remark, s.started_at,
                   (SELECT COUNT(*) FROM transactions t
                    WHERE t.customer_id = s.customer_id
                      AND t.type = 'purchase'
                      AND t.amount < 0
                      AND ABS(TIMESTAMPDIFF(SECOND, t.created_at, s.started_at)) <= 300
                   ) as nearby_purchase_txns,
                   (SELECT GROUP_CONCAT(t.id, ':', t.description SEPARATOR ' | ')
                    FROM transactions t
                    WHERE t.customer_id = s.customer_id
                      AND t.type = 'purchase'
                      AND t.amount < 0
                      AND ABS(TIMESTAMPDIFF(SECOND, t.created_at, s.started_at)) <= 300
                    LIMIT 3
                   ) as txn_info
            FROM subscriptions s
            JOIN customers c ON c.id = s.customer_id
            WHERE s.is_test = 0
              AND s.balance_deducted = 0
              AND s.admin_set_price IS NOT NULL
              AND s.sales_cost IS NOT NULL AND s.sales_cost > 0
              AND s.started_at >= ? AND s.started_at <= ?
            ORDER BY s.started_at DESC
            LIMIT 20
        ", [$start, $end]);
        $this->table(
            ['SubID', 'CustID', '客户', '售价', 'sales_cost', 'remark', 'started_at', '附近扣费交易', '交易详情'],
            collect($missed)->map(fn($r) => [
                $r->id, $r->customer_id, $r->customer_name,
                $r->price, $r->sales_cost,
                mb_substr($r->remark ?? '', 0, 15), $r->started_at,
                $r->nearby_purchase_txns, mb_substr($r->txn_info ?? '无', 0, 50),
            ])
        );

        // 5. SparkOrder 的 payment_method 分布
        $this->newLine();
        $this->info('=== 5. 时段内 SparkOrder request_data.payment_method 分布 ===');
        $pmDist = DB::select("
            SELECT
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(request_data, '$.payment_method')), 'null') as pm,
                COUNT(*) as cnt
            FROM spark_orders
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY pm
        ", [$start, $end]);
        $this->table(['payment_method', '数量'], collect($pmDist)->map(fn($r) => [$r->pm, $r->cnt]));

        // 6. 迁移140是否已运行
        $this->newLine();
        $migrated = DB::table('migrations')
            ->where('migration', 'like', '%000140%')
            ->exists();
        $this->info('=== 6. 迁移 000140 状态: ' . ($migrated ? '已运行 ✓' : '未运行 ✗') . ' ===');

        $this->newLine();
        $this->info('诊断完成。');
    }
}
