<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Services\SparkApiService;
use Illuminate\Console\Command;

class SparkQueryInstance extends Command
{
    protected $signature = 'spark:query {ip : IP地址}';

    protected $description = '查询 Spark 上游实例详情（到期时间、续费记录等）';

    public function handle(SparkApiService $spark): int
    {
        $ip = $this->argument('ip');

        // 1. 本地查询
        $instance = SparkInstance::where('ip', $ip)->first();
        if (!$instance) {
            $this->error("本地未找到 IP {$ip} 的 Spark 实例记录");

            $proxyIp = ProxyIp::where('ip_address', $ip)->first();
            if ($proxyIp) {
                $this->info("但在 proxy_ips 中找到: ID={$proxyIp->id}, status={$proxyIp->status}, upstream_provider={$proxyIp->upstream_provider}");
                if ($proxyIp->spark_instance_id) {
                    $instance = SparkInstance::find($proxyIp->spark_instance_id);
                    if ($instance) {
                        $this->info("通过 spark_instance_id={$proxyIp->spark_instance_id} 找到实例");
                    }
                }
            }

            if (!$instance) return self::FAILURE;
        }

        $this->info("=== 本地记录 ===");
        $this->table(['字段', '值'], [
            ['SparkInstance ID', $instance->id],
            ['Spark instanceId', $instance->instance_id],
            ['IP', $instance->ip],
            ['Port', $instance->port],
            ['用户名', $instance->username],
            ['状态', $instance->status . ' (' . SparkApiService::mapInstanceStatus($instance->status) . ')'],
            ['本地到期时间', $instance->expire_at?->format('Y-m-d H:i:s')],
            ['proxy_ip_id', $instance->proxy_ip_id],
        ]);

        // 2. 查询上游 API
        $this->info("\n=== 查询 Spark API ===");
        try {
            $data = $spark->getInstance(['instanceId' => $instance->instance_id]);
            if (empty($data)) {
                $this->warn("Spark API 返回空数据");
                return self::SUCCESS;
            }

            $expireAt = isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : 'N/A';

            $this->table(['字段', '值'], [
                ['instanceId', $data['instanceId'] ?? 'N/A'],
                ['IP', $data['ip'] ?? 'N/A'],
                ['Port', $data['port'] ?? 'N/A'],
                ['用户名', $data['username'] ?? 'N/A'],
                ['密码', $data['password'] ?? 'N/A'],
                ['状态', ($data['status'] ?? 'N/A') . ' (' . SparkApiService::mapInstanceStatus($data['status'] ?? 0) . ')'],
                ['到期时间', $expireAt],
                ['到期时间戳', $data['expireAt'] ?? 'N/A'],
                ['流量 flow', $data['flow'] ?? 'N/A'],
                ['剩余流量', $data['balanceFlow'] ?? 'N/A'],
                ['类型 type', $data['type'] ?? 'N/A'],
                ['useType', $data['useType'] ?? 'N/A'],
            ]);

            // 打印完整的原始返回
            $this->info("\n=== 完整原始数据 ===");
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        } catch (\Throwable $e) {
            $this->error("API 查询失败: " . $e->getMessage());
        }

        // 3. 查看本地续费相关记录
        $this->info("\n=== 关联订阅 ===");
        $proxyIp = ProxyIp::find($instance->proxy_ip_id);
        if ($proxyIp) {
            $subs = $proxyIp->subscriptions()
                ->orderByDesc('id')
                ->get(['id', 'customer_id', 'price', 'duration', 'unit', 'status', 'started_at', 'expires_at', 'renewed_count', 'last_renewed_at']);

            if ($subs->isEmpty()) {
                $this->line("无关联订阅");
            } else {
                $rows = $subs->map(fn($s) => [
                    $s->id, $s->customer_id, $s->price, "{$s->duration}" . match($s->unit) { 1=>'天',2=>'周',3=>'月',4=>'年',default=>'?' },
                    $s->status, $s->started_at?->format('Y-m-d H:i'), $s->expires_at?->format('Y-m-d H:i'),
                    $s->renewed_count, $s->last_renewed_at?->format('Y-m-d H:i'),
                ])->toArray();

                $this->table(['Sub ID', 'Cust', 'Price', 'Duration', 'Status', 'Started', 'Expires', 'Renewed#', 'Last Renew'], $rows);
            }
        }

        // 4. Spark 订单记录
        $this->info("\n=== Spark 订单 ===");
        $order = $instance->sparkOrder;
        if ($order) {
            $this->table(['字段', '值'], [
                ['Order ID', $order->id],
                ['Spark orderNo', $order->order_no],
                ['reqOrderNo', $order->req_order_no],
                ['类型', $order->type],
                ['状态', $order->status],
                ['成本', $order->cost_amount],
                ['创建时间', $order->created_at?->format('Y-m-d H:i:s')],
            ]);
        }

        return self::SUCCESS;
    }
}
