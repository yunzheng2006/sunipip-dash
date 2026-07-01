<?php

namespace App\Services\Feishu;

use App\Models\FeishuSyncConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 飞书多维表格 API 客户端
 *
 * 认证：tenant_access_token（app_id + app_secret → internal token）
 * 缓存：写入 feishu_sync_configs.cached_token + token_expires_at
 */
class FeishuBitableService
{
    private const BASE = 'https://open.feishu.cn/open-apis';

    public function __construct(protected FeishuSyncConfig $config) {}

    // ========== Auth ==========

    public function getToken(bool $force = false): string
    {
        if (
            !$force
            && $this->config->cached_token
            && $this->config->token_expires_at
            && $this->config->token_expires_at->isFuture()
        ) {
            return $this->config->cached_token;
        }

        $resp = Http::timeout(10)->post(self::BASE . '/auth/v3/tenant_access_token/internal', [
            'app_id' => $this->config->app_id,
            'app_secret' => $this->config->app_secret,
        ]);

        $data = $resp->json();
        if (($data['code'] ?? -1) !== 0) {
            throw new FeishuException('飞书认证失败: ' . ($data['msg'] ?? json_encode($data)));
        }

        $token = $data['tenant_access_token'];
        // 飞书 token 有效期 2h，我们存 110 分钟
        $this->config->update([
            'cached_token' => $token,
            'token_expires_at' => now()->addMinutes(110),
        ]);

        return $token;
    }

    // ========== Fields ==========

    public function listFields(): array
    {
        $data = $this->get("/bitable/v1/apps/{$this->config->app_token}/tables/{$this->config->table_id}/fields");
        return $data['data']['items'] ?? [];
    }

    // ========== Media (Attachment) ==========

    /**
     * 上传文件到飞书多维表格（用于 Attachment 类型字段）
     *
     * parent_node 必须是真实的 bitable app_token。
     * 如果表嵌在知识库（wiki）中，config->app_token 可能是 wiki token，
     * 需通过 resolveUploadParentNode() 自动解析出真实 token。
     */
    public function uploadMedia(string $filename, string $binaryData, string $mimeType = 'image/png'): string
    {
        $token = $this->getToken();
        $size = strlen($binaryData);
        $parentNode = $this->resolveUploadParentNode();

        $response = Http::timeout(30)
            ->withToken($token)
            ->attach('file', $binaryData, $filename)
            ->post(self::BASE . "/drive/v1/medias/upload_all", [
                'file_name' => $filename,
                'parent_type' => 'bitable_image',
                'parent_node' => $parentNode,
                'size' => $size,
            ]);

        $data = $response->json();
        if (!$response->successful() || ($data['code'] ?? -1) !== 0) {
            throw new FeishuException('飞书上传文件失败: ' . ($data['msg'] ?? $response->body()));
        }

        return $data['data']['file_token'] ?? '';
    }

    /**
     * 解析上传所需的真实 bitable token
     *
     * Wiki 嵌入的多维表格：记录增删改可以用 wiki token，但文件上传必须用真实 bitable token。
     * 此方法通过飞书 API 自动解析 wiki token → 真实 token 并缓存到 DB。
     * 如果解析失败（权限不够），需要用户在"测试连接"后手动确认或在管理面板设置。
     */
    private function resolveUploadParentNode(): string
    {
        // 已缓存的真实 token
        if ($this->config->real_app_token) {
            return $this->config->real_app_token;
        }

        $appToken = $this->config->app_token;
        $token = $this->getToken();

        // 方法1: wiki/v2/spaces/get_node（需要 wiki:wiki:readonly 权限）
        try {
            $resp = Http::timeout(10)
                ->withToken($token)
                ->get(self::BASE . "/wiki/v2/spaces/get_node", ['token' => $appToken]);

            $data = $resp->json();
            Log::debug("FeishuBitable: wiki get_node response", [
                'config_id' => $this->config->id,
                'code' => $data['code'] ?? 'N/A',
                'has_obj_token' => !empty($data['data']['node']['obj_token']),
            ]);
            if (($data['code'] ?? -1) === 0 && !empty($data['data']['node']['obj_token'])) {
                $realToken = $data['data']['node']['obj_token'];
                $this->config->updateQuietly(['real_app_token' => $realToken]);
                Log::info("FeishuBitable: resolved wiki → real token: {$realToken}", ['config_id' => $this->config->id]);
                return $realToken;
            }
        } catch (\Throwable $e) {
            Log::debug("FeishuBitable: wiki get_node failed: {$e->getMessage()}");
        }

        // 方法2: drive/v1/metas/batch_query（需要 drive:drive:readonly 权限）
        try {
            $resp = Http::timeout(10)
                ->withToken($token)
                ->asJson()
                ->post(self::BASE . "/drive/v1/metas/batch_query", [
                    'request_docs' => [
                        ['doc_token' => $appToken, 'doc_type' => 'wiki'],
                    ],
                ]);

            $respData = $resp->json();
            Log::debug("FeishuBitable: batch_query response", [
                'config_id' => $this->config->id,
                'code' => $respData['code'] ?? 'N/A',
                'metas' => $respData['data']['metas'] ?? [],
            ]);
            $metas = $respData['data']['metas'] ?? [];
            if (!empty($metas[0]['doc_token']) && $metas[0]['doc_token'] !== $appToken) {
                $realToken = $metas[0]['doc_token'];
                $this->config->updateQuietly(['real_app_token' => $realToken]);
                Log::info("FeishuBitable: resolved wiki → real token via batch_query: {$realToken}", ['config_id' => $this->config->id]);
                return $realToken;
            }
        } catch (\Throwable $e) {
            Log::debug("FeishuBitable: batch_query failed: {$e->getMessage()}");
        }

        // 解析失败 → 不缓存错误值，直接用 app_token 尝试（可能是普通 bitable token）
        Log::warning("FeishuBitable: could not resolve real app_token, using app_token as-is", [
            'config_id' => $this->config->id,
            'app_token' => $appToken,
        ]);
        return $appToken;
    }

