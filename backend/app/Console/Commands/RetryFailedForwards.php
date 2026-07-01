<?php

namespace App\Console\Commands;

use App\Jobs\AttachForwardJob;
use App\Models\ForwardRule;
use Illuminate\Console\Command;

/**
 * 重新入队失败的转发规则
 *
 * 用法：
 *   php artisan forward:retry-failed                  # 重试所有 failed
 *   php artisan forward:retry-failed --batch=xxxx     # 只重试某个批次
 *   php artisan forward:retry-failed --rate-limit     # 只重试 429 限流失败的
 */
class RetryFailedForwards extends Command
{
    protected $signature = 'forward:retry-failed
        {--batch= : 只处理指定 batch_id}
        {--rate-limit : 只处理 429 限流导致的失败}
        {--dry : 试运行}
        {--force : 跳过确认（定时任务用）}';

    protected $description = '重新入队失败的转发规则';

    public function handle(): int
    {
        $query = ForwardRule::where('status', 'failed');

        if ($batch = $this->option('batch')) {
            $query->where('batch_id', $batch);
        }
        if ($this->option('rate-limit')) {
            $query->where('error_message', 'like', '%429%');
        }

        $failed = $query->get();

        if ($failed->isEmpty()) {
            $this->info('✓ 没有需要重试的 failed 规则');
            return self::SUCCESS;
        }

        $this->info("找到 {$failed->count()} 条 failed 规则");

        // 按错误类型分布
        $errorTypes = $failed->groupBy(function ($r) {
            $msg = $r->error_message ?? '';
            if (str_contains($msg, '429')) return '429 限流';
            if (str_contains($msg, 'timeout')) return '超时';
            if (str_contains($msg, 'connection')) return '连接错误';
            if (str_contains($msg, '端口') && str_contains($msg, '已被使用')) return '端口冲突';
            return '其他';
        })->map->count();

        $this->newLine();
        $this->line('失败原因分布：');
        foreach ($errorTypes as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        if ($this->option('dry')) {
            $this->warn('\n试运行模式，未实际入队。加 --apply 或去掉 --dry 才会执行。');
            return self::SUCCESS;
        }

        $this->newLine();
        if (!$this->option('force') && !$this->confirm("确认重新入队 {$failed->count()} 条？", true)) {
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($failed as $rule) {
            // 状态复位为 pending
            $rule->update([
                'status' => 'pending',
                'error_message' => null,
            ]);
            AttachForwardJob::dispatch($rule->id);
            $count++;
        }

        $this->info("✓ 已入队 {$count} 条，worker 会自动处理");
        $this->line('  可通过：php artisan queue:work --once 快速测试一条');
        $this->line('  或等 supervisor 的 worker 自动消费');

        return self::SUCCESS;
    }
}
