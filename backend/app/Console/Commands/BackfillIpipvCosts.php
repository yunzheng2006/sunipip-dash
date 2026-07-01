<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillIpipvCosts extends Command
{
    protected $signature = 'subscriptions:backfill-ipipv-costs {--dry-run}';
    protected $description = '回填 IPIPV 订阅的 hard_cost 和 sales_cost（使用 referral 设置的覆盖价）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $override = \App\Models\SystemConfig::get('cost.ipipv_hard_cost_override');

        if (!$override || (float) $override <= 0) {
            $this->error('cost.ipipv_hard_cost_override 未设置或为 0，请先在 设置/referral 页面配置');
            return 1;
        }

        $cost = (float) $override;
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "IPIPV 成本覆盖价: {$cost} 元/月");

        $subs = DB::table('subscriptions')
            ->join('proxy_ips', 'proxy_ips.id', '=', 'subscriptions.proxy_ip_id')
            ->whereNotNull('proxy_ips.ipipv_instance_id')
            ->where('proxy_ips.ipipv_instance_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('subscriptions.hard_cost')
                  ->orWhere('subscriptions.hard_cost', 0)
                  ->orWhereNull('subscriptions.sales_cost')
                  ->orWhere('subscriptions.sales_cost', 0);
            })
            ->select('subscriptions.id', 'subscriptions.hard_cost', 'subscriptions.sales_cost')
            ->get();

        $this->info("待回填: {$subs->count()} 条订阅");

        $updated = 0;
        foreach ($subs as $sub) {
            $changes = [];
            if (!$sub->hard_cost || (float) $sub->hard_cost == 0) {
                $changes['hard_cost'] = $cost;
            }
            if (!$sub->sales_cost || (float) $sub->sales_cost == 0) {
                $changes['sales_cost'] = $cost;
            }

            if (empty($changes)) {
                continue;
            }

            if ($dryRun) {
                $this->line("  Sub #{$sub->id}: " . collect($changes)->map(fn($v, $k) => "{$k}={$v}")->implode(', '));
            } else {
                DB::table('subscriptions')->where('id', $sub->id)->update($changes);
            }
            $updated++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "已更新: {$updated} 条");

        return 0;
    }
}
