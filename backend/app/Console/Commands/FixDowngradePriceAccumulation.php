<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDowngradePriceAccumulation extends Command
{
    protected $signature = 'fix:downgrade-price-accumulation {--dry-run}';
    protected $description = '修复降级(downgrade)时未递减 subscription.price 导致的中转费累积问题';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');
        $this->newLine();

        // 找出通过 downgrade 删除的 forward_rules（排除 ResetForwardLocalBatch 的 "本地重置"）
        $deletedRules = DB::table('forward_rules')
            ->where('status', 'deleted')
            ->where('forward_fee', '>', 0)
            ->where(function ($q) {
                $q->whereNull('error_message')
                  ->orWhere('error_message', '')
                  ->orWhere('error_message', 'not like', '%本地重置%');
            })
            ->orderBy('subscription_id')
            ->orderBy('created_at')
            ->get();

        if ($deletedRules->isEmpty()) {
            $this->info('没有找到需要修复的记录。');
            return 0;
        }

        // 按 subscription_id 分组
        $grouped = $deletedRules->groupBy('subscription_id');

        $fixCount = 0;

        foreach ($grouped as $subId => $rules) {
            $sub = DB::table('subscriptions')->where('id', $subId)->first();
            if (!$sub || $sub->status !== 'active') {
                continue;
            }

            $customer = DB::table('customers')->where('id', $sub->customer_id)->first();
            $customerName = $customer ? $customer->customer_name : "客户#{$sub->customer_id}";
            $ip = DB::table('proxy_ips')->where('id', $sub->proxy_ip_id)->first();
            $ipAddr = $ip ? $ip->ip_address : '未知';

            // 计算应减去的总额（每条 downgrade 删除的 active 转发都应该 decrement）
            $excessTotal = 0;
            $timeline = [];

            foreach ($rules as $fr) {
                $feeAmount = (float) $fr->forward_fee;

                // 查 activity_log 确认是 downgrade 操作
                $downgradeLog = DB::table('activity_logs')
                    ->where('properties', 'like', "%subscriptions/{$subId}/downgrade%")
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($fr->updated_at) - 5))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($fr->updated_at) + 5))
                    ->first();

                // 查退款交易
                $refundTxn = DB::table('transactions')
                    ->where('related_id', $subId)
                    ->where('related_type', 'like', '%Subscription')
                    ->where('type', 'refund')
                    ->where('description', 'like', "%降级退差价 #{$subId}%")
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($fr->updated_at) - 5))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($fr->updated_at) + 5))
                    ->first();

                // 查该转发是否有对应的扣款交易（判断是否曾实际扣费）
                $deductTxn = DB::table('transactions')
                    ->where('related_id', $subId)
                    ->where('related_type', 'like', '%Subscription')
                    ->where('type', 'deduction')
                    ->where('description', 'like', '%中转费用%')
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($fr->created_at) - 5))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($fr->created_at) + 5))
                    ->first();

                $timeline[] = [
                    'step' => '附加转发',
                    'time' => $fr->created_at,
                    'detail' => sprintf(
                        'FR#%d forward_fee=¥%.2f %s → AttachForwardJob increment price +%.2f',
                        $fr->id,
                        $feeAmount,
                        $deductTxn ? '扣款=' . $deductTxn->amount : '未扣款(下期扣)',
                        $feeAmount
                    ),
                ];

                $timeline[] = [
                    'step' => '降级删除',
                    'time' => $fr->updated_at,
                    'detail' => sprintf(
                        'FR#%d downgrade删除 %s → 【BUG】未执行 decrement price -%.2f',
                        $fr->id,
                        $refundTxn ? '退款=+' . $refundTxn->amount : '未退款',
                        $feeAmount
                    ),
                ];

                $excessTotal += $feeAmount;
            }

            if ($excessTotal <= 0) {
                continue;
            }

            // 当前 active 的转发
            $activeFr = DB::table('forward_rules')
                ->where('subscription_id', $subId)
                ->where('status', 'active')
                ->first();

            $correctPrice = round((float) $sub->price - $excessTotal, 2);

            $fixCount++;
            $this->line(str_repeat('─', 70));
            $this->info(sprintf(
                '异常 #%d: Sub#%d | 客户: %s | IP: %s',
                $fixCount, $subId, $customerName, $ipAddr
            ));
            $this->line(sprintf(
                '  当前price=¥%.2f → 应修正为 ¥%.2f（多出 ¥%.2f）',
                $sub->price, $correctPrice, $excessTotal
            ));
            if ($activeFr) {
                $this->line(sprintf(
                    '  当前活跃转发: FR#%d fee=¥%.2f',
                    $activeFr->id, $activeFr->forward_fee
                ));
            } else {
                $this->line('  当前活跃转发: 无');
            }
            $this->newLine();

            $this->line('  操作时间线:');
            foreach ($timeline as $event) {
                $this->line(sprintf(
                    '    [%s] %s: %s',
                    $event['time'], $event['step'], $event['detail']
                ));
            }
            $this->newLine();

            $this->line(sprintf(
                '  原因: downgrade 移除转发时只删除了 forward_rule 并退款，'
                . '但没有执行 $subscription->decrement(\'price\', %.2f)，'
                . '导致中转费 ¥%.2f 残留在 subscription.price 中',
                $excessTotal, $excessTotal
            ));

            if (!$dryRun) {
                DB::table('subscriptions')
                    ->where('id', $subId)
                    ->update(['price' => $correctPrice]);
                $this->info(sprintf('  ✓ 已修复: price %.2f → %.2f', $sub->price, $correctPrice));
            }

            $this->newLine();
        }

        $this->line(str_repeat('─', 70));
        if ($fixCount === 0) {
            $this->info('所有订阅价格正确，无需修复。');
        } elseif ($dryRun) {
            $this->warn("共 {$fixCount} 条需修复。去掉 --dry-run 执行实际修复。");
        } else {
            $this->info("修复完成，共 {$fixCount} 条。");
        }

        return 0;
    }
}
