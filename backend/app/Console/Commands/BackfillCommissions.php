<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ReferralCommission;
use App\Models\Subscription;
use App\Services\ReferralService;
use Illuminate\Console\Command;

class BackfillCommissions extends Command
{
    protected $signature = 'commissions:backfill {--dry-run : Preview without making changes}';
    protected $description = 'Backfill missing referral commission records for existing subscriptions';

    public function handle(ReferralService $referralService): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN MODE ===');
        }

        $customersWithReferral = Customer::whereNotNull('referred_by_customer')
            ->where('referred_by_customer', '>', 0)
            ->pluck('id')->all();

        if (empty($customersWithReferral)) {
            $this->info('No customers with referral relationships found.');
            return 0;
        }

        $subs = Subscription::whereIn('customer_id', $customersWithReferral)
            ->where('price', '>', 0)
            ->orderBy('created_at')
            ->get();

        $this->info("Found {$subs->count()} subscriptions (price > 0) for " . count($customersWithReferral) . " customers with referral relationships.");

        $existingReferralTriggers = ReferralCommission::where('trigger_type', 'purchase')
            ->whereIn('trigger_id', $subs->pluck('id'))
            ->pluck('trigger_id')->all();

        $referralCreated = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $customer = Customer::find($sub->customer_id);
            if (!$customer) continue;

            $amount = (float) $sub->price;
            if (!$customer->referred_by_customer || in_array($sub->id, $existingReferralTriggers)) {
                $skipped++;
                continue;
            }

            $this->line("Sub #{$sub->id} | {$customer->customer_name} | ¥{$amount} | {$sub->created_at} [+referral ref_by={$customer->referred_by_customer}]");

            if ($dryRun) {
                $referralCreated++;
                continue;
            }

            try {
                $referralService->processCommission($customer, 'purchase', $amount, $sub->id);
                $referralCreated++;
            } catch (\Throwable $e) {
                $this->error("  Referral commission failed for Sub #{$sub->id}: {$e->getMessage()}");
            }

        }

        $this->newLine();
        $this->info("Results:");
        $this->info("  Referral commissions created: {$referralCreated}");
        $this->info("  Skipped (already exist): {$skipped}");

        if ($dryRun) {
            $this->warn('No changes made (dry-run mode). Run without --dry-run to apply.');
        }

        return 0;
    }
}
