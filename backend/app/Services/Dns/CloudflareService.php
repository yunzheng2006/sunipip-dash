<?php

namespace App\Services\Dns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare DNS API 客户端
 *
 * 用 API token（不是 Global API Key），权限只需：
 *   Zone.DNS.Edit
 *
 * 主要接口：
 *   GET  /client/v4/zones/{zone_id}/dns_records/{record_id}
 *   PATCH /client/v4/zones/{zone_id}/dns_records/{record_id}  {content: "ip"}
 */
class CloudflareService
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        public string $apiToken,
    ) {}

    /**
     * 获取 DNS 记录当前配置
     */
    public function getRecord(string $zoneId, string $recordId): array
    {
        $response = Http::timeout(15)
            ->withToken($this->apiToken)
            ->get(self::BASE . "/zones/{$zoneId}/dns_records/{$recordId}");

        $data = $response->json();
        if (!$response->successful() || !($data['success'] ?? false)) {
            throw new CloudflareException(
                'CF getRecord failed: ' . json_encode($data['errors'] ?? $data)
            );
        }
        return $data['result'] ?? [];
    }

    /**
     * 改 DNS 记录 content（IP）
     *
     * @return array 完整的 CF 响应
     */
    public function updateRecordIp(string $zoneId, string $recordId, string $newIp): array
    {
        // 先查一下拿到 name / type 等不变字段，避免 PATCH 丢字段
        $current = $this->getRecord($zoneId, $recordId);

        $response = Http::timeout(15)
            ->withToken($this->apiToken)
            ->patch(self::BASE . "/zones/{$zoneId}/dns_records/{$recordId}", [
                'content' => $newIp,
                'ttl' => $current['ttl'] ?? 60,
                'proxied' => false, // vless+reality 必须直连，不能走 CF 代理
            ]);

        $data = $response->json();
        if (!$response->successful() || !($data['success'] ?? false)) {
            Log::error('CF updateRecordIp failed', [
                'zone_id' => $zoneId,
                'record_id' => $recordId,
                'new_ip' => $newIp,
                'response' => $data,
            ]);
            throw new CloudflareException(
                'CF updateRecordIp failed: ' . json_encode($data['errors'] ?? $data)
            );
        }
        return $data;
    }
}
