<?php

namespace App\Console\Commands;

use App\Models\IpipvInstance;
use App\Models\IpipvOrder;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Services\IpipvApiService;
use App\Services\SparkApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SparkUpstreamRenew extends Command
{
    protected $signature = 'upstream:auto-renew {--dry : 试运行，不实际续费} {--days=5 : 提前几天续费}';

    protected $description = '自动续费即将到期的上游实例（Spark + IPIPV，按月滚动，与客户计费解耦）';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $days = (int) $this->option('days');

        $this->info("=== Spark 上游续费 ===");
        [$r1, $s1, $f1] = $this->renewSpark($dry, $days);

        $this->info("=== IPIPV 上游续费 ===");
        [$r2, $s2, $f2] = $this->renewIpipv($dry, $days);

        $this->info("✓ 总计" . ($dry ? '（试运行）' : '')
            . " 续费:" . ($r1 + $r2)
            . " 跳过:" . ($s1 + $s2)
            . " 失败:" . ($f1 + $f2));

        return self::SUCCESS;
    }

    private function renewSpark(bool $dry, int $days): array
    {
        $cutoff = now()->addDays($days);
        $sparkApi = app(SparkApiService::class);

        $instances = SparkInstance::whereNotNull('expire_at')
            ->where('expire_at', '<=', $cutoff)
            ->whereIn('status', [1, 2])
            ->whereHas('proxyIp', fn ($q) => $q->whereNotNull('assigned_customer_id'))
            ->with(['proxyIp.activeSubscription'])
            ->get();

        $this->info("找到 {$instances->count()} 个即将到期的 Spark 实例（{$days}天内）");

        $renewed = $skipped = $failed = 0;

        foreach ($instances as $instance) {
            $proxyIp = $instance->proxyIp;
            $subscription = $proxyIp?->activeSubscription;

            if (!$subscription || $subscription->status !== 'active' || $subscription->expires_at <= now()) {
                $this->line("  跳过 {$instance->instance_id}: 无活跃订阅，Spark 自然到期");
                $skipped++;
                continue;
            }

            // 只要实例到期覆盖不到订阅到期就续费（容差 6 小时，仅吸收本地/上游几小时的时钟差）
            // 之前容差写成 3 天：订阅比实例晚到期 1-3 天时被跳过，客户已付费的最后几天上游掉线
            if ($instance->expire_at && $subscription->expires_at <= $instance->expire_at->copy()->addHours(6)) {
                $this->line("  跳过 {$instance->instance_id}: 实例到期({$instance->expire_at->format('m-d H:i')})已覆盖订阅到期({$subscription->expires_at->format('m-d H:i')})");
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->line("  [DRY] {$instance->instance_id} → 续费1个月（订阅到期: {$subscription->expires_at->format('Y-m-d')}）");
                $renewed++;
                continue;
            }

            try {
                $reqOrderNo = SparkOrder::generateReqOrderNo();

                $sparkOrder = SparkOrder::create([
                    'req_order_no' => $reqOrderNo,
                    'method' => 'RenewProxy',
                    'product_id' => '',
                    'amount' => 1,
                    'duration' => 1,
                    'unit' => 3,
                    'status' => 1,
                    'request_data' => [
                        'instanceId' => $instance->instance_id,
                        'duration' => 1,
                        'unit' => 3,
                        'trigger' => 'upstream_auto_renew',
                    ],
                ]);

                $response = $sparkApi->renewProxy($reqOrderNo, [[
                    'instanceId' => $instance->instance_id,
                    'duration' => 1,
                    'unit' => 3,
                ]]);

                $sparkOrder->update([
                    'spark_order_no' => $response['orderNo'] ?? null,
                    'status' => 2,
                    'response_data' => $response,
                ]);

                $base = ($instance->expire_at && $instance->expire_at->isFuture())
                    ? $instance->expire_at->copy() : now();
                $newExpireAt = $base->addDays(30);
                $instance->update(['expire_at' => $newExpireAt]);

                $renewed++;
                $this->line("  ✓ {$instance->instance_id} → 续至 {$newExpireAt->format('Y-m-d')}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  ✗ {$instance->instance_id}: {$e->getMessage()}");
                Log::error('Spark upstream renew failed', [
                    'instance_id' => $instance->instance_id,
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(300_000);
        }

        return [$renewed, $skipped, $failed];
    }

    private function renewIpipv(bool $dry, int $days): array
    {
        $cutoff = now()->addDays($days);

        $instances = IpipvInstance::whereNotNull('expire_at')
            ->where('expire_at', '<=', $cutoff)
            ->whereIn('status', [1, 2, 3])
            ->whereHas('proxyIp', fn ($q) => $q->whereNotNull('assigned_customer_id'))
            ->with(['proxyIp.activeSubscription'])
            ->get();

        $this->info("找到 {$instances->count()} 个即将到期的 IPIPV 实例（{$days}天内）");

        if ($instances->isEmpty()) return [0, 0, 0];

        $ipipvApi = app(IpipvApiService::class);
        $renewed = $skipped = $failed = 0;

        foreach ($instances as $instance) {
            $proxyIp = $instance->proxyIp;
            $subscription = $proxyIp?->activeSubscription;

            if (!$subscription || $subscription->status !== 'active' || $subscription->expires_at <= now()) {
                $this->line("  跳过 {$instance->instance_no}: 无活跃订阅，IPIPV 自然到期");
                $skipped++;
                continue;
            }

            if ($instance->expire_at && $subscription->expires_at <= $instance->expire_at->copy()->addDays(3)) {
                $this->line("  跳过 {$instance->instance_no}: 订阅({$subscription->expires_at->format('m-d')})与实例到期({$instance->expire_at->format('m-d')})差距在3天内，不续上游");
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->line("  [DRY] {$instance->instance_no} → 续费1个月（订阅到期: {$subscription->expires_at->format('Y-m-d')}）");
                $renewed++;
                continue;
            }

            try {
                $appOrderNo = IpipvOrder::generateAppOrderNo();

                $order = IpipvOrder::create([
                    'app_order_no' => $appOrderNo,
                    'method' => 'renew',
                    'product_no' => $instance->product_no ?? '',
                    'amount' => 1,
                    'duration' => 1,
                    'unit' => 3,
                    'cycle_times' => 1,
                    'status' => 1,
                    'request_data' => [
                        'instanceNo' => $instance->instance_no,
                        'cycleTimes' => 1,
                        'trigger' => 'upstream_auto_renew',
                    ],
                ]);

                $response = $ipipvApi->renewProxy($appOrderNo, [[
                    'instanceNo' => $instance->instance_no,
                    'cycleTimes' => 1,
                ]]);

                $order->update([
                    'ipipv_order_no' => $response['orderNo'] ?? null,
                    'status' => 2,
                    'response_data' => $response,
                ]);

                $base = ($instance->expire_at && $instance->expire_at->isFuture())
                    ? $instance->expire_at->copy() : now();
                $newExpireAt = $base->addDays(30);
                $instance->update(['expire_at' => $newExpireAt]);

                $renewed++;
                $this->line("  ✓ {$instance->instance_no} → 续至 {$newExpireAt->format('Y-m-d')}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  ✗ {$instance->instance_no}: {$e->getMessage()}");
                Log::error('IPIPV upstream renew failed', [
                    'instance_no' => $instance->instance_no,
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(300_000);
        }

        return [$renewed, $skipped, $failed];
    }
}
