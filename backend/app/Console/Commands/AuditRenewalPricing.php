<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class AuditRenewalPricing extends Command
{
    protected $signature = 'audit:renewal-pricing {--output=storage/app/renewal-audit.csv}';
    protected $description = '审计所有订阅的续费价格：对比实际收费 vs 正确价格，输出差异明细';

    public function handle(): int
    {
        $service = app(SubscriptionService::class);
        $outputPath = $this->option('output');
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = base_path($outputPath);
        }

        // ── 1. 所有活跃订阅的续费价格对比 ──
        $this->info('========== 活跃订阅续费价格审计 ==========');

        $subs = Subscription::with([
            'customer:id,customer_name,username,balance,vip_tier_id,sales_person',
            'customer.vipTier:id,name,discount_percent',
            'proxyIp:id,ip_address,country_code,city,source_name,spark_instance_id,ipipv_instance_id',
            'forwardRule:id,subscription_id,forward_plan_id,forward_fee',
            'forwardRule.forwardPlan:id,base_price,name',
        ])
            ->where('status', 'active')
            ->orderBy('customer_id')
            ->get();

        $this->info("活跃订阅数: {$subs->count()}");

        $rows = [];
        $issues = [];

        foreach ($subs as $sub) {
            $customer = $sub->customer;
            if (!$customer) continue;

            try {
                $breakdown = $service->calcRenewalPriceBreakdown($customer, $sub);
            } catch (\Throwable $e) {
                $rows[] = [
                    $sub->id, $customer->id, $customer->customer_name,
                    $sub->proxyIp?->ip_address ?? '-',
                    $sub->proxyIp?->country_code ?? '-',
                    $sub->proxyIp?->source_name ?? '-',
                    $sub->price, '-', '-', '-', '-', '-', '-', '-',
                    'ERROR: ' . $e->getMessage(),
                ];
                continue;
            }

            $months = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
            $storedMonthly = round((float) $sub->price / max($months, 1), 2);
            $correctPrice = (float) $breakdown['monthly_price'];
            $diff = round($storedMonthly - $correctPrice, 2);

            $row = [
                $sub->id,
                $customer->id,
                $customer->customer_name,
                $sub->proxyIp?->ip_address ?? '-',
                $sub->proxyIp?->country_code ?? '-',
                $sub->proxyIp?->source_name ?? '-',
                $storedMonthly,
                $correctPrice,
                $diff,
                $breakdown['ip_list_price'],
                $breakdown['ip_price'],
                $breakdown['forward_base_price'],
                $breakdown['forward_price'],
                $breakdown['discount_source'],
                $breakdown['discount_percent'] ? $breakdown['discount_percent'] . '%' : '-',
            ];
            $rows[] = $row;

            if (abs($diff) >= 0.01) {
                $issues[] = [
                    'subscription_id' => $sub->id,
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'ip' => $sub->proxyIp?->ip_address,
                    'country' => $sub->proxyIp?->country_code,
                    'stored_price' => $storedPrice,
                    'correct_price' => $correctPrice,
                    'diff' => $diff,
                    'discount_source' => $breakdown['discount_source'],
                ];
            }
        }

        $headers = ['订阅ID', '客户ID', '客户名', 'IP', '国家', '来源',
            '当前月价', '正确月价', '差额', 'IP底价', 'IP折后', '中转底价', '中转折后',
            '折扣来源', '折扣比例'];
        $this->table($headers, array_slice($rows, 0, 50));

        if (count($rows) > 50) {
            $this->info("  ... 共 " . count($rows) . " 条，表格只显示前50条");
        }

        // ── 2. 历史续费交易审计 ──
        $this->newLine();
        $this->info('========== 历史续费交易 vs 正确价格 ==========');

        $renewTxns = Transaction::where('type', Transaction::TYPE_RENEW)
            ->where('amount', '<', 0)
            ->with(['customer:id,customer_name,username,balance,vip_tier_id',
                'customer.vipTier:id,name,discount_percent'])
            ->orderBy('created_at', 'desc')
            ->get();

        $this->info("历史续费交易数: {$renewTxns->count()}");

        $txnRows = [];
        $undercharges = [];

        foreach ($renewTxns as $txn) {
            $sub = Subscription::with([
                'proxyIp:id,ip_address,country_code,city,source_name',
                'forwardRule:id,subscription_id,forward_plan_id,forward_fee',
                'forwardRule.forwardPlan:id,base_price,name',
            ])->find($txn->related_id);

            if (!$sub) continue;

            $customer = $txn->customer;
            if (!$customer) continue;

            $actualCharged = abs((float) $txn->amount);

            try {
                $breakdown = $service->calcRenewalPriceBreakdown($customer, $sub);
                $correctMonthly = (float) $breakdown['monthly_price'];
            } catch (\Throwable $e) {
                $correctMonthly = null;
            }

            // 推算续费月数：amount / monthly_price
            $durationGuess = $correctMonthly > 0 ? round($actualCharged / $correctMonthly, 1) : '?';
            // 尝试从 description 或 duration 推算
            $months = 1;
            if (preg_match('/(\d+)\s*(个月|月)/', $txn->description, $m)) {
                $months = (int) $m[1];
            } elseif (preg_match('/(\d+)\s*天/', $txn->description, $m)) {
                $months = max(1, round((int) $m[1] / 30));
            }

            $correctTotal = $correctMonthly !== null ? round($correctMonthly * $months, 2) : null;
            $diff = $correctTotal !== null ? round($actualCharged - $correctTotal, 2) : null;

            $txnRow = [
                $txn->id,
                $txn->created_at->format('Y-m-d H:i'),
                $customer->id,
                $customer->customer_name,
                $sub->id,
                $sub->proxyIp?->ip_address ?? '-',
                $sub->proxyIp?->country_code ?? '-',
                $months,
                $actualCharged,
                $correctMonthly ?? 'ERR',
                $correctTotal ?? 'ERR',
                $diff ?? 'ERR',
                $txn->description,
            ];
            $txnRows[] = $txnRow;

            if ($diff !== null && $diff < -0.5) {
                $undercharges[] = [
                    'txn_id' => $txn->id,
                    'txn_date' => $txn->created_at->format('Y-m-d H:i'),
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'customer_balance' => $customer->balance,
                    'subscription_id' => $sub->id,
                    'ip' => $sub->proxyIp?->ip_address,
                    'country' => $sub->proxyIp?->country_code,
                    'months' => $months,
                    'actual_charged' => $actualCharged,
                    'correct_total' => $correctTotal,
                    'underpaid' => abs($diff),
                    'correct_monthly' => $correctMonthly,
                ];
            }
        }

        $txnHeaders = ['交易ID', '时间', '客户ID', '客户名', '订阅ID', 'IP', '国家',
            '月数', '实际收费', '正确月价', '正确总价', '差额(少收)', '描述'];

        if (!empty($undercharges)) {
            $this->warn("  发现 " . count($undercharges) . " 笔少收的续费：");
            $underchargeRows = array_map(fn($u) => [
                $u['txn_id'], $u['txn_date'], $u['customer_id'], $u['customer_name'],
                $u['subscription_id'], $u['ip'], $u['country'], $u['months'],
                $u['actual_charged'], $u['correct_total'], $u['underpaid'],
                '¥' . number_format($u['customer_balance'], 2),
            ], $undercharges);
            $this->table(['交易ID', '时间', '客户ID', '客户名', '订阅ID', 'IP', '国家',
                '月数', '实付', '应付', '少收', '当前余额'], $underchargeRows);
        } else {
            $this->info('  无少收');
        }

        // ── 3. 输出 CSV ──
        $this->newLine();
        $this->info('========== 导出 CSV ==========');

        $fp = fopen($outputPath, 'w');
        // BOM for Excel UTF-8
        fwrite($fp, "\xEF\xBB\xBF");

        // Sheet 1: 活跃订阅价格对比
        fputcsv($fp, ['=== 活跃订阅续费价格对比 ===']);
        fputcsv($fp, $headers);
        foreach ($rows as $r) fputcsv($fp, $r);
        fputcsv($fp, []);

        // Sheet 2: 历史续费交易
        fputcsv($fp, ['=== 历史续费交易审计 ===']);
        fputcsv($fp, $txnHeaders);
        foreach ($txnRows as $r) fputcsv($fp, $r);
        fputcsv($fp, []);

        // Sheet 3: 少收明细
        fputcsv($fp, ['=== 少收客户明细 ===']);
        fputcsv($fp, ['交易ID', '时间', '客户ID', '客户名', '订阅ID', 'IP', '国家',
            '月数', '实付', '正确月价', '正确总价', '少收金额', '客户当前余额']);
        foreach ($undercharges as $u) {
            fputcsv($fp, [
                $u['txn_id'], $u['txn_date'], $u['customer_id'], $u['customer_name'],
                $u['subscription_id'], $u['ip'], $u['country'], $u['months'],
                $u['actual_charged'], $u['correct_monthly'], $u['correct_total'],
                $u['underpaid'], $u['customer_balance'],
            ]);
        }
        fputcsv($fp, []);

        // Sheet 4: 有差异的活跃订阅汇总
        fputcsv($fp, ['=== 当前价格有差异的活跃订阅（需修正） ===']);
        fputcsv($fp, ['订阅ID', '客户ID', '客户名', 'IP', '国家', '当前存储月价', '正确月价', '差额', '折扣来源']);
        foreach ($issues as $iss) {
            fputcsv($fp, [
                $iss['subscription_id'], $iss['customer_id'], $iss['customer_name'],
                $iss['ip'], $iss['country'], $iss['stored_price'], $iss['correct_price'],
                $iss['diff'], $iss['discount_source'],
            ]);
        }

        fclose($fp);
        $this->info("  CSV 已保存: {$outputPath}");

        // ── 4. 少收 JSON (供补扣脚本使用) ──
        if (!empty($undercharges)) {
            $jsonPath = str_replace('.csv', '-undercharges.json', $outputPath);
            file_put_contents($jsonPath, json_encode($undercharges, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("  少收明细 JSON: {$jsonPath}");
        }

        // ── 汇总 ──
        $this->newLine();
        $this->info('========== 汇总 ==========');
        $this->info("  活跃订阅: {$subs->count()}");
        $this->info("  当前价格有差异: " . count($issues));
        $this->info("  历史续费交易: {$renewTxns->count()}");
        $this->info("  历史少收笔数: " . count($undercharges));
        if (!empty($undercharges)) {
            $totalUnderpaid = array_sum(array_column($undercharges, 'underpaid'));
            $this->warn("  总计少收: ¥" . number_format($totalUnderpaid, 2));
        }

        return 0;
    }
}
