<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentGateway;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Services\NotificationService;
use App\Services\Payment\EPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 易支付异步回调接收器
 *
 * URL: GET /api/v1/payment/epay/notify/{gateway}
 *      （公开路由，通过签名校验身份）
 *
 * 成功处理需要返回纯文本 "success"（epay 协议规定）。
 */
class EPayNotifyController extends Controller
{
    public function notify(Request $request, PaymentGateway $gateway, EPayService $epay)
    {
        $params = $request->query();

        Log::channel('daily')->info('EPay notify received', [
            'gateway_id' => $gateway->id,
            'out_trade_no' => $params['out_trade_no'] ?? null,
            'money' => $params['money'] ?? null,
            'trade_status' => $params['trade_status'] ?? null,
        ]);

        // 1. 网关类型校验
        if ($gateway->type !== 'epay' || !$gateway->is_active) {
            Log::warning('EPay notify: gateway type mismatch or inactive', ['gateway_id' => $gateway->id]);
            return response('fail', 200);
        }

        // 2. 签名校验
        if (!$epay->verifyNotify($params, $gateway)) {
            Log::warning('EPay notify: signature verification failed', [
                'gateway_id' => $gateway->id,
                'params' => $params,
            ]);
            return response('fail', 200);
        }

        // 3. 业务校验
        $outTradeNo = $params['out_trade_no'] ?? null;
        $tradeStatus = $params['trade_status'] ?? null;
        $money = $params['money'] ?? null;
        $tradeNo = $params['trade_no'] ?? null;

        if (!$outTradeNo || $tradeStatus !== 'TRADE_SUCCESS') {
            Log::warning('EPay notify: not a success callback', ['params' => $params]);
            return response('fail', 200);
        }

        $order = PaymentOrder::where('order_no', $outTradeNo)
            ->where('gateway_id', $gateway->id)
            ->first();

        if (!$order) {
            Log::warning('EPay notify: order not found', ['out_trade_no' => $outTradeNo]);
            return response('fail', 200);
        }

        // 金额核对（ePay 可能返回 "5" 而非 "5.00"，统一格式再比）
        if (number_format((float) $order->amount, 2, '.', '') !== number_format((float) $money, 2, '.', '')) {
            Log::warning('EPay notify: amount mismatch', [
                'order_no' => $outTradeNo,
                'expected' => $order->amount,
                'received' => $money,
            ]);
            return response('fail', 200);
        }

        // 4. 事务：标记订单 paid + 客户余额 increment + 写 Transaction
        try {
            DB::transaction(function () use ($order, $params, $tradeNo) {
                // 重新取一次 + lockForUpdate 防并发重复入账
                $fresh = PaymentOrder::where('id', $order->id)->lockForUpdate()->first();
                if ($fresh->status === 'paid') {
                    return; // 其他并发请求已处理
                }

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
                    'description' => sprintf(
                        '在线充值 (%s #%s)',
                        $fresh->method ?: 'epay',
                        $fresh->order_no
                    ),
                    'operated_by' => null,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('EPay notify: DB transaction failed', [
                'order_no' => $outTradeNo,
                'error' => $e->getMessage(),
            ]);
            return response('fail', 200);
        }

        // Record topup for VIP calculation
        try {
            $vipCustomer = Customer::find($order->customer_id);
            if ($vipCustomer) {
                app(\App\Services\VipService::class)->recordTopup($vipCustomer, (float) $order->amount);
            }
        } catch (\Throwable) {}

        // 6. 可选：派发客户充值通知 webhook
        try {
            app(NotificationService::class)->dispatch('customer_topup', [
                'title' => '💰 客户充值成功',
                'content' => sprintf(
                    "客户：**%s**\n\n充值：¥%.2f\n\n订单号：`%s`\n\n方式：%s",
                    $order->customer?->customer_name ?? '?',
                    (float) $order->amount,
                    $order->order_no,
                    $order->method ?: 'epay'
                ),
                'related_type' => 'PaymentOrder',
                'related_id' => $order->id,
                'dedup_key' => 'topup_' . $order->order_no,
            ]);
        } catch (\Throwable $e) {
            // 通知失败不影响主流程
            Log::warning('EPay notify: webhook dispatch failed: ' . $e->getMessage());
        }

        return response('success', 200);
    }
}
