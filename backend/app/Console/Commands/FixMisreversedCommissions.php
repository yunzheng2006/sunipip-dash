<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixMisreversedCommissions extends Command
{
    protected $signature = 'fix:misreversed-commissions {--dry-run : 仅显示受影响记录}';
    protected $description = '修复因 orWhereNull(trigger_id) 导致被误收的推荐佣金：订阅仍有效但佣金被标记为 reversed';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');

        $misreversed = DB::table('referral_commissions as rc')
            ->join('subscriptions as s', 'rc.trigger_id', '=', 's.id')
            ->where('rc.status', 'reversed')
            ->whereNotNull('rc.trigger_id')
            ->where('s.status', '!=', 'refunded')
            ->select('rc.*', 's.status as sub_status')
            ->get();

        if ($misreversed->isEmpty()) {
            $this->info('没有被误收的推荐佣金。');
            return 0;
        }

        $customerNames = DB::table('customers')->pluck('customer_name', 'id');
        $rows = [];
        $fixed = 0;

        foreach ($misreversed as $rc) {
            $referrerName = ($customerNames[$rc->referrer_id] ?? '?') . " (#$rc->referrer_id)";
            $refereeName = ($customerNames[$rc->referee_id] ?? '?') . " (#$rc->referee_id)";

            $reversalTxn = DB::table('transactions')
                ->where('customer_id', $rc->referrer_id)
                ->where('type', 'commission_reversal')
                ->whereRaw('ABS(amount) = ?', [$rc->commission_amount])
                ->whereBetween('created_at', [
                    date('Y-m-d H:i:s', strtotime($rc->updated_at) - 5),
                    date('Y-m-d H:i:s', strtotime($rc->updated_at) + 5),
                ])
                ->first();

            $rows[] = [
                $rc->id,
                $referrerName,
                $refereeName,
                '¥' . $rc->commission_amount,
                '#' . $rc->trigger_id . ' (' . $rc->sub_status . ')',
                $reversalTxn ? '#' . $reversalTxn->id : '未找到',
                substr($rc->updated_at, 0, 16),
            ];

            if (!$dryRun) {
                DB::table('referral_commissions')
                    ->where('id', $rc->id)
                    ->update(['status' => 'credited']);

                $referrer = Customer::find($rc->referrer_id);
                if ($referrer) {
                    $referrer->increment('commission_balance', $rc->commission_amount);
                }

                if ($reversalTxn) {
                    DB::table('transactions')->where('id', $reversalTxn->id)->delete();
                }

                $fixed++;
            }
        }

        $this->table(
            ['佣金ID', '推荐人', '被推荐人', '误收金额', '订阅(状态)', '误收交易', '误收时间'],
            $rows
        );

        $this->newLine();
        if ($dryRun) {
            $this->warn("共 " . count($rows) . " 条被误收。去掉 --dry-run 执行修复。");
        } else {
            $this->info("修复完成，共恢复 {$fixed} 条推荐佣金。");
        }

        return 0;
    }
}
