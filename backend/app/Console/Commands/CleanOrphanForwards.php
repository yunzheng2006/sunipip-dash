<?php

namespace App\Console\Commands;

use App\Models\ForwardRule;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanOrphanForwards extends Command
{
    protected $signature = 'forwards:clean-orphan {--dry-run}';
    protected $description = 'Clean up active forward rules belonging to expired/cancelled subscriptions';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '=== DRY RUN ===' : '=== EXECUTING CLEANUP ===');

        $orphanRules = ForwardRule::where('status', 'active')
            ->whereHas('subscription', function ($q) {
                $q->whereIn('status', ['expired', 'cancelled', 'refunded']);
            })
            ->with(['subscription:id,status,customer_id,proxy_ip_id', 'subscription.customer:id,customer_name'])
            ->get();

        $this->info("Found {$orphanRules->count()} active forward rules on non-active subscriptions.");

        if ($orphanRules->isEmpty()) {
            return 0;
        }

        $grouped = $orphanRules->groupBy('subscription_id');
        $nyDeleted = 0;
        $xuiDeleted = 0;

        foreach ($grouped as $subId => $rules) {
            $sub = $rules->first()->subscription;
            $customerName = $sub?->customer?->customer_name ?? '?';

            $this->line("  Sub #{$subId} ({$customerName}, status={$sub?->status}): {$rules->count()} active forward rules");

            if ($dryRun) continue;

            try {
                $count = app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($sub);
                $nyDeleted += $count;
                if ($count > 0) {
                    $this->line("    Ny deleted: {$count}");
                }
            } catch (\Throwable $e) {
                $this->warn("    Ny cleanup failed: {$e->getMessage()}");
            }

            try {
                $count = app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($sub);
                $xuiDeleted += $count;
                if ($count > 0) {
                    $this->line("    Xui deleted: {$count}");
                }
            } catch (\Throwable $e) {
                $this->warn("    Xui cleanup failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Cleaned: Ny={$nyDeleted}, Xui={$xuiDeleted}");
        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply.');
        }

        return 0;
    }
}
