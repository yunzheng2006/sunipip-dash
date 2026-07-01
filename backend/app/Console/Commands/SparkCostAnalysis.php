<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use Illuminate\Console\Command;

class SparkCostAnalysis extends Command
{
    protected $signature = 'spark:cost-analysis {--from=2026-05-25 00:11:00} {--to=}';
    protected $description = '详细分析 Spark 余额消耗明细';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to') ?: now()->toDateTimeString();

        $orders = SparkOrder::where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->get();

        $this->info("时段: {$from} → {$to}");
        $this->info("总订单: {$orders->count()}");

        // ======== CreateProxy ========
        $this->newLine();
        $this->info('========== CreateProxy (新开 → 消耗余额) ==========');
        $createTotal = 0;
        $createDetails = [];

        foreach ($orders->where('method', 'CreateProxy') as $o) {
            $rd = $o->request_data ?? [];
            $resp = $o->response_data ?? [];
            $ipInfoList = $resp['ipInfo'] ?? [];
            $amount = (int) ($o->amount ?? 1);

            // 找所有实例对应的subscription
            $subCosts = [];
            foreach ($ipInfoList as $ipInfo) {
                $iid = $ipInfo['instanceId'] ?? '';
                $si = SparkInstance::where('instance_id', $iid)->first();
                $sub = null;
                if ($si && $si->proxy_ip_id) {
                    $sub = Subscription::where('proxy_ip_id', $si->proxy_ip_id)->first();
                }
                $subCosts[] = [
                    'instance_id' => $iid,
                    'ip' => $ipInfo['ip'] ?? '',
                    'sales_cost' => $sub ? (float) $sub->sales_cost : null,
                    'sub_id' => $sub->id ?? null,
                ];
            }

            $lineCost = 0;
            foreach ($subCosts as $sc) {
                if ($sc['sales_cost'] !== null) {
                    $lineCost += $sc['sales_cost'];
                }
            }

            $createTotal += $lineCost;
            $customer = isset($rd['customer_id']) ? Customer::find($rd['customer_id']) : null;

            $this->line(sprintf(
                "  #%d %s | %s | amt=%d | IPs=%d | cost=¥%.2f | %s | %s",
                $o->id,
                $o->created_at->format('H:i'),
                $customer->customer_name ?? '?',
                $amount,
                count($ipInfoList),
                $lineCost,
                $rd['product_name'] ?? $o->product_id,
                $rd['country_cn'] ?? ''
            ));

            // 如果 amount > IP数，说明有些IP没出来
            if ($amount > count($ipInfoList)) {
                $this->warn("    ⚠ 请求 {$amount} 个但只返回 " . count($ipInfoList) . " 个IP");
            }

            // 如果 amount > 1，逐个显示
            if (count($subCosts) > 1) {
                foreach ($subCosts as $sc) {
                    $this->line(sprintf(
                        "    → %s (Sub#%s) cost=¥%s",
                        $sc['ip'],
                        $sc['sub_id'] ?? '?',
                        $sc['sales_cost'] ?? '?'
                    ));
                }
            }
        }
        $this->info("CreateProxy 合计: ¥{$createTotal}");

        // ======== RenewProxy ========
        $this->newLine();
        $this->info('========== RenewProxy (续费 → 消耗余额) ==========');
        $renewTotal = 0;

        foreach ($orders->where('method', 'RenewProxy') as $o) {
            $rd = $o->request_data ?? [];
            $iid = $rd['instanceId'] ?? '';

            $si = SparkInstance::where('instance_id', $iid)->first();
            $sub = null;
            $pip = null;
            if ($si && $si->proxy_ip_id) {
                $pip = ProxyIp::withTrashed()->find($si->proxy_ip_id);
                $sub = Subscription::where('proxy_ip_id', $si->proxy_ip_id)->first();
            }

            $cost = $sub ? (float) $sub->sales_cost : 0;
            $renewTotal += $cost;

            $this->line(sprintf(
                "  #%d %s | %s | %s | cost=¥%.2f | Sub#%s | trigger=%s",
                $o->id,
                $o->created_at->format('H:i'),
                $sub?->customer?->customer_name ?? '?',
                $pip->ip_address ?? '?',
                $cost,
                $sub->id ?? '?',
                $rd['trigger'] ?? '?'
            ));
        }
        $this->info("RenewProxy 合计: ¥{$renewTotal}");

