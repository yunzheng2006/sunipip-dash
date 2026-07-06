<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTestCommissions extends Command
{
    protected $signature = 'fix:test-commissions {--fix : 执行修复，默认 dry-run}';

    protected $description = '回收测试订阅(is_test=1)产生的错误佣金';

    public function handle(): int
    {
        $isDryRun = !$this->option('fix');
        $this->info($isDryRun ? '[DRY RUN] 预览模式' : '[FIX] 执行模式');

        // 1. 查找所有关联测试订阅的返佣记录
        $badReferrals = ReferralCommission::whereIn('status', ['pending', 'credited'])
            ->where(function ($q) {
                // trigger_id 指向测试订阅
                $q->whereIn('trigger_id', function ($sq) {
                    $sq->select('id')->from('subscriptions')->where('is_test', 1);
                })
                // 或者 trigger_id 为空但 referee 只有测试订阅（追溯返佣场景）
                ->orWhere(function ($q2) {
                    $q2->whereNull('trigger_id')
                        ->whereIn('referee_id', function ($sq) {
                            $sq->select('customer_id')->from('subscriptions')
                                ->where('is_test', 1)
                                ->groupBy('customer_id')
                                ->havingRaw('COUNT(*) = SUM(is_test)');
                        });
                });
            })
            ->get();

        $this->info("\n=== 返佣记录 (referral_commissions) ===");
        $this->info("找到 {$badReferrals->count()} 条问题记录");

        $rows = [];
        foreach ($badReferrals as $rc) {
            $referrer = Customer::find($rc->referrer_id);
            $referee = Customer::find($rc->referee_id);
            $rows[] = [
                $rc->id,
                $referrer?->customer_name ?? '#' . $rc->referrer_id,
                $referee?->customer_name ?? '#' . $rc->referee_id,
                $rc->trigger_type,
                $rc->trigger_amount,
                $rc->commission_amount,
                $rc->status,
                $rc->created_at,
            ];
        }

        if ($rows) {
            $this->table(
                ['RC#', '推荐人', '被推荐人', '类型', '触发金额', '佣金', '状态', '创建时间'],
                $rows
            );
        }

        // 2. 查找关联测试订阅的销售佣金
        $badSales = SalesCommission::whereIn('status', ['pending', 'credited'])
            ->whereIn('trigger_id', function ($sq) {
                $sq->select('id')->from('subscriptions')->where('is_test', 1);
            })
            ->get();

        $this->info("\n=== 销售佣金 (sales_commissions) ===");
        $this->info("找到 {$badSales->count()} 条问题记录");

        foreach ($badSales as $sc) {
            $this->line("  SC#{$sc->id} user=#{$sc->user_id} amount={$sc->commission_amount} status={$sc->status}");
        }

        if ($badReferrals->isEmpty() && $badSales->isEmpty()) {
            $this->info("\n无需修复");
            return 0;
        }

        if ($isDryRun) {
            $this->newLine();
            $this->warn('使用 --fix 执行修复');
            return 1;
        }

        // 3. 执行修复
        DB::transaction(function () use ($badReferrals, $badSales) {
            // 回收返佣
            foreach ($badReferrals as $rc) {
                if ($rc->status === 'credited') {
                    $referrer = Customer::lockForUpdate()->find($rc->referrer_id);
                    if ($referrer) {
                        $before = (float) $referrer->commission_balance;
                        $deduct = min($rc->commission_amount, $before);
                        if ($deduct > 0) {
                            $referrer->decrement('commission_balance', $deduct);
                            Transaction::create([
                                'customer_id' => $referrer->id,
                                'type' => Transaction::TYPE_COMMISSION_REVERSAL,
                                'amount' => -$deduct,
                                'balance_before' => $before,
                                'balance_after' => $before - $deduct,
                                'description' => "回收测试订阅错误返佣 (RC#{$rc->id}, 被推荐人#{$rc->referee_id})",
                                'operated_by' => null,
                            ]);
                            $this->info("  ✓ RC#{$rc->id}: {$referrer->customer_name} commission_balance {$before} → " . ($before - $deduct));
                        }
                    }
                }
                $rc->update(['status' => 'reversed']);
            }

            // 回收销售佣金
            foreach ($badSales as $sc) {
                if ($sc->status === 'credited') {
                    $user = \App\Models\User::lockForUpdate()->find($sc->user_id);
                    if ($user) {
                        $deduct = min($sc->commission_amount, (float) ($user->commission_balance ?? 0));
                        if ($deduct > 0) {
                            $user->decrement('commission_balance', $deduct);
                            $this->info("  ✓ SC#{$sc->id}: {$user->name} commission_balance 扣回 {$deduct}");
                        }
                    }
                }
                $sc->update(['status' => 'reversed']);
            }
        });

        $this->info("\n✓ 修复完成");
        return 0;
    }
}
