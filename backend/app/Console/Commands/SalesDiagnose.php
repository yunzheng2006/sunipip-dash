<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SalesDiagnose extends Command
{
    protected $signature = 'sales:diagnose
        {customer : 客户名或ID}
        {--from=2026-05-01 : 开始日期}
        {--to=2026-05-31 : 结束日期}';

    protected $description = '诊断销售统计数据，逐项对账';

    public function handle(): int
    {
        $input = $this->argument('customer');
        $customer = is_numeric($input)
            ? Customer::find($input)
            : Customer::where('customer_name', $input)->first();

        if (!$customer) {
            $this->error("客户不存在: {$input}");
            return self::FAILURE;
        }

        $from = \Carbon\Carbon::parse($this->option('from'))->startOfDay();
        $to   = \Carbon\Carbon::parse($this->option('to'))->endOfDay();

        $this->info("=== 客户: {$customer->customer_name} (ID:{$customer->id}) ===");
        $this->info("时段: {$from->toDateString()} ~ {$to->toDateString()}");
        $this->line('');

        // ── 1. 全部交易 ──
        $this->info("── 1. 时段内交易 ──");
        $txns = Transaction::where('customer_id', $customer->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->get();

        $rows = $txns->map(fn($t) => [
            $t->id, $t->type, $t->amount,
            $t->related_type ? class_basename($t->related_type) . '#' . $t->related_id : '-',
            mb_substr($t->description ?? '', 0, 30),
            $t->created_at->format('m-d H:i'),
        ])->toArray();
        $this->table(['ID', 'Type', 'Amount', 'Related', 'Desc', 'Time'], $rows);

        // ── 2. 成交价 ──
        $this->info("\n── 2. 成交价 ──");
        $revenueTxns = $txns->filter(fn($t) => in_array($t->type, Transaction::REVENUE_TYPES) && $t->amount < 0);
        $revenueTotal = abs($revenueTxns->sum('amount'));

        foreach ($revenueTxns as $t) {
            $this->line("  Txn#{$t->id} {$t->type} {$t->amount}");
        }
        $this->info("  小计 = {$revenueTotal}");

        // 退款
        $refundTxns = Transaction::where('customer_id', $customer->id)
            ->whereIn('type', [Transaction::TYPE_REFUND, Transaction::TYPE_GATEWAY_REFUND])
            ->where('amount', '>', 0)
            ->get();
        $refundTotal = 0;
        foreach ($refundTxns as $rt) {
            $sub = $rt->related_type === 'App\\Models\\Subscription' ? Subscription::find($rt->related_id) : null;
            $attrDate = $sub?->started_at ?? $rt->created_at;
            if ($attrDate < $from || $attrDate > $to) continue;
            $keepPerf = $sub?->keep_performance ?? false;
            if ($keepPerf) {
                $this->line("  退款 Txn#{$rt->id} +{$rt->amount} → keep_performance=true，不扣");
                continue;
            }
            $refundTotal += $rt->amount;
            $this->line("  退款 Txn#{$rt->id} +{$rt->amount} → 扣减");
        }

        $finalRevenue = $revenueTotal - $refundTotal;
        $this->info("  成交价 = {$revenueTotal} - {$refundTotal} = {$finalRevenue}");

        // ── 3. 销售成本 ──
        $this->info("\n── 3. 销售成本 ──");

        // 新开
        $this->line("  [新开] started_at 在时段内:");
        $newSubs = Subscription::where('customer_id', $customer->id)
            ->where('is_test', false)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<=', $to)
            ->where(fn($q) => $q->where('status', '!=', 'refunded')->orWhere('keep_performance', true))
            ->get();

        $newSubCost = 0;
        foreach ($newSubs as $s) {
            $months = $this->durationToMonths($s->duration, $s->unit);
            $cost = ($s->sales_cost ?? 0) * $months;
            $newSubCost += $cost;
            $this->line("    Sub#{$s->id} sales_cost={$s->sales_cost} × {$months}月 = {$cost}  (dur={$s->duration} unit={$s->unit} status={$s->status})");
        }
        $this->info("  新开成本 = {$newSubCost}");

        // 续费
        $this->line("  [续费] renew交易在时段内, sub.started_at < 时段起:");
        $renewTxns = Transaction::where('customer_id', $customer->id)
            ->where('type', Transaction::TYPE_RENEW)
            ->where('amount', '<', 0)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get();

        $renewCost = 0;
        foreach ($renewTxns as $t) {
            if ($t->related_type !== 'App\\Models\\Subscription') continue;
            $sub = Subscription::find($t->related_id);
            if (!$sub || $sub->is_test) continue;
            if ($sub->status === 'refunded' && !$sub->keep_performance) continue;
            if ($sub->started_at >= $from) {
                $this->line("    Txn#{$t->id} Sub#{$sub->id} started_at在时段内，跳过(新开已算)");
                continue;
            }
            $months = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
            $monthlyPrice = $sub->price > 0 ? round($sub->price / max($months, 1), 2) : 0;
            $ratio = $monthlyPrice > 0 ? max(round(abs($t->amount) / $monthlyPrice), 1) : 1;
            $cost = ($sub->sales_cost ?? 0) * $ratio;
            $renewCost += $cost;
            $this->line("    Txn#{$t->id} amt={$t->amount} Sub#{$sub->id} sales_cost={$sub->sales_cost} × ratio={$ratio} = {$cost}");
        }
        $this->info("  续费成本 = {$renewCost}");

        // 中转
        $this->line("  [中转]:");
        $fwdCost = 0;
        $fwdNewRows = DB::table('forward_rules')
            ->join('subscriptions', 'forward_rules.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('forward_plans', 'forward_rules.forward_plan_id', '=', 'forward_plans.id')
            ->where('subscriptions.customer_id', $customer->id)
            ->where(fn($q) => $q->where('subscriptions.status', '!=', 'refunded')->orWhere('subscriptions.keep_performance', true))
            ->where('subscriptions.is_test', false)
            ->where('subscriptions.started_at', '>=', $from)
            ->where('subscriptions.started_at', '<=', $to)
            ->select('subscriptions.id as sub_id', 'forward_rules.forward_plan_id', 'forward_plans.cost_price', 'subscriptions.duration', 'subscriptions.unit')
            ->get();
        foreach ($fwdNewRows as $f) {
            $months = $this->durationToMonths($f->duration, $f->unit);
            $c = ($f->cost_price ?? 0) * $months;
            $fwdCost += $c;
            $planInfo = $f->forward_plan_id ? "plan#{$f->forward_plan_id}" : "NO PLAN";
            $this->line("    新开 Sub#{$f->sub_id} [{$planInfo}] fwd={$f->cost_price} × {$months}月 = {$c}");
        }
        $this->info("  中转成本 = " . round($fwdCost, 2));

        $totalCost = $newSubCost + $renewCost + $fwdCost;
        $this->info("  总成本 = {$newSubCost} + {$renewCost} + " . round($fwdCost, 2) . " = " . round($totalCost, 2));

        // ── 汇总 ──
        $this->info("\n── 汇总 ──");
        $this->table(['指标', '值'], [
            ['成交价', round($finalRevenue, 2)],
            ['销售成本', round($totalCost, 2)],
            ['利润', round($finalRevenue - $totalCost, 2)],
        ]);

        return self::SUCCESS;
    }

    private function durationToMonths(int $duration, int $unit): int
    {
        $months = match ($unit) {
            1 => (int) ceil($duration / 30),
            2 => (int) ceil($duration * 7 / 30),
            3 => $duration,
            4 => $duration * 12,
            default => 1,
        };
        return max($months, 1);
    }
}
