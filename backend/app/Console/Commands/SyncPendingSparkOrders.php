<?php

namespace App\Console\Commands;

use App\Models\SparkOrder;
use App\Services\NotificationService;
use App\Services\SparkProvisionService;
use App\Services\SparkApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPendingSparkOrders extends Command
{
    protected $signature = 'spark:sync-pending {--minutes=5 : 超过多少分钟视为卡住}';
    protected $description = '自动同步卡住的 Spark 待处理订单，超时则发企业微信通知';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $pendingOrders = SparkOrder::where('status', 1)
            ->where('method', 'CreateProxy')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get();

        if ($pendingOrders->isEmpty()) {
            $this->info('没有卡住的待处理订单');
            return 0;
        }

        $this->info("发现 {$pendingOrders->count()} 个卡住的订单（>{$minutes}分钟），开始同步...");

        $spark = app(SparkApiService::class);
        $provisionService = app(SparkProvisionService::class);
        $stuckOrders = [];

        foreach ($pendingOrders as $sparkOrder) {
            try {
                $result = $spark->getOrder(
                    $sparkOrder->req_order_no,
                    $sparkOrder->spark_order_no ?? ''
                );

                $newStatus = (int) ($result['status'] ?? $sparkOrder->status);
                $sparkOrder->update([
                    'status' => $newStatus,
                    'cost_amount' => $result['amount'] ?? $sparkOrder->cost_amount,
                    'response_data' => $result,
                ]);

                if ($newStatus === 2 && !empty($result['ipInfo'])) {
                    $provisionService->processInstances(
                        $sparkOrder,
                        $result['ipInfo'],
                        $sparkOrder->request_data ?? []
                    );
                    $this->info("✓ 订单 {$sparkOrder->req_order_no} 同步完成，IP已入库");
                    Log::info("spark:sync-pending 自动同步成功", [
                        'order_id' => $sparkOrder->id,
                        'req_order_no' => $sparkOrder->req_order_no,
                    ]);
                } elseif ($newStatus === 3) {
                    $this->warn("✗ 订单 {$sparkOrder->req_order_no} Spark返回失败");
                    $stuckOrders[] = $sparkOrder;
                } else {
                    $this->warn("⏳ 订单 {$sparkOrder->req_order_no} 仍在处理中 (status={$newStatus})");
                    $stuckOrders[] = $sparkOrder;
                }
            } catch (\Exception $e) {
                $this->error("同步订单 {$sparkOrder->req_order_no} 异常: {$e->getMessage()}");
                Log::error("spark:sync-pending 同步异常", [
                    'order_id' => $sparkOrder->id,
                    'error' => $e->getMessage(),
                ]);
                $stuckOrders[] = $sparkOrder;
            }
        }

        if (!empty($stuckOrders)) {
            $this->notifyStuckOrders($stuckOrders);
        }

        return 0;
    }

    private function notifyStuckOrders(array $orders): void
    {
        $lines = [];
        foreach ($orders as $order) {
            $reqData = $order->request_data ?? [];
            $customerName = $reqData['customer_name'] ?? $reqData['country_cn'] ?? '';
            $productName = $reqData['product_name'] ?? $reqData['country_code'] ?? '';
            $minutesAgo = now()->diffInMinutes($order->created_at);
            $lines[] = "> 订单 `{$order->req_order_no}` {$customerName} {$productName} × {$order->amount}条，等待 {$minutesAgo} 分钟";
        }

        $content = "### ⚠️ Spark 订单开通超时\n\n"
            . "以下 **" . count($orders) . "** 个订单已超时未完成开通：\n\n"
            . implode("\n", $lines)
            . "\n\n请检查 [Spark 订单管理](" . rtrim(config('proxy.platform.admin_portal_url', 'https://admin.sunipip.com'), '/') . "/spark-orders) 或联系 Spark 运营。";

        try {
            app(NotificationService::class)->dispatch('spark_order_stuck', [
                'title' => 'Spark 订单开通超时',
                'content' => $content,
                'dedup_key' => 'spark_stuck_' . now()->format('Y-m-d_H'),
            ]);
        } catch (\Exception $e) {
            Log::error("spark:sync-pending 通知发送失败: {$e->getMessage()}");
        }
    }
}
