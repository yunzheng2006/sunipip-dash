<?php

namespace App\Services\Payment;

use App\Models\Customer;
use App\Models\PaymentOrder;
use App\Models\PaymentRefund;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentRefundService
{
    public function __construct(protected AlipayService $alipay) {}

    public function refund(
        PaymentOrder $order,
        float $amount,
        int $operatedBy,
        ?string $reason = null,
        ?int $subscriptionId = null,
    ): PaymentRefund {
        if ($order->status !== 'paid') {
            throw new \InvalidArgumentException('只能退款已支付的订单');
        }
        if ($order->gateway_type !== 'alipay') {
            throw new \InvalidArgumentException('目前仅支持支付宝原路退款');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('退款金额必须大于 0');
        }
        if ($amount > $order->refundable_amount) {
            throw new \InvalidArgumentException(
                "退款金额超出可退金额（可退: ¥{$order->refundable_amount}）"
            );
        }

        $customer = Customer::findOrFail($order->customer_id);
        if ((float) $customer->balance < $amount) {
            throw new \InvalidArgumentException(
                "客户余额不足（当前: ¥{$customer->balance}，需退: ¥{$amount}）"
            );
        }

        if ($order->paid_at && $order->paid_at->lt(now()->subMonths(11))) {
            throw new \InvalidArgumentException('该订单已超过支付宝退款期限（支付后 1 年内）');
        }

        $refundNo = PaymentRefund::generateRefundNo();

        $refund = PaymentRefund::create([
            'refund_no' => $refundNo,
            'payment_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'subscription_id' => $subscriptionId,
            'gateway_id' => $order->gateway_id,
            'gateway_type' => $order->gateway_type,
            'amount' => $amount,
            'status' => 'pending',
            'reason' => $reason,
            'operated_by' => $operatedBy,
        ]);

        try {
            $result = $this->alipay->refund($order, $refundNo, $amount, $reason);
        } catch (\Throwable $e) {
            $refund->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (!$result['success']) {
            $errorMsg = $result['sub_msg'] ?: $result['msg'] ?: '支付宝退款失败';
            $refund->update([
                'status' => 'failed',
                'error_message' => $errorMsg,
                'provider_response' => $result['response'] ?? null,
            ]);
            throw new \RuntimeException("支付宝退款失败: {$errorMsg}");
        }

        DB::transaction(function () use ($refund, $order, $customer, $amount, $result, $operatedBy, $reason) {
            $customer = Customer::lockForUpdate()->find($customer->id);
            $order = PaymentOrder::lockForUpdate()->find($order->id);

            $balanceBefore = (float) $customer->balance;
            $customer->decrement('balance', $amount);
            $balanceAfter = $balanceBefore - $amount;

            Transaction::create([
                'customer_id' => $customer->id,
                'type' => Transaction::TYPE_GATEWAY_REFUND,
                'amount' => -$amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'related_type' => PaymentRefund::class,
                'related_id' => $refund->id,
                'description' => '原路退款: ' . ($reason ?: $order->order_no),
                'operated_by' => $operatedBy,
            ]);

            $order->increment('refunded_amount', $amount);

            $refund->update([
                'status' => 'success',
                'provider_refund_no' => $result['trade_no'] ?? null,
                'provider_response' => $result['response'] ?? null,
                'refunded_at' => now(),
            ]);
        });

        Log::info('Gateway refund success', [
            'refund_id' => $refund->id,
            'refund_no' => $refundNo,
            'order_no' => $order->order_no,
            'customer_id' => $order->customer_id,
            'amount' => $amount,
        ]);

        return $refund->fresh();
    }

    public function getRefundableOrders(int $customerId): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentOrder::where('customer_id', $customerId)
            ->where('status', 'paid')
            ->where('gateway_type', 'alipay')
            ->whereRaw('amount - refunded_amount > 0')
            ->where('paid_at', '>=', now()->subMonths(11))
            ->orderByDesc('paid_at')
            ->get();
    }
}
