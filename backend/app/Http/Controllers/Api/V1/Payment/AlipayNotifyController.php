<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentGateway;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Services\NotificationService;
use App\Services\Payment\AlipayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlipayNotifyController extends Controller
{
    public function notify(Request $request, PaymentGateway $gateway, AlipayService $alipay)
    {
        // 支付宝回调可能是 POST 或 GET
        $params = $request->all();

        // 支付宝/第三方平台验证 URL 可用性时发送无参 GET/HEAD，直接返回 success
        if (empty($params) || (!isset($params['sign']) && !isset($params['trade_no']))) {
            Log::info('Alipay notify: health check', ['method' => $request->method(), 'gateway_id' => $gateway->id]);
            return response('success', 200);
        }

        Log::channel('daily')->info('Alipay notify received', [
            'gateway_id' => $gateway->id,
            'method' => $request->method(),
            'trade_no' => $params['trade_no'] ?? null,
            'out_trade_no' => $params['out_trade_no'] ?? null,
            'trade_status' => $params['trade_status'] ?? null,
            'total_amount' => $params['total_amount'] ?? null,
            'has_sign' => !empty($params['sign']),
            'param_keys' => array_keys($params),
        ]);

        if ($gateway->type !== 'alipay' || !$gateway->is_active) {
            Log::warning('Alipay notify: gateway mismatch or inactive', ['gateway_id' => $gateway->id]);
            return response('fail', 200);
        }

        if (!$alipay->verifyNotify($params, $gateway)) {
            Log::warning('Alipay notify: signature verification failed', ['gateway_id' => $gateway->id]);
            return response('fail', 200);
        }

        $outTradeNo = $params['out_trade_no'] ?? null;
        $tradeStatus = $params['trade_status'] ?? null;
        $totalAmount = $params['total_amount'] ?? null;
        $tradeNo = $params['trade_no'] ?? null;

        Log::info('Alipay notify: processing', [
            'out_trade_no' => $outTradeNo,
            'trade_status' => $tradeStatus,
            'total_amount' => $totalAmount,
            'trade_no' => $tradeNo,
        ]);

        if (!$outTradeNo || !in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            Log::info('Alipay notify: skipped (status not success)', ['trade_status' => $tradeStatus]);
            return response('success', 200); // 返回 success 避免支付宝重复推送非成功状态
        }

        $order = PaymentOrder::where('order_no', $outTradeNo)
            ->where('gateway_id', $gateway->id)
            ->first();

        if (!$order) {
            Log::warning('Alipay notify: order not found', ['out_trade_no' => $outTradeNo, 'gateway_id' => $gateway->id]);
            return response('fail', 200);
        }

        // Amount verification
        if (number_format((float) $order->amount, 2, '.', '') !== number_format((float) $totalAmount, 2, '.', '')) {
            Log::warning('Alipay notify: amount mismatch', [
                'order_no' => $outTradeNo,
                'expected' => $order->amount,
                'received' => $totalAmount,
            ]);
            return response('fail', 200);
        }

        Log::info('Alipay notify: starting transaction', ['order_no' => $outTradeNo, 'amount' => $order->amount]);

        try {
            DB::transaction(function () use ($order, $params, $tradeNo) {
                $fresh = PaymentOrder::where('id', $order->id)->lockForUpdate()->first();
                if ($fresh->status === 'paid') return;

                $customer = Customer::lockForUpdate()->findOrFail($fresh->customer_id);
                $balanceBefore = $customer->balance;
                $customer->increment('balance', $fresh->amount);
                $balanceAfter = bcadd($balanceBefore, $fresh->amount, 2);

                $fresh->update([
                    'status' => 'paid',
                    'provider_trade_no' => $tradeNo,
                    'provider_payload' => $params,
                    'paid_at' => now(),
                ]);

                Transaction::create([
                    'customer_id' => $customer->id,
                    'type' => Transaction::TYPE_TOPUP,
                    'amount' => $fresh->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'related_type' => PaymentOrder::class,
                    'related_id' => $fresh->id,
                    'description' => sprintf('支付宝充值 #%s', $fresh->order_no),
                    'operated_by' => null,
                ]);
            });

            Log::info('Alipay notify: payment confirmed', ['order_no' => $outTradeNo, 'amount' => $order->amount]);

            // Record topup for VIP calculation
            try {
                $vipCustomer = Customer::find($order->customer_id);
                if ($vipCustomer) {
                    app(\App\Services\VipService::class)->recordTopup($vipCustomer, (float) $order->amount);
                }
            } catch (\Throwable) {}

        } catch (\Throwable $e) {
            Log::error('Alipay notify: transaction failed', [
                'order_no' => $outTradeNo,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            return response('fail', 200);
        }

        try {
            app(NotificationService::class)->dispatch('customer_topup', [
                'title' => '💰 客户充值成功（支付宝）',
                'content' => sprintf(
                    "客户：**%s**\n充值：¥%.2f\n订单号：`%s`\n方式：支付宝",
                    $order->customer?->customer_name ?? '?',
                    (float) $order->amount,
                    $order->order_no
                ),
                'related_type' => 'PaymentOrder',
                'related_id' => $order->id,
                'dedup_key' => 'topup_' . $order->order_no,
            ]);
        } catch (\Throwable) {}

        return response('success', 200);
    }
}
