<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SparkApiService
{
    private string $apiUrl;
    private string $supplierNo;
    private string $aesKey;
    private string $version;

    public function __construct()
    {
        // 优先从 upstream_providers 表读取，失败则回退到 .env
        try {
            $provider = \App\Models\UpstreamProvider::where('slug', 'spark')
                ->where('is_active', true)
                ->first();
            if ($provider && !empty($provider->credentials['aes_key'])) {
                $creds = $provider->credentials;
                $this->apiUrl     = $provider->api_url ?: config('proxy.spark.api_url');
                $this->supplierNo = $creds['supplier_no'] ?? config('proxy.spark.supplier_no');
                $this->aesKey     = $creds['aes_key'] ?? config('proxy.spark.aes_key');
                $this->version    = $creds['version'] ?? config('proxy.spark.version', '2.0');
                return;
            }
        } catch (\Throwable $e) {
            // DB 不可用（迁移未跑等）— 安全回退到 .env
        }

        $this->apiUrl     = config('proxy.spark.api_url');
        $this->supplierNo = config('proxy.spark.supplier_no');
        $this->aesKey     = config('proxy.spark.aes_key');
        $this->version    = config('proxy.spark.version', '2.0');
    }

    // ========== AES 加解密 ==========

    /**
     * 根据密钥长度选择算法：
     * 16字节 → AES-128-CBC
     * 24字节 → AES-192-CBC
     * 32字节 → AES-256-CBC
     */
    private function getCipher(): string
    {
        $len = strlen($this->aesKey);
        return match (true) {
            $len >= 32 => 'AES-256-CBC',
            $len >= 24 => 'AES-192-CBC',
            default => 'AES-128-CBC',
        };
    }

    private function encrypt(string $plainText): string
    {
        $cipher = $this->getCipher();
        // IV 固定使用密钥前16字节（Spark 文档使用 AES-CBC，按文档示例 qwertyuiop123456 作为key+iv）
        $iv = substr($this->aesKey, 0, 16);
        $encrypted = openssl_encrypt($plainText, $cipher, $this->aesKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }

    private function decrypt(string $cipherText): string
    {
        $cipher = $this->getCipher();
        $iv = substr($this->aesKey, 0, 16);
        $decoded = base64_decode($cipherText);
        return openssl_decrypt($decoded, $cipher, $this->aesKey, OPENSSL_RAW_DATA, $iv);
    }

    // ========== 统一请求 ==========

    private function request(string $method, array $params = []): array
    {
        $reqId = Str::uuid()->toString();
        $encryptedParams = $this->encrypt(json_encode($params));

        $body = [
            'reqId' => $reqId,
            'version' => $this->version,
            'timestamp' => time(),
            'method' => $method,
            'supplierNo' => $this->supplierNo,
            'params' => $encryptedParams,
        ];

        Log::channel('daily')->info("Spark API Request: {$method}", [
            'reqId' => $reqId,
            'params' => $params,
        ]);

        $response = Http::timeout(30)->post($this->apiUrl, $body);
        $result = $response->json();

        if (!$result || !isset($result['code'])) {
            Log::error("Spark API: 无效响应", ['response' => $response->body()]);
            throw new \RuntimeException('Spark API 返回无效响应');
        }

        if ($result['code'] !== 200) {
            // "data not found" 当作空结果处理，不抛异常
            $msg = $result['message'] ?? $result['msg'] ?? "Spark API 错误: {$result['code']}";
            if (stripos($msg, 'data not found') !== false || stripos($msg, 'no data') !== false) {
                Log::channel('daily')->info("Spark API {$method}: 无数据", $result);
                return [];
            }
            Log::error("Spark API Error: {$method}", $result);
            throw new \RuntimeException($msg);
        }

        // 解密返回数据
        $data = null;
        if (!empty($result['data'])) {
            $decrypted = $this->decrypt($result['data']);
            $data = json_decode($decrypted, true);
        }

        Log::channel('daily')->info("Spark API Response: {$method}", [
            'reqId' => $reqId,
            'data' => $data,
        ]);

        return $data ?? [];
    }

    // ========== 业务方法 ==========

    /**
     * 获取产品库存
     */
    public function getProductStock(array $filters = []): array
    {
        // int 字段强制转型，防止 Go 端 JSON unmarshal 失败
        $params = [
            'page' => (int) ($filters['page'] ?? 1),
            'pageSize' => (int) ($filters['pageSize'] ?? 100),
        ];
        if (!empty($filters['proxyType'])) {
            $params['proxyType'] = (int) $filters['proxyType'];
        }
        foreach (['countryCode', 'areaCode', 'cityCode', 'productId'] as $key) {
            if (!empty($filters[$key])) {
                $params[$key] = (string) $filters[$key];
            }
        }

        return $this->request('GetProductStock', $params);
    }

    /**
     * 创建代理 (下单开通)
     */
    public function createProxy(string $reqOrderNo, string $productId, int $duration, int $unit, int $amount = 1, ?array $cidrBlocks = null): array
    {
        $params = [
            'reqOrderNo' => $reqOrderNo,
            'productId' => $productId,
            'duration' => $duration,
            'unit' => $unit,
            'amount' => $amount,
        ];
        if ($cidrBlocks) {
            $params['cidrBlocks'] = $cidrBlocks;
        }
        return $this->request('CreateProxy', $params);
    }

    /**
     * 续费代理
     */
    public function renewProxy(string $reqOrderNo, array $instances): array
    {
        return $this->request('RenewProxy', [
            'reqOrderNo' => $reqOrderNo,
            'instances' => $instances, // [{instanceId, duration, unit}]
        ]);
    }

    /**
     * 删除/释放代理
     */
    public function delProxy(string $reqOrderNo, array $instanceIds): array
    {
        return $this->request('DelProxy', [
            'reqOrderNo' => $reqOrderNo,
            'instanceIds' => $instanceIds,
        ]);
    }

    /**
     * 获取订单状态
     */
    public function getOrder(string $reqOrderNo, string $orderNo, int $page = 1, int $pageSize = 100): array
    {
        return $this->request('GetOrder', [
            'reqOrderNo' => $reqOrderNo,
            'orderNo' => $orderNo,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 获取实例详情
     */
    public function getInstance(array $filters = []): array
    {
        return $this->request('GetInstance', array_filter([
            'instanceId' => $filters['instanceId'] ?? null,
            'ip' => $filters['ip'] ?? null,
            'username' => $filters['username'] ?? null,
        ]));
    }

    /**
     * 添加域名白名单
     */
    public function addIpDomainWhiteList(string $ip, string $domain): array
    {
        return $this->request('AddIpDomainWhiteList', [
            'ip' => $ip,
            'domain' => $domain,
        ]);
    }

    /**
     * 获取 Spark 账户余额
     */
    public function getBalance(): array
    {
        return $this->request('GetBalance', []);
    }

    /**
     * 重置静态代理密码
     * 重置后通过 getInstance 获取新密码
     */
    public function resetProxyPassword(array $instanceIds): array
    {
        return $this->request('ResetProxyPassword', [
            'instanceIds' => $instanceIds,
        ]);
    }

    // ========== 数据映射 ==========

    public static function mapProxyType(int $type): string
    {
        return match ($type) {
            103 => 'static_residential',
            104 => 'dynamic_residential',
            default => 'unknown',
        };
    }

    public static function mapProtocol(int $protocol): string
    {
        return match ($protocol) {
            1 => 'socks5',
            2 => 'http',
            default => 'unknown',
        };
    }

    public static function mapInstanceStatus(int $status): string
    {
        return match ($status) {
            1 => 'provisioning',  // 开通中
            2 => 'active',        // 正常使用
            3 => 'releasing',     // 释放中
            4 => 'released',      // 释放完成
            default => 'unknown',
        };
    }

    /**
     * 并发批量获取实例 — 用于导出
     * @param array<string> $instanceIds
     * @param int $concurrency 并发数
     * @return array<string, array> instanceId => data|['error' => msg]
     */
    public function getInstancesConcurrently(array $instanceIds, int $concurrency = 10): array
    {
        $results = [];
        $chunks = array_chunk($instanceIds, $concurrency);

        foreach ($chunks as $chunk) {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($chunk) {
                foreach ($chunk as $instanceId) {
                    $params = ['instanceId' => $instanceId];
                    $encryptedParams = $this->encrypt(json_encode($params));
                    $pool->as($instanceId)->timeout(30)->post($this->apiUrl, [
                        'reqId' => Str::uuid()->toString(),
                        'version' => $this->version,
                        'timestamp' => time(),
                        'method' => 'GetInstance',
                        'supplierNo' => $this->supplierNo,
                        'params' => $encryptedParams,
                    ]);
                }
            });

            foreach ($chunk as $instanceId) {
                try {
                    $response = $responses[$instanceId];
                    if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                        $body = $response->json();
                        if (($body['code'] ?? 0) === 200 && !empty($body['data'])) {
                            $decrypted = $this->decrypt($body['data']);
                            $results[$instanceId] = json_decode($decrypted, true) ?? [];
                        } else {
                            $results[$instanceId] = ['error' => $body['message'] ?? 'API error ' . ($body['code'] ?? '?')];
                        }
                    } else {
                        $results[$instanceId] = ['error' => 'HTTP ' . ($response->status ?? 'failed')];
                    }
                } catch (\Throwable $e) {
                    $results[$instanceId] = ['error' => $e->getMessage()];
                }
            }
        }

        return $results;
    }
}
