<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCommissionTriggerIds extends Command
{
    protected $signature = 'fix:backfill-commission-trigger-ids {--dry-run : 仅显示匹配结果}';
    protected $description = '回填 referral_commissions 和 sales_commissions 中缺失的 trigger_id';

    private array $customerNames = [];
    private array $userNames = [];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');

        $this->preloadNames();

        $this->backfillReferral($dryRun);
        $this->newLine();
        $this->backfillSales($dryRun);

        return 0;
    }

    private function preloadNames(): void
    {
        $this->customerNames = DB::table('customers')
            ->pluck('customer_name', 'id')
            ->all();
        $this->userNames = DB::table('users')
            ->pluck('name', 'id')
            ->all();
    }

    private function customerName(int $id): string
    {
        return ($this->customerNames[$id] ?? '?') . " (#$id)";
    }

    private function userName(int $id): string
    {
        return ($this->userNames[$id] ?? '?') . " (#$id)";
    }

    private function backfillReferral(bool $dryRun): void
    {
        $this->info('── 推荐佣金 (referral_commissions) ──');
        $records = DB::table('referral_commissions')
            ->whereNull('trigger_id')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            $this->info('没有需要回填的记录。');
            return;
        }

        $matched = 0;
        $unmatched = 0;
        $rows = [];
        $unmatchedRows = [];

        foreach ($records as $rc) {
            $txn = DB::table('transactions')
                ->where('customer_id', $rc->referee_id)
                ->whereIn('type', ['purchase', 'deduction'])
                ->where('amount', '<', 0)
                ->whereRaw('ABS(amount) = ?', [$rc->trigger_amount])
                ->where('related_type', 'like', '%Subscription')
                ->whereNotNull('related_id')
                ->whereBetween('created_at', [
                    date('Y-m-d H:i:s', strtotime($rc->created_at) - 10),
                    date('Y-m-d H:i:s', strtotime($rc->created_at) + 10),
                ])
                ->first();

            if ($txn) {
                $rows[] = [
                    $rc->id,
                    $this->customerName($rc->referrer_id),
                    $this->customerName($rc->referee_id),
                    '¥' . $rc->trigger_amount,
                    '¥' . $rc->commission_amount,
                    $rc->status,
                    '#' . $txn->related_id,
                    substr($rc->created_at, 0, 16),
                ];
                if (!$dryRun) {
                    DB::table('referral_commissions')
                        ->where('id', $rc->id)
                        ->update(['trigger_id' => $txn->related_id]);
                }
                $matched++;
            } else {
                $unmatchedRows[] = [
                    $rc->id,
                    $this->customerName($rc->referrer_id),
                    $this->customerName($rc->referee_id),
                    '¥' . $rc->trigger_amount,
                    '¥' . $rc->commission_amount,
                    $rc->status,
                    substr($rc->created_at, 0, 16),
                ];
                $unmatched++;
            }
        }

        if (!empty($rows)) {
            $this->info("已匹配 ({$matched}):");
            $this->table(['佣金ID', '推荐人', '被推荐人', '消费金额', '佣金', '状态', '订阅ID', '时间'], $rows);
        }

        if (!empty($unmatchedRows)) {
            $this->warn("未匹配 ({$unmatched}):");
            $this->table(['佣金ID', '推荐人', '被推荐人', '消费金额', '佣金', '状态', '时间'], $unmatchedRows);
        }

        $this->newLine();
        $this->info("referral: 匹配 {$matched}，未匹配 {$unmatched}");
        if ($dryRun && $matched > 0) {
            $this->warn("去掉 --dry-run 执行实际回填。");
        }
    }

    private function backfillSales(bool $dryRun): void
    {
        $this->info('── 销售佣金 (sales_commissions) ──');
        $records = DB::table('sales_commissions')
            ->whereNull('trigger_id')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            $this->info('没有需要回填的记录。');
            return;
        }

        $matched = 0;
        $unmatched = 0;
        $rows = [];
        $unmatchedRows = [];

        foreach ($records as $sc) {
            $txn = DB::table('transactions')
                ->where('customer_id', $sc->customer_id)
                ->whereIn('type', ['purchase', 'deduction'])
                ->where('amount', '<', 0)
                ->whereRaw('ABS(amount) = ?', [$sc->trigger_amount])
                ->where('related_type', 'like', '%Subscription')
                ->whereNotNull('related_id')
                ->whereBetween('created_at', [
                    date('Y-m-d H:i:s', strtotime($sc->created_at) - 10),
                    date('Y-m-d H:i:s', strtotime($sc->created_at) + 10),
                ])
                ->first();

            $salesPerson = $this->userName($sc->user_id);

            if ($txn) {
                $rows[] = [
                    $sc->id,
                    $salesPerson,
                    $this->customerName($sc->customer_id),
                    '¥' . $sc->trigger_amount,
                    '¥' . $sc->commission_amount,
                    $sc->status,
                    '#' . $txn->related_id,
                    substr($sc->created_at, 0, 16),
                ];
                if (!$dryRun) {
                    DB::table('sales_commissions')
                        ->where('id', $sc->id)
                        ->update(['trigger_id' => $txn->related_id]);
                }
                $matched++;
            } else {
                $unmatchedRows[] = [
                    $sc->id,
                    $salesPerson,
                    $this->customerName($sc->customer_id),
                    '¥' . $sc->trigger_amount,
                    '¥' . $sc->commission_amount,
                    $sc->status,
                    substr($sc->created_at, 0, 16),
                ];
                $unmatched++;
            }
        }

        if (!empty($rows)) {
            $this->info("已匹配 ({$matched}):");
            $this->table(['佣金ID', '销售', '客户', '消费金额', '佣金', '状态', '订阅ID', '时间'], $rows);
        }

        if (!empty($unmatchedRows)) {
            $this->warn("未匹配 ({$unmatched}):");
            $this->table(['佣金ID', '销售', '客户', '消费金额', '佣金', '状态', '时间'], $unmatchedRows);
        }

        $this->newLine();
        $this->info("sales: 匹配 {$matched}，未匹配 {$unmatched}");
        if ($dryRun && $matched > 0) {
            $this->warn("去掉 --dry-run 执行实际回填。");
        }
    }
}
