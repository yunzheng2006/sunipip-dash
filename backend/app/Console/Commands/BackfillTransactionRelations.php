<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTransactionRelations extends Command
{
    protected $signature = 'transactions:backfill-relations {--dry : 试运行}';

    protected $description = '回填交易记录的 related_type/related_id（关联到对应订阅）';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $transactions = Transaction::whereIn('type', [
                Transaction::TYPE_PURCHASE,
                Transaction::TYPE_RENEW,
                Transaction::TYPE_DEDUCTION,
            ])
            ->where('amount', '<', 0)
            ->where(function ($q) {
                $q->whereNull('related_type')->orWhere('related_type', '');
            })
            ->orderBy('id')
            ->get();

        $this->info(($dry ? '[DRY] ' : '') . "待处理: {$transactions->count()} 条");

        $matched = 0;
        $skipped = 0;

        foreach ($transactions as $txn) {
            // 找同客户、创建时间前后5分钟内、金额匹配的订阅
            $sub = Subscription::where('customer_id', $txn->customer_id)
                ->whereBetween('started_at', [
                    $txn->created_at->copy()->subMinutes(5),
                    $txn->created_at->copy()->addMinutes(5),
                ])
                ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, started_at, ?))', [$txn->created_at])
                ->first();

            if (!$sub) {
                // 宽松匹配：同客户同天
                $sub = Subscription::where('customer_id', $txn->customer_id)
                    ->whereDate('started_at', $txn->created_at->toDateString())
                    ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, started_at, ?))', [$txn->created_at])
                    ->first();
            }

            if (!$sub) {
                $skipped++;
                if ($dry) {
                    $this->line("  SKIP Txn#{$txn->id} cust={$txn->customer_id} amt={$txn->amount} at={$txn->created_at}");
                }
                continue;
            }

            if ($dry) {
                $this->line("  MATCH Txn#{$txn->id} → Sub#{$sub->id} ({$sub->status}) amt={$txn->amount} sub_price={$sub->price}");
            } else {
                $txn->update([
                    'related_type' => Subscription::class,
                    'related_id' => $sub->id,
                ]);
            }
            $matched++;
        }

        $this->info(($dry ? '[DRY] ' : '') . "完成: 匹配 {$matched}，跳过 {$skipped}");

        return self::SUCCESS;
    }
}
