<?php

namespace App\Services\Dns;

use App\Models\DnsFailoverEvent;
use App\Models\DnsTarget;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DNS 故障切换编排器
 *
 * 职责：
 *   - triggerFailover: 从主到备（主机被墙 → 切到备机 IP）
 *   - triggerFailback: 从备回主（主机恢复后切回去）
 *
 * 两个方法都：
 *   1. 调 CloudflareService 改 DNS 记录
 *   2. 写 dns_failover_events 审计
 *   3. 更新 dns_targets 状态
 *   4. 发 webhook 通知
 */
class DnsFailoverService
{
    public function __construct(protected NotificationService $notifier) {}

    /**
     * 从主切到备
     *
     * @param DnsTarget $target
     * @param string $trigger 'auto' | 'manual'
     * @param int|null $userId 手动切换时的操作人
     * @param string|null $reason
     * @return array{success: bool, message: string}
     */
    public function triggerFailover(
        DnsTarget $target,
        string $trigger = 'auto',
        ?int $userId = null,
        ?string $reason = null,
    ): array {
        if ($target->current_active === 'backup') {
            return ['success' => false, 'message' => '已经在备机上运行，无需切换'];
        }

        return $this->executeSwitch(
            $target,
            action: 'failover',
            fromIp: $target->primary_ip,
            toIp: $target->backup_ip,
            newActive: 'backup',
            newStatus: 'switched',
            trigger: $trigger,
            userId: $userId,
            reason: $reason ?: '主机连续探测失败',
            notifyEvent: 'dns_failover',
            notifyTitle: '🚨 DNS 容灾已切换到备机',
        );
    }

    /**
     * 从备切回主
     */
    public function triggerFailback(
        DnsTarget $target,
        string $trigger = 'manual',
        ?int $userId = null,
        ?string $reason = null,
    ): array {
        if ($target->current_active === 'primary') {
            return ['success' => false, 'message' => '已经在主机上运行，无需切回'];
        }

        return $this->executeSwitch(
            $target,
            action: 'failback',
            fromIp: $target->backup_ip,
            toIp: $target->primary_ip,
            newActive: 'primary',
            newStatus: 'healthy',
            trigger: $trigger,
            userId: $userId,
            reason: $reason ?: '主机已恢复',
            notifyEvent: 'dns_failback',
            notifyTitle: '✅ DNS 已切回主机',
        );
    }

    private function executeSwitch(
        DnsTarget $target,
        string $action,
        string $fromIp,
        string $toIp,
        string $newActive,
        string $newStatus,
        string $trigger,
        ?int $userId,
        string $reason,
        string $notifyEvent,
        string $notifyTitle,
    ): array {
        $cfResponse = null;
        $success = false;
        $message = '';

        try {
            $cf = new CloudflareService($target->cf_api_token);
            $cfResponse = $cf->updateRecordIp(
                $target->cf_zone_id,
                $target->cf_record_id,
                $toIp
            );
            $success = true;
            $message = "{$target->cf_record_name} → {$toIp}";
        } catch (\Throwable $e) {
            $success = false;
            $message = 'Cloudflare API 失败: ' . $e->getMessage();
            Log::error("DnsFailoverService {$action} CF failed", [
                'target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
        }

        DB::transaction(function () use ($target, $action, $fromIp, $toIp, $newActive, $newStatus, $trigger, $userId, $reason, $cfResponse, $success) {
            DnsFailoverEvent::create([
                'dns_target_id' => $target->id,
                'action' => $action,
                'from_ip' => $fromIp,
                'to_ip' => $toIp,
                'trigger' => $trigger,
                'triggered_by_user_id' => $userId,
                'reason' => $reason,
                'cf_response' => $cfResponse,
                'success' => $success,
            ]);

            if ($success) {
                $target->update([
                    'current_active' => $newActive,
                    'status' => $newStatus,
                    'consecutive_failures' => 0,
                    'last_switched_at' => now(),
                ]);
            }
        });

        if ($success) {
            try {
                $this->notifier->dispatch($notifyEvent, [
                    'title' => $notifyTitle,
                    'content' => sprintf(
                        "**目标**：%s\n\n**记录**：`%s`\n\n**切换**：%s → %s\n\n**触发**：%s\n\n**原因**：%s",
                        $target->name,
                        $target->cf_record_name,
                        $fromIp,
                        $toIp,
                        $trigger === 'auto' ? '自动探测' : '手动',
                        $reason,
                    ),
                    'related_type' => 'DnsTarget',
                    'related_id' => $target->id,
                    'dedup_key' => "dns_{$action}_{$target->id}_" . now()->format('Ymd_H'),
                ]);
            } catch (\Throwable $e) {
                Log::warning("DnsFailoverService: webhook dispatch failed: {$e->getMessage()}");
            }
        }

        return ['success' => $success, 'message' => $message];
    }
}
