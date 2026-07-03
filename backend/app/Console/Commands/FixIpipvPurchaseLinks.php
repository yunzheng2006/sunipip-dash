<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixIpipvPurchaseLinks extends Command
{
    protected $signature = 'fix:purchase-links {--dry-run}';
    protected $description = '修复 IPIPv/Spark 开通购买交易缺失 related_id（确定性链路匹配）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');

        $orphans = Transaction::where('type', Transaction::TYPE_PURCHASE)
            ->where('description', 'like', '开通订单扣费%')
            ->where('amount', '<', 0)
            ->where(function ($q) {
                $q->whereNull('related_id')
                  ->orWhere('related_id', 0);
            })
            ->orderBy('created_at')
            ->get();

        $this->line("无 related_id 的购买交易: {$orphans->count()} 笔");
        $this->newLine();

        $fixCount = 0;
        $failCount = 0;
        $impactRows = [];
        $noImpactRows = [];

        foreach ($orphans as $txn) {
            $amt = abs((float) $txn->amount);
            $custName = DB::table('customers')->where('id', $txn->customer_id)->value('customer_name') ?: "#{$txn->customer_id}";

            $this->line(str_repeat('─', 60));
            $this->line("Txn#{$txn->id} ¥{$amt} 客户:{$custName} {$txn->created_at}");

            $subId = $this->tryIpipvChain($txn);
            $source = 'ipipv';

            if (!$subId) {
                $subId = $this->trySparkChain($txn);
                $source = 'spark';
            }

            if (!$subId) {
                $this->warn("  ✗ 无法通过 IPIPv/Spark 链路匹配");
                $failCount++;
                continue;
            }

            $sub = DB::table('subscriptions')->where('id', $subId)->first();
            $proxyIp = DB::table('proxy_ips')->where('id', $sub->proxy_ip_id)->first();
            $ip = $proxyIp->ip_address ?? '?';
            $country = $proxyIp->country_code ?? '?';
            $isRefunded = $sub->status === 'refunded';
            $tag = $isRefunded ? '⚠ 业绩将被扣除' : '无业绩影响';
            $this->info("  ✓ [{$source}] → Sub#{$subId} IP={$country}-{$ip} status={$sub->status} price={$sub->price} [{$tag}]");

            $fixCount++;
            $row = "Txn#{$txn->id} ¥{$amt} {$custName} [{$source}] → Sub#{$subId} {$country}-{$ip} ({$sub->status})";
            if ($isRefunded) {
                $impactRows[] = $row;
            } else {
                $noImpactRows[] = $row;
            }

            if (!$dryRun) {
                $txn->update([
                    'related_type' => Subscription::class,
                    'related_id'   => $subId,
                ]);
                $this->info("  → 已关联");
            }
        }

        $this->newLine();
        $this->line(str_repeat('═', 60));

        if (!empty($impactRows)) {
            $totalImpact = 0;
            $this->error("⚠ 影响业绩（退订订阅，关联后业绩将被扣除）: " . count($impactRows) . " 笔");
            foreach ($impactRows as $r) {
                preg_match('/¥([\d.]+)/', $r, $m);
                $totalImpact += (float) ($m[1] ?? 0);
                $this->line("  {$r}");
            }
            $this->error("  合计虚增业绩: ¥{$totalImpact}");
            $this->newLine();
        }

        if (!empty($noImpactRows)) {
            $this->info("✓ 无业绩影响（仅数据完整性修复）: " . count($noImpactRows) . " 笔");
            foreach ($noImpactRows as $r) {
                $this->line("  {$r}");
            }
            $this->newLine();
        }

        $this->line("无法匹配: {$failCount} 笔");

        $label = $dryRun ? '可修复' : '已修复';
        $this->info("总计 {$label}: {$fixCount} 笔");
        if ($dryRun && $fixCount > 0) {
            $this->warn("去掉 --dry-run 执行修复。");
        }

        return 0;
    }

    private function tryIpipvChain(Transaction $txn): ?int
    {
        $ipipvOrder = DB::table('ipipv_orders')
            ->where('created_at', $txn->created_at)
            ->first();

        if (!$ipipvOrder) {
            $ipipvOrder = DB::table('ipipv_orders')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($txn->created_at) - 2))
                ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($txn->created_at) + 2))
                ->first();
        }

        if (!$ipipvOrder) return null;

        $this->line("  ipipv_order#{$ipipvOrder->id} created={$ipipvOrder->created_at}");

        $instances = DB::table('ipipv_instances')
            ->where('ipipv_order_id', $ipipvOrder->id)
            ->get();

        foreach ($instances as $inst) {
            $sub = DB::table('subscriptions')
                ->where('proxy_ip_id', $inst->proxy_ip_id)
                ->where('customer_id', $txn->customer_id)
                ->orderByDesc('id')
                ->first();
            if ($sub) return $sub->id;
        }

        return null;
    }

    private function trySparkChain(Transaction $txn): ?int
    {
        $sparkOrder = DB::table('spark_orders')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($txn->created_at) - 2))
            ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($txn->created_at) + 2))
            ->first();

        if (!$sparkOrder) return null;

        $this->line("  spark_order#{$sparkOrder->id} created={$sparkOrder->created_at}");

        $instances = DB::table('spark_instances')
            ->where('spark_order_id', $sparkOrder->id)
            ->get();

        foreach ($instances as $inst) {
            if (!$inst->proxy_ip_id) continue;
            $sub = DB::table('subscriptions')
                ->where('proxy_ip_id', $inst->proxy_ip_id)
                ->where('customer_id', $txn->customer_id)
                ->orderByDesc('id')
                ->first();
            if ($sub) return $sub->id;
        }

        return null;
    }
}
