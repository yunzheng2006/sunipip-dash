<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixIpipvPurchaseLinks extends Command
{
    protected $signature = 'fix:ipipv-purchase-links {--dry-run}';
    protected $description = '修复 IPIPv 开通购买交易缺失 related_id 的问题';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');

        $orphans = Transaction::where('type', Transaction::TYPE_PURCHASE)
            ->where('description', 'like', '开通订单扣费%')
            ->where('amount', '<', 0)
            ->where(function ($q) {
                $q->whereNull('related_id')
                  ->orWhere('related_id', 0);
            })
            ->orderBy('created_at')
            ->get();

        $this->line("无 related_id 的购买交易: {$orphans->count()} 笔");
        $this->newLine();

        $claimedSubIds = [];
        $fixCount = 0;
        $failCount = 0;

        foreach ($orphans as $txn) {
            $amt = abs((float) $txn->amount);
            $custName = DB::table('customers')->where('id', $txn->customer_id)->value('customer_name') ?: "#{$txn->customer_id}";

            $candidates = Subscription::where('customer_id', $txn->customer_id)
                ->where('balance_deducted', true)
                ->where('started_at', '>=', date('Y-m-d H:i:s', strtotime($txn->created_at) - 300))
                ->where('started_at', '<=', date('Y-m-d H:i:s', strtotime($txn->created_at) + 300))
                ->whereNotIn('id', $claimedSubIds)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('transactions as t2')
                      ->whereColumn('t2.related_id', 'subscriptions.id')
                      ->where('t2.related_type', 'like', '%Subscription')
                      ->where('t2.type', 'purchase');
                })
                ->orderBy(DB::raw('ABS(TIMESTAMPDIFF(SECOND, started_at, ?))'))
                ->addBinding($txn->created_at, 'order')
                ->get();

            $match = $candidates->first();

            $this->line(str_repeat('─', 60));
            $this->line("Txn#{$txn->id} ¥{$amt} 客户:{$custName} {$txn->created_at}");

            if (!$match) {
                $this->warn("  ✗ 无法匹配订阅（±300秒内无未关联的订阅）");
                $failCount++;
                continue;
            }

            $diff = strtotime($match->started_at) - strtotime($txn->created_at);
            $this->info("  → Sub#{$match->id} status={$match->status} price={$match->price} started={$match->started_at} (差{$diff}秒)");

            if (abs((float) $match->price - $amt) > 0.01) {
                $this->warn("  ⚠ 金额不匹配: 交易={$amt} 订阅price={$match->price}");
            }

            $claimedSubIds[] = $match->id;
            $fixCount++;

            if (!$dryRun) {
                $txn->update([
                    'related_type' => Subscription::class,
                    'related_id'   => $match->id,
                ]);
                $this->info("  ✓ 已关联");
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 60));
        if ($dryRun) {
            $this->warn("可修复: {$fixCount} 笔, 无法匹配: {$failCount} 笔。去掉 --dry-run 执行。");
        } else {
            $this->info("已修复: {$fixCount} 笔, 无法匹配: {$failCount} 笔。");
        }

        return 0;
    }
}
