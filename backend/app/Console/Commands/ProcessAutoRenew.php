<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * 处理开启了 auto_renew 的订阅：每天凌晨自动扣费续费。
 *
 * 运行：每天 00:00 UTC+8（由 routes/console.php 注册）
 *
 * 逻辑：
 *   1. 扫描 auto_renew=1 && status='active' && 在未来 48h 内到期
 *   2. 每条尝试用当前价 + duration 续费一个周期
 *   3. 余额不足：
 *      - 到期 >24h（明天到期）→ 通知，明天重试
 *      - 到期 <=24h（今天到期）→ 最终失败，标记过期，释放 IP
 *   4. 失败通知按客户聚合，一个客户只发一条
 */
class ProcessAutoRenew extends Command
{
    protected $signature = 'subscriptions:auto-renew {--dry : 试运行}';
    protected $description = '扫描并处理开启了自动续费的订阅';

    public function handle(): int
    {
        $dryRun = $this->option('dry');
        if ($dryRun) $this->warn('[DRY RUN] 不会实际扣费');

        // Find subscriptions expiring within the next 48 hours with auto_renew enabled
        $subs = Subscription::with(['customer', 'proxyIp'])
            ->where('auto_renew', 1)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours(48))
            ->get();

        if ($subs->isEmpty()) {
            $this->info('No auto-renew subscriptions due.');
            return 0;
        }

        $this->info("Found {$subs->count()} subscriptions to process.");

        $renewed = 0;
        $failed = 0;
        $expired = 0;
        // Collect failures by customer for batched notification
        $failuresByCustomer = []; // customer_id => [{sub, reason, is_final}]

        $service = app(\App\Services\SubscriptionService::class);

        foreach ($subs as $sub) {
            $customer = $sub->customer;
            if (!$customer || (int) $customer->status !== 1) {
                continue;
            }

            $hoursUntilExpiry = now()->diffInHours($sub->expires_at, false);
            $isFinalAttempt = $hoursUntilExpiry <= 24; // Expires within 24h = today or already due

            if ($dryRun) {
                $this->line("  [DRY] #{$sub->id} {$customer->customer_name}: ¥{$sub->price}, expires in {$hoursUntilExpiry}h" . ($isFinalAttempt ? ' [FINAL]' : ''));
                continue;
            }

            try {
                $monthlyPrice = $service->calcRenewalMonthlyPrice($customer, $sub);
                $renewDuration = $sub->duration ?: 1;
                $renewUnit = $sub->unit ?: 3;
                $durationMonths = \App\Support\DurationHelper::toMonths($renewDuration, $renewUnit);
                $renewPrice = round($monthlyPrice * max($durationMonths, 1), 2);

                $service->renewOne($sub, [
                    'duration' => $renewDuration,
                    'unit' => $renewUnit,
                    'price' => $renewPrice,
                    'auto_renew_triggered' => true,
                ], null);

                $renewed++;
                $this->info("  ✓ #{$sub->id} renewed: ¥{$renewPrice}");

            } catch (\Exception $e) {
                $failed++;
                $reason = $e->getMessage();

                // 只有确定性失败（余额不足）才在最终日强制过期；
                // 数据库死锁/上游超时等临时性错误留给到期后的正常过期流程处理，
                // 避免有钱的客户因一次抖动被关停 + auto_renew 被永久关闭
                $isDeterministicFail = str_contains($reason, '余额不足');

                if ($isFinalAttempt && $isDeterministicFail) {
                    // Final attempt failed — expire the subscription
                    $sub->update(['status' => 'expired', 'auto_renew' => 0]);

                    // Release the IP (mark as expired, not available for reuse)
                    if ($sub->proxyIp) {
                        $sub->proxyIp->update([
                            'status' => 'expired',
                            'assigned_customer_id' => null,
                        ]);
                    }

                    $expired++;
                    $this->error("  ✗ #{$sub->id} FINAL FAIL → expired: {$reason}");
                } elseif ($isFinalAttempt) {
                    $this->warn("  ✗ #{$sub->id} 临时性错误，交由正常过期流程处理: {$reason}");
                } else {
                    $this->warn("  ✗ #{$sub->id} failed (will retry tomorrow): {$reason}");
                }

                // Collect for batched notification
                $customerId = $customer->id;
                if (!isset($failuresByCustomer[$customerId])) {
                    $failuresByCustomer[$customerId] = [
                        'customer' => $customer,
                        'items' => [],
                    ];
                }
                $failuresByCustomer[$customerId]['items'][] = [
                    'sub' => $sub,
                    'reason' => $reason,
                    'is_final' => $isFinalAttempt,
                ];
            }
        }

        // Recalculate VIP tier for customers who had successful renewals
        $renewedCustomerIds = $subs->filter(fn ($s) => $s->last_renewed_at && $s->last_renewed_at->isToday())
            ->pluck('customer_id')->unique();
        foreach ($renewedCustomerIds as $cid) {
            try {
                $c = \App\Models\Customer::find($cid);
                if ($c) \App\Services\VipService::recalculate($c);
            } catch (\Throwable $e) {
                \Log::warning("Auto-renew VIP recalc failed for customer #{$cid}: {$e->getMessage()}");
            }
        }

        // Send batched notifications per customer
        $notifier = app(\App\Services\NotificationService::class);
        foreach ($failuresByCustomer as $customerId => $data) {
            $customer = $data['customer'];
            $items = $data['items'];
            $hasFinal = collect($items)->contains('is_final', true);

            $lines = collect($items)->map(function ($item) {
                $sub = $item['sub'];
                $ip = $sub->proxyIp;
                $tag = $item['is_final'] ? '**[已失效]**' : '[待重试]';
                $months = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
                return sprintf(
                    "%s %s (%s) ¥%.2f/月",
                    $tag,
                    $ip?->asset_name ?? "订阅#{$sub->id}",
                    $ip?->ip_address ?? '-',
                    round((float) $sub->price / max($months, 1), 2)
                );
            })->join("\n> ");

            $title = $hasFinal
                ? "🚨 自动续费失败 - {$customer->customer_name}（含已失效）"
                : "⚠️ 自动续费失败 - {$customer->customer_name}";

            try {
                $notifier->dispatch('auto_renew_failed', [
                    'title' => $title,
                    'content' => sprintf(
                        "**客户**：%s\n**余额**：¥%.2f\n**失败数**：%d 条\n\n> %s\n\n%s",
                        $customer->customer_name,
                        (float) $customer->balance,
                        count($items),
                        $lines,
                        $hasFinal ? '> ⚠️ 标记[已失效]的订阅已自动过期，IP 已释放。' : '> 将在明天凌晨最后尝试一次。'
                    ),
                    'related_type' => 'Customer',
                    'related_id' => $customerId,
                    'dedup_key' => "auto_renew_batch_{$customerId}_" . now()->format('Ymd'),
                ]);
            } catch (\Throwable $e) {
                \Log::warning("Auto-renew notification failed for customer #{$customerId}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done: renewed={$renewed}, failed={$failed}, expired={$expired}");
        return 0;
    }
}
