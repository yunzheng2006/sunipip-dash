<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseCustomerCost extends Command
{
    protected $signature = 'diagnose:customer-cost {names* : 客户名称}';
    protected $description = '诊断指定客户的销售成本缺失原因';

    public function handle()
    {
        $names = $this->argument('names');
        $month = now()->format('Y-m');
        $start = "{$month}-01 00:00:00";
        $end = \Carbon\Carbon::parse($start)->endOfMonth()->toDateTimeString();

        $customers = DB::table('customers')
            ->whereIn('customer_name', $names)
            ->get(['id', 'customer_name', 'sales_person', 'balance']);

        if ($customers->isEmpty()) {
            $this->error('未找到这些客户');
            return;
        }

        foreach ($customers as $c) {
            $this->newLine();
            $this->info("========== {$c->customer_name} (ID:{$c->id}, 业务员:{$c->sales_person}, 余额:{$c->balance}) ==========");

            // 1. 本月订阅
            $subs = DB::select("
                SELECT id, price, sales_cost, balance_deducted, admin_set_price,
                       status, is_test, auto_renew, duration, unit,
                       SUBSTRING(remark, 1, 30) as remark, created_by, started_at
                FROM subscriptions
                WHERE customer_id = ? AND is_test = 0
                  AND started_at >= ? AND started_at <= ?
                ORDER BY started_at DESC
            ", [$c->id, $start, $end]);

            $this->info("--- 本月新订阅 ({$start} ~ {$end}) ---");
            if (empty($subs)) {
                $this->warn('  无本月新订阅');
            } else {
                $this->table(
                    ['SubID', '售价', 'sales_cost', 'bal_deducted', 'admin_price', 'status', 'auto_renew', '时长', 'remark', 'created_by', 'started_at'],
                    collect($subs)->map(fn($s) => [
                        $s->id, $s->price, $s->sales_cost ?? 'NULL',
                        $s->balance_deducted ? 'YES' : 'NO',
                        $s->admin_set_price ?? '-',
                        $s->status, $s->auto_renew,
                        "{$s->duration}u{$s->unit}",
                        $s->remark, $s->created_by, $s->started_at,
                    ])
                );
            }

            // 2. 本月交易
            $txns = DB::select("
                SELECT t.id, t.type, t.amount, t.related_type, t.related_id,
                       SUBSTRING(t.description, 1, 40) as description,
                       t.created_at,
                       s.balance_deducted as sub_bal_deducted
                FROM transactions t
                LEFT JOIN subscriptions s ON t.related_id = s.id
                    AND t.related_type = 'App\\\\Models\\\\Subscription'
                WHERE t.customer_id = ?
                  AND t.created_at >= ? AND t.created_at <= ?
                ORDER BY t.created_at DESC
            ", [$c->id, $start, $end]);

            $this->info("--- 本月交易 ---");
            if (empty($txns)) {
                $this->warn('  无本月交易');
            } else {
                $this->table(
                    ['TxnID', 'type', 'amount', 'related_id', 'sub_bal_deducted', 'description', 'created_at'],
                    collect($txns)->map(fn($t) => [
                        $t->id, $t->type, $t->amount,
                        $t->related_id ?? '-',
                        $t->sub_bal_deducted === null ? '-' : ($t->sub_bal_deducted ? 'YES' : 'NO'),
                        $t->description, $t->created_at,
                    ])
                );
            }

            // 3. 所有活跃订阅（含历史开通的）的续费情况
            $renewSubs = DB::select("
                SELECT s.id, s.price, s.sales_cost, s.balance_deducted, s.admin_set_price,
                       s.status, s.started_at,
                       (SELECT COUNT(*) FROM transactions t
                        WHERE t.related_id = s.id
                          AND t.related_type = 'App\\\\Models\\\\Subscription'
                          AND t.type = 'renew'
                          AND t.created_at >= ? AND t.created_at <= ?
                       ) as renew_txn_count
                FROM subscriptions s
                WHERE s.customer_id = ? AND s.is_test = 0 AND s.status = 'active'
                HAVING renew_txn_count > 0
                ORDER BY renew_txn_count DESC
                LIMIT 10
            ", [$start, $end, $c->id]);

            $this->info("--- 本月有续费交易的活跃订阅 ---");
            if (empty($renewSubs)) {
                $this->warn('  无本月续费');
            } else {
                $this->table(
                    ['SubID', '售价', 'sales_cost', 'bal_deducted', 'admin_price', 'status', 'started_at', '续费次数'],
                    collect($renewSubs)->map(fn($s) => [
                        $s->id, $s->price, $s->sales_cost ?? 'NULL',
                        $s->balance_deducted ? 'YES' : 'NO',
                        $s->admin_set_price ?? '-',
                        $s->status, $s->started_at, $s->renew_txn_count,
                    ])
                );
            }

            // 4. 手动业绩
            $manual = DB::select("
                SELECT * FROM manual_stat_entries
                WHERE customer_id = ?
                  AND entry_date >= ? AND entry_date <= ?
            ", [$c->id, "{$month}-01", \Carbon\Carbon::parse($start)->endOfMonth()->toDateString()]);
            $oldManual = DB::select("
                SELECT * FROM manual_performances
                WHERE customer_id = ?
                  AND performance_date >= ? AND performance_date <= ?
            ", [$c->id, "{$month}-01", \Carbon\Carbon::parse($start)->endOfMonth()->toDateString()]);

            if (!empty($manual) || !empty($oldManual)) {
                $this->info("--- 手动业绩条目 ---");
                foreach ($manual as $m) {
                    $this->line("  [新表] spending={$m->spending}, cost={$m->sales_cost}, date={$m->entry_date}, note={$m->note}");
                }
                foreach ($oldManual as $m) {
                    $this->line("  [旧表] amount={$m->amount}, profit={$m->profit}, date={$m->performance_date}");
                }
            } else {
                $this->info("--- 手动业绩: 无 ---");
            }
        }

        $this->newLine();
        $this->info('诊断完成。');
    }
}
