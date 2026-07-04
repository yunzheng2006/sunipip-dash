<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理后台 - 支付网关配置
 */
class PaymentGatewayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $gateways = PaymentGateway::orderBy('sort')->orderByDesc('id')->get();
        return $this->success($gateways);
    }

    public function show(PaymentGateway $paymentGateway): JsonResponse
    {
        return $this->success($paymentGateway);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $gateway = PaymentGateway::create($data);
        return $this->success($gateway, '支付网关已创建');
    }

    public function update(Request $request, PaymentGateway $paymentGateway): JsonResponse
    {
        $data = $this->validated($request);
        // 如果敏感密钥字段为空字符串，视为不修改（避免前端编辑时误清空）
        if (empty($data['config']['key'])) {
            $data['config']['key'] = $paymentGateway->config['key'] ?? '';
        }
        if (empty($data['config']['app_private_key'])) {
            $data['config']['app_private_key'] = $paymentGateway->config['app_private_key'] ?? '';
        }
        if (empty($data['config']['alipay_public_key'])) {
            $data['config']['alipay_public_key'] = $paymentGateway->config['alipay_public_key'] ?? '';
        }
        $paymentGateway->update($data);
        return $this->success($paymentGateway->fresh(), '已更新');
    }

    public function destroy(PaymentGateway $paymentGateway): JsonResponse
    {
        if ($paymentGateway->orders()->where('status', 'pending')->exists()) {
            return $this->error('存在待支付的订单，不能删除');
        }
        $paymentGateway->delete();
        return $this->success(null, '已删除');
    }

    /**
     * 发送一笔测试订单的签名样本给前端，方便管理员核对配置
     * POST /payment-gateways/{paymentGateway}/test-sign
     */
    public function testSign(PaymentGateway $paymentGateway): JsonResponse
    {
        if ($paymentGateway->type !== 'epay') {
            return $this->error('仅支持 epay 类型');
        }

        $callbackDomain = self::getCallbackDomain();
        $returnDomain = self::getReturnDomain();

        $epay = app(\App\Services\Payment\EPayService::class);
        $sampleParams = [
            'pid' => $paymentGateway->config['pid'] ?? '',
            'out_trade_no' => 'TEST' . time(),
            'money' => '1.00',
            'name' => 'EPay 签名测试',
            'notify_url' => $callbackDomain . '/api/v1/payment/epay/notify/' . $paymentGateway->id,
            'return_url' => $returnDomain . '/billing/topup/success',
        ];
        $sign = $epay->sign($sampleParams, $paymentGateway->config['key'] ?? '');

        return $this->success([
            'params' => $sampleParams,
            'sign' => $sign,
            'api_url' => rtrim($paymentGateway->config['api_url'] ?? '', '/') . '/submit.php',
        ], '签名生成成功');
    }

    /**
     * GET /payment-gateways/domain-settings
     */
    public function getDomainSettings(): JsonResponse
    {
        return $this->success([
            'callback_domain' => SystemConfig::get('payment.callback_domain', ''),
            'return_domain' => SystemConfig::get('payment.return_domain', ''),
        ]);
    }

    /**
     * PUT /payment-gateways/domain-settings
     */
    public function updateDomainSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'callback_domain' => 'nullable|string|max:200',
            'return_domain' => 'nullable|string|max:200',
        ]);

        $cb = rtrim($data['callback_domain'] ?? '', '/');
        $rt = rtrim($data['return_domain'] ?? '', '/');

        SystemConfig::set('payment.callback_domain', $cb, 'string', 'payment', '支付回调域名（如 https://sunip-pay.sunip.cc）');
        SystemConfig::set('payment.return_domain', $rt, 'string', 'payment', '支付完成跳转域名（客户面板地址）');

        return $this->success(null, '域名设置已保存');
    }

    public static function getCallbackDomain(): string
    {
        $domain = SystemConfig::get('payment.callback_domain', '');
        return $domain ?: rtrim(config('app.url', 'https://api-all.sunip.cc'), '/');
    }

    public static function getReturnDomain(): string
    {
        $domain = SystemConfig::get('payment.return_domain', '');
        return $domain ?: rtrim(config('proxy.platform.customer_portal_url', 'https://user.sunipip.com'), '/');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:epay,wechat,alipay',
            'config' => 'required|array',
            // EPay 字段
            'config.pid' => 'required_if:type,epay|nullable|string|max:100',
            'config.key' => 'nullable|string|max:200',
            'config.api_url' => 'required_if:type,epay|nullable|url|max:500',
            // Alipay 字段
            'config.app_id' => 'required_if:type,alipay|nullable|string|max:100',
            'config.app_private_key' => 'nullable|string|max:5000',
            'config.alipay_public_key' => 'nullable|string|max:5000',
            // 通用
            'config.methods' => 'nullable|array',
            'config.methods.*' => 'string|in:alipay,wxpay,qqpay,bank,jdpay,usdt',
            'is_active' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:500',
        ]);
        $data['is_active'] = $data['is_active'] ?? 1;
        $data['sort'] = $data['sort'] ?? 0;
        return $data;
    }
}
