<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Services\SparkReleaseService;
use Illuminate\Console\Command;

class ReleaseStuckSparkInstances extends Command
{
    protected $signature = 'spark:release-stuck {--dry-run : 仅列出，不实际释放}';
    protected $description = '释放已标记 released 但未调用 Spark DelProxy 的实例';

    public function handle(): int
    {
        $ips = ProxyIp::where('status', 'released')
            ->whereNotNull('spark_instance_id')
            ->where('spark_instance_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('spark_release_status')->orWhere('spark_release_status', '');
            })
            ->get();

        if ($ips->isEmpty()) {
            $this->info('没有需要补释放的 Spark 实例');
            return 0;
        }

        $this->info("找到 {$ips->count()} 个未释放的 Spark 实例：");

        foreach ($ips as $ip) {
            $this->line("  IP#{$ip->id} {$ip->ip_address} | instance: {$ip->spark_instance_id}");
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run 模式，不实际释放');
            return 0;
        }

        $success = 0;
        $fail = 0;
        foreach ($ips as $ip) {
            $this->info("释放 IP#{$ip->id} {$ip->ip_address} ...");
            $result = SparkReleaseService::releaseInstance($ip, 'backfill_stuck_release');
            $this->line("  结果: [{$result['status']}] {$result['message']}");

            if ($result['status'] !== 'failed') {
                $success++;
            } else {
                $fail++;
            }
        }

        $this->info("完成：成功 {$success}，失败 {$fail}");
        return $fail > 0 ? 1 : 0;
    }
}
