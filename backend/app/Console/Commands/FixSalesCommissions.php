<?php

namespace App\Console\Commands;

use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSalesCommissions extends Command
{
    protected $signature = 'commissions:fix-sales {--dry-run : Preview without making changes}';
    protected $description = 'Fix sales commissions that were calculated on full amount instead of amount minus referral commission';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '=== DRY RUN ===' : '=== EXECUTING FIX ===');

        $fixed = 0;
        $totalAdjusted = 0;
        $userAdjustments = [];

        $refComms = ReferralCommission::orderBy('id')->get();
        $this->info("Scanning {$refComms->count()} referral commission records...");

        foreach ($refComms as $rc) {
            $scs = SalesCommission::where('customer_id', $rc->referee_id)
                ->where('trigger_type', $rc->trigger_type)
                ->where('trigger_amount', $rc->trigger_amount)
                ->whereBetween('created_at', [$rc->created_at->subSeconds(10), $rc->created_at->addSeconds(10)])
                ->get();

            foreach ($scs as $sc) {
                $correctBase = round($rc->trigger_amount - $rc->commission_amount, 2);
                $correctComm = round($correctBase * $sc->commission_rate / 100, 2);
                $overpaid = round($sc->commission_amount - $correctComm, 2);

                if ($overpaid <= 0) continue;

                $this->line(sprintf(
                    "  SC#%d (L%d user=%d): trigger %.2f->%.2f, comm %.2f->%.2f, overpaid=%.2f %s",
                    $sc->id, $sc->level, $sc->user_id,
                    $sc->trigger_amount, $correctBase,
                    $sc->commission_amount, $correctComm,
                    $overpaid,
                    $sc->status === 'credited' ? '[CREDITED - will adjust balance]' : "[{$sc->status}]"
                ));

                if (!$dryRun) {
                    $sc->update([
                        'trigger_amount' => $correctBase,
                        'commission_amount' => $correctComm,
                    ]);

                    if ($sc->status === 'credited') {
                        $userAdjustments[$sc->user_id] = ($userAdjustments[$sc->user_id] ?? 0) + $overpaid;
                    }
                }

                $fixed++;
                $totalAdjusted += $overpaid;
            }
        }

        if (!$dryRun && !empty($userAdjustments)) {
            foreach ($userAdjustments as $userId => $amount) {
                $user = User::find($userId);
                if ($user) {
                    $before = (float) $user->commission_balance;
                    $user->decrement('commission_balance', $amount);
                    $this->info(sprintf(
                        "  User#%d (%s) commission_balance: %.2f -> %.2f (adjusted -%.2f)",
                        $userId, $user->name ?? $user->username, $before, $before - $amount, $amount
                    ));
                }
            }
        }

        $this->newLine();
        $this->info("Fixed: {$fixed} records, total adjustment: ¥{$totalAdjusted}");
        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return 0;
    }
}
