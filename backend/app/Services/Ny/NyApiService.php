<?php

namespace App\Services\Ny;

use App\Models\NyPanel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nyanpass 面板 API 客户端
 *
 * 封装认证 token 缓存 + 自动重登录 + 核心接口调用。
 *
 * 文档：ny接口.md（项目根目录）
 */
class NyApiService
{
    private const TOKEN_TTL_HOURS = 12;

    public function __construct(protected NyPanel $panel) {}

    /**
     * 获取有效的 Authorization token（必要时自动重新登录）
     *
     * @throws NyApiException
     */
    public function getToken(bool $forceRelogin = false): string
    {
        if (!$forceRelogin && $this->panel->last_token && $this->panel->token_expires_at?->isFuture()) {
            return $this->panel->last_token;
        }

        return $this->login();
    }

    /**
     * POST /auth/login
     *
     * @throws NyApiException
     */
    public function login(): string
    {
        $response = Http::timeout(15)->asJson()->post(
            $this->panel->normalizedBase() . '/auth/login',
            [
                'username' => $this->panel->username,
                'password' => $this->panel->password,
            ]
        );

        $data = $this->parseResponse($response, '登录失败');
        $token = $data['data'] ?? null;
        if (!$token) {
            throw new NyApiException('NY 登录未返回 token');
        }

        $this->panel->update([
            'last_token' => $token,
            'token_expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
        ]);

        return $token;
    }

    /**
     * GET /user/devicegroup
     *
     * @return array 设备组列表
     * @throws NyApiException
     */
    public function listDeviceGroups(): array
    {
        $data = $this->authenticatedGet('/user/devicegroup');
        return $data['data'] ?? [];
    }

    /**
     * GET /user/info
     *
     * @throws NyApiException
     */
    public function getUserInfo(): array
    {
        $data = $this->authenticatedGet('/user/info');
        return $data['data'] ?? [];
    }

    /**
     * PUT /user/forward - 创建转发
     *
     * @param array $payload {
     *   name: string,
     *   device_group_in: int,
     *   device_group_out: int|null,
     *   config: string (JSON string),
     *   listen_port?: int
     * }
     * @return array 原始响应（NY 只返回 {code, msg}，需配合 search_rules 才能拿到新 rule 详情）
     * @throws NyApiException
     */
    public function createForward(array $payload): array
    {
        return $this->authenticatedRequest('PUT', '/user/forward', $payload);
    }

    /**
     * POST /user/forward/search_rules - 按条件搜索规则
     *
     * @throws NyApiException
     */
    public function searchRules(array $filters): array
    {
        $data = $this->authenticatedRequest('POST', '/user/forward/search_rules', $filters);
        return $data['data'] ?? [];
    }

    /**
     * DELETE /user/forward - 删除规则
     *
     * @param array $ids rule id 列表
     * @throws NyApiException
     */
    public function deleteForwards(array $ids): array
    {
        return $this->authenticatedRequest('DELETE', '/user/forward', ['ids' => array_values($ids)]);
    }

    /**
     * POST /user/forward/{id} - 更新规则
     *
     * @throws NyApiException
     */
    public function updateForward(int $id, array $payload): array
    {
        return $this->authenticatedRequest('POST', "/user/forward/{$id}", $payload);
    }

    // ========== 内部方法 ==========

    /**
     * 每个 panel 之间至少间隔的毫秒数，防止触发 NY 429 限流。
     * NY 实测限频约 1 次/2-3 秒，设 2500ms 留足余量。
     * 所有 worker 共享同一个节流窗口（通过 Cache + atomic lock）。
     */
    private const MIN_INTERVAL_MS = 2500;

    /**
     * 429 触发后，下一次请求前强制额外等待的毫秒数
     */
    private const RATE_LIMIT_COOLDOWN_MS = 15000;

