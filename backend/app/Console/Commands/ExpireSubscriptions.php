<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 自动标记过期订阅 + 清理 IP 资产
 *
 * 逻辑：
 *   1. 找到所有 status=active && expires_at <= now() 的订阅 → 标记 expired
 *   1.5. 测试订单过期后立即回收 + API 释放
 *   2+3. 所有 IP 统一 3 天宽限期 — 过期超 3 天后清理转发 + 软删除 IP
 *   4. 兜底：所有无活跃订阅且超宽限期的废弃 IP → 软删除
 *
 * 每小时运行一次
 */
class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire {--dry-run}';
    protected $description = '自动标记过期订阅，清理无用 IP 资产';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $stats = ['marked' => 0, 'deleted_non_spark' => 0, 'deleted_spark' => 0, 'deleted_orphan' => 0];

        // ── 步骤 1：标记过期订阅 ──
        $expiredSubs = Subscription::with(['proxyIp', 'customer:id,customer_name'])
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredSubs as $sub) {
            if ($dryRun) {
                $this->line("  [标记过期] 订阅 #{$sub->id} ({$sub->customer?->customer_name})");
            } else {
                $sub->update(['status' => 'expired']);

                // 立即释放上游实例，避免继续被 upstream:auto-renew 续费浪费钱
                $ip = $sub->proxyIp;
                if ($ip && !$ip->trashed() && !$this->hasOtherActiveSub($ip->id, $sub->id)) {
                    $this->releaseUpstream($ip);
                    $this->line("  → 已释放上游实例 (IP #{$ip->id} {$ip->ip_address})");
                }
            }
            $stats['marked']++;
        }

        // ── 步骤 1.5：测试订单 — 到期后立即回收+API释放（安全网，正常由 ReclaimTestIpJob 处理）──
        $testExpired = Subscription::with('proxyIp')
            ->where('is_test', true)
            ->where('status', 'expired')
            ->whereHas('proxyIp', fn ($q) => $q->whereIn('status', ['assigned', 'expired']))
            ->get();

        foreach ($testExpired as $sub) {
            $ip = $sub->proxyIp;
            if (!$ip || $ip->trashed()) continue;
            if ($this->hasOtherActiveSub($ip->id, $sub->id)) continue;

            if ($dryRun) {
                $this->line("  [测试回收] IP #{$ip->id} {$ip->ip_address} (订阅 #{$sub->id})");
            } else {
                $this->cleanForwards($sub);
                $this->releaseUpstream($ip);
                $this->releaseAndDelete($ip, $sub, '测试IP自动回收(cron)');
            }
        }

        // ── 步骤 2 + 3：统一 3 天宽限期 — 过期超过 3 天后清理转发+软删除 IP ──
        $gracePeriodEnd = now()->subDays(3);

        $expiredPastGrace = Subscription::with('proxyIp')
            ->where('status', 'expired')
            ->where('expires_at', '<=', $gracePeriodEnd)
            ->whereHas('proxyIp', function ($q) {
                $q->whereIn('status', ['assigned', 'expired']);
            })
            ->get();

        foreach ($expiredPastGrace as $sub) {
            $ip = $sub->proxyIp;
            if (!$ip || $ip->trashed()) continue;
            if ($this->hasOtherActiveSub($ip->id, $sub->id)) continue;

            $isSpark = !empty($ip->spark_instance_id);
            $isIpipv = !empty($ip->ipipv_instance_id);
            $label = $isSpark ? 'Spark' : ($isIpipv ? 'IPIPV' : '非Spark');

            if ($dryRun) {
                $this->line("  [删除] {$label} IP #{$ip->id} {$ip->ip_address} 宽限期已过 (订阅 #{$sub->id})");
            } else {
                $this->cleanForwards($sub);
                $this->releaseUpstream($ip);
                $this->releaseAndDelete($ip, $sub, '订阅过期超3天宽限期，自动清理');
            }
            if ($isSpark) {
                $stats['deleted_spark']++;
            } else {
                $stats['deleted_non_spark']++;
            }
        }

        // ── 步骤 4：清理所有无活跃订阅的废弃 IP ──
        // 核心逻辑：上游 IP 断了就废了，不可能重新分配
        // 涵盖：available/未分配的老IP、expired 残留、assigned 但订阅已过期
        // 排除：测试池 IP、有活跃订阅的 IP、宽限期内的 IP
        $deadIps = ProxyIp::whereIn('status', ['available', 'assigned', 'expired'])
            ->where(function ($q) {
                $q->where('is_test_pool', false)->orWhereNull('is_test_pool');
            })
            ->whereDoesntHave('subscriptions', function ($q) {
                $q->where('status', 'active');
            })
            ->whereDoesntHave('subscriptions', function ($q) use ($gracePeriodEnd) {
                // 排除还在宽限期内的
                $q->where('status', 'expired')
                   ->where('expires_at', '>', $gracePeriodEnd);
            })
            ->get();

        $deadCount = 0;
        foreach ($deadIps as $ip) {
            if ($dryRun) {
                $this->line("  [删除] 废弃 IP #{$ip->id} {$ip->ip_address} (status={$ip->status}, source={$ip->source_name})");
            } else {
                // 兜底清转发规则（Ny / Xui）
                $expiredSub = Subscription::where('proxy_ip_id', $ip->id)
                    ->where('status', 'expired')
                    ->latest('expires_at')
                    ->first();
                if ($expiredSub) {
                    $this->cleanForwards($expiredSub);
                }

                $this->releaseUpstream($ip);

                $ip->update([
                    'status' => 'expired',
                    'assigned_customer_id' => null,
                    'released_at' => $ip->released_at ?? now(),
                    'release_reason' => $ip->release_reason ?: '无活跃订阅，自动清理',
                ]);
                $ip->delete(); // soft delete
            }
            $deadCount++;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}标记过期 {$stats['marked']}，删除非Spark {$stats['deleted_non_spark']}，删除Spark(宽限后) {$stats['deleted_spark']}，清理废弃 {$deadCount}");

        return 0;
    }

    private function cleanForwards(Subscription $sub): void
    {
        try {
            $count = app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($sub);
            if ($count > 0) {
                $this->line("    清理 Ny 转发 {$count} 条 (订阅 #{$sub->id})");
            }
        } catch (\Throwable $e) {
            Log::error('ExpireSubscriptions: Ny forward cleanup failed', [
                'subscription_id' => $sub->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn("    Ny 转发清理失败 (订阅 #{$sub->id}): {$e->getMessage()}");
        }

        try {
            $count = app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($sub);
            if ($count > 0) {
                $this->line("    清理 Xui 转发 {$count} 条 (订阅 #{$sub->id})");
            }
        } catch (\Throwable $e) {
            Log::error('ExpireSubscriptions: Xui forward cleanup failed', [
                'subscription_id' => $sub->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn("    Xui 转发清理失败 (订阅 #{$sub->id}): {$e->getMessage()}");
        }
    }

    private function cleanForwardsAndRelease(ProxyIp $ip, Subscription $sub, string $reason): void
    {
        $this->cleanForwards($sub);
        $this->releaseAndDelete($ip, $sub, $reason);
    }

    private function releaseAndDelete(ProxyIp $ip, Subscription $sub, string $reason): void
    {
        $ip->update([
            'status' => 'expired',
            'assigned_customer_id' => null,
            'released_at' => now(),
            'release_reason' => $reason,
        ]);
        $ip->delete();
    }

    private function hasOtherActiveSub(int $proxyIpId, int $excludeSubId): bool
    {
        return Subscription::where('proxy_ip_id', $proxyIpId)
            ->where('id', '!=', $excludeSubId)
            ->where('status', 'active')
            ->exists();
    }

    private function releaseUpstream(ProxyIp $ip): void
    {
        if ($ip->spark_instance_id) {
            try {
                $spark = app(\App\Services\SparkApiService::class);
                $reqOrderNo = \App\Models\SparkOrder::generateReqOrderNo();
                $spark->delProxy($reqOrderNo, [$ip->spark_instance_id]);
                \App\Models\SparkOrder::create([
                    'req_order_no' => $reqOrderNo,
                    'method' => 'DelProxy',
                    'product_id' => '',
                    'amount' => 1,
                    'duration' => 0,
                    'unit' => 0,
                    'status' => 1,
                    'request_data' => ['reason' => '测试IP过期自动释放', 'proxy_ip_id' => $ip->id],
                    'response_data' => [],
                ]);
                $this->line("    Spark DelProxy: {$ip->spark_instance_id}");
            } catch (\Throwable $e) {
                Log::error('ExpireSubscriptions: Spark release failed', [
                    'instance_id' => $ip->spark_instance_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($ip->ipipv_instance_id) {
            try {
                $ipipv = app(\App\Services\IpipvApiService::class);
                $orderNo = \App\Models\IpipvOrder::generateAppOrderNo();
                $ipipv->releaseProxy($orderNo, [$ip->ipipv_instance_id]);
                \App\Models\IpipvOrder::create([
                    'app_order_no' => $orderNo,
                    'method' => 'release',
                    'status' => 1,
                    'request_data' => ['reason' => '测试IP过期自动释放', 'proxy_ip_id' => $ip->id],
                    'response_data' => [],
                ]);
                $this->line("    IPIPV release: {$ip->ipipv_instance_id}");
            } catch (\Throwable $e) {
                Log::error('ExpireSubscriptions: IPIPV release failed', [
                    'instance_id' => $ip->ipipv_instance_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
