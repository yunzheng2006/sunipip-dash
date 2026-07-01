<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTransferredSubscriptions extends Command
{
    protected $signature = 'subscriptions:fix-transferred {--dry-run}';
    protected $description = '修复已划转但未收费的订阅：设置 transferred_from_customer_id 和 balance_deducted';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 找出 subscription.customer_id 与原始购买交易的 customer_id 不一致的订阅
        // 且尚未标记 transferred_from_customer_id
        // 且目标客户没有为该订阅付费（排除 charge_target=true 的情况）
        $rows = DB::table('subscriptions as s')
            ->join('transactions as purchase_txn', function ($join) {
                $join->on('purchase_txn.related_id', '=', 's.id')
                    ->where('purchase_txn.related_type', 'App\\Models\\Subscription')
                    ->where('purchase_txn.type', 'purchase')
                    ->where('purchase_txn.amount', '<', 0);
            })
            ->leftJoin('transactions as target_txn', function ($join) {
                $join->on('target_txn.related_id', '=', 's.id')
                    ->where('target_txn.related_type', 'App\\Models\\Subscription')
                    ->where('target_txn.type', 'purchase')
                    ->where('target_txn.amount', '<', 0)
                    ->whereColumn('target_txn.customer_id', 's.customer_id');
            })
            ->where('s.customer_id', '!=', DB::raw('purchase_txn.customer_id'))
            ->whereNull('s.transferred_from_customer_id')
            ->whereNull('target_txn.id')
            ->where('s.status', 'active')
            ->select(
                's.id as sub_id',
                's.customer_id as current_customer_id',
                'purchase_txn.customer_id as original_customer_id',
                's.balance_deducted',
                's.hard_cost',
                's.sales_cost'
            )
            ->distinct()
            ->get();

        if ($rows->isEmpty()) {
            $this->info('没有需要修复的记录');
            return 0;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "发现 {$rows->count()} 条划转未收费且未标记的订阅:");

        $fixed = 0;
        foreach ($rows as $row) {
            $this->line(sprintf(
                '  Sub#%d: 当前客户=%d → 原客户=%d, bd=%s, hc=%s, sc=%s',
                $row->sub_id, $row->current_customer_id, $row->original_customer_id,
                $row->balance_deducted ? 'Y' : 'N', $row->hard_cost, $row->sales_cost
            ));

            if (!$dryRun) {
                DB::table('subscriptions')
                    ->where('id', $row->sub_id)
                    ->update([
                        'transferred_from_customer_id' => $row->original_customer_id,
                        'balance_deducted' => true,
                    ]);
            }
            $fixed++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "修复: {$fixed} 条");
        return 0;
    }
}
