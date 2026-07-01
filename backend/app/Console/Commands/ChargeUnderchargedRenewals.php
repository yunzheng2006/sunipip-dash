<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ChargeUnderchargedRenewals extends Command
{
    protected $signature = 'billing:charge-undercharged
        {--json=storage/app/renewal-audit-20260524-undercharges.json : 少收明细 JSON 文件路径}
        {--dry-run : 试运行，不实际扣款}
        {--customer= : 只处理指定客户ID}
        {--force : 余额不足也强制扣款（余额会变负数）}';

    protected $description = '根据续费审计结果，对少收客户进行差额补扣';

    public function handle(): int
    {
        $jsonPath = $this->option('json');
        if (!str_starts_with($jsonPath, '/')) {
            $jsonPath = base_path($jsonPath);
        }

        if (!file_exists($jsonPath)) {
            $this->error("文件不存在: {$jsonPath}");
            $this->info("请先运行 php artisan audit:renewal-pricing 生成少收明细");
            return 1;
        }

        $undercharges = json_decode(file_get_contents($jsonPath), true);
        if (empty($undercharges)) {
            $this->info('无少收记录');
            return 0;
        }

        $dryRun = $this->option('dry-run');
        $filterCustomer = $this->option('customer');
        $force = $this->option('force');

        // 按客户汇总
        $byCustomer = [];
        foreach ($undercharges as $u) {
            $cid = $u['customer_id'];
            if ($filterCustomer && $cid != $filterCustomer) continue;
            $byCustomer[$cid][] = $u;
        }

        if (empty($byCustomer)) {
            $this->info('无匹配的少收记录');
            return 0;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "待处理客户: " . count($byCustomer));
        $this->newLine();

        $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'total_charged' => 0];
        $results = [];

        foreach ($byCustomer as $customerId => $items) {
            $customer = Customer::find($customerId);
            if (!$customer) {
                $this->warn("  客户 #{$customerId} 不存在，跳过");
                $stats['skipped'] += count($items);
                continue;
            }

            $totalUnderpaid = round(array_sum(array_column($items, 'underpaid')), 2);
            $currentBalance = (float) $customer->balance;

            $this->info("客户 #{$customerId} {$customer->customer_name}:");
            $this->info("  少收笔数: " . count($items));
            $this->info("  少收总额: ¥" . number_format($totalUnderpaid, 2));
            $this->info("  当前余额: ¥" . number_format($currentBalance, 2));

            if (!$force && $currentBalance < $totalUnderpaid) {
                $this->warn("  余额不足 (差 ¥" . number_format($totalUnderpaid - $currentBalance, 2) . ")，跳过（用 --force 强制扣款）");
                $stats['skipped'] += count($items);
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->customer_name,
                    'status' => 'SKIPPED_INSUFFICIENT_BALANCE',
                    'underpaid_total' => $totalUnderpaid,
                    'balance' => $currentBalance,
                    'items_count' => count($items),
                ];
                $this->newLine();
                continue;
            }

            if ($dryRun) {
                $this->info("  [DRY RUN] 将扣除 ¥" . number_format($totalUnderpaid, 2));
                foreach ($items as $item) {
                    $this->line("    交易#{$item['txn_id']} 订阅#{$item['subscription_id']} {$item['ip']} 少收¥{$item['underpaid']}");
                }
                $stats['success'] += count($items);
                $stats['total_charged'] += $totalUnderpaid;
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->customer_name,
                    'status' => 'DRY_RUN',
                    'underpaid_total' => $totalUnderpaid,
                    'balance' => $currentBalance,
                    'items_count' => count($items),
                ];
                $this->newLine();
                continue;
            }

            try {
                DB::transaction(function () use ($customer, $items, $totalUnderpaid) {
                    $customer->decrement('balance', $totalUnderpaid);

                    $txnIds = array_column($items, 'txn_id');
                    $description = sprintf(
                        '续费差价补扣（%d笔续费少收，涉及交易ID: %s）',
                        count($items),
                        implode(',', array_slice($txnIds, 0, 10)) . (count($txnIds) > 10 ? '...' : '')
                    );

                    Transaction::create([
                        'customer_id' => $customer->id,
                        'type' => Transaction::TYPE_ADJUSTMENT_OUT,
                        'amount' => -$totalUnderpaid,
                        'balance_after' => $customer->fresh()->balance,
                        'description' => $description,
                        'related_id' => null,
                        'operator_id' => null,
                    ]);
                });

                $newBalance = $customer->fresh()->balance;
                $this->info("  ✓ 已扣除 ¥" . number_format($totalUnderpaid, 2) . "，余额: ¥" . number_format($newBalance, 2));
                $stats['success'] += count($items);
                $stats['total_charged'] += $totalUnderpaid;
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->customer_name,
                    'status' => 'CHARGED',
                    'underpaid_total' => $totalUnderpaid,
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'items_count' => count($items),
                ];
            } catch (\Throwable $e) {
                $this->error("  ✗ 扣款失败: {$e->getMessage()}");
                $stats['failed'] += count($items);
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->customer_name,
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                    'underpaid_total' => $totalUnderpaid,
                    'items_count' => count($items),
                ];
            }
            $this->newLine();
        }

        $this->info('========== 汇总 ==========');
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}成功: {$stats['success']} 笔, 失败: {$stats['failed']} 笔, 跳过: {$stats['skipped']} 笔");
        $this->info("{$prefix}总计补扣: ¥" . number_format($stats['total_charged'], 2));

        $resultPath = str_replace('.json', '-charge-results.json', $jsonPath);
        file_put_contents($resultPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("结果已保存: {$resultPath}");

        return 0;
    }
}
