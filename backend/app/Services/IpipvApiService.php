<?php

namespace App\Services;

use App\Models\UpstreamProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IpipvApiService
{
    private string $apiUrl;
    private string $appKey;
    private string $appSecret;

    public function __construct()
    {
        $provider = UpstreamProvider::where('is_active', true)
            ->where(function ($q) {
                $q->whereRaw("LOWER(slug) = 'ipipv'")
                  ->orWhere('driver', 'ipipv');
            })
            ->first();

        if ($provider) {
            $creds = $provider->credentials ?? [];
            $this->apiUrl    = rtrim($provider->api_url ?: ($creds['api_url'] ?? ''), '/');
            $this->appKey    = $creds['app_key'] ?? $creds['appKey'] ?? '';
            $this->appSecret = $creds['app_secret'] ?? $creds['appSecret'] ?? '';
        } else {
            $this->apiUrl    = '';
            $this->appKey    = '';
            $this->appSecret = '';
        }
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl && $this->appKey && $this->appSecret;
    }

    // ========== AES 加解密（与 Spark 相同算法，密钥来源不同） ==========

    private function getCipher(): string
    {
        $len = strlen($this->appSecret);
        return match (true) {
            $len >= 32 => 'AES-256-CBC',
            $len >= 24 => 'AES-192-CBC',
            default    => 'AES-128-CBC',
        };
    }

    private function encrypt(string $plainText): string
    {
        $cipher = $this->getCipher();
        $iv = substr($this->appSecret, 0, 16);
        $encrypted = openssl_encrypt($plainText, $cipher, $this->appSecret, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }

    private function decrypt(string $cipherText): string
    {
        $cipher = $this->getCipher();
        $iv = substr($this->appSecret, 0, 16);
        $decoded = base64_decode($cipherText);
        return openssl_decrypt($decoded, $cipher, $this->appSecret, OPENSSL_RAW_DATA, $iv);
    }

    // ========== 统一请求 ==========

    private function request(string $path, array $params = []): array
    {
        if (!$this->isConfigured()) {
            $missing = [];
            if (!$this->apiUrl) $missing[] = 'api_url';
            if (!$this->appKey) $missing[] = 'app_key';
            if (!$this->appSecret) $missing[] = 'app_secret';
            throw new \RuntimeException('IPIPV 插件未配置（缺少: ' . implode(', ', $missing) . '），请在 API 管理页面检查 IPIPV 提供商的 driver 设为 ipipv 且已启用');
        }

        $reqId = Str::uuid()->toString();
        $jsonParams = json_encode($params ?: new \stdClass, JSON_UNESCAPED_UNICODE);
        $encryptedParams = $this->encrypt($jsonParams);

        $body = [
            'version' => 'v2',
            'encrypt' => 'aes',
            'appKey'  => $this->appKey,
            'reqId'   => $reqId,
            'params'  => $encryptedParams,
        ];

        $url = $this->apiUrl . $path;

        Log::channel('daily')->info("IPIPV API Request: {$path}", [
            'reqId'  => $reqId,
            'params' => $params,
        ]);

        $response = Http::timeout(30)->post($url, $body);
        $result = $response->json();

        if (!$result || !isset($result['code'])) {
            Log::error("IPIPV API: 无效响应", ['response' => $response->body()]);
            throw new \RuntimeException('IPIPV API 返回无效响应');
        }

        if ((int) $result['code'] !== 200) {
            $msg = $result['msg'] ?? "IPIPV API 错误: {$result['code']}";
            Log::error("IPIPV API Error: {$path}", $result);
            throw new \RuntimeException($msg, (int) $result['code']);
        }

        $data = null;
        if (!empty($result['data'])) {
            $decrypted = $this->decrypt($result['data']);
            $data = json_decode($decrypted, true);

            if (is_array($data) && array_key_exists('code', $data)) {
                if ((int) $data['code'] !== 200) {
                    $innerMsg = $data['msg'] ?? "IPIPV 内层错误: {$data['code']}";
                    Log::error("IPIPV API Inner Error: {$path}", $data);
                    throw new \RuntimeException($innerMsg, (int) $data['code']);
                }
                $data = $data['data'] ?? [];
            }
        }

        Log::channel('daily')->info("IPIPV API Response: {$path}", [
            'reqId' => $reqId,
            'data'  => $data,
        ]);

        return $data ?? [];
    }

    // ========== 业务方法 ==========

    public function getProducts(array $filters = []): array
    {
        $params = [];
        if (!empty($filters['proxyType'])) {
            $params['proxyType'] = array_map('intval', (array) $filters['proxyType']);
        } else {
            $params['proxyType'] = [103];
        }
        foreach (['productNo', 'countryCode', 'cityCode', 'supplierCode'] as $key) {
            if (!empty($filters[$key])) {
                $params[$key] = (string) $filters[$key];
            }
        }
        foreach (['unit', 'ispType', 'duration'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $params[$key] = (int) $filters[$key];
            }
        }
        return $this->request('/api/open/app/product/query/v2', $params);
    }

    public function createOrder(string $appOrderNo, array $items): array
    {
        return $this->request('/api/open/app/instance/open/v2', [
            'appOrderNo' => $appOrderNo,
            'params'     => $items,
        ]);
    }

    public function createOrderAssignIp(string $appOrderNo, string $productNo, string $assignIp, int $cycleTimes = 1): array
    {
        return $this->request('/api/open/app/instance/open/assign/ip/v2', [
            'appOrderNo' => $appOrderNo,
            'productNo'  => $productNo,
            'assignIp'   => $assignIp,
            'cycleTimes' => $cycleTimes,
        ]);
    }

    public function renewProxy(string $appOrderNo, array $instances): array
    {
        return $this->request('/api/open/app/instance/renew/v2', [
            'appOrderNo' => $appOrderNo,
            'instances'  => $instances,
        ]);
    }

    public function releaseProxy(string $orderNo, array $instanceNos): array
    {
        return $this->request('/api/open/app/instance/release/v2', [
            'orderNo'   => $orderNo,
            'instances' => $instanceNos,
        ]);
    }

    public function getOrder(?string $orderNo = null, ?string $appOrderNo = null, int $page = 1, int $pageSize = 100): array
    {
        return $this->request('/api/open/app/order/v2', array_filter([
            'orderNo'    => $orderNo,
            'appOrderNo' => $appOrderNo,
            'page'       => $page,
            'pageSize'   => $pageSize,
        ]));
    }

    public function getInstance(array $instanceNos): array
    {
        return $this->request('/api/open/app/instance/v2', [
            'instances' => $instanceNos,
        ]);
    }

    public function getAppInfo(): array
    {
        return $this->request('/api/open/app/info/v2');
    }

    public function getAreas(?array $codes = null): array
    {
        return $this->request('/api/open/app/area/v2', $codes ? ['codes' => $codes] : []);
    }

    public function getCities(?array $codes = null): array
    {
        return $this->request('/api/open/app/city/list/v2', $codes ? ['codes' => $codes] : []);
    }

    public function queryAssignIp(string $ip): array
    {
        return $this->request('/api/open/app/assign/ip/info/v2', ['ip' => $ip]);
    }

    // ========== 数据映射 ==========

    public static function mapProxyType(int $type): string
    {
        return match ($type) {
            101 => 'static_cloud',
            102 => 'static_domestic',
            103 => 'static_residential',
            104 => 'dynamic_overseas',
            105 => 'dynamic_domestic',
            201 => 'whatsapp',
            default => 'unknown',
        };
    }

    public static function mapProtocol(string $protocols): string
    {
        $map = ['1' => 'socks5', '2' => 'http', '3' => 'https', '4' => 'ssh'];
        $first = explode(',', $protocols)[0] ?? '1';
        return $map[$first] ?? 'socks5';
    }

    public static function mapInstanceStatus(int $status): string
    {
        return match ($status) {
            1  => 'pending',
            2  => 'creating',
            3  => 'running',
            6  => 'stopped',
            10 => 'closed',
            11 => 'released',
            default => 'unknown',
        };
    }
}
