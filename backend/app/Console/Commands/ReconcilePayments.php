<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\PaymentGateway;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Services\Payment\AlipayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcilePayments extends Command
{
    protected $signature = 'payment:reconcile {--dry-run} {--order= : 指定订单号} {--date= : 指定日期，默认今天}';
    protected $description = '对账：查询支付宝确认pending订单的实际支付状态，已付款的自动到账';

    public function handle(AlipayService $alipay): int
    {
        $dryRun = $this->option('dry-run');
        $orderNo = $this->option('order');
        $date = $this->option('date') ?: now()->toDateString();

        if ($dryRun) {
            $this->info('[DRY-RUN] 仅查询，不做任何修改');
        }

        $query = PaymentOrder::where('gateway_type', 'alipay');

        if ($orderNo) {
            $query->where('order_no', $orderNo);
        } else {
            $query->where('status', 'pending')
                  ->whereDate('created_at', '>=', $date);
        }

        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->info('没有需要对账的订单');
            return 0;
        }

        $this->info("找到 {$orders->count()} 笔待对账订单\n");

        $fixed = 0;
        $notPaid = 0;

        foreach ($orders as $order) {
            $customer = Customer::find($order->customer_id);
            $label = "#{$order->id} {$order->order_no} ¥{$order->amount} {$customer->customer_name}";

            $this->line("查询 {$label} ...");

            try {
                $result = $alipay->queryTrade($order);
            } catch (\Throwable $e) {
                $this->error("  查询失败: {$e->getMessage()}");
                continue;
            }

            if (!$result['success']) {
                $this->warn("  支付宝返回: [{$result['code']}] {$result['msg']}");
                $notPaid++;
                continue;
            }

            $tradeStatus = $result['trade_status'];
            $tradeNo = $result['trade_no'];
            $totalAmount = $result['total_amount'];

            $this->line("  支付宝状态: {$tradeStatus} | 交易号: {$tradeNo} | 金额: ¥{$totalAmount}");

            if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
                $this->warn("  未支付，跳过");
                $notPaid++;
                continue;
            }

            if (number_format((float) $order->amount, 2, '.', '') !== number_format((float) $totalAmount, 2, '.', '')) {
                $this->error("  金额不匹配! 订单 ¥{$order->amount} vs 支付宝 ¥{$totalAmount}");
                continue;
            }

            if ($order->status === 'paid') {
                $this->info("  已到账，无需处理");
                continue;
            }

            if ($dryRun) {
                $this->info("  ✓ 支付宝确认已付款，余额 ¥{$customer->balance} → ¥" . bcadd($customer->balance, $order->amount, 2) . " [DRY-RUN]");
                $fixed++;
                continue;
            }

            DB::transaction(function () use ($order, $customer, $tradeNo) {
                $fresh = PaymentOrder::where('id', $order->id)->lockForUpdate()->first();
                if ($fresh->status === 'paid') return;

                $cust = Customer::lockForUpdate()->findOrFail($customer->id);
                $balanceBefore = $cust->balance;
                $cust->increment('balance', $fresh->amount);
                $balanceAfter = bcadd($balanceBefore, $fresh->amount, 2);

                $fresh->update([
                    'status' => 'paid',
                    'provider_trade_no' => $tradeNo,
                    'paid_at' => now(),
                ]);

                Transaction::create([
                    'customer_id' => $cust->id,
                    'type' => Transaction::TYPE_TOPUP,
                    'amount' => $fresh->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'related_type' => PaymentOrder::class,
                    'related_id' => $fresh->id,
                    'description' => sprintf('支付宝充值 #%s', $fresh->order_no),
                    'operated_by' => null,
                ]);

                Log::info('Payment reconciled', [
                    'order_no' => $fresh->order_no,
                    'customer' => $cust->customer_name,
                    'amount' => $fresh->amount,
                    'trade_no' => $tradeNo,
                ]);
            });

            $newBalance = bcadd($customer->balance, $order->amount, 2);
            $this->info("  ✓ 到账成功 余额 ¥{$customer->balance} → ¥{$newBalance}");
            $fixed++;
        }

        $this->newLine();
        $this->info("完成: {$fixed} 笔到账, {$notPaid} 笔未支付");

        return 0;
    }
}