    /**
     * 公开方法：尝试解析并返回真实 bitable token（供 testConnection 调用）
     */
    public function detectRealAppToken(): ?string
    {
        $appToken = $this->config->app_token;
        $token = $this->getToken();

        // wiki/v2/spaces/get_node
        try {
            $resp = Http::timeout(10)
                ->withToken($token)
                ->get(self::BASE . "/wiki/v2/spaces/get_node", ['token' => $appToken]);

            $data = $resp->json();
            if (($data['code'] ?? -1) === 0 && !empty($data['data']['node']['obj_token'])) {
                return $data['data']['node']['obj_token'];
            }
        } catch (\Throwable) {}

        // drive/v1/metas/batch_query
        try {
            $resp = Http::timeout(10)
                ->withToken($token)
                ->asJson()
                ->post(self::BASE . "/drive/v1/metas/batch_query", [
                    'request_docs' => [
                        ['doc_token' => $appToken, 'doc_type' => 'wiki'],
                    ],
                ]);

            $metas = $resp->json()['data']['metas'] ?? [];
            if (!empty($metas[0]['doc_token']) && $metas[0]['doc_token'] !== $appToken) {
                return $metas[0]['doc_token'];
            }
        } catch (\Throwable) {}

        return null;
    }

    // ========== Records ==========

    /**
     * 拉取所有记录（自动分页）
     *
     * @return array [record_id => fields, ...]
     */
    public function listAllRecords(): array
    {
        $all = [];
        $pageToken = null;

        do {
            $params = ['page_size' => 500];
            if ($pageToken) {
                $params['page_token'] = $pageToken;
            }

            $data = $this->get(
                "/bitable/v1/apps/{$this->config->app_token}/tables/{$this->config->table_id}/records",
                $params
            );

            $items = $data['data']['items'] ?? [];
            foreach ($items as $item) {
                $all[$item['record_id']] = $item['fields'];
            }

            $pageToken = ($data['data']['has_more'] ?? false) ? ($data['data']['page_token'] ?? null) : null;
        } while ($pageToken);

        return $all;
    }

    /**
     * 批量创建记录（最多 500 条 / 次）
     *
     * @param array $records [['fields' => [...]], ...]
     * @return array 创建的记录
     */
    public function batchCreate(array $records): array
    {
        $chunks = array_chunk($records, 500);
        $created = [];

        foreach ($chunks as $chunk) {
            $data = $this->post(
                "/bitable/v1/apps/{$this->config->app_token}/tables/{$this->config->table_id}/records/batch_create",
                ['records' => $chunk]
            );
            $created = array_merge($created, $data['data']['records'] ?? []);
            usleep(200000); // 200ms 限流缓冲
        }

        return $created;
    }

    /**
     * 批量更新记录（最多 500 条 / 次）
     *
     * @param array $records [['record_id' => 'recXXX', 'fields' => [...]], ...]
     */
    public function batchUpdate(array $records): array
    {
        $chunks = array_chunk($records, 500);
        $updated = [];

        foreach ($chunks as $chunk) {
            $data = $this->post(
                "/bitable/v1/apps/{$this->config->app_token}/tables/{$this->config->table_id}/records/batch_update",
                ['records' => $chunk]
            );
            $updated = array_merge($updated, $data['data']['records'] ?? []);
            usleep(200000);
        }

        return $updated;
    }

    /**
     * 批量删除记录
     *
     * @param array $recordIds ['recXXX', ...]
     */
    public function batchDelete(array $recordIds): void
    {
        $chunks = array_chunk($recordIds, 500);
        foreach ($chunks as $chunk) {
            $this->post(
                "/bitable/v1/apps/{$this->config->app_token}/tables/{$this->config->table_id}/records/batch_delete",
                ['records' => $chunk]
            );
            usleep(200000);
        }
    }

    // ========== HTTP helpers ==========

    private function get(string $path, array $query = []): array
    {
        $token = $this->getToken();
        $url = self::BASE . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $resp = Http::timeout(15)
            ->withToken($token)
            ->get($url);

        return $this->parseResponse($resp, "GET {$path}");
    }

    private function post(string $path, array $body): array
    {
        $token = $this->getToken();
        $resp = Http::timeout(30)
            ->withToken($token)
            ->asJson()
            ->post(self::BASE . $path, $body);

        return $this->parseResponse($resp, "POST {$path}");
    }

    private function parseResponse($resp, string $context): array
    {
        if (!$resp->successful()) {
            Log::warning("Feishu API {$context} HTTP {$resp->status()}", [
                'config_id' => $this->config->id,
                'body' => substr($resp->body(), 0, 500),
            ]);
            throw new FeishuException("飞书 HTTP {$resp->status()}: {$context}");
        }

        $data = $resp->json();
        if (($data['code'] ?? -1) !== 0) {
            throw new FeishuException("飞书 API 错误: " . ($data['msg'] ?? '') . " (code={$data['code']}, {$context})");
        }

        return $data;
    }
}
