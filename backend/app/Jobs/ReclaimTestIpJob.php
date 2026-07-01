<?php

namespace App\Jobs;

use App\Models\ProxyIp;
use App\Models\SparkOrder;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReclaimTestIpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public int $subscriptionId) {}

    public function handle(): void
    {
        $sub = Subscription::find($this->subscriptionId);
        if (!$sub || !$sub->is_test) {
            return;
        }

        if (in_array($sub->status, ['cancelled', 'refunded'])) {
            return;
        }

        if ($sub->renewed_count > 0) {
            Log::info('ReclaimTestIpJob: skipping renewed subscription', [
                'subscription_id' => $sub->id,
                'renewed_count' => $sub->renewed_count,
            ]);
            return;
        }

        Log::info('ReclaimTestIpJob: reclaiming test subscription', [
            'subscription_id' => $sub->id,
            'proxy_ip_id' => $sub->proxy_ip_id,
        ]);

        $proxyIp = $sub->proxy_ip_id ? ProxyIp::withTrashed()->find($sub->proxy_ip_id) : null;

        // 1. 清理转发规则
        $this->cleanForwards($sub);

        // 2. API 释放
        if ($proxyIp) {
            $this->releaseUpstream($proxyIp);
        }

        // 3. 取消订阅
        $sub->update(['status' => 'cancelled']);

        // 4. 软删除 IP
        if ($proxyIp) {
            $proxyIp->update([
                'status' => 'expired',
                'assigned_customer_id' => null,
                'released_at' => now(),
                'release_reason' => '测试IP自动回收(10h)',
            ]);
            if (!$proxyIp->trashed()) {
                $proxyIp->delete();
            }
        }

        Log::info('ReclaimTestIpJob: reclaim complete', [
            'subscription_id' => $sub->id,
        ]);
    }

    private function cleanForwards(Subscription $sub): void
    {
        try {
            app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($sub);
        } catch (\Throwable $e) {
            Log::warning('ReclaimTestIpJob: Ny forward cleanup failed', [
                'subscription_id' => $sub->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($sub);
        } catch (\Throwable $e) {
            Log::warning('ReclaimTestIpJob: Xui forward cleanup failed', [
                'subscription_id' => $sub->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function releaseUpstream(ProxyIp $proxyIp): void
    {
        // Spark 释放
        if ($proxyIp->spark_instance_id) {
            try {
                $spark = app(\App\Services\SparkApiService::class);
                $reqOrderNo = SparkOrder::generateReqOrderNo();
                $spark->delProxy($reqOrderNo, [$proxyIp->spark_instance_id]);

                SparkOrder::create([
                    'req_order_no' => $reqOrderNo,
                    'method' => 'DelProxy',
                    'product_id' => '',
                    'amount' => 1,
                    'duration' => 0,
                    'unit' => 0,
                    'status' => 1,
                    'request_data' => ['reason' => '测试IP自动回收', 'proxy_ip_id' => $proxyIp->id],
                    'response_data' => [],
                ]);

                Log::info('ReclaimTestIpJob: Spark DelProxy sent', [
                    'instance_id' => $proxyIp->spark_instance_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('ReclaimTestIpJob: Spark release failed', [
                    'instance_id' => $proxyIp->spark_instance_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // IPIPV 释放
        if ($proxyIp->ipipv_instance_id) {
            try {
                $ipipv = app(\App\Services\IpipvApiService::class);
                $orderNo = \App\Models\IpipvOrder::generateAppOrderNo();
                $ipipv->releaseProxy($orderNo, [$proxyIp->ipipv_instance_id]);

                \App\Models\IpipvOrder::create([
                    'app_order_no' => $orderNo,
                    'method' => 'release',
                    'status' => 1,
                    'request_data' => ['reason' => '测试IP自动回收', 'proxy_ip_id' => $proxyIp->id],
                    'response_data' => [],
                ]);

                Log::info('ReclaimTestIpJob: IPIPV release sent', [
                    'instance_id' => $proxyIp->ipipv_instance_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('ReclaimTestIpJob: IPIPV release failed', [
                    'instance_id' => $proxyIp->ipipv_instance_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
