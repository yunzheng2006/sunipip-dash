<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use Illuminate\Console\Command;

class SparkTestIpTrace extends Command
{
    protected $signature = 'spark:trace-test-ips';
    protected $description = '追踪今天删除的测试IP的创建订单和实际 Spark 扣费';

    public function handle(): int
    {
        $delOrders = SparkOrder::where('created_at', '>=', '2026-05-25 00:11:00')
            ->where('method', 'DelProxy')
            ->orderBy('created_at')
            ->get();

        $this->info("今天删除的订单: {$delOrders->count()} 笔");
        $this->newLine();

        $totalCreateCost = 0;
        $totalEstRefund = 0;

        foreach ($delOrders as $del) {
            $rd = $del->request_data ?? [];
            $reason = $rd['reason'] ?? '?';
            $iid = $rd['instance_id'] ?? '';
            $pipId = $rd['proxy_ip_id'] ?? null;

            $this->info("--- Del#{$del->id} {$del->created_at->format('H:i')} reason={$reason} ---");

            // 找 SparkInstance 和 ProxyIp — 支持 instance_id 或 proxy_ip_id 两种路径
            $si = null;
            $pip = null;

            if ($iid) {
                $si = SparkInstance::where('instance_id', $iid)->first();
            }

            if ($pipId) {
                $pip = ProxyIp::withTrashed()->find($pipId);
                if ($pip && !$si && $pip->spark_instance_id) {
                    $si = SparkInstance::where('instance_id', $pip->spark_instance_id)->first();
                    $iid = $pip->spark_instance_id;
                }
            }

            if ($si && !$pip && $si->proxy_ip_id) {
                $pip = ProxyIp::withTrashed()->find($si->proxy_ip_id);
            }

            $ip = $pip->ip_address ?? ($si->ip ?? '?');

            // 找创建订单
            $createOrder = null;
            if ($si && $si->spark_order_id) {
                $createOrder = SparkOrder::find($si->spark_order_id);
            }
            if (!$createOrder && $iid) {
                $createOrder = SparkOrder::where('method', 'CreateProxy')
                    ->where('response_data', 'LIKE', "%{$iid}%")
                    ->first();
            }

            // 找 subscription
            $sub = null;
            if ($pip) {
                $sub = Subscription::where('proxy_ip_id', $pip->id)->first();
            }

            $salesCost = $sub ? (float) $sub->sales_cost : 0;
            $isTest = $sub ? (bool) $sub->is_test : (str_contains($reason, '测试'));
            $productId = $createOrder->product_id ?? '?';

            // 计算时间：从创建到删除
            $createdAt = $createOrder?->created_at ?? $si?->created_at;
            $hoursUsed = $createdAt ? round($createdAt->diffInMinutes($del->created_at) / 60, 1) : '?';

            // 从创建订单找产品信息来估算 Spark 实际扣费
            $createRd = $createOrder?->request_data ?? [];
            $createResp = $createOrder?->response_data ?? [];

            $this->line("  IP: {$ip}");
            $this->line("  instance_id: {$iid}");
            $this->line("  创建订单: #" . ($createOrder->id ?? '?') . " 时间=" . ($createdAt?->format('m-d H:i') ?? '?'));
            $this->line("  产品ID: {$productId}");
            $this->line("  产品名: " . ($createRd['product_name'] ?? '?'));
            $this->line("  国家: " . ($createRd['country_cn'] ?? ($createRd['country_code'] ?? '?')));
            $this->line("  使用时长: {$hoursUsed}h");
            $this->line("  是否测试: " . ($isTest ? '是' : '否'));
            $this->line("  平台 sales_cost: ¥{$salesCost}");

            if ($salesCost > 0 && $createdAt) {
                // 估算: Spark购买1个月，使用N小时后删除，退回约 (30天-N小时)/30天 * cost
                $totalHours = 30 * 24; // 1个月
                $refundRatio = max(0, ($totalHours - $hoursUsed) / $totalHours);
                $estRefund = round($salesCost * $refundRatio, 2);
                $netCost = round($salesCost - $estRefund, 2);
                $this->line("  估算 Spark 扣费: ¥{$salesCost}");
                $this->line("  估算退回: ¥{$estRefund} (使用{$hoursUsed}h, 退回比例=" . round($refundRatio * 100, 1) . "%)");
                $this->line("  估算净成本: ¥{$netCost}");
                $totalCreateCost += $salesCost;
                $totalEstRefund += $estRefund;
            } elseif ($salesCost == 0 && $isTest) {
                // 测试IP但没有 sales_cost，尝试从产品ID推断成本
                $this->warn("  ⚠ 测试IP但无 sales_cost 记录");

                // 查同产品ID的其他有 sales_cost 的订阅
                $refSub = Subscription::whereHas('proxyIp', function ($q) use ($productId) {
                    $q->whereHas('sparkInstance', function ($q2) use ($productId) {
                        $q2->whereHas('sparkOrder', function ($q3) use ($productId) {
                            $q3->where('product_id', $productId);
                        });
                    });
                })->where('sales_cost', '>', 0)->first();

                if ($refSub) {
                    $refCost = (float) $refSub->sales_cost;
                    $this->line("  参考同产品成本: ¥{$refCost} (来自Sub#{$refSub->id})");

                    if ($createdAt) {
                        $totalHours = 30 * 24;
                        $refundRatio = max(0, ($totalHours - $hoursUsed) / $totalHours);
                        $estRefund2 = round($refCost * $refundRatio, 2);
                        $this->line("  估算退回: ¥{$estRefund2}");
                        $totalCreateCost += $refCost;
                        $totalEstRefund += $estRefund2;
                    }
                }
            }
        }

        $this->newLine();
        $this->info('==================== 汇总 ====================');
        $this->info("删除IP预估原始扣费合计: ¥{$totalCreateCost}");
        $this->info("删除IP预估退回合计:     ¥{$totalEstRefund}");
        $this->info("删除IP净成本:           ¥" . round($totalCreateCost - $totalEstRefund, 2));
        $this->newLine();

        // 计算完整对账
        $createCost = 743; // from previous analysis
        $renewCost = 675;
        $testNetCost = round($totalCreateCost - $totalEstRefund, 2);

        $this->info('========== 完整对账 ==========');
        $this->info("非测试新开成本:   ¥{$createCost}");
        $this->info("续费成本:         ¥{$renewCost}");
        $this->info("测试IP净成本:     ¥{$testNetCost} (买了又删，只亏使用时段)");
        $this->info("退款退回(志月):   -¥23 (估)");
        $net = round($createCost + $renewCost + $testNetCost - 23, 2);
        $this->info("估算净消耗:       ¥{$net}");
        $this->info("实际余额消耗:     ¥1149");
        $this->info("剩余差异:         ¥" . round($net - 1149, 2));

        return 0;
    }
}
