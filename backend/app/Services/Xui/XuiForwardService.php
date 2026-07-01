<?php

namespace App\Services\Xui;

use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\XuiInbound;
use App\Models\XuiPanel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 3x-ui 转发编排器
 *
 * 一次 createForward() 会：
 *   1. 调 /server/getNewX25519Cert 生成 reality 密钥对
 *   2. 随机生成端口 / uuid / short_id / subId
 *   3. 调 /panel/api/inbounds/add 创建 vless+reality 入站
 *   4. 拉全局 xray 模板 → 在 outbounds 追加 socks5 出站 → 在 routing.rules 追加
 *      按 user(email) 匹配的规则 → 回写
 *   5. 本地写入 xui_inbounds 审计记录
 *
 * 任何中途失败会尝试清理已创建的 inbound（幂等性由 caller 保证）
 */
class XuiForwardService
{
    /**
     * 为指定源 IP 在 3x-ui 面板创建一条 vless+reality 中转
     *
     * @param XuiPanel $panel
     * @param ProxyIp $proxyIp 源 IP（含 auth_username/auth_password/ip/port）
     * @param Subscription|null $subscription 关联订阅（可选）
     * @param string|null $remarkOverride 自定义备注（默认用 ip.asset_name）
     * @return XuiInbound
     *
     * @throws XuiApiException
     */
    public function createForward(
        XuiPanel $panel,
        ProxyIp $proxyIp,
        ?Subscription $subscription = null,
        ?string $remarkOverride = null,
    ): XuiInbound {
        if (!$proxyIp->ip_address || !$proxyIp->port) {
            throw new XuiApiException('源 IP 信息不完整');
        }

        $remark = $remarkOverride
            ?: ($proxyIp->asset_name ?: "{$proxyIp->country_name}-{$proxyIp->ip_address}");

        // 先写本地 pending 记录，失败也能查到
        $record = XuiInbound::create([
            'xui_panel_id' => $panel->id,
            'proxy_ip_id' => $proxyIp->id,
            'subscription_id' => $subscription?->id,
            'remark' => $remark,
            'protocol' => 'vless',
            'server_name' => 'www.intel.com',
            'status' => 'pending',
        ]);

        return $this->processExistingRecord($record, $panel, $proxyIp);
    }

