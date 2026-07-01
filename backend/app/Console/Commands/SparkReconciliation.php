<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use App\Services\SparkApiService;
use Illuminate\Console\Command;

class SparkReconciliation extends Command
{
    protected $signature = 'spark:reconcile {--from= : 起始时间 (Y-m-d H:i:s)} {--to= : 结束时间} {--balance-before= : 期初余额} {--balance-after= : 期末余额}';
    protected $description = 'Spark 余额对账：列出时段内所有订单，交叉比对平台数据，输出 CSV';

    public function handle(): int
    {
        $from = $this->option('from') ?: '2026-05-25 00:11:00';
        $to = $this->option('to') ?: now()->toDateTimeString();
        $balanceBefore = $this->option('balance-before') ? (float) $this->option('balance-before') : null;
        $balanceAfter = $this->option('balance-after') ? (float) $this->option('balance-after') : null;

        $this->info("对账时段: {$from} → {$to}");

        if ($balanceBefore !== null && $balanceAfter !== null) {
            $diff = round($balanceBefore - $balanceAfter, 2);
            $this->info("余额变化: {$balanceBefore} → {$balanceAfter} (消耗: {$diff})");
        }

        // 当前余额
        try {
            $spark = app(SparkApiService::class);
            $balance = $spark->getBalance();
            $this->info("当前 Spark 余额: " . json_encode($balance));
        } catch (\Throwable $e) {
            $this->warn("获取余额失败: " . $e->getMessage());
        }

        $orders = SparkOrder::where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->get();

        $this->info("时段内 Spark 订单: {$orders->count()} 条");

        $rows = [];
        $summary = ['CreateProxy' => 0, 'RenewProxy' => 0, 'DelProxy' => 0];
        $costSummary = ['CreateProxy' => 0.0, 'RenewProxy' => 0.0, 'DelProxy' => 0.0];

        foreach ($orders as $o) {
            $rd = $o->request_data ?? [];
            $resp = $o->response_data ?? [];

            $instanceId = $rd['instanceId'] ?? null;
            $ipInfo = $resp['ipInfo'][0] ?? null;
            if (!$instanceId && $ipInfo) {
                $instanceId = $ipInfo['instanceId'] ?? null;
            }
            if (!$instanceId && isset($rd['instance_id'])) {
                $instanceId = $rd['instance_id'];
            }

            // 关联链: SparkInstance → ProxyIp → Subscription → Customer
            $proxyIp = null;
            $sub = null;
            $customer = null;
            $sparkInst = null;

            if ($instanceId) {
                $sparkInst = SparkInstance::where('instance_id', $instanceId)->first();
                if ($sparkInst && $sparkInst->proxy_ip_id) {
                    $proxyIp = ProxyIp::withTrashed()->find($sparkInst->proxy_ip_id);
                    if ($proxyIp) {
                        $sub = Subscription::where('proxy_ip_id', $proxyIp->id)->first();
                        if ($sub) {
                            $customer = Customer::find($sub->customer_id);
                        }
                    }
                }
            }

            // 补充: 从 request_data 取 customer
            if (!$customer && isset($rd['customer_id'])) {
                $customer = Customer::find($rd['customer_id']);
            }

            // IP 地址
            $ip = $proxyIp->ip_address ?? ($ipInfo['ip'] ?? ($rd['ip'] ?? ''));

            // 单位映射
            $unitMap = [1 => '天', 2 => '周', 3 => '月'];
            $unitText = $unitMap[$o->unit] ?? $o->unit;

            // 过期时间
            $expireAt = '';
            if ($ipInfo && isset($ipInfo['expireAt']) && $ipInfo['expireAt'] > 0) {
                $expireAt = date('Y-m-d H:i', $ipInfo['expireAt']);
            }

            $row = [
                '序号' => $o->id,
                '时间' => $o->created_at->format('m-d H:i:s'),
                '类型' => $this->methodLabel($o->method),
                '方法' => $o->method,
                'IP地址' => $ip,
                '国家' => $rd['country_cn'] ?? ($rd['country_code'] ?? ''),
                '产品ID' => $o->product_id ?? '',
                '产品名称' => $rd['product_name'] ?? '',
                '数量' => $o->amount ?? 1,
                '时长' => $o->duration ?? '',
                '单位' => $unitText,
                'Spark订单号' => $o->spark_order_no ?? '',
                '平台订单号' => $o->req_order_no,
                '客户ID' => $customer->id ?? '',
                '客户名称' => $customer->customer_name ?? '',
                '平台售价' => $sub->price ?? ($rd['sale_price'] ?? ''),
                '平台成本' => $sub->sales_cost ?? '',
                '平台挂牌价' => $sub->list_price ?? '',
                '订阅ID' => $sub->id ?? '',
                '订阅状态' => $sub->status ?? '',
                '续费次数' => $sub->renewed_count ?? '',
                '过期时间' => $expireAt,
                '触发方式' => $rd['trigger'] ?? ($rd['source_remark'] ?? ($rd['reason'] ?? '')),
                'instance_id' => $instanceId ?? '',
            ];

            $rows[] = $row;
            $summary[$o->method] = ($summary[$o->method] ?? 0) + 1;

            // 估算成本 (amount * sales_cost, or from known pricing)
            if ($sub && $sub->sales_cost > 0) {
                $costSummary[$o->method] += (float) $sub->sales_cost * ($o->amount ?? 1);
            }
        }

        // 输出统计
        $this->newLine();
        $this->info('=== 订单统计 ===');
        $this->info("新开 (CreateProxy): {$summary['CreateProxy']} 笔");
        $this->info("续费 (RenewProxy):  {$summary['RenewProxy']} 笔");
        $this->info("删除 (DelProxy):    {$summary['DelProxy']} 笔");

        if (array_sum($costSummary) > 0) {
            $this->newLine();
            $this->info('=== 估算成本 (基于平台 sales_cost) ===');
            foreach ($costSummary as $method => $cost) {
                if ($cost > 0) $this->info("  {$method}: ¥" . round($cost, 2));
            }
            $this->info("  合计: ¥" . round(array_sum($costSummary), 2));
        }

        // 写 CSV
        $dir = storage_path('app/spark-reconcile-' . now()->format('Ymd-His'));
        @mkdir($dir, 0755, true);

        $csvPath = "{$dir}/spark_orders_reconcile.csv";
        $this->writeCsv($csvPath, $rows);
        $this->info("\nCSV 文件: {$csvPath}");

        // 按类型分别导出
        foreach (['CreateProxy', 'RenewProxy', 'DelProxy'] as $method) {
            $filtered = array_filter($rows, fn($r) => $r['方法'] === $method);
            if (!empty($filtered)) {
                $this->writeCsv("{$dir}/spark_{$method}.csv", array_values($filtered));
            }
        }

        // 导出汇总
        $summaryRows = [
            ['项目' => '期初余额', '金额' => $balanceBefore ?? '未提供'],
            ['项目' => '期末余额', '金额' => $balanceAfter ?? ($balance['amount'] ?? '未获取')],
            ['项目' => '余额消耗', '金额' => ($balanceBefore !== null && $balanceAfter !== null) ? round($balanceBefore - $balanceAfter, 2) : ''],
            ['项目' => '', '金额' => ''],
            ['项目' => '新开订单数', '金额' => $summary['CreateProxy']],
            ['项目' => '续费订单数', '金额' => $summary['RenewProxy']],
            ['项目' => '删除订单数', '金额' => $summary['DelProxy']],
            ['项目' => '合计订单数', '金额' => array_sum($summary)],
        ];
        $this->writeCsv("{$dir}/summary.csv", $summaryRows);

        $this->info("导出完成！目录: {$dir}");

        return 0;
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'CreateProxy' => '新开',
            'RenewProxy' => '续费',
            'DelProxy' => '删除/退款',
            default => $method,
        };
    }

    private function writeCsv(string $path, array $rows): void
    {
        if (empty($rows)) {
            file_put_contents($path, '');
            return;
        }

        $fp = fopen($path, 'w');
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $k) {
                    if (!in_array($k, $headers)) $headers[] = $k;
                }
            }
        }
        fputcsv($fp, $headers);

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $line = [];
            foreach ($headers as $h) {
                $val = $row[$h] ?? '';
                $line[] = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
            }
            fputcsv($fp, $line);
        }
        fclose($fp);
    }
}
