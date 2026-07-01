<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use App\Services\SparkApiService;
use App\Support\DurationHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 修复 subs:sync-expiry-from-ip 对 Spark 多月订阅造成的损害：
 *   1. 扫描所有 Spark 订阅，检查 expires_at 是否被错误缩短
 *   2. 重算正确的 expires_at（based on started_at/last_renewed_at + duration）
 *   3. 对上游已过期的实例，调 RenewProxy 赎回（保留原 IP/账号/密码）
 *   4. 重新激活被错误标记为 expired 的订阅
 */
class FixSyncExpiryDamage extends Command
{
    protected $signature = 'fix:sync-expiry-damage
                            {--dry-run : 只检查不修改}
                            {--ip= : 只处理指定 IP}
                            {--redeem : 对上游已过期实例调 RenewProxy 赎回}';

    protected $description = '修复 subs:sync-expiry-from-ip 对 Spark 多月订阅的损害（过期恢复+上游赎回）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetIp = $this->option('ip');
        $doRedeem = (bool) $this->option('redeem');

        $prefix = $dryRun ? '[DRY RUN] ' : '';

        // ── 1. 扫描受影响的订阅 ──
        $query = Subscription::query()
            ->whereIn('status', ['active', 'expired'])
            ->where('duration', '>', 1)
            ->where('unit', 3) // 月
            ->whereHas('proxyIp', function ($q) use ($targetIp) {
                $q->whereNotNull('spark_instance_id');
                if ($targetIp) {
                    $q->where('ip_address', $targetIp);
                }
            })
            ->with(['proxyIp', 'proxyIp.sparkInstance']);

        $subs = $query->get();
        $this->info("扫描到 {$subs->count()} 条 Spark 多月订阅");

        $fixedExpiry = 0;
        $reactivated = 0;
        $redeemed = 0;
        $failed = 0;
        $rows = [];

        foreach ($subs as $sub) {
            $ip = $sub->proxyIp;
            if (!$ip) continue;

            $instance = $ip->sparkInstance;

            // 重算正确到期时间
            $baseDate = $sub->last_renewed_at
                ? Carbon::parse($sub->last_renewed_at)
                : Carbon::parse($sub->started_at);
            $correctExpiry = DurationHelper::addToDate($baseDate, (int) $sub->duration, (int) $sub->unit);
            $currentExpiry = $sub->expires_at ? Carbon::parse($sub->expires_at) : null;

            $needsExpiryFix = false;
            $needsReactivate = false;
            $needsRedeem = false;

            // 检查到期时间是否被错误缩短
            if ($currentExpiry && $correctExpiry->greaterThan($currentExpiry->copy()->addDays(1))) {
                $needsExpiryFix = true;
            }

            // 检查是否被错误设为 expired
            if ($sub->status === 'expired' && $correctExpiry->isFuture()) {
                $needsReactivate = true;
                $needsExpiryFix = true;
            }

            // 检查上游实例是否已过期
            if ($instance && $instance->expire_at && $instance->expire_at->isPast()) {
                $needsRedeem = true;
            }

            if (!$needsExpiryFix && !$needsReactivate && !$needsRedeem) {
                continue;
            }

            $actions = [];
            if ($needsExpiryFix) $actions[] = '修正到期';
            if ($needsReactivate) $actions[] = '重新激活';
            if ($needsRedeem) $actions[] = '上游赎回';

            $rows[] = [
                $sub->id,
                $ip->ip_address,
                $sub->status,
                $currentExpiry?->format('Y-m-d') ?? 'null',
                $correctExpiry->format('Y-m-d'),
                $instance?->expire_at?->format('Y-m-d') ?? '-',
                implode('+', $actions),
            ];

            if ($dryRun) {
                if ($needsExpiryFix) $fixedExpiry++;
                if ($needsReactivate) $reactivated++;
                if ($needsRedeem) $redeemed++;
                continue;
            }

            // ── 2. 上游赎回 ──
            if ($needsRedeem && $doRedeem && $instance) {
                try {
                    $result = $this->redeemUpstream($instance);
                    $this->info("  ✓ 赎回 {$ip->ip_address} 上游实例 {$instance->instance_id}");
                    $redeemed++;
                } catch (\Throwable $e) {
                    $this->error("  ✗ 赎回失败 {$ip->ip_address}: {$e->getMessage()}");
                    Log::error('fix:sync-expiry-damage redeem failed', [
                        'ip' => $ip->ip_address,
                        'instance_id' => $instance->instance_id,
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                    continue;
                }
            } elseif ($needsRedeem && !$doRedeem) {
                $this->warn("  ⚠ {$ip->ip_address} 上游已过期，需加 --redeem 参数赎回");
            }

            // ── 3. 修正订阅到期时间 ──
            if ($needsExpiryFix) {
                $sub->update(['expires_at' => $correctExpiry]);
                $fixedExpiry++;
            }

            // ── 4. 重新激活订阅 ──
            if ($needsReactivate) {
                $sub->update(['status' => 'active']);
                $ip->update(['status' => 'assigned']);
                $reactivated++;
                $this->info("  ✓ 重新激活订阅 #{$sub->id} ({$ip->ip_address})");
            }
        }

        if (count($rows)) {
            $this->table(
                ['订阅ID', 'IP', '状态', '当前到期', '正确到期', '上游到期', '操作'],
                $rows
            );
        }

        $this->newLine();
        $this->info("{$prefix}处理完成：");
        $this->line("  修正到期时间：{$fixedExpiry} 条");
        $this->line("  重新激活：{$reactivated} 条");
        $this->line("  上游赎回：{$redeemed} 条");
        if ($failed > 0) {
            $this->line("  赎回失败：{$failed} 条");
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * 通过 RenewProxy API 赎回上游过期实例（保留原 IP/账号/密码）
     */
    private function redeemUpstream(SparkInstance $instance): array
    {
        $sparkApi = app(SparkApiService::class);
        $reqOrderNo = SparkOrder::generateReqOrderNo();

        // 先查询实例当前状态
        // 创建续费订单记录
        $sparkOrder = SparkOrder::create([
            'req_order_no' => $reqOrderNo,
            'method' => 'RenewProxy',
            'product_id' => '',
            'amount' => 1,
            'duration' => 1,
            'unit' => 3,
            'status' => 1,
            'request_data' => [
                'instanceId' => $instance->instance_id,
                'duration' => 1,
                'unit' => 3,
                'trigger' => 'fix_sync_expiry_redeem',
            ],
        ]);

        // 调 RenewProxy 赎回
        $response = $sparkApi->renewProxy($reqOrderNo, [[
            'instanceId' => $instance->instance_id,
            'duration' => 1,
            'unit' => 3,
        ]]);

        $sparkOrder->update([
            'spark_order_no' => $response['orderNo'] ?? null,
            'status' => 2,
            'response_data' => $response,
        ]);

        // 更新实例到期时间
        $newExpireAt = now()->addDays(30);
        $instance->update([
            'expire_at' => $newExpireAt,
            'status' => 2,
        ]);

        // 更新 ProxyIp 的上游到期时间
        $instance->proxyIp?->update([
            'upstream_expires_at' => $newExpireAt,
        ]);

        return $response;
    }
}