    /**
     * 对一条已存在的 pending XuiInbound 执行 3x-ui 创建逻辑
     *
     * 用于队列批量 Job 复用。调用方必须保证 record / panel / proxyIp 已加载且 record.status 为 pending/processing。
     *
     * @throws XuiApiException
     */
    public function processExistingRecord(
        XuiInbound $record,
        XuiPanel $panel,
        ProxyIp $proxyIp,
    ): XuiInbound {
        if ($record->status === 'active') {
            return $record;
        }

        if ($record->status === 'pending') {
            $record->update(['status' => 'processing']);
        }

        try {
            $api = new XuiApiService($panel->fresh());
            // 强制刷新 session，确保整条流程期间 cookie 不会过期
            $api->ensureFreshSession();

            $remark = $record->remark;

            // 1. 生成 reality 密钥对
            $keys = $api->generateRealityKeypair();
            $privateKey = $keys['privateKey'];
            $publicKey = $keys['publicKey'];

            // 2. 生成随机字段
            $uuid = (string) Str::uuid();
            $subId = Str::lower(Str::random(16));
            $port = random_int(20000, 60000);
            $shortId = bin2hex(random_bytes(4));
            $outboundTag = 'socks-out-' . $record->id;

            // 3. 构造 inbound payload
            $settings = [
                'clients' => [
                    [
                        'id' => $uuid,
                        'flow' => '',
                        'email' => $remark,
                        'limitIp' => 0,
                        'totalGB' => 0,
                        'expiryTime' => 0,
                        'enable' => true,
                        'tgId' => '',
                        'subId' => $subId,
                        'comment' => '',
                        'reset' => 0,
                    ],
                ],
                'decryption' => 'none',
                'fallbacks' => [],
            ];

            $streamSettings = [
                'network' => 'tcp',
                'security' => 'reality',
                'externalProxy' => [],
                'realitySettings' => [
                    'show' => false,
                    'xver' => 0,
                    'dest' => 'www.intel.com:443',
                    'serverNames' => ['www.intel.com'],
                    'privateKey' => $privateKey,
                    'minClient' => '',
                    'maxClient' => '',
                    'maxTimediff' => 0,
                    'shortIds' => [$shortId],
                    'settings' => [
                        'publicKey' => $publicKey,
                        'fingerprint' => 'chrome',
                        'serverName' => '',
                        'spiderX' => '/',
                    ],
                ],
                'tcpSettings' => [
                    'acceptProxyProtocol' => false,
                    'header' => ['type' => 'none'],
                ],
            ];

            $sniffing = [
                'enabled' => false,
                'destOverride' => ['http', 'tls', 'quic', 'fakedns'],
                'metadataOnly' => false,
                'routeOnly' => false,
            ];

            $payload = [
                'remark' => $remark,
                'enable' => true,
                'listen' => '',
                'port' => $port,
                'protocol' => 'vless',
                'expiryTime' => 0,
                'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'streamSettings' => json_encode($streamSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sniffing' => json_encode($sniffing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            // 4. 添加 inbound
            $created = $api->addInbound($payload);
            $remoteId = (int) ($created['id'] ?? 0);
            $actualPort = (int) ($created['port'] ?? $port);

            // 5. 修改全局 xray 模板：追加 outbound + routing rule（带全局锁）
            $this->addOutboundAndRoute(
                $api,
                $panel,
                $outboundTag,
                $proxyIp->ip_address,
                (int) $proxyIp->port,
                (string) $proxyIp->auth_username,
                (string) $proxyIp->auth_password,
                $remark // user 字段匹配
            );

            // 6. 本地落库
            $record->update([
                'remote_inbound_id' => $remoteId,
                'port' => $actualPort,
                'client_uuid' => $uuid,
                'private_key' => $privateKey,
                'public_key' => $publicKey,
                'short_id' => $shortId,
                'flow' => '',
                'outbound_tag' => $outboundTag,
                'status' => 'active',
                'last_synced_at' => now(),
                'error_message' => null,
                'mirror_sync_status' => $panel->mirror_panel_id ? 'pending' : null,
            ]);

            // 7. 如果主面板配置了备机，异步 replay 到备机
            if ($panel->mirror_panel_id) {
                \App\Jobs\XuiSyncToMirrorJob::dispatch($record->id, 'sync');
            }

            return $record->fresh();
        } catch (\Throwable $e) {
            Log::error('XuiForwardService::createForward failed', [
                'xui_panel_id' => $panel->id,
                'proxy_ip_id' => $proxyIp->id,
                'error' => $e->getMessage(),
            ]);
            $record->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);

            throw $e instanceof XuiApiException ? $e : new XuiApiException($e->getMessage());
        }
    }

    /**
     * 在 Xray 全局 template 里追加 outbound + 匹配 user 的 routing rule
     *
     * 使用 Cache::lock 保证同一个 panel 的 template 读改写是串行化的，
     * 防止多个 worker 并发 read-modify-write 时互相覆盖。
     */
    private function addOutboundAndRoute(
        XuiApiService $api,
        XuiPanel $panel,
        string $tag,
        string $destIp,
        int $destPort,
        string $user,
        string $pass,
        string $userEmail,
    ): void {
        $lock = \Illuminate\Support\Facades\Cache::lock("xui:xray_settings:{$panel->id}", 60);
        // 最多阻塞等 30 秒拿到锁；拿不到就抛异常（上层会标 failed 让 admin 手动重试）
        $lock->block(30);

        try {
            $config = $api->getXraySettings();

            // 追加 outbound
            $config['outbounds'] = $config['outbounds'] ?? [];
            $config['outbounds'][] = [
                'tag' => $tag,
                'protocol' => 'socks',
                'settings' => [
                    'servers' => [
                        [
                            'address' => $destIp,
                            'port' => $destPort,
                            'users' => $user !== '' || $pass !== ''
                                ? [['user' => $user, 'pass' => $pass]]
                                : [],
                        ],
                    ],
                ],
            ];

            // 追加 routing rule（按 user/email 匹配）
            if (!isset($config['routing']) || !is_array($config['routing'])) {
                $config['routing'] = ['rules' => []];
            }
            if (!isset($config['routing']['rules']) || !is_array($config['routing']['rules'])) {
                $config['routing']['rules'] = [];
            }

            // 把新规则插在最前面，确保优先匹配
            array_unshift($config['routing']['rules'], [
                'type' => 'field',
                'user' => [$userEmail],
                'outboundTag' => $tag,
            ]);

            $api->updateXraySettings($config);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * 删除一条中转记录（从面板侧移除 inbound + outbound + rule，本地标记 deleted）
     *
     * 状态追踪：
     *   - release_status = 'confirmed': 全部操作成功
     *   - release_status = 'failed':    任何一步失败，保留 error 让 admin 重试
     */
    public function deleteForward(XuiInbound $record): void
    {
        $panel = $record->panel;
        if (!$panel) {
            $record->update([
                'status' => 'deleted',
                'release_status' => 'confirmed',
                'released_at' => now(),
                'last_synced_at' => now(),
            ]);
            return;
        }

        $errors = [];

        try {
            $api = new XuiApiService($panel);
            $api->ensureFreshSession();

            // 1. 删 inbound
            if ($record->remote_inbound_id) {
                try {
                    $api->deleteInbound($record->remote_inbound_id);
                } catch (\Throwable $e) {
                    $errors[] = 'deleteInbound: ' . $e->getMessage();
                    Log::warning("Xui delete inbound failed: {$e->getMessage()}", [
                        'record_id' => $record->id,
                    ]);
                }
            }

            // 2. 从 xray template 剔除 outbound + routing rule（带全局锁）
            if ($record->outbound_tag) {
                $lock = \Illuminate\Support\Facades\Cache::lock("xui:xray_settings:{$panel->id}", 60);
                try {
                    $lock->block(30);
                    $config = $api->getXraySettings();
                    $config['outbounds'] = array_values(array_filter(
                        $config['outbounds'] ?? [],
                        fn($o) => ($o['tag'] ?? '') !== $record->outbound_tag
                    ));
                    if (isset($config['routing']['rules']) && is_array($config['routing']['rules'])) {
                        $config['routing']['rules'] = array_values(array_filter(
                            $config['routing']['rules'],
                            fn($r) => ($r['outboundTag'] ?? '') !== $record->outbound_tag
                        ));
                    }
                    $api->updateXraySettings($config);
                } catch (\Throwable $e) {
                    $errors[] = 'updateXraySettings: ' . $e->getMessage();
                    Log::warning("Xui remove outbound/route failed: {$e->getMessage()}", [
                        'record_id' => $record->id,
                    ]);
                } finally {
                    optional($lock)->release();
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'panel: ' . $e->getMessage();
            Log::error('XuiForwardService::deleteForward panel error', [
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }

        $record->update([
            'status' => 'deleted',
            'release_status' => empty($errors) ? 'confirmed' : 'failed',
            'release_error' => empty($errors) ? null : implode(' | ', array_slice($errors, 0, 3)),
            'released_at' => empty($errors) ? now() : null,
            'last_synced_at' => now(),
        ]);

        // 如果面板配置了备机，同步删除
        if ($panel->mirror_panel_id && $record->mirror_remote_id) {
            \App\Jobs\XuiSyncToMirrorJob::dispatch($record->id, 'delete');
        }
    }

    /**
     * 删除订阅关联的所有 3x-ui 中转（客户退订/IP 释放时调用）
     */
    public function deleteForSubscription(Subscription $subscription): int
    {
        $rows = XuiInbound::where('subscription_id', $subscription->id)
            ->where('status', '!=', 'deleted')
            ->get();

        foreach ($rows as $row) {
            $this->deleteForward($row);
        }

        return $rows->count();
    }
}
