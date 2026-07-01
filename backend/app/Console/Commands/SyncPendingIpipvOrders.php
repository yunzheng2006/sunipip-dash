<?php

namespace App\Console\Commands;

use App\Models\IpipvOrder;
use App\Services\IpipvProvisionService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPendingIpipvOrders extends Command
{
    protected $signature = 'ipipv:sync-pending {--minutes=5 : 超过多少分钟视为卡住}';
    protected $description = '自动同步卡住的 IPIPV 待处理订单';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $pendingOrders = IpipvOrder::where('status', 1)
            ->where('method', 'open')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get();

        if ($pendingOrders->isEmpty()) {
            return 0;
        }

        $this->info("发现 {$pendingOrders->count()} 个 IPIPV 卡住订单，开始同步...");

        $svc = app(IpipvProvisionService::class);
        $stuckOrders = [];

        foreach ($pendingOrders as $order) {
            try {
                $result = $svc->syncOrder($order);
                $order->refresh();

                if ($order->status === 3) {
                    $this->info("✓ IPIPV 订单 {$order->app_order_no} 同步完成");
                } else {
                    $this->warn("⏳ IPIPV 订单 {$order->app_order_no} 仍在处理中 (status={$order->status})");
                    $stuckOrders[] = $order;
                }
            } catch (\Exception $e) {
                $this->error("IPIPV 订单 {$order->app_order_no} 同步异常: {$e->getMessage()}");
                Log::error("ipipv:sync-pending error", [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                $stuckOrders[] = $order;
            }
        }

        if (!empty($stuckOrders)) {
            $lines = [];
            foreach ($stuckOrders as $o) {
                $mins = now()->diffInMinutes($o->created_at);
                $lines[] = "> 订单 `{$o->app_order_no}` 产品 {$o->product_no} × {$o->amount}条，等待 {$mins} 分钟";
            }

            try {
                app(NotificationService::class)->dispatch('spark_order_stuck', [
                    'title' => 'IPIPV 订单开通超时',
                    'content' => "### ⚠️ IPIPV 订单开通超时\n\n"
                        . "以下 **" . count($stuckOrders) . "** 个 IPIPV 订单超时未完成：\n\n"
                        . implode("\n", $lines),
                    'dedup_key' => 'ipipv_stuck_' . now()->format('Y-m-d_H'),
                ]);
            } catch (\Exception $e) {
                Log::error("ipipv:sync-pending notification failed: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
