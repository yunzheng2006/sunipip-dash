<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixBalanceDeducted extends Command
{
    protected $signature = 'fix:balance-deducted {--dry-run : 只检查不修改}';

    protected $description = '修复 balance_deducted 与实际交易不一致的订阅（双向）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $revenueTypes = Transaction::REVENUE_TYPES;

        // 判据1：直接关联的"自助购买"交易（CheckoutService 单购或批量首条）
        $hasCheckoutTxn = function ($q) use ($revenueTypes) {
            $q->select(DB::raw(1))
              ->from('transactions')
              ->whereColumn('transactions.related_id', 'subscriptions.id')
              ->where('transactions.related_type', 'App\\Models\\Subscription')
              ->whereIn('transactions.type', $revenueTypes)
              ->where('transactions.amount', '<', 0)
              ->where('transactions.description', 'like', '自助购买:%');
        };

        // 判据2：同批次"兄弟"订阅有自助购买交易（CheckoutService 批量购买的第2+条）
        // 同客户 + 开始时间差 ≤ 5分钟 + 兄弟有直接关联的自助购买交易
        $hasSiblingCheckoutTxn = function ($q) use ($revenueTypes) {
            $q->select(DB::raw(1))
              ->from('subscriptions as sibling')
              ->whereColumn('sibling.customer_id', 'subscriptions.customer_id')
              ->whereColumn('sibling.id', '!=', 'subscriptions.id')
              ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, sibling.started_at, subscriptions.started_at)) <= 300')
              ->whereExists(function ($sq) use ($revenueTypes) {
                  $sq->select(DB::raw(1))
                    ->from('transactions')
                    ->whereColumn('transactions.related_id', 'sibling.id')
                    ->where('transactions.related_type', 'App\\Models\\Subscription')
                    ->whereIn('transactions.type', $revenueTypes)
                    ->where('transactions.amount', '<', 0)
                    ->where('transactions.description', 'like', '自助购买:%');
              });
        };

        // ── 方向1：有自助购买交易（直接或兄弟）但未标记 → true ──
        $missing = Subscription::where(function ($q) {
                $q->where('balance_deducted', false)->orWhereNull('balance_deducted');
            })
            ->where('is_test', false)
            ->where(function ($q) use ($hasCheckoutTxn, $hasSiblingCheckoutTxn) {
                $q->whereExists($hasCheckoutTxn)
                  ->orWhereExists($hasSiblingCheckoutTxn);
            })
            ->with(['proxyIp:id,ip_address', 'customer:id,customer_name'])
            ->get();

        if ($missing->isNotEmpty()) {
            $this->info("── 方向1: balance_deducted → true（自助购买交易）──");
            $this->renderTable($missing);
            if (!$dryRun) {
                Subscription::whereIn('id', $missing->pluck('id'))
                    ->update(['balance_deducted' => true]);
            }
            $this->info("{$prefix}修复 {$missing->count()} 条 → balance_deducted = true");
        } else {
            $this->info('方向1：没有遗漏的订阅');
        }

        // ── 方向2：无自助购买交易（直接+兄弟都没有）但已标记 → false ──
        $phantom = Subscription::where('balance_deducted', true)
            ->where('is_test', false)
            ->whereNotExists($hasCheckoutTxn)
            ->whereNotExists($hasSiblingCheckoutTxn)
            ->with(['proxyIp:id,ip_address', 'customer:id,customer_name'])
            ->get();

        if ($phantom->isNotEmpty()) {
            $this->info("── 方向2: balance_deducted → false（无自助购买交易）──");
            $this->renderTable($phantom);
            if (!$dryRun) {
                Subscription::whereIn('id', $phantom->pluck('id'))
                    ->update(['balance_deducted' => false]);
            }
            $this->info("{$prefix}修复 {$phantom->count()} 条 → balance_deducted = false");
        } else {
            $this->info('方向2：没有虚假标记的订阅');
        }

        if ($missing->isEmpty() && $phantom->isEmpty()) {
            $this->info('全部一致，无需修复');
        }

        return 0;
    }

    private function renderTable($subs): void
    {
        $this->table(
            ['订阅ID', '客户', 'IP', '状态', '售价', '成本', '开始日'],
            $subs->map(fn($s) => [
                $s->id,
                $s->customer?->customer_name ?? '-',
                $s->proxyIp?->ip_address ?? '-',
                $s->status,
                number_format((float) $s->price, 2),
                number_format((float) $s->sales_cost, 2),
                $s->started_at?->format('Y-m-d') ?? '-',
            ])->toArray()
        );
    }
}