        // ======== DelProxy ========
        $this->newLine();
        $this->info('========== DelProxy (删除 → 可能退回余额) ==========');
        $delDetails = [];

        foreach ($orders->where('method', 'DelProxy') as $o) {
            $rd = $o->request_data ?? [];
            $reason = $rd['reason'] ?? '未知';
            $iid = $rd['instance_id'] ?? '';

            $si = $iid ? SparkInstance::where('instance_id', $iid)->first() : null;
            $sub = null;
            $pip = null;
            if ($si && $si->proxy_ip_id) {
                $pip = ProxyIp::withTrashed()->find($si->proxy_ip_id);
                $sub = Subscription::where('proxy_ip_id', $si->proxy_ip_id)->first();
            }

            // 检查该实例的创建时间和过期时间来估算已使用比例
            $expireAt = $si ? $si->expire_at : null;
            $createdAt = $si ? $si->created_at : null;
            $deletedAt = $o->created_at;

            $salesCost = $sub ? (float) $sub->sales_cost : 0;
            $remainDays = 0;
            $totalDays = 0;
            $estimatedRefund = 0;

            if ($expireAt && $createdAt) {
                $totalDays = $createdAt->diffInDays($expireAt);
                $usedDays = $createdAt->diffInDays($deletedAt);
                $remainDays = max(0, $totalDays - $usedDays);

                if ($totalDays > 0 && $salesCost > 0) {
                    $estimatedRefund = round($salesCost * $remainDays / $totalDays, 2);
                }
            }

            $this->line(sprintf(
                "  #%d %s | %s | %s | reason=%s | cost=¥%.2f | total=%dd used=%dd remain=%dd | est_refund=¥%.2f",
                $o->id,
                $o->created_at->format('H:i'),
                $sub?->customer?->customer_name ?? ($reason === '测试IP过期自动释放' || $reason === '测试IP自动回收' ? '(测试)' : '?'),
                $pip->ip_address ?? '?',
                $reason,
                $salesCost,
                $totalDays,
                $totalDays - $remainDays,
                $remainDays,
                $estimatedRefund
            ));

            $delDetails[] = [
                'order_id' => $o->id,
                'reason' => $reason,
                'sales_cost' => $salesCost,
                'estimated_refund' => $estimatedRefund,
                'instance_id' => $iid,
            ];
        }

        $totalEstRefund = array_sum(array_column($delDetails, 'estimated_refund'));
        $this->info("DelProxy 估算退款合计: ¥{$totalEstRefund}");

        // ======== 汇总 ========
        $this->newLine();
        $this->info('==================== 汇总 ====================');
        $this->info("CreateProxy 成本:     ¥{$createTotal}");
        $this->info("RenewProxy 成本:      ¥{$renewTotal}");
        $this->info("DelProxy 估算退回:    -¥{$totalEstRefund}");
        $netCost = round($createTotal + $renewTotal - $totalEstRefund, 2);
        $this->info("估算净消耗:           ¥{$netCost}");
        $this->info("实际余额消耗:         ¥1149");
        $diff = round($netCost - 1149, 2);
        $this->info("差异:                 ¥{$diff}");

        if (abs($diff) > 1) {
            $this->newLine();
            $this->warn("差异原因分析:");
            $this->warn("1. sales_cost 是平台记录的成本，可能不完全等于 Spark 实际扣费");
            $this->warn("2. Spark 删除退款金额按剩余时间比例计算，但实际退款规则可能不同");
            $this->warn("3. 测试IP释放是否退款取决于Spark的策略（部分免费测试可能不退）");
        }

        return 0;
    }
}
