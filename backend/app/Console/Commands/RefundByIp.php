<?php

namespace App\Console\Commands;

use App\Models\IpAssignmentLog;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefundByIp extends Command
{
    protected $signature = 'subscription:refund-by-ip
        {ips* : IP 地址列表}
        {--refund-to-balance : 退款到客户余额（默认不退）}
        {--no-release : 不释放上游}
        {--reason=CLI批量退订 : 退订原因}
        {--dry-run : 仅预览，不执行}';

    protected $description = '根据 IP 地址批量退订（释放上游 + 更新状态）';

    public function handle(): int
    {
        $ips = $this->argument('ips');
        $dryRun = $this->option('dry-run');
        $refundToBalance = $this->option('refund-to-balance');
        $releaseUpstream = !$this->option('no-release');
        $reason = $this->option('reason');

        $this->info('查找 IP 对应的活跃订阅...');

        $rows = [];
        foreach ($ips as $ip) {
            $proxyIp = ProxyIp::where('ip_address', $ip)->first();
            if (!$proxyIp) {
                $this->warn("  ✗ {$ip} — 未找到 ProxyIp 记录");
                continue;
            }

            $sub = Subscription::where('proxy_ip_id', $proxyIp->id)
                ->where('status', 'active')
                ->first();

            if (!$sub) {
                $this->warn("  ✗ {$ip} (ProxyIp#{$proxyIp->id}) — 无活跃订阅");
                continue;
            }

            $customer = $sub->customer;
            $rows[] = [
                'ip' => $ip,
                'proxyIp' => $proxyIp,
                'subscription' => $sub,
                'customer' => $customer,
            ];

            $sparkInfo = $proxyIp->spark_instance_id ? "spark:{$proxyIp->spark_instance_id}" : 'no-spark';
            $fwdInfo = $sub->has_forward ? 'has-forward' : 'no-forward';
            $this->line("  ✓ {$ip} → Sub#{$sub->id} 客户:{$customer?->customer_name} 价格:{$sub->price} {$sparkInfo} {$fwdInfo}");
        }

        if (empty($rows)) {
            $this->error('没有找到可退订的订阅');
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['IP', 'Sub ID', '客户', '价格', '开始时间', 'Spark ID'],
            collect($rows)->map(fn($r) => [
                $r['ip'],
                $r['subscription']->id,
                $r['customer']?->customer_name ?? '-',
                $r['subscription']->price,
                $r['subscription']->started_at?->format('Y-m-d H:i'),
                $r['proxyIp']->spark_instance_id ?? '-',
            ])->toArray()
        );

        $this->info("操作: 释放上游=" . ($releaseUpstream ? '是' : '否')
            . " 退款到客户余额=" . ($refundToBalance ? '是' : '否')
            . " 原因={$reason}");

        if ($dryRun) {
            $this->warn('--dry-run 模式，不执行任何操作');
            return self::SUCCESS;
        }

        if (!$this->confirm('确认执行以上退订操作？')) {
            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->newLine();
            $this->info("处理 {$r['ip']} (Sub#{$r['subscription']->id})...");
            $this->processRefund($r, $releaseUpstream, $refundToBalance, $reason);
        }

        $this->newLine();
        $this->info('全部完成');
        return self::SUCCESS;
    }

    private function processRefund(array $r, bool $releaseUpstream, bool $refundToBalance, string $reason): void
    {
        $sub = $r['subscription'];
        $proxyIp = $r['proxyIp'];
        $refundAmount = $refundToBalance ? $sub->price : 0;

        // 1. 释放上游
        $sparkResult = null;
        if ($releaseUpstream && $proxyIp->spark_instance_id) {
            $this->line('  → 调用 Spark DelProxy...');
            $sparkResult = \App\Services\SparkReleaseService::releaseInstance($proxyIp, $reason);
            $this->line("  → Spark: {$sparkResult['status']} — {$sparkResult['message']}");

            if ($sparkResult['status'] === 'failed') {
                $this->error("  ✗ Spark 释放失败，跳过此 IP");
                return;
            }
        }

        // 2. 清理转发规则
        $forwardDeleted = 0;
        if ($sub->has_forward) {
            try {
                $this->line('  → 删除 NY 转发规则...');
                $forwardDeleted = app(\App\Services\Ny\NyForwardService::class)
                    ->deleteForSubscription($sub);
                $this->line("  → 已删除 {$forwardDeleted} 条转发规则");
            } catch (\Throwable $e) {
                $this->warn("  → NY 转发删除失败: {$e->getMessage()}");
            }
        }
        try {
            app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($sub);
        } catch (\Throwable $e) {
            // ignore
        }

        // 3. 事务：更新状态
        DB::transaction(function () use ($sub, $proxyIp, $refundAmount, $reason, $releaseUpstream) {
            $sub->update([
                'status' => 'refunded',
                'keep_performance' => false,
                'refunded_at' => now(),
                'refund_reason' => $reason,
                'refund_amount' => $refundAmount,
                'refunded_by' => null,
            ]);

            if ($refundAmount > 0) {
                $customer = $sub->customer()->lockForUpdate()->first();
                if ($customer) {
                    $balanceBefore = $customer->balance;
                    $customer->increment('balance', $refundAmount);
                    Transaction::create([
                        'customer_id' => $customer->id,
                        'type' => Transaction::TYPE_REFUND,
                        'amount' => $refundAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $customer->balance,
                        'related_type' => Subscription::class,
                        'related_id' => $sub->id,
                        'description' => "退订 #{$sub->id}: {$reason}",
                    ]);
                }
            }

            if ($proxyIp) {
                if ($releaseUpstream) {
                    $proxyIp->update([
                        'assigned_customer_id' => null,
                        'status' => 'released',
                        'released_at' => now(),
                        'release_reason' => "退订: {$reason}",
                    ]);
                } else {
                    $proxyIp->update([
                        'assigned_customer_id' => null,
                        'status' => 'available',
                        'is_test_pool' => true,
                        'test_pool_added_at' => now(),
                        'test_pool_reason' => '退订未释放上游-回收至测试池',
                    ]);
                }

                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $sub->customer_id,
                    'subscription_id' => $sub->id,
                    'action' => 'unassign',
                    'operated_by' => 1,
                    'remark' => $releaseUpstream ? "CLI退订(已释放上游)" : "CLI退订(未释放上游)",
                    'created_at' => now(),
                ]);
            }

            try {
                app(\App\Services\ReferralService::class)
                    ->reverseCommissions($sub->customer_id, $sub->id);
            } catch (\Throwable $e) {
                // ignore
            }
        });

        try {
            \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($sub->customer_id);
        } catch (\Throwable) {}

        $this->info("  ✓ 退订完成 — 退款:{$refundAmount} 转发删除:{$forwardDeleted}");
    }
}
