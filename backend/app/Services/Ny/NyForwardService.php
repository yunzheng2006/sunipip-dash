<?php

namespace App\Services\Ny;

use App\Models\ForwardRule;
use App\Models\NyDeviceGroup;
use App\Models\NyPanel;
use App\Models\ProxyIp;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * 转发规则编排服务
 *
 * 职责：
 *   - attachToSubscription 创建一条转发规则，绑定到 subscription + proxy_ip
 *   - deleteForSubscription 删除订阅关联的所有 active 规则
 *   - syncDeviceGroups 把 NY 面板的设备组同步到本地缓存
 */
class NyForwardService
{
    /**
     * 为指定订阅创建一条转发规则。
     *
     * @param Subscription $subscription
     * @param NyDeviceGroup $deviceGroup 已启用的本地缓存行
     * @param int|null $speedLimitMbps null=不限速
     * @param float $forwardFee 单月转发费（仅记录，实际金额已计入 subscription.price）
     *
     * @return ForwardRule 创建的规则（status=active 成功，failed 失败）
     */
    public function attachToSubscription(
        Subscription $subscription,
        NyDeviceGroup $deviceGroup,
        ?int $speedLimitMbps,
        float $forwardFee,
    ): ForwardRule {
        $proxyIp = $subscription->proxyIp()->first();
        if (!$proxyIp) {
            throw new NyApiException('订阅未关联 IP');
        }

        $panel = $deviceGroup->panel;
        if (!$panel || !$panel->is_active) {
            throw new NyApiException('NY 面板未启用');
        }

        // 先插本地记录（pending），失败时也能查到
        $rule = ForwardRule::create([
            'subscription_id' => $subscription->id,
            'proxy_ip_id' => $proxyIp->id,
            'ny_panel_id' => $panel->id,
            'ny_device_group_id' => $deviceGroup->id,
            'name' => $this->buildRuleName($subscription, $proxyIp),
            'dest_host' => $proxyIp->ip_address,
            'dest_port' => (int) $proxyIp->port,
            'speed_limit_mbps' => $speedLimitMbps,
            'forward_fee' => $forwardFee,
            'status' => 'pending',
        ]);

        return $this->processRule($rule);
    }

