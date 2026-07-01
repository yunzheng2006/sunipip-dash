<?php

namespace App\Console\Commands;

use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTestConversionCommissions extends Command
{
    protected $signature = 'commissions:fix-test-conversion {--dry-run}';
    protected $description = '修正测试订阅转正时用了 renew 费率的佣金（应为 purchase 费率）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) $this->warn('[DRY RUN]');

        $purchaseRate = (float) \App\Models\SystemConfig::get('referral.rate_purchase', 20);
        $renewRate = (float) \App\Models\SystemConfig::get('referral.rate_renew', 10);

        $this->info("Purchase rate: {$purchaseRate}%, Renew rate: {$renewRate}%");

        $renews = ReferralCommission::where('trigger_type', 'renew')
            ->whereNotIn('status', ['reversed'])
            ->get();

        $fixed = 0;

        foreach ($renews as $rc) {
            $sub = Subscription::find($rc->trigger_id);
            if (!$sub) continue;
            if ($sub->renewed_count > 1) continue;

            $currentAmt = (float) $rc->commission_amount;
            $triggerAmt = (float) $rc->trigger_amount;
            $currentRate = (float) $rc->commission_rate;

            if (abs($currentRate - $renewRate) > 0.01) continue;

            $correctAmt = round($triggerAmt * $purchaseRate / 100, 2);
            $diff = round($correctAmt - $currentAmt, 2);

            if (abs($diff) < 0.01) continue;

            $referrer = \App\Models\Customer::find($rc->referrer_id);
            $customer = $sub->customer;

            if ($dryRun) {
                $this->line("  Comm#{$rc->id} Sub#{$sub->id} ({$customer?->customer_name}→{$referrer?->customer_name}): ¥{$currentAmt}({$currentRate}%) → ¥{$correctAmt}({$purchaseRate}%) diff=+¥{$diff}");
            } else {
                DB::transaction(function () use ($rc, $correctAmt, $purchaseRate, $diff, $referrer) {
                    $rc->update([
                        'trigger_type' => 'purchase',
                        'commission_rate' => $purchaseRate,
                        'commission_amount' => $correctAmt,
                    ]);
                    if ($referrer && $diff > 0) {
                        $referrer->increment('commission_balance', $diff);
                    }
                });
            }
            $fixed++;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}修正: {$fixed}笔");

        return 0;
    }
}
