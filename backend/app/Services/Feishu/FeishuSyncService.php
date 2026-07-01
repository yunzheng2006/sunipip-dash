<?php

namespace App\Services\Feishu;

use App\Models\FeishuSyncConfig;
use App\Models\ProxyIp;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * 飞书多维表格同步器
 *
 * 同步方向：平台 → 飞书（单向推送）
 * 触发方式：事件驱动（续费/退订/转发变更后主动调用），不轮询
 *
 * 核心逻辑：
 *   1. 拉平台 IP 数据 → 构造飞书字段（含二维码图片）
 *   2. 拉飞书现有记录 → 按"代理socks5"主键比对
 *   3. 篡改检测：如果飞书侧的"我方管理字段"和上次推送时不一样 → webhook 告警
 *   4. 增量 create/update，附件单独上传
 */
class FeishuSyncService
{
    /**
     * @return array{created: int, updated: int, unchanged: int, deleted: int, tampered: int, errors: string[]}
     */
    public function sync(FeishuSyncConfig $config, bool $deleteOrphans = false): array
    {
        $api = new FeishuBitableService($config);
        $mapping = $config->effectiveMapping();
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'tampered' => 0, 'errors' => []];

        try {
            // 1. 平台数据
            $platformRows = $this->buildPlatformData($config);

            // 2. 飞书现有记录
            $feishuRecords = $api->listAllRecords();

            $primaryField = $mapping['socks5_raw'] ?? '代理socks5';
            $feishuByKey = [];
            foreach ($feishuRecords as $recId => $fields) {
                $key = $fields[$primaryField] ?? null;
                if ($key) {
                    $feishuByKey[$key] = ['record_id' => $recId, 'fields' => $fields];
                }
            }

            // 3. 篡改检测：飞书侧的"我方管理字段"和平台不一致的行
            $tamperedRows = [];

            // 4. 比对
            $toCreate = [];
            $toUpdate = [];
            // 需要上传二维码的记录 (record_id → qr_png_binary)
            $qrUploads = [];

            foreach ($platformRows as $socks5Raw => $row) {
                $qrData = $row['__qr_data'] ?? null; // 待上传的二维码数据
                unset($row['__qr_data']); // 不写入飞书字段

                if (isset($feishuByKey[$socks5Raw])) {
                    $existing = $feishuByKey[$socks5Raw];

                    // 篡改检测：检查飞书侧"我方管理字段"是否被人为修改
                    $tamperFields = $this->detectTamper($existing['fields'], $row, $mapping);
                    if (!empty($tamperFields)) {
                        $tamperedRows[] = [
                            'record_id' => $existing['record_id'],
                            'socks5' => $socks5Raw,
                            'tampered_fields' => $tamperFields,
                        ];
                        $stats['tampered']++;
                    }

                    // 正常的 diff → update
                    $diff = $this->diffFields($existing['fields'], $row, $mapping);

                    // 二维码独立判断：有转发二维码数据 + 飞书侧该字段为空 → 需要上传
                    $qrFieldName = $mapping['qr_image'] ?? '直连二维码';
                    $feishuHasQr = !empty($existing['fields'][$qrFieldName]);
                    $needQrUpload = $qrData && !$feishuHasQr;

                    if (!empty($diff)) {
                        $toUpdate[] = [
                            'record_id' => $existing['record_id'],
                            'fields' => $diff,
                        ];
                        if ($qrData) {
                            $qrUploads[$existing['record_id']] = $qrData;
                        }
                    } elseif ($needQrUpload) {
                        // 文本字段没变，但缺二维码 → 单独上传
                        $qrUploads[$existing['record_id']] = $qrData;
                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }
                    unset($feishuByKey[$socks5Raw]);
                } else {
                    $toCreate[] = ['fields' => $row, '__qr_data' => $qrData];
                }
            }

            // 5. 篡改告警
            if (!empty($tamperedRows)) {
                $this->sendTamperAlert($config, $tamperedRows);
            }

            // 6. 批量创建
            if (!empty($toCreate)) {
                try {
                    // 先创建记录（不含附件），拿到 record_id 后再上传二维码
                    $createPayload = array_map(function ($item) {
                        $fields = $item['fields'];
                        unset($fields['__qr_data']);
                        return ['fields' => $fields];
                    }, $toCreate);

                    $created = $api->batchCreate($createPayload);
                    $stats['created'] = count($created);

                    // 新建记录的二维码也入队
                    $urlField = $mapping['socks5_url'] ?? '直连链接';
                    foreach ($created as $idx => $rec) {
                        $qrData = $toCreate[$idx]['__qr_data'] ?? null;
                        $recId = $rec['record_id'] ?? null;
                        if ($qrData && $recId) {
                            $socksUrl = $toCreate[$idx]['fields'][$urlField] ?? null;
                            if ($socksUrl) {
                                \App\Jobs\FeishuUploadQrJob::dispatch($config->id, $recId, $socksUrl);
                                $stats['qr_queued'] = ($stats['qr_queued'] ?? 0) + 1;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = '创建失败: ' . $e->getMessage();
                    Log::error('FeishuSync batchCreate failed', ['config_id' => $config->id, 'error' => $e->getMessage()]);
                }
            }

            // 7. 批量更新（文本字段变化的行）
            if (!empty($toUpdate)) {
                try {
                    $api->batchUpdate($toUpdate);
                    $stats['updated'] += count($toUpdate);
                } catch (\Throwable $e) {
                    $stats['errors'][] = '更新失败: ' . $e->getMessage();
                }
            }

            // 8. 二维码上传入队（不阻塞同步响应）
            // $qrUploads 里的 value 现在是 socks:// URL 字符串
            if (!empty($qrUploads)) {
                $qrQueued = 0;
                foreach ($qrUploads as $recId => $socksUrl) {
                    if ($socksUrl) {
                        \App\Jobs\FeishuUploadQrJob::dispatch($config->id, $recId, $socksUrl);
                        $qrQueued++;
                    }
                }
                if ($qrQueued > 0) {
                    $stats['qr_queued'] = $qrQueued;
                }
            }

            // 9. 可选删除孤儿
            if ($deleteOrphans && !empty($feishuByKey)) {
                $orphanIds = array_map(fn($r) => $r['record_id'], array_values($feishuByKey));
                try {
                    $api->batchDelete($orphanIds);
                    $stats['deleted'] = count($orphanIds);
                } catch (\Throwable $e) {
                    $stats['errors'][] = '删除失败: ' . $e->getMessage();
                }
            }

            $config->update([
                'synced_count' => $stats['created'] + $stats['updated'] + $stats['unchanged'],
                'last_synced_at' => now(),
                'last_sync_error' => empty($stats['errors']) ? null : implode(' | ', $stats['errors']),
            ]);
        } catch (\Throwable $e) {
            $config->update(['last_sync_error' => $e->getMessage(), 'last_synced_at' => now()]);
            $stats['errors'][] = $e->getMessage();
            Log::error('FeishuSync failed', ['config_id' => $config->id, 'error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * 构造平台数据，每行包含 __qr_data（转发后二维码 PNG binary，需后续单独上传）
     */
    private function buildPlatformData(FeishuSyncConfig $config): array
    {
        $mapping = $config->effectiveMapping();

        $ips = ProxyIp::with([
                'activeSubscription.forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
            ])
            ->where('assigned_customer_id', $config->customer_id)
            ->where('status', 'assigned')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($ips as $ip) {
            $socks5Raw = implode(':', array_filter([
                $ip->ip_address, $ip->port, $ip->auth_username, $ip->auth_password,
            ]));

            $sub = $ip->activeSubscription;
            $fwd = $sub?->forwardRule;
            $forwardedSocks5 = '';
            $forwardedUrl = '';

            if ($fwd && $fwd->status === 'active' && $fwd->listen_port) {
                $host = $fwd->deviceGroup?->custom_connect_host
                    ?: $fwd->deviceGroup?->original_connect_host
                    ?: $ip->ip_address;
                $port = $fwd->listen_port;
                $forwardedSocks5 = "{$host}:{$port}:{$ip->auth_username}:{$ip->auth_password}";

                $authB64 = base64_encode("{$ip->auth_username}:{$ip->auth_password}");
                $remark = $ip->asset_name ?: "{$ip->country_name}-{$ip->ip_address}";
                $forwardedUrl = 'socks://' . rawurlencode($authB64) . "@{$host}:{$port}#" . rawurlencode($remark);
            }

            $fields = [];
            if ($m = $mapping['socks5_raw'] ?? null) $fields[$m] = $socks5Raw;
            if ($m = $mapping['socks5_forwarded'] ?? null) $fields[$m] = $forwardedSocks5;
            if ($m = $mapping['socks5_url'] ?? null) $fields[$m] = $forwardedUrl;
            if ($m = $mapping['label'] ?? null) $fields[$m] = $ip->asset_name ?: "{$ip->country_name}-{$ip->ip_address}";
            if ($m = $mapping['country'] ?? null) $fields[$m] = $ip->country_name ?: '';
            if (($m = $mapping['purchase_date'] ?? null) && $ip->created_at) {
                $fields[$m] = $ip->created_at->getTimestampMs();
            }
            if (($m = $mapping['expire_date'] ?? null) && $ip->upstream_expires_at) {
                $fields[$m] = $ip->upstream_expires_at->getTimestampMs();
            }

            // 标记是否有转发 URL（二维码由队列 Job 生成上传，这里只判断是否需要）
            $fields['__qr_data'] = $forwardedUrl ?: null;
            $rows[$socks5Raw] = $fields;
        }

        return $rows;
    }

    /**
     * 篡改检测：检查飞书侧的"我方管理字段"是否被人为修改
     *
     * 对比飞书当前值和平台即将写入的值，如果不一样说明有人在飞书手动改了
     * 注意：只检测"我方管理的列"（不含备注/备注1/续费情况等客户自行编辑的列）
     *
     * @return array 被篡改的字段列表 [{field: '直连socks5', feishu_value: 'xxx', platform_value: 'yyy'}, ...]
     */
    private function detectTamper(array $feishuFields, array $newFields, array $mapping): array
    {
        $tampered = [];
        // 只检测这几个"不应该被客户改"的字段
        $checkKeys = ['socks5_raw', 'socks5_forwarded', 'socks5_url', 'label', 'country'];

        foreach ($checkKeys as $mapKey) {
            $colName = $mapping[$mapKey] ?? null;
            if (!$colName) continue;

            $feishuVal = $feishuFields[$colName] ?? null;
            $platformVal = $newFields[$colName] ?? null;

            // 两边都为空 → 正常
            if (empty($feishuVal) && empty($platformVal)) continue;
            // 飞书有值但平台即将写入不同的值 → 可能篡改
            if ($feishuVal !== null && $platformVal !== null && (string) $feishuVal !== (string) $platformVal) {
                $tampered[] = [
                    'field' => $colName,
                    'feishu_value' => is_string($feishuVal) ? \Illuminate\Support\Str::limit($feishuVal, 80) : $feishuVal,
                    'platform_value' => is_string($platformVal) ? \Illuminate\Support\Str::limit($platformVal, 80) : $platformVal,
                ];
            }
        }

        return $tampered;
    }

    /**
     * 发送篡改告警到企业微信
     */
    private function sendTamperAlert(FeishuSyncConfig $config, array $tamperedRows): void
    {
        $count = count($tamperedRows);
        $samples = array_slice($tamperedRows, 0, 5);
        $detail = '';
        foreach ($samples as $row) {
            $detail .= sprintf("\n> **%s**", $row['socks5']);
            foreach ($row['tampered_fields'] as $tf) {
                $detail .= sprintf(
                    "\n>   - `%s`: 飞书=`%s` → 平台=`%s`",
                    $tf['field'], $tf['feishu_value'], $tf['platform_value']
                );
            }
        }

        try {
            app(NotificationService::class)->dispatch('dns_probe_failed', [
                'title' => '⚠️ 飞书表格数据篡改检测',
                'content' => sprintf(
                    "**同步配置**：%s（客户：%s）\n\n**检测到 %d 条记录与平台数据不一致**\n\n"
                    . "可能有人在飞书表格中手动修改了记录，平台将以平台数据覆盖。\n\n"
                    . "**样例：**%s\n\n"
                    . "> 请排查是否有人为修改记录，人为修改可能导致平台数据冲突，请谨慎/避免人为操作。",
                    $config->name,
                    $config->customer?->customer_name ?? '?',
                    $count,
                    $detail ?: '(无详情)'
                ),
                'related_type' => 'FeishuSyncConfig',
                'related_id' => $config->id,
                'dedup_key' => "feishu_tamper_{$config->id}_" . now()->format('Ymd_H'),
            ]);
        } catch (\Throwable $e) {
            Log::warning("FeishuSync tamper alert dispatch failed: {$e->getMessage()}");
        }
    }

    /**
     * 比对字段差异（只含变化的）
     */
    private function diffFields(array $existingFields, array $newFields, array $mapping): array
    {
        $diff = [];
        $managedColumns = array_values(array_filter($mapping));

        foreach ($newFields as $col => $newVal) {
            if ($col === '__qr_data') continue;
            if (!in_array($col, $managedColumns, true)) continue;

            $oldVal = $existingFields[$col] ?? null;

            if (is_int($newVal) && is_int($oldVal) && abs($newVal - $oldVal) < 60000) continue;
            if ((string) $oldVal !== (string) $newVal) {
                $diff[$col] = $newVal;
            }
        }

        return $diff;
    }

}
