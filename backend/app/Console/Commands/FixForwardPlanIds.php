<?php

namespace App\Console\Commands;

use App\Models\ForwardRule;
use Illuminate\Console\Command;

class FixForwardPlanIds extends Command
{
    protected $signature = 'fix:forward-plan-ids
        {--video-plan-id=1 : 视频专线 ForwardPlan ID}
        {--dry-run : 仅统计，不修改}';

    protected $description = '为没有 forward_plan_id 的 ForwardRule 标记为视频专线';

    public function handle(): int
    {
        $videoPlanId = (int) $this->option('video-plan-id');
        $dryRun = $this->option('dry-run');

        $query = ForwardRule::whereNull('forward_plan_id');
        $count = $query->count();

        $this->info("找到 {$count} 条没有 forward_plan_id 的 ForwardRule");

        if ($count === 0) {
            $this->info('无需修复');
            return 0;
        }

        if ($dryRun) {
            $this->warn('Dry run 模式，不会修改数据');
            return 0;
        }

        $updated = ForwardRule::whereNull('forward_plan_id')
            ->update(['forward_plan_id' => $videoPlanId]);

        $this->info("已将 {$updated} 条 ForwardRule 的 forward_plan_id 设为 {$videoPlanId}（视频专线）");

        return 0;
    }
}
