<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixRefundPerformance extends Command
{
    protected $signature = 'sales:fix-refund-performance {--dry-run : Preview without making changes}';
    protected $description = '诊断并修复退款/退订相关的业绩数据';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '=== 预览模式 ===' : '=== 执行修复 ===');

        $refundedSubs = Subscription::where('status', 'refunded')
            ->with('customer:id,customer_name')
            ->get();
        $this->info("退款订阅总数: {$refundedSubs->count()}");
        $this->newLine();

        $issues = 0;
        $fixed = 0;

        foreach ($refundedSubs as $sub) {
            $custName = $sub->customer?->customer_name ?? '未知';

            // 找到该订阅关联的所有交易
            $txns = Transaction::where('related_type', Subscription::class)
                ->where('related_id', $sub->id)
                ->orderBy('created_at')
                ->get();

            $purchaseTxn = $txns->first(fn($t) => $t->amount < 0);
            $refundTxn = $txns->first(fn($t) => $t->amount > 0);

            $this->line("─── 订阅 #{$sub->id} ({$custName}) ───");
            $this->line("  订阅价格: ¥{$sub->price}  开通: {$sub->started_at}  退款: {$sub->refunded_at}");

            if ($purchaseTxn) {
                $this->line("  扣费交易: #{$purchaseTxn->id} type={$purchaseTxn->type} amount={$purchaseTxn->amount}");
            } else {
                $this->warn("  [!] 没有找到扣费交易记录");
            }

            if ($refundTxn) {
                $this->line("  退款交易: #{$refundTxn->id} type={$refundTxn->type} amount=+{$refundTxn->amount}");
            } else {
                $this->warn("  [!] 没有找到退款交易记录");
            }

            // 问题1: 退款交易type不是'refund'
            if ($refundTxn && $refundTxn->type !== 'refund') {
                $issues++;
                $this->error("  [问题] 退款交易 type='{$refundTxn->type}' 应为 'refund'");
                if (!$dryRun) {
                    $refundTxn->update(['type' => 'refund']);
                    $fixed++;
                    $this->info("    -> 已修复为 type='refund'");
                }
            }

            // 问题2: 扣费交易type是'refund'（被误标，会导致业绩被排除）
            if ($purchaseTxn && $purchaseTxn->type === 'refund') {
                $issues++;
                $this->error("  [问题] 扣费交易 type='refund' 导致业绩被排除");
                if (!$dryRun) {
                    $purchaseTxn->update(['type' => 'purchase']);
                    $fixed++;
                    $this->info("    -> 已修复为 type='purchase'");
                }
            }

            // 问题3: 扣费交易type是'withdrawal'或'adjustment_out'（也会被排除）
            if ($purchaseTxn && in_array($purchaseTxn->type, ['withdrawal', 'adjustment_out'])) {
                $issues++;
                $this->error("  [问题] 扣费交易 type='{$purchaseTxn->type}' 导致业绩被排除");
                if (!$dryRun) {
                    $purchaseTxn->update(['type' => 'purchase']);
                    $fixed++;
                    $this->info("    -> 已修复为 type='purchase'");
                }
            }

            // 问题4: 负金额的refund类型（错误双重减少）
            $badNegRefunds = $txns->filter(fn($t) => $t->amount < 0 && $t->type === 'refund');
            foreach ($badNegRefunds as $bad) {
                $issues++;
                $this->error("  [问题] 交易#{$bad->id} 负金额 type='refund'（扣费被标为退款类型）");
                if (!$dryRun) {
                    $bad->update(['type' => 'purchase']);
                    $fixed++;
                    $this->info("    -> 已修复为 type='purchase'");
                }
            }

            // 问题5: 没有扣费记录但有退款（说明原始购买交易丢失）
            if (!$purchaseTxn && $refundTxn) {
                $issues++;
                $this->error("  [问题] 有退款但无原始扣费交易 — 无法自动修复");
            }
        }

        // 全局检查: 被误标为 refund 的负金额交易（可能不关联具体订阅）
        $this->newLine();
        $this->info('=== 全局检查 ===');

        $badGlobal = Transaction::where('type', 'refund')->where('amount', '<', 0)->get();
        if ($badGlobal->count() > 0) {
            $this->error("发现 {$badGlobal->count()} 条负金额的 refund 交易（这会被排除在业绩之外）:");
            foreach ($badGlobal as $bg) {
                $this->line("  交易#{$bg->id} customer={$bg->customer_id} amount={$bg->amount} desc={$bg->description}");
                $issues++;
                if (!$dryRun) {
                    $bg->update(['type' => 'deduction']);
                    $fixed++;
                    $this->info("    -> 已修复为 type='deduction'");
                }
            }
        } else {
            $this->info('无负金额 refund 交易');
        }

        // 检查所有被排除在业绩之外的购买相关交易
        $excludedPurchases = Transaction::whereIn('type', ['withdrawal', 'adjustment_out', 'refund'])
            ->where('amount', '<', 0)
            ->where('related_type', Subscription::class)
            ->get();
        if ($excludedPurchases->count() > 0) {
            $this->error("发现 {$excludedPurchases->count()} 条订阅扣费被标记为排除类型:");
            foreach ($excludedPurchases as $ep) {
                $this->line("  交易#{$ep->id} type={$ep->type} customer={$ep->customer_id} amount={$ep->amount} sub={$ep->related_id}");
                $issues++;
                if (!$dryRun) {
                    $ep->update(['type' => 'deduction']);
                    $fixed++;
                    $this->info("    -> 已修复为 type='deduction'");
                }
            }
        } else {
            $this->info('无被错误排除的订阅扣费交易');
        }

        // 汇总
        $this->newLine();
        $this->info("=== 结果 ===");
        $this->info("检查退款订阅: {$refundedSubs->count()}");
        $this->info("发现问题: {$issues}");
        if (!$dryRun) {
            $this->info("已修复: {$fixed}");
        } else if ($issues > 0) {
            $this->warn("去掉 --dry-run 执行修复");
        }

        return self::SUCCESS;
    }
}