    /**
     * 处理一条已创建为 pending/processing 状态的转发规则。
     *
     * 从 attachToSubscription 抽出来的核心逻辑，让 Job 可以复用。
     *
     * 成功：调 NY createForward → searchRules → 更新 rule → 更新 sub.has_forward
     * 失败：更新 rule.status = failed，抛 NyApiException
     *
     * 注意：此方法 **不** 调整 subscription.price（转发费扣款交给调用方决定）
     */
    public function processRule(ForwardRule $rule): ForwardRule
    {
        $rule->loadMissing(['proxyIp', 'deviceGroup.panel', 'subscription']);

        $proxyIp = $rule->proxyIp;
        $deviceGroup = $rule->deviceGroup;
        $panel = $deviceGroup?->panel;
        $subscription = $rule->subscription;

        if (!$proxyIp || !$deviceGroup || !$panel || !$subscription) {
            $rule->update([
                'status' => 'failed',
                'error_message' => '规则缺少必需的关联（IP/设备组/面板/订阅）',
            ]);
            throw new NyApiException('缺少关联对象');
        }

        if (!$panel->is_active) {
            $rule->update([
                'status' => 'failed',
                'error_message' => 'NY 面板未启用',
            ]);
            throw new NyApiException('NY 面板未启用');
        }

        // 只为激活订阅创建转发：退款/取消/过期订阅遗留的规则不得重建（防复活+重复加价）
        if ($subscription->status !== 'active') {
            $rule->update([
                'status' => 'failed',
                'error_message' => "订阅状态为 {$subscription->status}，不创建转发",
            ]);
            throw new NyApiException('订阅非激活状态，不创建转发');
        }

        // 标记为处理中（防止并发重复处理）
        if ($rule->status === 'pending') {
            $rule->update(['status' => 'processing']);
        }

        try {
            $api = new NyApiService($panel->fresh());
            $config = [
                'dest' => [$proxyIp->ip_address . ':' . $proxyIp->port],
            ];
            if ($rule->speed_limit_mbps !== null && $rule->speed_limit_mbps > 0) {
                // NY speed_limit 单位 bytes/sec；1 Mbps = 125,000 bytes/sec
                $config['speed_limit'] = (int) ($rule->speed_limit_mbps * 125000);
            }

            // 幂等：先按名称查上游是否已存在（上次创建成功但查询失败的场景），
            // 存在则直接收养，避免重试时重复创建产生上游孤儿规则
            $searchParams = [
                'gid_in' => (int) $deviceGroup->remote_id,
                'gid_out' => 0,
                'name' => $rule->name,
                'dest' => '',
                'listen_port' => 0,
            ];
            $existing = collect($api->searchRules($searchParams))
                ->first(fn($r) => ($r['name'] ?? '') === $rule->name);

            if (!$existing) {
                $api->createForward([
                    'name' => $rule->name,
                    'device_group_in' => (int) $deviceGroup->remote_id,
                    'device_group_out' => null,
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);

                // PUT /user/forward 不返回 rule id，search_rules 查回来
                $existing = collect($api->searchRules($searchParams))
                    ->first(fn($r) => ($r['name'] ?? '') === $rule->name);
            }

            $found = $existing;
            if (!$found) {
                throw new NyApiException('创建成功但未能查回 rule id');
            }

            $rule->update([
                'remote_rule_id' => (int) ($found['id'] ?? 0),
                'listen_port' => (int) ($found['listen_port'] ?? 0),
                'status' => 'active',
                'last_synced_at' => now(),
                'error_message' => null,
            ]);

            $subscription->update([
                'has_forward' => true,
                'purchased_module' => $rule->forwardPlan?->module ?? $subscription->purchased_module,
            ]);

            return $rule->fresh();
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage() ?: get_class($e);
            Log::error('NyForwardService::processRule failed', [
                'rule_id' => $rule->id,
                'error' => $errorMsg,
                'exception_class' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            $rule->update([
                'status' => 'failed',
                'error_message' => substr($errorMsg, 0, 500),
            ]);
            throw $e instanceof NyApiException ? $e : new NyApiException($errorMsg ?: '未知错误');
        }
    }

    /**
     * 删除某订阅的所有 active 转发规则
     *
     * @return int 删除成功的数量
     */
    public function deleteForSubscription(Subscription $subscription): int
    {
        $rules = ForwardRule::where('subscription_id', $subscription->id)
            ->where('status', 'active')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $deleted = 0;
        // 按 panel 分组批量删
        foreach ($rules->groupBy('ny_panel_id') as $panelId => $group) {
            $panel = NyPanel::find($panelId);
            if (!$panel) {
                continue;
            }
            $remoteIds = $group->pluck('remote_rule_id')->filter()->values()->all();
            try {
                if (!empty($remoteIds)) {
                    (new NyApiService($panel))->deleteForwards($remoteIds);
                }
                foreach ($group as $rule) {
                    $rule->update(['status' => 'deleted', 'last_synced_at' => now()]);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                Log::error('NyForwardService::deleteForSubscription failed', [
                    'subscription_id' => $subscription->id,
                    'panel_id' => $panelId,
                    'error' => $e->getMessage(),
                ]);
                foreach ($group as $rule) {
                    $rule->update([
                        'status' => 'failed',
                        'error_message' => substr('删除失败: ' . $e->getMessage(), 0, 500),
                    ]);
                }
            }
        }

        // 如果所有规则都删掉了，关掉 has_forward
        if (!ForwardRule::where('subscription_id', $subscription->id)
            ->where('status', 'active')->exists()) {
            $subscription->update(['has_forward' => false]);
        }

        return $deleted;
    }

    /**
     * 从 NY 面板同步设备组到本地
     */
    public function syncDeviceGroups(NyPanel $panel): array
    {
        $api = new NyApiService($panel);
        $groups = $api->listDeviceGroups();

        $saved = [];
        foreach ($groups as $g) {
            $remoteId = (int) ($g['id'] ?? 0);
            if (!$remoteId) {
                continue;
            }
            $row = NyDeviceGroup::updateOrCreate(
                ['ny_panel_id' => $panel->id, 'remote_id' => $remoteId],
                [
                    'name' => $g['name'] ?? '',
                    'type' => $g['type'] ?? null,
                    'original_connect_host' => $g['connect_host'] ?? null,
                    'port_range' => $g['port_range'] ?? null,
                    'last_synced_at' => now(),
                ]
            );
            $saved[] = $row->id;
        }

        return ['synced' => count($saved), 'ids' => $saved];
    }

    private function buildRuleName(Subscription $subscription, ProxyIp $ip): string
    {
        // 唯一性很重要，同名会被 searchRules 匹配到
        return sprintf(
            'SNP-S%d-%s:%d',
            $subscription->id,
            $ip->ip_address,
            $ip->port
        );
    }
}
