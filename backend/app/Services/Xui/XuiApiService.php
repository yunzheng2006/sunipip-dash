<?php

namespace App\Services\Xui;

use App\Models\XuiPanel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 3x-ui HTTP 客户端
 *
 * 认证：cookie-based
 *   POST /login → 返回 Set-Cookie，我们存 cookie string 到 DB
 *   后续请求带 Cookie header
 *
 * base URL: {api_url}/{optional-basepath}/
 *   所有 path 相对 base 追加，例如 /login、/panel/api/inbounds/list
 *
 * 3x-ui 响应统一格式：{ success: bool, msg: string, obj: ... }
 */
class XuiApiService
{
    public function __construct(protected XuiPanel $panel) {}

    // ========== Cookie / Login ==========

    /**
     * 登录并缓存 cookie
     *
     * @return string cookie（形如 "3x-ui=xxxxx"）
     */
    public function login(): string
    {
        $response = Http::timeout(15)
            ->withOptions(['verify' => false]) // 面板常用自签证书
            ->asForm()
            ->post($this->panel->normalizedBase() . '/login', [
                'username' => $this->panel->username,
                'password' => $this->panel->password,
            ]);

        if (!$response->successful()) {
            Log::warning('Xui login http error', [
                'panel_id' => $this->panel->id,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            throw new XuiApiException("3x-ui 登录 HTTP {$response->status()}");
        }

        $data = $response->json();
        if (!is_array($data) || empty($data['success'])) {
            $msg = $data['msg'] ?? '未知';
            throw new XuiApiException("3x-ui 登录失败: {$msg}");
        }

        // 提取 Set-Cookie header
        $setCookies = $response->headers()['Set-Cookie'] ?? [];
        if (!is_array($setCookies)) {
            $setCookies = [$setCookies];
        }

        $cookiePairs = [];
        foreach ($setCookies as $sc) {
            // 取 "name=value" 之前的部分，忽略 Path/HttpOnly/Expires 等属性
            $firstSegment = explode(';', $sc)[0] ?? '';
            if ($firstSegment && str_contains($firstSegment, '=')) {
                $cookiePairs[] = trim($firstSegment);
            }
        }

        if (empty($cookiePairs)) {
            throw new XuiApiException('3x-ui 登录成功但未返回 cookie');
        }

        $cookie = implode('; ', $cookiePairs);
        $this->panel->update([
            'session_cookie' => $cookie,
            // 3x-ui session 默认 1h，我们用 20 分钟留足缓冲
            'cookie_expires_at' => now()->addMinutes(20),
        ]);

        return $cookie;
    }

    /**
     * 关键操作前强制刷新 session（绕过 TTL 检查）
     * 建议在批量前、手动创建中转前调用
     */
    public function ensureFreshSession(): void
    {
        $this->login();
    }

    /**
     * 获取有效 cookie，过期则重新登录
     */
    public function getCookie(bool $forceRelogin = false): string
    {
        if ($forceRelogin) {
            return $this->login();
        }

        if (
            $this->panel->session_cookie
            && $this->panel->cookie_expires_at
            && $this->panel->cookie_expires_at->isFuture()
        ) {
            return $this->panel->session_cookie;
        }

        return $this->login();
    }

    // ========== Inbound API ==========

    public function listInbounds(): array
    {
        $data = $this->authenticatedRequest('GET', '/panel/api/inbounds/list');
        return $data['obj'] ?? [];
    }

    /**
     * 添加入站规则
     *
     * @param array $payload {
     *   remark, enable, port, protocol, settings(JSON string), streamSettings(JSON string),
     *   sniffing(JSON string), listen, ...
     * }
     * @return array 3x-ui 返回的 obj（新 inbound 对象）
     */
    public function addInbound(array $payload): array
    {
        $data = $this->authenticatedRequest('POST', '/panel/api/inbounds/add', $payload, asForm: false);
        // 3x-ui 的 add 返回 obj 是完整 inbound 模型（含 id）
        return $data['obj'] ?? [];
    }

    public function deleteInbound(int $inboundId): array
    {
        return $this->authenticatedRequest('POST', "/panel/api/inbounds/del/{$inboundId}");
    }

    public function getInbound(int $inboundId): array
    {
        $data = $this->authenticatedRequest('GET', "/panel/api/inbounds/get/{$inboundId}");
        return $data['obj'] ?? [];
    }

    // ========== Reality Keypair ==========

    /**
     * 生成新的 x25519 密钥对
     *
     * @return array{privateKey: string, publicKey: string}
     */
    public function generateRealityKeypair(): array
    {
        $paths = [
            '/server/getNewX25519Cert',
            '/panel/server/getNewX25519Cert',
            '/panel/api/server/getNewX25519Cert',
        ];

        $lastError = null;
        foreach ($paths as $path) {
            try {
                $data = $this->authenticatedRequest('POST', $path);
                $obj = $data['obj'] ?? $data;
                if (isset($obj['privateKey']) && isset($obj['publicKey'])) {
                    return [
                        'privateKey' => $obj['privateKey'],
                        'publicKey' => $obj['publicKey'],
                    ];
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                continue;
            }
        }

        throw new XuiApiException('无法生成 Reality 密钥对：' . ($lastError ?? '所有路径均失败'));
    }

    // ========== Xray 全局配置（outbounds + routing）==========

    /**
     * 获取当前 Xray 模板配置（包含 outbounds 和 routing.rules）
     *
     * @return array 解析后的 Xray config
     */
    public function getXraySettings(): array
    {
        $data = $this->authenticatedRequest('POST', '/panel/setting/all');
        $obj = $data['obj'] ?? [];

        $template = $obj['xrayTemplateConfig'] ?? null;
        if (!$template) {
            throw new XuiApiException('3x-ui 未返回 xrayTemplateConfig');
        }

        $decoded = is_string($template) ? json_decode($template, true) : $template;
        if (!is_array($decoded)) {
            throw new XuiApiException('xrayTemplateConfig 不是有效 JSON');
        }
        return $decoded;
    }

    /**
     * 更新 Xray 模板配置
     */
    public function updateXraySettings(array $xrayConfig): void
    {
        $payload = [
            'xrayTemplateConfig' => json_encode($xrayConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $this->authenticatedRequest('POST', '/panel/setting/update', $payload, asForm: true);
    }

    // ========== 内部方法 ==========

    /**
     * 带 cookie 的 HTTP 请求，401 / 会话失效自动重登一次
     */
    private function authenticatedRequest(
        string $method,
        string $path,
        array $payload = [],
        bool $asForm = false,
    ): array {
        $attempt = 0;

        while ($attempt < 2) {
            $cookie = $this->getCookie(forceRelogin: $attempt > 0);
            $url = $this->panel->normalizedBase() . $path;

            $client = Http::timeout(20)
                ->withOptions(['verify' => false])
                ->withHeaders([
                    'Cookie' => $cookie,
                    'Accept' => 'application/json',
                ]);

            if ($asForm) {
                $client = $client->asForm();
            } else {
                $client = $client->asJson();
            }

            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $payload),
                'POST' => $client->post($url, $payload),
                'PUT' => $client->put($url, $payload),
                'DELETE' => $client->delete($url, $payload),
                default => throw new XuiApiException("不支持的方法 {$method}"),
            };

            $data = $this->parseResponse($response, "{$method} {$path}");

            // 3x-ui 未鉴权时会返回 HTML 登录页或 success=false，检测并重试
            if (isset($data['__unauthenticated']) && $attempt === 0) {
                $attempt++;
                continue;
            }

            if (isset($data['success']) && $data['success'] === false) {
                throw new XuiApiException('3x-ui: ' . ($data['msg'] ?? '未知错误') . " ({$method} {$path})");
            }

            return $data;
        }

        throw new XuiApiException("3x-ui 请求失败: {$method} {$path}");
    }

    private function parseResponse(Response $response, string $context): array
    {
        $status = $response->status();
        $body = $response->body();

        if (!$response->successful()) {
            // 401 或 302（被重定向到登录页）视为需要重登
            if ($status === 401 || $status === 302) {
                return ['__unauthenticated' => true];
            }
            Log::warning("Xui {$context} HTTP {$status}", [
                'panel_id' => $this->panel->id,
                'body' => substr($body, 0, 300),
            ]);
            throw new XuiApiException("3x-ui HTTP {$status}: {$context}");
        }

        // 响应可能是 HTML 登录页（cookie 失效），需要检测
        if (str_starts_with(trim($body), '<')) {
            return ['__unauthenticated' => true];
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new XuiApiException("3x-ui 响应非 JSON: {$context}");
        }
        return $json;
    }
}
