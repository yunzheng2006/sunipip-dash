<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Models\PaymentGateway;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Services\Payment\AlipayService;
use App\Services\Payment\EPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $customer = $request->user();
        return $this->success([
            'balance' => (float) $customer->balance,
            'currency' => 'CNY',
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $customer = $request->user();

        $query = Transaction::where('customer_id', $customer->id)
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('date_from')) {
            try { $query->where('created_at', '>=', \Carbon\Carbon::parse($request->input('date_from'))->startOfDay()); } catch (\Exception) {}
        }
        if ($request->filled('date_to')) {
            try { $query->where('created_at', '<=', \Carbon\Carbon::parse($request->input('date_to'))->endOfDay()); } catch (\Exception) {}
        }

        return $this->paginated($query->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    // ========== 充值相关 ==========

    /**
     * GET /customer/topup/methods
     * 返回所有启用中的支付网关（过滤掉敏感字段）
     */
    public function topupMethods(): JsonResponse
    {
        $gateways = PaymentGateway::where('is_active', 1)
            ->orderBy('sort')
            ->get()
            ->map(fn($g) => $g->toPublicArray());

        return $this->success($gateways);
    }

    /**
     * POST /customer/topup/create
     * 创建充值订单并返回跳转 URL
     */
    public function createTopup(Request $request, EPayService $epay, AlipayService $alipay): JsonResponse
    {
        $data = $request->validate([
            'gateway_id' => 'required|integer|exists:payment_gateways,id',
            'amount' => 'required|numeric|min:0.01|max:10000',
            'method' => 'nullable|string|in:alipay,wxpay,qqpay,bank,jdpay,usdt',
        ]);

        $customer = $request->user();
        $gateway = PaymentGateway::where('id', $data['gateway_id'])
            ->where('is_active', 1)
            ->firstOrFail();

        if (!in_array($gateway->type, ['epay', 'alipay'])) {
            return $this->error('不支持的网关类型', 422);
        }

        $order = PaymentOrder::create([
            'order_no' => PaymentOrder::generateOrderNo(),
            'customer_id' => $customer->id,
            'gateway_id' => $gateway->id,
            'gateway_type' => $gateway->type,
            'method' => $data['method'] ?? null,
            'amount' => $data['amount'],
            'currency' => 'CNY',
            'status' => 'pending',
            'client_ip' => $request->ip(),
        ]);

        $callbackDomain = PaymentGatewayController::getCallbackDomain();
        $returnDomain = PaymentGatewayController::getReturnDomain();
        $returnUrl = $returnDomain . '/billing/topup/success?order_no=' . $order->order_no;

        try {
            if ($gateway->type === 'alipay') {
                $notifyUrl = $callbackDomain . '/api/v1/payment/alipay/notify/' . $gateway->id;
                $isMobile = $this->isMobileRequest($request);

                $checkoutUrl = $alipay->buildPayUrl(
                    $order->load('gateway'),
                    $notifyUrl,
                    $returnUrl,
                    $isMobile,
                );

                return $this->success([
                    'order_no' => $order->order_no,
                    'amount' => (float) $order->amount,
                    'checkout_url' => $checkoutUrl,
                ], '订单已创建，请跳转支付');
            } else {
                // epay
                $notifyUrl = $callbackDomain . '/api/v1/payment/epay/notify/' . $gateway->id;
                $checkoutUrl = $epay->buildCheckoutUrl(
                    $order->load('gateway'),
                    $notifyUrl,
                    $returnUrl,
                    $data['method'] ?? null
                );
            }
        } catch (\Throwable $e) {
            $order->update(['status' => 'failed']);
            return $this->error('支付网关构造失败: ' . $e->getMessage(), 500);
        }

        return $this->success([
            'order_no' => $order->order_no,
            'amount' => (float) $order->amount,
            'checkout_url' => $checkoutUrl,
        ], '订单已创建，请跳转支付');
    }

    /**
     * 通过 User-Agent 判断是否为移动端请求
     */
    private function isMobileRequest(Request $request): bool
    {
        $ua = $request->header('User-Agent', '');
        return (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod|Opera Mini|IEMobile/i', $ua);
    }

    /**
     * GET /customer/topup/orders
     * 客户的充值订单列表
     */
    public function topupOrders(Request $request): JsonResponse
    {
        $customer = $request->user();
        $query = PaymentOrder::where('customer_id', $customer->id)
            ->with('gateway:id,name,type')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->paginated($query->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    /**
     * GET /customer/topup/orders/{orderNo}
     * 客户端轮询单笔订单状态（支付成功后 success 页面用）
     */
    public function topupOrder(Request $request, string $orderNo): JsonResponse
    {
        $customer = $request->user();
        $order = PaymentOrder::where('customer_id', $customer->id)
            ->where('order_no', $orderNo)
            ->firstOrFail();

        return $this->success([
            'order_no' => $order->order_no,
            'amount' => (float) $order->amount,
            'status' => $order->status,
            'paid_at' => $order->paid_at,
            'balance' => (float) $customer->fresh()->balance,
        ]);
    }
}
