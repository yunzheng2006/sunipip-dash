<?php

namespace App\Console\Commands;

use App\Models\ReferralCommission;
use App\Models\SystemConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixCommissionRates extends Command
{
    protected $signature = 'fix:commission-rates {--dry-run : 仅显示受影响记录，不修改}';
    protected $description = '修复续费佣金使用了新购费率(20%)的错误记录，应使用续费费率(10%)';

    public function handle(): int
    {
        $correctRenewRate = (float) SystemConfig::get('referral.rate_renew', 10);
        $wrongRate = (float) SystemConfig::get('referral.rate', 20);
        $dryRun = $this->option('dry-run');

        $this->info("续费正确费率: {$correctRenewRate}%  错误使用的费率: {$wrongRate}%");
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');
        $this->newLine();

        $affected = ReferralCommission::where('trigger_type', 'renew')
            ->where('commission_rate', $wrongRate)
            ->get();

        if ($affected->isEmpty()) {
            $this->info('没有找到需要修复的续费佣金记录。');
            return 0;
        }

        $this->table(
            ['ID', '推荐人', '被推荐人', '金额', '错误费率', '错误佣金', '正确费率', '正确佣金', '差额', '状态'],
            $affected->map(function ($c) use ($correctRenewRate) {
                $correctCommission = round($c->trigger_amount * $correctRenewRate / 100, 2);
                $diff = round($c->commission_amount - $correctCommission, 2);
                return [
                    $c->id,
                    $c->referrer_id,
                    $c->referee_id,
                    $c->trigger_amount,
                    $c->commission_rate . '%',
                    $c->commission_amount,
                    $correctRenewRate . '%',
                    $correctCommission,
                    "-{$diff}",
                    $c->status,
                ];
            })
        );

        if ($dryRun) {
            $this->warn('DRY RUN 完成，未修改任何数据。去掉 --dry-run 执行实际修复。');
            return 0;
        }

        if (!$this->confirm('确认修复以上记录？')) {
            $this->info('已取消。');
            return 0;
        }

        $fixed = 0;
        DB::transaction(function () use ($affected, $correctRenewRate, &$fixed) {
            foreach ($affected as $c) {
                $correctCommission = round($c->trigger_amount * $correctRenewRate / 100, 2);
                $diff = round($c->commission_amount - $correctCommission, 2);

                if ($c->status === 'credited' && $diff > 0) {
                    $referrer = \App\Models\Customer::find($c->referrer_id);
                    if ($referrer) {
                        $referrer->decrement('commission_balance', $diff);
                        $this->line("  扣回推荐人#{$referrer->id}多发佣金: ¥{$diff}");
                    }
                }

                $c->update([
                    'commission_rate' => $correctRenewRate,
                    'commission_amount' => $correctCommission,
                ]);

                $fixed++;
                $this->line("  修复 #{$c->id}: {$c->commission_amount} → {$correctCommission}");
            }
        });

        $this->info("修复完成，共修复 {$fixed} 条记录。");
        return 0;
    }
}
