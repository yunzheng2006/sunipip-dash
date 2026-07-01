<?php

namespace App\Services\Xui;

use App\Models\ProxyIp;
use App\Models\XuiInbound;
use App\Models\XuiPanel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 3x-ui 主备同步服务
 *
 * 职责：把主机（primary）成功的写操作 replay 到备机（mirror），
 * 保证两台面板的 inbound / xray template 配置一致，
 * 故障切换时客户只需改 DNS 就能无缝切到备机。
 *
 * 原则：
 *   1. 同步是 best-effort：失败只记录 mirror_sync_status=failed，
 *      不影响主机主流程（主机成功即可出票）
 *   2. 幂等：所有 replay 操作按 remark 精确匹配，防止重复创建
 *   3. 配置相同：uuid / reality 密钥 / short_id / port 从主机记录拷贝，
 *      确保 vless URL 只需换 host
 */
class XuiMirrorService
{
    /**
     * 把主机上的一条 XuiInbound 同步到备机
     *
     * 依赖：record.status == 'active'，说明主机已成功
     */
    public function syncInboundToMirror(XuiInbound $record): void
    {
        $primary = $record->panel;
        if (!$primary || !$primary->mirror_panel_id) {
            return; // 未配置备机
        }

        $mirror = XuiPanel::find($primary->mirror_panel_id);
        if (!$mirror || !$mirror->is_active) {
            $record->update([
                'mirror_sync_status' => 'failed',
                'mirror_sync_error' => '备机未启用或不存在',
            ]);
            return;
        }

        try {
            $api = new XuiApiService($mirror);
            $api->ensureFreshSession();

            // 检查备机是否已存在同 remark 的 inbound（幂等）
            $existing = $this->findInboundByRemark($api, $record->remark);
            if ($existing) {
                $record->update([
                    'mirror_remote_id' => (int) ($existing['id'] ?? 0),
                    'mirror_sync_status' => 'synced',
                    'mirror_sync_error' => null,
                    'mirror_synced_at' => now(),
                ]);
                return;
            }

            // 构造和主机完全相同的 inbound payload
            // 策略：备机**重用主机的 reality 密钥对 + uuid + short_id + port**
            // 两台服务器生成的 vless URL 完全一致（只差 host），
            // 故障时 DNS 从主机 IP 切到备机 IP，客户无需改任何配置，真无感切换。
            //
            // 安全权衡：x25519 私钥同时存在于主备两台 3x-ui 上（可接受）
            $privateKey = $record->private_key;
            $publicKey = $record->public_key;
            if (!$privateKey || !$publicKey) {
                // 兜底：老记录没存密钥，退化成重新生成
                $keys = $api->generateRealityKeypair();
                $privateKey = $keys['privateKey'];
                $publicKey = $keys['publicKey'];
            }

            $settings = [
                'clients' => [
                    [
                        'id' => $record->client_uuid, // 复用主机 uuid
                        'flow' => $record->flow ?: '',
                        'email' => $record->remark,
                        'limitIp' => 0,
                        'totalGB' => 0,
                        'expiryTime' => 0,
                        'enable' => true,
                        'tgId' => '',
                        'subId' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(16)),
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
                    'shortIds' => [$record->short_id ?: bin2hex(random_bytes(4))],
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
                'remark' => $record->remark,
                'enable' => true,
                'listen' => '',
                'port' => $record->port ?: random_int(20000, 60000), // 尝试复用主机端口
                'protocol' => 'vless',
                'expiryTime' => 0,
                'settings' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'streamSettings' => json_encode($streamSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sniffing' => json_encode($sniffing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            // 尝试用主机端口，如果被占用则让 3x-ui 自动分配
            try {
                $created = $api->addInbound($payload);
            } catch (\Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'port')) {
                    $payload['port'] = random_int(20000, 60000);
                    $created = $api->addInbound($payload);
                } else {
                    throw $e;
                }
            }

            $mirrorRemoteId = (int) ($created['id'] ?? 0);

            // 同步 outbound + routing rule 到备机（带 lock）
            $proxyIp = $record->proxyIp;
            if ($proxyIp && $record->outbound_tag) {
                $this->syncOutboundAndRoute($api, $mirror, $record, $proxyIp);
            }

            $record->update([
                'mirror_remote_id' => $mirrorRemoteId,
                'mirror_sync_status' => 'synced',
                'mirror_sync_error' => null,
                'mirror_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('XuiMirrorService: syncInboundToMirror failed', [
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
            $record->update([
                'mirror_sync_status' => 'failed',
                'mirror_sync_error' => substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * 同步删除：把主机已删除的 inbound 从备机移除
     */
    public function syncDeleteToMirror(XuiInbound $record): void
    {
        $primary = XuiPanel::find($record->xui_panel_id);
        if (!$primary || !$primary->mirror_panel_id) {
            return;
        }

        $mirror = XuiPanel::find($primary->mirror_panel_id);
        if (!$mirror || !$mirror->is_active) {
            return;
        }

        try {
            $api = new XuiApiService($mirror);
            $api->ensureFreshSession();

            // 用 mirror_remote_id 或按 remark 搜
            $remoteId = $record->mirror_remote_id;
            if (!$remoteId) {
                $existing = $this->findInboundByRemark($api, $record->remark);
                $remoteId = $existing['id'] ?? null;
            }

            if ($remoteId) {
                try {
                    $api->deleteInbound((int) $remoteId);
                } catch (\Throwable $e) {
                    Log::warning("Mirror delete inbound failed: {$e->getMessage()}");
                }
            }

            // 从备机 xray template 剔除对应 outbound+routing
            if ($record->outbound_tag) {
                $lock = Cache::lock("xui:xray_settings:{$mirror->id}", 60);
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
                } finally {
                    optional($lock)->release();
                }
            }

            $record->update([
                'mirror_sync_status' => 'synced',
                'mirror_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('XuiMirrorService: syncDeleteToMirror failed', [
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
            $record->update([
                'mirror_sync_status' => 'failed',
                'mirror_sync_error' => substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * 在 3x-ui 中按 remark 查找 inbound
     */
    private function findInboundByRemark(XuiApiService $api, string $remark): ?array
    {
        try {
            $list = $api->listInbounds();
            foreach ($list as $inbound) {
                if (($inbound['remark'] ?? '') === $remark) {
                    return $inbound;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Mirror findInboundByRemark failed: {$e->getMessage()}");
        }
        return null;
    }

    /**
     * 在备机追加 socks5 outbound + routing rule，和主机一致
     */
    private function syncOutboundAndRoute(
        XuiApiService $api,
        XuiPanel $mirror,
        XuiInbound $record,
        ProxyIp $proxyIp,
    ): void {
        $lock = Cache::lock("xui:xray_settings:{$mirror->id}", 60);
        $lock->block(30);

        try {
            $config = $api->getXraySettings();

            // 检查是否已存在同 tag 的 outbound（幂等）
            $existing = collect($config['outbounds'] ?? [])
                ->first(fn($o) => ($o['tag'] ?? '') === $record->outbound_tag);
            if (!$existing) {
                $config['outbounds'] = $config['outbounds'] ?? [];
                $config['outbounds'][] = [
                    'tag' => $record->outbound_tag,
                    'protocol' => 'socks',
                    'settings' => [
                        'servers' => [
                            [
                                'address' => $proxyIp->ip_address,
                                'port' => (int) $proxyIp->port,
                                'users' => ($proxyIp->auth_username || $proxyIp->auth_password)
                                    ? [[
                                        'user' => (string) $proxyIp->auth_username,
                                        'pass' => (string) $proxyIp->auth_password,
                                    ]]
                                    : [],
                            ],
                        ],
                    ],
                ];
            }

            // routing rule 幂等
            if (!isset($config['routing']) || !is_array($config['routing'])) {
                $config['routing'] = ['rules' => []];
            }
            if (!isset($config['routing']['rules']) || !is_array($config['routing']['rules'])) {
                $config['routing']['rules'] = [];
            }
            $ruleExists = collect($config['routing']['rules'])
                ->contains(fn($r) => ($r['outboundTag'] ?? '') === $record->outbound_tag);
            if (!$ruleExists) {
                array_unshift($config['routing']['rules'], [
                    'type' => 'field',
                    'user' => [$record->remark],
                    'outboundTag' => $record->outbound_tag,
                ]);
            }

            $api->updateXraySettings($config);
        } finally {
            optional($lock)->release();
        }
    }
}
