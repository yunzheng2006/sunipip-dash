<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairUnlinkedTransactions extends Command
{
    protected $signature = 'transactions:repair-unlinked {--dry-run : Preview without making changes}';
    protected $description = 'Link unlinked purchase transactions to their subscriptions by matching customer_id, amount, and timing';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — 不会修改任何数据 ===');
        }

        $unlinked = Transaction::whereNull('related_type')
            ->where('type', Transaction::TYPE_PURCHASE)
            ->where('amount', '<', 0)
            ->orderBy('id')
            ->get();

        $this->info("找到 {$unlinked->count()} 条未关联的 purchase 交易");

        $customerCache = [];
        $fixed = 0;
        $skipped = 0;

        foreach ($unlinked as $txn) {
            $amount = abs((float) $txn->amount);

            $customerName = $customerCache[$txn->customer_id] ?? null;
            if ($customerName === null) {
                $c = \App\Models\Customer::find($txn->customer_id);
                $customerName = $c ? ($c->customer_name ?: $c->display_name ?: "#{$c->id}") : "未知";
                $customerCache[$txn->customer_id] = $customerName;
            }

            $candidates = Subscription::where('customer_id', $txn->customer_id)
                ->where('created_at', '>=', $txn->created_at->subMinutes(1))
                ->where('created_at', '<=', $txn->created_at->addMinutes(5))
                ->where('balance_deducted', true)
                ->with('proxyIp')
                ->get();

            if ($candidates->isEmpty()) {
                $this->line("  txn#{$txn->id} (¥{$amount}, 客户: {$customerName}): 找不到匹配的订阅");
                $skipped++;
                continue;
            }

            $unlinkedSubs = $candidates->filter(function ($sub) {
                return !Transaction::where('related_type', Subscription::class)
                    ->where('related_id', $sub->id)
                    ->where('type', Transaction::TYPE_PURCHASE)
                    ->exists();
            });

            if ($unlinkedSubs->isEmpty()) {
                $this->line("  txn#{$txn->id} (¥{$amount}, 客户: {$customerName}): 候选订阅都已有关联交易");
                $skipped++;
                continue;
            }

            $subTotal = $unlinkedSubs->sum('price');
            $firstSub = $unlinkedSubs->first();

            $subDetails = $unlinkedSubs->map(function ($sub) {
                $ip = $sub->proxyIp?->ip_address ?: '无IP';
                $module = $sub->purchased_module ?: 'static';
                $status = $sub->status;
                return "sub#{$sub->id}({$ip}, {$module}, {$status})";
            })->join(', ');

            $this->info("  txn#{$txn->id} (¥{$amount}, 客户: {$customerName}) → {$subDetails} (总价¥{$subTotal})");

            if (!$dryRun) {
                $txn->update([
                    'related_type' => Subscription::class,
                    'related_id' => $firstSub->id,
                ]);
                $fixed++;
            } else {
                $fixed++;
            }
        }

        $this->info("完成: 修复 {$fixed} 条, 跳过 {$skipped} 条");

        return 0;
    }
}
