<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairBalanceDeducted extends Command
{
    protected $signature = 'subscriptions:repair-balance-deducted {--dry-run : Preview without making changes}';
    protected $description = 'Fix subscriptions where balance was actually deducted but balance_deducted=false (transfers, forwarding charges)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — 不会修改任何数据 ===');
        }

        $subIds = DB::table('transactions')
            ->where('related_type', 'App\\Models\\Subscription')
            ->whereNotNull('related_id')
            ->where('amount', '<', 0)
            ->whereNotIn('type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
            ->pluck('related_id')
            ->unique();

        $rows = DB::table('subscriptions')
            ->whereIn('id', $subIds)
            ->where('balance_deducted', false)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('没有需要修复的记录');
            return 0;
        }

        $this->info("找到 {$rows->count()} 条 balance_deducted=false 但有真实扣款的订阅：");
        $this->newLine();

        $fixed = 0;
        foreach ($rows as $sub) {
            $txns = DB::table('transactions')
                ->where('related_type', 'App\\Models\\Subscription')
                ->where('related_id', $sub->id)
                ->where('amount', '<', 0)
                ->whereNotIn('type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
                ->get();

            $totalDeducted = $txns->sum(fn($t) => abs((float) $t->amount));
            $types = $txns->pluck('type')->unique()->implode(',');
            $descriptions = $txns->pluck('description')->implode(' | ');

            $customer = DB::table('customers')->find($sub->customer_id);
            $customerName = $customer->customer_name ?? "#{$sub->customer_id}";

            $this->line(sprintf(
                "  订阅#%d 客户[%s] 状态=%s 扣款合计=¥%.2f 交易类型=[%s]",
                $sub->id, $customerName, $sub->status, $totalDeducted, $types
            ));
            $this->line("    描述: {$descriptions}");

            if (!$dryRun) {
                DB::table('subscriptions')
                    ->where('id', $sub->id)
                    ->update(['balance_deducted' => true]);
                $fixed++;
            } else {
                $fixed++;
            }
        }

        $this->newLine();
        $this->info("=== 汇总 ===");
        $this->info("  需修复: {$fixed}");

        if ($dryRun && $fixed > 0) {
            $this->newLine();
            $this->warn("以上为预览，执行修复请去掉 --dry-run 参数");
        }

        return 0;
    }
}