    /**
     * 跨进程节流：确保同一个 panel 的相邻 API 调用之间至少间隔 MIN_INTERVAL_MS。
     * 用 Cache atomic lock 实现，worker 之间互斥。
     */
    private function throttle(): void
    {
        $cacheKey = "ny_api_throttle:{$this->panel->id}";
        $cooldownKey = "ny_api_cooldown:{$this->panel->id}";

        // 如果最近触发过 429，等待冷却结束
        $cooldownUntil = \Illuminate\Support\Facades\Cache::get($cooldownKey);
        if ($cooldownUntil) {
            $waitMs = (int) ($cooldownUntil - microtime(true) * 1000);
            if ($waitMs > 0) {
                usleep($waitMs * 1000);
            }
        }

        // 节流锁
        $lock = \Illuminate\Support\Facades\Cache::lock("{$cacheKey}:lock", 10);
        $lock->block(15); // 最多阻塞等 15 秒拿到锁

        try {
            $lastMs = (float) \Illuminate\Support\Facades\Cache::get($cacheKey, 0);
            $nowMs = microtime(true) * 1000;
            $elapsed = $nowMs - $lastMs;

            if ($elapsed < self::MIN_INTERVAL_MS) {
                $waitMs = (int) (self::MIN_INTERVAL_MS - $elapsed);
                usleep($waitMs * 1000);
            }

            \Illuminate\Support\Facades\Cache::put(
                $cacheKey,
                microtime(true) * 1000,
                now()->addMinutes(5)
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * 标记 429 冷却
     */
    private function markRateLimited(): void
    {
        $cooldownKey = "ny_api_cooldown:{$this->panel->id}";
        \Illuminate\Support\Facades\Cache::put(
            $cooldownKey,
            microtime(true) * 1000 + self::RATE_LIMIT_COOLDOWN_MS,
            now()->addMinute()
        );
        Log::warning('NyApi rate limited (429), cooldown ' . self::RATE_LIMIT_COOLDOWN_MS . 'ms', [
            'panel_id' => $this->panel->id,
        ]);
    }

    /**
     * 带鉴权的 GET
     */
    private function authenticatedGet(string $path): array
    {
        $attempt = 0;
        while ($attempt < 2) {
            $this->throttle();
            $token = $this->getToken(forceRelogin: $attempt > 0);
            $response = Http::timeout(15)
                ->withHeaders(['Authorization' => $token])
                ->get($this->panel->normalizedBase() . $path);

            $data = $this->parseResponse($response, 'GET ' . $path);
            if (($data['code'] ?? 0) === 403 && $attempt === 0) {
                $attempt++;
                continue;
            }
            if (($data['code'] ?? 0) !== 0) {
                throw new NyApiException('NY API 错误: ' . ($data['msg'] ?? 'unknown') . ' (code=' . ($data['code'] ?? '?') . ')');
            }
            return $data;
        }
        throw new NyApiException('NY API 401 重试仍失败');
    }

    /**
     * 带鉴权的 PUT/POST/DELETE（带 body）
     */
    private function authenticatedRequest(string $method, string $path, array $payload): array
    {
        $attempt = 0;
        while ($attempt < 2) {
            $this->throttle();
            $token = $this->getToken(forceRelogin: $attempt > 0);
            $request = Http::timeout(20)
                ->withHeaders(['Authorization' => $token])
                ->asJson();

            $response = match (strtoupper($method)) {
                'PUT' => $request->put($this->panel->normalizedBase() . $path, $payload),
                'POST' => $request->post($this->panel->normalizedBase() . $path, $payload),
                'DELETE' => $request->delete($this->panel->normalizedBase() . $path, $payload),
                default => throw new NyApiException("Unsupported method {$method}"),
            };

            $data = $this->parseResponse($response, "{$method} {$path}");
            if (($data['code'] ?? 0) === 403 && $attempt === 0) {
                $attempt++;
                continue;
            }
            if (($data['code'] ?? 0) !== 0) {
                throw new NyApiException('NY API 错误: ' . ($data['msg'] ?? 'unknown') . ' (code=' . ($data['code'] ?? '?') . ')');
            }
            return $data;
        }
        throw new NyApiException("NY API 401 重试仍失败: {$method} {$path}");
    }

    /**
     * 解析响应并处理网络错误（含 429 限流标记）
     */
    private function parseResponse(Response $response, string $context): array
    {
        if (!$response->successful()) {
            $status = $response->status();

            // 429: 标记冷却并抛专用异常，让 Job 可以识别重试
            if ($status === 429) {
                $this->markRateLimited();
                throw new NyApiRateLimitException("NY API 限流 (429): {$context}");
            }

            Log::warning("NyApi {$context} http error", [
                'panel_id' => $this->panel->id,
                'status' => $status,
                'body' => $response->body(),
            ]);
            throw new NyApiException("NY API HTTP {$status}: {$context}");
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new NyApiException("NY API 响应非 JSON: {$context}");
        }
        return $json;
    }
}
