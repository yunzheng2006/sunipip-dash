<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixBalanceDeductedOnRenew extends Command
{
    protected $signature = 'fix:balance-deducted-on-renew {--dry-run : 仅预览，不修改}';
    protected $description = '修复：订阅创建时未扣余额(balance_deducted=false)，但续费时扣了余额的记录';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 预览模式】' : '【执行模式】');
        $this->newLine();

        $rows = DB::table('subscriptions')
            ->join('transactions', function ($j) {
                $j->on('transactions.related_id', '=', 'subscriptions.id')
                  ->where('transactions.related_type', 'App\\Models\\Subscription')
                  ->where('transactions.type', 'renew')
                  ->where('transactions.amount', '<', 0);
            })
            ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->where('subscriptions.balance_deducted', false)
            ->select(
                'subscriptions.id',
                'customers.customer_name',
                'subscriptions.price',
                'subscriptions.renewed_count',
                'subscriptions.status',
                DB::raw('SUM(transactions.amount) as total_renew_amount'),
                DB::raw('COUNT(transactions.id) as renew_count'),
                DB::raw('MIN(transactions.created_at) as first_renew'),
                DB::raw('MAX(transactions.created_at) as last_renew')
            )
            ->groupBy('subscriptions.id', 'customers.customer_name', 'subscriptions.price', 'subscriptions.renewed_count', 'subscriptions.status')
            ->orderBy('customers.customer_name')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('没有找到需要修复的记录。');
            return 0;
        }

        $this->table(
            ['订阅ID', '客户', '订阅价格', '续费次数', '续费总扣款', '首次续费', '最近续费', '状态'],
            $rows->map(fn($r) => [
                $r->id,
                $r->customer_name,
                '¥' . $r->price,
                $r->renew_count,
                '¥' . abs($r->total_renew_amount),
                substr($r->first_renew, 0, 10),
                substr($r->last_renew, 0, 10),
                $r->status,
            ])
        );

        $subIds = $rows->pluck('id')->all();
        $totalMissing = $rows->sum(fn($r) => abs($r->total_renew_amount));
        $customerCount = $rows->unique('customer_name')->count();

        $this->newLine();
        $this->info("共 {$rows->count()} 条订阅，涉及 {$customerCount} 个客户，遗漏续费业绩合计 ¥{$totalMissing}");

        // 按月统计
        $byMonth = DB::table('transactions')
            ->whereIn('related_id', $subIds)
            ->where('related_type', 'App\\Models\\Subscription')
            ->where('type', 'renew')
            ->where('amount', '<', 0)
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(ABS(amount)) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $this->newLine();
        $this->info('按月份统计遗漏业绩：');
        $this->table(['月份', '遗漏金额'], $byMonth->map(fn($m) => [$m->month, '¥' . $m->total]));

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN 完成，未修改任何数据。去掉 --dry-run 执行修复。');
            return 0;
        }

        if (!$this->confirm('确认将以上订阅的 balance_deducted 设为 true？')) {
            $this->info('已取消。');
            return 0;
        }

        $updated = \App\Models\Subscription::whereIn('id', $subIds)->update(['balance_deducted' => true]);
        $this->info("修复完成，已更新 {$updated} 条记录。");

        return 0;
    }
}
