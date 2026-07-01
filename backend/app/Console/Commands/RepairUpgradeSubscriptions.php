<?php

namespace App\Console\Commands;

use App\Models\ForwardRule;
use App\Models\Subscription;
use Illuminate\Console\Command;

class RepairUpgradeSubscriptions extends Command
{
    protected $signature = 'subscriptions:repair-upgrades {--dry-run : Preview without making changes}';
    protected $description = 'Fix subscriptions with active forward rules but missing purchased_module';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN MODE ===');
        }

        $subs = Subscription::whereIn('status', ['active', 'expired'])
            ->where('is_test', false)
            ->where(function ($q) {
                $q->whereNull('purchased_module')
                  ->orWhere('purchased_module', 'static');
            })
            ->whereHas('forwardRule', fn($q) => $q->whereIn('status', ['active', 'pending', 'processing']))
            ->with(['forwardRule' => fn($q) => $q->with('forwardPlan')])
            ->get();

        $this->info("Found {$subs->count()} subscriptions with forward rules but missing/wrong purchased_module");

        $fixed = 0;
        foreach ($subs as $sub) {
            $rule = $sub->forwardRule;
            $module = $rule?->forwardPlan?->module ?? 'video';

            $updates = [];
            if (!$sub->has_forward) {
                $updates['has_forward'] = true;
            }
            if ($sub->purchased_module !== $module) {
                $updates['purchased_module'] = $module;
            }

            if (empty($updates)) {
                continue;
            }

            $this->line(sprintf(
                "  Sub #%d (customer #%d): %s → has_forward=%s, purchased_module=%s",
                $sub->id,
                $sub->customer_id,
                $sub->purchased_module ?? 'null',
                isset($updates['has_forward']) ? 'true' : '(no change)',
                $updates['purchased_module'] ?? '(no change)',
            ));

            if (!$dryRun) {
                $sub->update($updates);
            }
            $fixed++;
        }

        $this->info(($dryRun ? '[DRY RUN] Would fix' : 'Fixed') . " {$fixed} subscriptions");
        return 0;
    }
}
