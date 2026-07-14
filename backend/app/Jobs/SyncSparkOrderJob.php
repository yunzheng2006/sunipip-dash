<?php

namespace App\Jobs;

use App\Models\SparkOrder;
use App\Services\SparkApiService;
use App\Services\SparkProvisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 异步同步 Spark 开通订单 - 替代 createOrder 里的原地轮询
 *
 * 管理后台开通请求现在立即返回（status=1 开通中），本任务在后台
 * 轮询上游订单状态，完成后调 processInstances 入库（幂等）。
 * 任务放弃后仍有 spark:sync-pending 每分钟兜底，不会丢单。
 */
class SyncSparkOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 最多轮询 6 次（首次 + 5 次重试），之后交给 cron 兜底 */
    public int $tries = 6;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [8, 10, 15, 20, 30];
    }

    public function __construct(public int $sparkOrderId) {}

    public function handle(SparkApiService $spark, SparkProvisionService $provision): void
    {
        $order = SparkOrder::find($this->sparkOrderId);
        if (!$order || (int) $order->status !== 1) {
            return; // 已被 cron/手动同步处理，或订单不存在
        }

        try {
            $result = $spark->getOrder($order->req_order_no, $order->spark_order_no ?? '');
        } catch (\Throwable $e) {
            Log::warning("SyncSparkOrderJob: getOrder 失败，稍后重试", [
                'spark_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            $this->releaseOrGiveUp();
            return;
        }

        $newStatus = (int) ($result['status'] ?? $order->status);
        $order->update([
            'status' => $newStatus,
            'cost_amount' => $result['amount'] ?? $order->cost_amount,
            'response_data' => $result,
        ]);

        if ($newStatus === 2 && !empty($result['ipInfo'])) {
            $res = $provision->processInstances($order, $result['ipInfo'], $order->request_data ?? []);
            $this->linkPurchaseTransaction($order, $res['subscription_ids'] ?? []);
            Log::info("SyncSparkOrderJob: 订单开通完成", [
                'spark_order_id' => $order->id,
                'req_order_no' => $order->req_order_no,
                'subscriptions' => $res['subscription_ids'] ?? [],
            ]);
            return;
        }

        if ($newStatus === 3) {
            // 终态失败：processInstances 内的 refundShortfall 逻辑不会被走到，
            // 直接触发差额退款（传空实例列表，幂等）
            $provision->processInstances($order, [], $order->request_data ?? []);
            Log::warning("SyncSparkOrderJob: 订单上游开通失败", [
                'spark_order_id' => $order->id,
                'req_order_no' => $order->req_order_no,
            ]);
            return;
        }

        // 仍在开通中 → 按退避重试；用尽后交给 spark:sync-pending cron
        $this->releaseOrGiveUp();
    }

    private function releaseOrGiveUp(): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release(
                $this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)] ?? 30
            );
        }
        // 用尽重试静默结束，cron 每分钟兜底，且超时会发企微通知
    }

    /**
     * 把"开通订单扣费"流水挂到首个订阅上（与 SparkController::syncOrder 同逻辑）
     */
    private function linkPurchaseTransaction(SparkOrder $order, array $subscriptionIds): void
    {
        if (empty($subscriptionIds)) {
            return;
        }
        $customerId = $order->request_data['customer_id'] ?? null;
        if (!$customerId) {
            return;
        }
        try {
            \App\Models\Transaction::where('customer_id', $customerId)
                ->where('type', \App\Models\Transaction::TYPE_PURCHASE)
                ->where('description', 'like', '开通订单扣费%')
                ->where(function ($q) {
                    $q->whereNull('related_id')->orWhere('related_id', 0);
                })
                ->where('created_at', '>=', $order->created_at->copy()->subSeconds(5))
                ->where('created_at', '<=', $order->created_at->copy()->addSeconds(5))
                ->first()
                ?->update([
                    'related_type' => \App\Models\Subscription::class,
                    'related_id' => $subscriptionIds[0],
                ]);
        } catch (\Throwable $e) {
            Log::warning("SyncSparkOrderJob: 流水关联失败: {$e->getMessage()}");
        }
    }
}
