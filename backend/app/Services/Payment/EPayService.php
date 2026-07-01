<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Models\PaymentOrder;
use Illuminate\Support\Facades\Log;

/**
 * 易支付（EPay）对接服务
 *
 * 文档：https://payments.nodelay.cloud/doc_old.html
 *
 * 核心流程：
 *   1. buildCheckoutUrl(PaymentOrder $order): string
 *      - 组装参数 → 签名 → 返回 submit.php?xxx URL，前端直接 window.location 跳转
 *   2. verifyNotify(array $params, PaymentGateway $gw): bool
 *      - 验证异步回调签名
 *
 * 签名算法：
 *   1. 过滤：去除 sign / sign_type / 空值
 *   2. 按 key 字典序升序排序
 *   3. 拼接为 URL 格式 a=b&c=d&e=f（value 不做 urlencode）
 *   4. md5(queryString . key) 转小写
 */
class EPayService
{
    /**
     * 构造 epay 支付跳转 URL
     *
     * @param PaymentOrder $order
     * @param string $notifyUrl 完整 URL（含域名）
     * @param string $returnUrl 完整 URL（含域名）
     * @param string|null $method 子支付方式 alipay/wxpay/qqpay，null 则让客户在 epay 页面选
     * @return string
     */
    public function buildCheckoutUrl(
        PaymentOrder $order,
        string $notifyUrl,
        string $returnUrl,
        ?string $method = null
    ): string {
        $gateway = $order->gateway;
        $config = $gateway->config ?? [];

        $pid = $config['pid'] ?? null;
        $key = $config['key'] ?? null;
        $apiUrl = rtrim($config['api_url'] ?? '', '/');

        if (!$pid || !$key || !$apiUrl) {
            throw new \RuntimeException('支付网关配置不完整');
        }

        $params = array_filter([
            'pid' => (string) $pid,
            'type' => $method,
            'out_trade_no' => $order->order_no,
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'name' => 'SuniPIP 账户充值 #' . $order->order_no,
            'money' => number_format((float) $order->amount, 2, '.', ''),
            'sitename' => 'SuniPIP',
        ], fn($v) => $v !== null && $v !== '');

        $params['sign'] = $this->sign($params, $key);
        $params['sign_type'] = 'MD5';

        // 注意：epay 的 submit 接口接受 GET 参数，values 需要 urlencode 以免特殊字符破坏 URL
        $query = http_build_query($params);
        return "{$apiUrl}/submit.php?{$query}";
    }

    /**
     * 验证 epay 异步回调签名
     *
     * @param array $params 回调传来的全部 GET 参数（包含 sign / sign_type）
     * @param PaymentGateway $gateway
     * @return bool
     */
    public function verifyNotify(array $params, PaymentGateway $gateway): bool
    {
        $key = $gateway->config['key'] ?? null;
        if (!$key) {
            Log::warning('EPay verifyNotify: missing key in gateway config', ['gateway_id' => $gateway->id]);
            return false;
        }

        $receivedSign = $params['sign'] ?? '';
        if (!$receivedSign) {
            return false;
        }

        $expected = $this->sign($params, $key);
        return hash_equals($expected, strtolower($receivedSign));
    }

    /**
     * 签名算法
     *
     * @param array $params
     * @param string $key 商户 KEY
     * @return string lowercase md5 hash
     */
    public function sign(array $params, string $key): string
    {
        // 1. 排除 sign / sign_type / 空值
        $filtered = array_filter(
            $params,
            fn($v, $k) => !in_array($k, ['sign', 'sign_type'], true)
                       && $v !== null
                       && $v !== '',
            ARRAY_FILTER_USE_BOTH
        );

        // 2. key 字典序升序
        ksort($filtered);

        // 3. 拼接 a=b&c=d&e=f（value 不做 urlencode）
        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $queryString = implode('&', $parts);

        // 4. md5(queryString . key) 转小写
        return strtolower(md5($queryString . $key));
    }
}
