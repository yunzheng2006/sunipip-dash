<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsProbeResult;
use App\Models\DnsTarget;
use App\Services\Dns\DnsFailoverService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Agent API - 供中国大陆 Agent 调用的公开接口
 *
 * 认证方式：HTTP Header `X-Agent-Key: {agent_key}`
 * 不使用 Sanctum，因为 Agent 是无状态的纯 HTTP 客户端。
 *
 * 三个核心接口：
 *   POST /agent/heartbeat   - Agent 心跳 + 拉取待探测任务
 *   POST /agent/report      - 上报探测结果
 *   GET  /agent/targets     - （可选）单独拉取任务列表
 */
class AgentApiController extends Controller
{
    /**
     * 从请求头拿 agent_key 鉴权，返回 DnsAgent 或 null
     */
    private function authenticate(Request $request): ?DnsAgent
    {
        $key = $request->header('X-Agent-Key');
        if (!$key) {
            return null;
        }
        $agent = DnsAgent::where('agent_key', $key)->where('is_active', 1)->first();
        return $agent;
    }

    /**
     * Agent 心跳 + 拉任务（一次请求完成）
     * POST /agent/heartbeat
     *
     * 返回：{ agent_id, targets: [ {id, host, port, vless_url, interval_minutes, timeout_seconds}, ... ] }
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);
        if (!$agent) {
            return $this->error('Unauthorized', 401);
        }

        $agent->update([
            'last_heartbeat_at' => now(),
            'last_ip' => $request->ip(),
        ]);

        // 拉出所有 active targets，距离上次探测超过 interval 的
        $targets = DnsTarget::where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('last_probe_at')
                    ->orWhereRaw('TIMESTAMPDIFF(MINUTE, last_probe_at, NOW()) >= probe_interval_minutes');
            })
            ->get();

        $payload = $targets->map(fn($t) => [
            'id' => $t->id,
            'host' => $t->effectiveProbeHost(),
            'port' => $t->probe_port,
            'timeout_seconds' => $t->probe_timeout_seconds,
            'vless_url' => $t->probe_vless_url, // 可为空，agent 不做 vless 握手就 fallback 到 TCP
        ])->values();

        return $this->success([
            'agent_id' => $agent->id,
            'server_time' => now()->toIso8601String(),
            'targets' => $payload,
        ]);
    }

    /**
     * 上报探测结果
     * POST /agent/report
     *
     * Body: {
     *   results: [
     *     { target_id, success, latency_ms, error_message }
     *   ]
     * }
     */
    public function report(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);
        if (!$agent) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->validate([
            'results' => 'required|array|min:1|max:200',
            'results.*.target_id' => 'required|integer|exists:dns_targets,id',
            'results.*.success' => 'required|boolean',
            'results.*.latency_ms' => 'nullable|integer|min:0',
            'results.*.error_message' => 'nullable|string|max:500',
        ]);

        $actionsTriggered = [];

        foreach ($data['results'] as $result) {
            $target = DnsTarget::find($result['target_id']);
            if (!$target || !$target->is_active) continue;

            DnsProbeResult::create([
                'dns_target_id' => $target->id,
                'dns_agent_id' => $agent->id,
                'probed_host' => $target->effectiveProbeHost(),
                'probed_port' => $target->probe_port,
                'success' => (bool) $result['success'],
                'latency_ms' => $result['latency_ms'] ?? null,
                'error_message' => $result['error_message'] ?? null,
                'probed_at' => now(),
            ]);

            // 更新目标状态
            if ($result['success']) {
                // 成功：清零失败计数，恢复 healthy（如果之前是 degraded）
                $updates = ['last_probe_at' => now(), 'consecutive_failures' => 0];
                if ($target->status === 'degraded') {
                    $updates['status'] = 'healthy';
                }
                // 注意：如果是 switched 状态，**不**自动切回，等 admin 确认
                $target->update($updates);
            } else {
                $newFailures = $target->consecutive_failures + 1;
                $target->update([
                    'last_probe_at' => now(),
                    'consecutive_failures' => $newFailures,
                    'status' => $newFailures >= $target->failure_threshold ? 'failed' : 'degraded',
                ]);

                // 单次失败但还没到阈值：发个低优先级通知
                if ($newFailures < $target->failure_threshold) {
                    try {
                        app(NotificationService::class)->dispatch('dns_probe_failed', [
                            'title' => '⚠️ DNS 探测失败',
                            'content' => sprintf(
                                "**目标**：%s\n\n**探测地址**：%s:%d\n\n**失败次数**：%d / %d\n\n**错误**：%s",
                                $target->name,
                                $target->effectiveProbeHost(),
                                $target->probe_port,
                                $newFailures,
                                $target->failure_threshold,
                                $result['error_message'] ?? '连接失败'
                            ),
                            'related_type' => 'DnsTarget',
                            'related_id' => $target->id,
                            'dedup_key' => "dns_probe_{$target->id}_{$newFailures}",
                        ]);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }

                // 达到阈值且当前在 primary → 自动切换到 backup
                if (
                    $newFailures >= $target->failure_threshold
                    && $target->current_active === 'primary'
                ) {
                    try {
                        $res = app(DnsFailoverService::class)->triggerFailover(
                            $target->fresh(),
                            trigger: 'auto',
                            reason: "连续 {$newFailures} 次探测失败"
                        );
                        $actionsTriggered[] = [
                            'target_id' => $target->id,
                            'action' => 'failover',
                            'result' => $res,
                        ];
                    } catch (\Throwable $e) {
                        Log::error("Auto failover failed for target {$target->id}: {$e->getMessage()}");
                    }
                }
            }
        }

        return $this->success([
            'received' => count($data['results']),
            'actions_triggered' => $actionsTriggered,
        ]);
    }
}
