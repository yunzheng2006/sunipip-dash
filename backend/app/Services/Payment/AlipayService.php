<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Models\PaymentOrder;
use Illuminate\Support\Facades\Log;

/**
 * 支付宝官方 API 对接
 *
 * 使用 RSA2 (SHA256WithRSA) 签名，不依赖第三方 SDK。
 *
 * 支持：
 *   - alipay.trade.page.pay（电脑网站支付）
 *   - alipay.trade.wap.pay（手机网站支付）
 */
class AlipayService
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    /**
     * 构造支付宝支付 URL（直接跳转，不走 iframe 避免被风控拦截）
     */
    public function buildPayUrl(
        PaymentOrder $order,
        string $notifyUrl,
        string $returnUrl,
        bool $isMobile = false,
    ): string {
        $gateway = $order->gateway;
        $config = $gateway->config ?? [];

        $appId = $config['app_id'] ?? null;
        $privateKey = $config['app_private_key'] ?? null;

        if (!$appId || !$privateKey) {
            throw new \RuntimeException('支付宝网关配置不完整（缺少 app_id 或 app_private_key）');
        }

        $method = $isMobile ? 'alipay.trade.wap.pay' : 'alipay.trade.page.pay';
        $productCode = $isMobile ? 'QUICK_WAP_WAY' : 'FAST_INSTANT_TRADE_PAY';

        $bizContent = json_encode([
            'out_trade_no' => $order->order_no,
            'total_amount' => number_format((float) $order->amount, 2, '.', ''),
            'subject' => 'SuniPIP 账户充值 #' . $order->order_no,
            'product_code' => $productCode,
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id' => $appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'biz_content' => $bizContent,
        ];

        $params['sign'] = $this->sign($params, $privateKey);

        $query = '';
        foreach ($params as $key => $value) {
            $query .= '&' . $key . '=' . urlencode($value);
        }

        return self::GATEWAY . '?' . substr($query, 1);
    }

    /**
     * 构造支付宝跳转表单 HTML（旧方法，保留兼容）
     */
    public function buildPayForm(
        PaymentOrder $order,
        string $notifyUrl,
        string $returnUrl,
        bool $isMobile = false,
    ): string {
        $url = $this->buildPayUrl($order, $notifyUrl, $returnUrl, $isMobile);
        return '<script>window.location.href=' . json_encode($url) . ';</script>';
    }

    /**
     * 构造完整的自动提交 HTML 页面
     * 包含 DOCTYPE + 提示文字 + 自动提交表单
     */
    public function buildPayPage(
        PaymentOrder $order,
        string $notifyUrl,
        string $returnUrl,
        bool $isMobile = false,
    ): string {
        $formHtml = $this->buildPayForm($order, $notifyUrl, $returnUrl, $isMobile);

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>正在跳转到支付宝...</title></head><body>'
            . '<p style="text-align:center;margin-top:100px;font-size:16px;color:#666">正在跳转到支付宝，请稍候...</p>'
            . $formHtml
            . '</body></html>';
    }

    /**
     * 支付宝交易查询
     */
    public function queryTrade(PaymentOrder $order): array
    {
        $gateway = $order->gateway;
        $config = $gateway->config ?? [];

        $appId = $config['app_id'] ?? null;
        $privateKey = $config['app_private_key'] ?? null;

        if (!$appId || !$privateKey) {
            throw new \RuntimeException('支付宝网关配置不完整');
        }

        $bizContent = json_encode([
            'out_trade_no' => $order->order_no,
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id' => $appId,
            'method' => 'alipay.trade.query',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => $bizContent,
        ];

        $params['sign'] = $this->sign($params, $privateKey);

        $response = $this->postToGateway($params);

        $queryResponse = $response['alipay_trade_query_response'] ?? null;

        if ($queryResponse === null) {
            return ['success' => false, 'code' => 'PARSE_ERROR', 'msg' => '响应格式异常'];
        }

        $code = $queryResponse['code'] ?? '';

        return [
            'success' => $code === '10000',
            'code' => $code,
            'msg' => $queryResponse['msg'] ?? '',
            'trade_status' => $queryResponse['trade_status'] ?? null,
            'trade_no' => $queryResponse['trade_no'] ?? null,
            'total_amount' => $queryResponse['total_amount'] ?? null,
            'buyer_logon_id' => $queryResponse['buyer_logon_id'] ?? null,
            'response' => $queryResponse,
        ];
    }

    /**
     * 支付宝退款（同步接口，无异步回调）
     */
    public function refund(
        PaymentOrder $order,
        string $refundNo,
        float $amount,
        ?string $reason = null,
    ): array {
        $gateway = $order->gateway;
        $config = $gateway->config ?? [];

        $appId = $config['app_id'] ?? null;
        $privateKey = $config['app_private_key'] ?? null;

        if (!$appId || !$privateKey) {
            throw new \RuntimeException('支付宝网关配置不完整（缺少 app_id 或 app_private_key）');
        }

        $bizContent = json_encode([
            'trade_no' => $order->provider_trade_no,
            'out_trade_no' => $order->order_no,
            'refund_amount' => number_format($amount, 2, '.', ''),
            'out_request_no' => $refundNo,
            'refund_reason' => $reason ?: '管理员退款',
        ], JSON_UNESCAPED_UNICODE);

        $params = [
            'app_id' => $appId,
            'method' => 'alipay.trade.refund',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => $bizContent,
        ];

        $params['sign'] = $this->sign($params, $privateKey);

        $response = $this->postToGateway($params);

        $refundResponse = $response['alipay_trade_refund_response'] ?? null;

        if ($refundResponse === null) {
            Log::error('Alipay refund: missing alipay_trade_refund_response key', [
                'refund_no' => $refundNo,
                'response_keys' => array_keys($response),
                'response' => $response,
            ]);

            $errorResponse = $response['error_response'] ?? null;
            if ($errorResponse) {
                return [
                    'success' => false,
                    'trade_no' => null,
                    'refund_fee' => null,
                    'code' => $errorResponse['code'] ?? '',
                    'msg' => $errorResponse['msg'] ?? '',
                    'sub_msg' => $errorResponse['sub_msg'] ?? ($errorResponse['sub_code'] ?? ''),
                    'response' => $errorResponse,
                ];
            }

            return [
                'success' => false,
                'trade_no' => null,
                'refund_fee' => null,
                'code' => 'PARSE_ERROR',
                'msg' => '支付宝响应格式异常',
                'sub_msg' => '响应中缺少 alipay_trade_refund_response，keys: ' . implode(',', array_keys($response)),
                'response' => $response,
            ];
        }

        $code = $refundResponse['code'] ?? '';
        $success = $code === '10000';

        Log::info('Alipay refund result', [
            'refund_no' => $refundNo,
            'order_no' => $order->order_no,
            'amount' => $amount,
            'success' => $success,
            'code' => $code,
            'msg' => $refundResponse['msg'] ?? '',
            'sub_code' => $refundResponse['sub_code'] ?? '',
            'sub_msg' => $refundResponse['sub_msg'] ?? '',
        ]);

        return [
            'success' => $success,
            'trade_no' => $refundResponse['trade_no'] ?? null,
            'refund_fee' => $refundResponse['refund_fee'] ?? null,
            'code' => $code,
            'msg' => $refundResponse['msg'] ?? '',
            'sub_msg' => $refundResponse['sub_msg'] ?? '',
            'response' => $refundResponse,
        ];
    }

    private function postToGateway(array $params): array
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . urlencode($value);
        }
        $query = implode('&', $parts);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::GATEWAY,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $query,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            Log::error('Alipay gateway request failed', [
                'http_code' => $httpCode,
                'error' => $error,
            ]);
            throw new \RuntimeException("支付宝网关请求失败: HTTP {$httpCode}, {$error}");
        }

        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'GBK');
        }

        $decoded = json_decode($body, true);
        if ($decoded === null) {
            Log::error('Alipay gateway response is not valid JSON', [
                'body_length' => strlen($body),
                'body_preview' => substr($body, 0, 500),
            ]);
            throw new \RuntimeException('支付宝网关返回了非 JSON 响应');
        }

        Log::debug('Alipay gateway raw response', [
            'body_length' => strlen($body),
            'keys' => array_keys($decoded),
        ]);

        return $decoded;
    }

    /**
     * 验证支付宝异步通知签名
     *
     * 支付宝异步通知验签规则：
     * - 排除 sign 和 sign_type
     * - 排除值为空的参数
     * - 按 key 字典序排序
     * - 用支付宝公钥 RSA2 验签
     */
    public function verifyNotify(array $params, PaymentGateway $gateway): bool
    {
        $alipayPublicKey = $gateway->config['alipay_public_key'] ?? null;
        if (!$alipayPublicKey) {
            Log::warning('Alipay verifyNotify: missing alipay_public_key', ['gateway_id' => $gateway->id]);
            return false;
        }

        $sign = $params['sign'] ?? '';
        if (!$sign) {
            Log::warning('Alipay verifyNotify: no sign in params');
            return false;
        }

        // 排除 sign 和 sign_type，排除空值和非字符串值
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type') continue;
            if ($v === '' || $v === null || is_array($v)) continue;
            $filtered[$k] = $v;
        }
        ksort($filtered);

        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $data = implode('&', $parts);

        Log::debug('Alipay verifyNotify: data to verify', [
            'data_length' => strlen($data),
            'data_preview' => substr($data, 0, 300),
            'sign_preview' => substr($sign, 0, 40) . '...',
            'key_length' => strlen($alipayPublicKey),
        ]);

        // Format public key
        $publicKeyPem = $this->formatKey($alipayPublicKey, 'PUBLIC');

        $publicKeyRes = openssl_pkey_get_public($publicKeyPem);
        if (!$publicKeyRes) {
            Log::warning('Alipay verifyNotify: invalid public key', [
                'gateway_id' => $gateway->id,
                'openssl_error' => openssl_error_string(),
            ]);
            return false;
        }

        $result = openssl_verify($data, base64_decode($sign), $publicKeyRes, OPENSSL_ALGO_SHA256);

        Log::debug('Alipay verifyNotify: result', ['result' => $result, 'openssl_error' => openssl_error_string()]);

        return $result === 1;
    }

    /**
     * RSA2 签名 (SHA256WithRSA)
     *
     * 支付宝签名规则：
     * - 只排除 sign 字段（sign_type 参与签名）
     * - 按 key 字典序排序
     * - 值不做 URL 编码，直接拼接
     */
    private function sign(array $params, string $privateKey): string
    {
        // 1. 只排除 sign 字段（sign_type 参与签名）
        $filtered = array_filter(
            $params,
            fn($v, $k) => $k !== 'sign' && $v !== null && $v !== '',
            ARRAY_FILTER_USE_BOTH
        );

        // 2. Sort by key
        ksort($filtered);

        // 3. Concatenate (值不做 URL 编码)
        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $data = implode('&', $parts);

        // 4. Sign with RSA2
        $privateKeyPem = $this->formatKey($privateKey, 'RSA PRIVATE');

        $privateKeyRes = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKeyRes) {
            Log::error('Alipay sign: invalid private key', [
                'key_length' => strlen($privateKey),
                'key_prefix' => substr($privateKey, 0, 30) . '...',
                'openssl_error' => openssl_error_string(),
            ]);
            throw new \RuntimeException('支付宝私钥格式错误: ' . openssl_error_string());
        }

        openssl_sign($data, $signature, $privateKeyRes, OPENSSL_ALGO_SHA256);

        Log::debug('Alipay sign: string to sign', [
            'data_length' => strlen($data),
            'data_preview' => substr($data, 0, 200),
            'sign_preview' => substr(base64_encode($signature), 0, 40) . '...',
        ]);

        return base64_encode($signature);
    }

    /**
     * 格式化密钥为 PEM 格式
     * 支持传入带 BEGIN/END 的完整 PEM，也支持纯 base64 字符串
     * 自动尝试 PKCS1 和 PKCS8 两种格式
     */
    private function formatKey(string $key, string $type): string
    {
        $key = trim($key);

        // Already in PEM format
        if (str_starts_with($key, '-----BEGIN')) {
            return $key;
        }

        // Strip any whitespace/newlines from raw key
        $key = str_replace(["\r", "\n", " ", "\t"], '', $key);

        // Try standard format first
        $pem = "-----BEGIN {$type} KEY-----\n"
            . chunk_split($key, 64, "\n")
            . "-----END {$type} KEY-----";

        // Verify the key is loadable; if not, try alternate header
        if ($type === 'RSA PRIVATE') {
            $res = openssl_pkey_get_private($pem);
            if (!$res) {
                // Try PKCS8 format
                $pem = "-----BEGIN PRIVATE KEY-----\n"
                    . chunk_split($key, 64, "\n")
                    . "-----END PRIVATE KEY-----";
            }
        }

        return $pem;
    }
}
