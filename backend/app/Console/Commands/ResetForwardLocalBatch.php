<?php

namespace App\Console\Commands;

use App\Models\ForwardRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 清理本地 forward_rules（配合用户已在 NY 面板手动删除的情况）
 *
 * 场景：
 *   用户上次批量创建 ~90 条卡住了，自己去 NY 面板手动删除了这些转发，
 *   但本地 forward_rules 表里还是 active/pending，
 *   subscription.price 也被加过转发费，需要一并回滚。
 *
 * 用法：
 *   php artisan forward:reset-local --hours=6                 # 预览
 *   php artisan forward:reset-local --hours=6 --apply         # 执行
 *   php artisan forward:reset-local --batch=xxxx --apply      # 按 batch_id 清理
 */
class ResetForwardLocalBatch extends Command
{
    protected $signature = 'forward:reset-local
        {--hours=6 : 清理最近多少小时内创建的规则}
        {--batch= : 按 batch_id 清理（如果指定，忽略 hours）}
        {--apply : 实际写库，默认仅预览}';

    protected $description = '清理本地 forward_rules（用户已在 NY 面板手动删除的情况）';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $hours = (int) $this->option('hours');
        $batch = $this->option('batch');

        $query = ForwardRule::whereIn('status', ['pending', 'processing', 'active', 'failed']);

        if ($batch) {
            $query->where('batch_id', $batch);
            $this->info("按 batch_id = {$batch} 清理");
        } else {
            $cutoff = now()->subHours($hours);
            $query->where('created_at', '>=', $cutoff);
            $this->info("清理最近 {$hours} 小时内创建的规则（cutoff: {$cutoff}）");
        }

        $rules = $query->orderBy('id')->get();

        if ($rules->isEmpty()) {
            $this->info('✓ 没有需要清理的规则');
            return self::SUCCESS;
        }

        $byStatus = $rules->groupBy('status')->map->count();
        $this->line("找到 {$rules->count()} 条规则：");
        foreach ($byStatus as $st => $n) {
            $this->line("  {$st}: {$n}");
        }

        // 预估会退的费用
        $totalRefund = (float) $rules->where('status', 'active')->sum('forward_fee');
        $this->line("预计退还转发费：¥" . number_format($totalRefund, 2) . "（从 subscription.price 减掉）");

        $this->newLine();
        $this->line('前 10 条样本：');
        foreach ($rules->take(10) as $r) {
            $this->line(sprintf(
                "  #%-5d sub=%d  %s:%d → %s  fee=¥%.2f  status=%s",
                $r->id,
                $r->subscription_id,
                $r->dest_host,
                $r->dest_port,
                $r->name,
                (float) $r->forward_fee,
                $r->status
            ));
        }

        if (!$apply) {
            $this->newLine();
            $this->warn('预览模式。加 --apply 实际清理。');
            return self::SUCCESS;
        }

        if (!$this->confirm('确认清理？此操作会把 forward_rules 标记为 deleted 并从 subscription.price 扣回转发费', false)) {
            $this->info('已取消');
            return self::SUCCESS;
        }

        $affected = 0;
        $refunded = 0.0;
        $subsUpdated = [];

        foreach ($rules as $rule) {
            DB::transaction(function () use ($rule, &$affected, &$refunded, &$subsUpdated) {
                $sub = $rule->subscription;

                // 只有 active 才需要退费（pending/failed 没加过钱）
                if ($rule->status === 'active' && $rule->forward_fee > 0 && $sub) {
                    $sub->decrement('price', $rule->forward_fee);
                    $refunded += (float) $rule->forward_fee;
                }

                $rule->update([
                    'status' => 'deleted',
                    'error_message' => '本地重置（用户已在 NY 手动删除）',
                    'last_synced_at' => now(),
                ]);
                $affected++;

                // 如果该订阅没有其他 active 转发了，关 has_forward
                if ($sub && !in_array($sub->id, $subsUpdated, true)) {
                    $hasOther = ForwardRule::where('subscription_id', $sub->id)
                        ->where('status', 'active')
                        ->where('id', '!=', $rule->id)
                        ->exists();
                    if (!$hasOther) {
                        $sub->update(['has_forward' => false]);
                    }
                    $subsUpdated[] = $sub->id;
                }
            });
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ 完成：标记 %d 条为 deleted，退还 ¥%.2f 转发费，涉及 %d 个订阅',
            $affected,
            $refunded,
            count($subsUpdated)
        ));

        return self::SUCCESS;
    }
}
