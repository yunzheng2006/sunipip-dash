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
        // 只重试活跃订阅的规则：过期/退款/取消订阅遗留的 failed 规则不能重建，
        // 否则会给已退订的客户复活转发并重复加价。
        // "删除失败"是逆向操作失败（该删没删掉），语义与创建失败相反，同样不能重建。
        // 卡住超过 10 分钟的 pending 一并捡起（异步挂载的队列任务丢失时的兜底）
        $query = ForwardRule::where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('status', 'failed')
                       ->where(function ($q3) {
                           $q3->whereNull('error_message')
                              ->orWhere(function ($q4) {
                                  $q4->where('error_message', 'not like', '删除失败%')
                                     ->where('error_message', 'not like', '[重试中] 删除失败%');
                              });
                       });
                })
                ->orWhere(function ($q2) {
                    $q2->whereIn('status', ['pending', 'processing'])
                       ->where('updated_at', '<', now()->subMinutes(10));
                });
            })
            ->whereHas('subscription', function ($q) {
                $q->where('status', 'active')
                  ->where('expires_at', '>', now());
            });

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
            // 状态复位为 pending；保留原错误信息便于诊断和限流统计（避免重复加前缀）
            $origErr = $rule->error_message;
            if ($origErr && !str_starts_with($origErr, '[重试中]')) {
                $origErr = "[重试中] {$origErr}";
            }
            $rule->update([
                'status' => 'pending',
                'error_message' => $origErr,
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
