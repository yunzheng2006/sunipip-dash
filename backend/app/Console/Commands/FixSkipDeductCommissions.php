<?php

namespace App\Console\Commands;

use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSkipDeductCommissions extends Command
{
    protected $signature = 'commissions:fix-skip-deduct {--dry-run} {--exclude-customer=*} {--include-sales}';
    protected $description = '撤销 skipDeduct（线下已付不扣余额）续费产生的错误介绍人佣金（业务员佣金仅记业绩，默认不动）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $excludeCustomers = $this->option('exclude-customer');
        $includeSales = $this->option('include-sales');

        if ($dryRun) $this->warn('[DRY RUN]');
        if (!$includeSales) $this->info('业务员佣金仅记业绩，默认跳过。加 --include-sales 可一并撤销。');

        $query = Transaction::where('type', Transaction::TYPE_RENEW)->where('amount', 0);
        if (!empty($excludeCustomers)) {
            $query->whereNotIn('customer_id', $excludeCustomers);
        }
        $zeroTxns = $query->orderBy('id')->get();

        $this->info("零金额续费交易: {$zeroTxns->count()}");

        $refReversed = 0;
        $salesReversed = 0;
        $refTotal = 0.0;
        $salesTotal = 0.0;

        foreach ($zeroTxns as $txn) {
            $sub = Subscription::find($txn->related_id);
            if (!$sub) continue;

            $timeWindow = [$txn->created_at->copy()->subMinutes(5), $txn->created_at->copy()->addMinutes(5)];

            $refComms = ReferralCommission::where('trigger_id', $sub->id)
                ->where('trigger_type', 'renew')
                ->whereNotIn('status', ['reversed'])
                ->whereBetween('created_at', $timeWindow)
                ->get();

            foreach ($refComms as $rc) {
                $amt = (float) $rc->commission_amount;
                if ($dryRun) {
                    $referrer = \App\Models\Customer::find($rc->referrer_id);
                    $this->line("  [REF] Comm#{$rc->id} Sub#{$sub->id} ({$sub->customer->customer_name}) → {$referrer?->customer_name} ¥{$amt}");
                } else {
                    DB::transaction(function () use ($rc, $amt) {
                        $rc->update(['status' => 'reversed']);
                        $referrer = \App\Models\Customer::lockForUpdate()->find($rc->referrer_id);
                        if ($referrer && (float) $referrer->commission_balance >= $amt) {
                            $referrer->decrement('commission_balance', $amt);
                        }
                    });
                }
                $refReversed++;
                $refTotal += $amt;
            }

            if ($includeSales) {
                $salesComms = SalesCommission::where('trigger_id', $sub->id)
                    ->where('trigger_type', 'renew')
                    ->whereNotIn('status', ['reversed'])
                    ->whereBetween('created_at', $timeWindow)
                    ->get();

                foreach ($salesComms as $sc) {
                    $amt = (float) $sc->commission_amount;
                    if ($dryRun) {
                        $user = \App\Models\User::find($sc->user_id);
                        $this->line("  [SALES] Comm#{$sc->id} Sub#{$sub->id} ({$sub->customer->customer_name}) → {$user?->name} L{$sc->level} ¥{$amt}");
                    } else {
                        $sc->update(['status' => 'reversed']);
                    }
                    $salesReversed++;
                    $salesTotal += $amt;
                }
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}介绍人佣金撤销: {$refReversed}笔 ¥" . round($refTotal, 2));
        $this->info("{$prefix}业务员佣金撤销: {$salesReversed}笔 ¥" . round($salesTotal, 2));
        $this->info("{$prefix}合计: ¥" . round($refTotal + $salesTotal, 2));

        return 0;
    }
}
