<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use App\Support\DurationHelper;
use Illuminate\Console\Command;

class FixDowngradePrices extends Command
{
    protected $signature = 'fix:downgrade-prices {--dry-run : 仅显示受影响记录}';
    protected $description = '修复旧版降级代码错误修改的订阅价格（还原为降级前的原价）。仅在代码修复部署前运行一次。';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');
        $this->newLine();

        $refundTxns = Transaction::where('type', Transaction::TYPE_REFUND)
            ->where('description', 'like', '降级退差价%')
            ->where('amount', '>', 0)
            ->whereNotNull('related_id')
            ->where('related_type', 'App\\Models\\Subscription')
            ->orderBy('created_at')
            ->get();

        if ($refundTxns->isEmpty()) {
            $this->info('没有找到降级退款记录。');
            return 0;
        }

        $rows = [];
        $fixed = 0;
        $skipped = 0;
        $seen = [];

        foreach ($refundTxns as $txn) {
            if (isset($seen[$txn->related_id])) {
                $skipped++;
                continue;
            }
            $seen[$txn->related_id] = true;

            $sub = Subscription::find($txn->related_id);
            if (!$sub) continue;

            // 从描述解析中转费/月："降级退差价 #123: 中转费 ¥42/月, 剩余15天"
            $forwardFeePerMonth = null;
            if (preg_match('/中转费 ¥([\d.]+)\/月/', $txn->description, $m)) {
                $forwardFeePerMonth = (float) $m[1];
            }

            if (!$forwardFeePerMonth || $forwardFeePerMonth <= 0) {
                $this->warn("  订阅#{$sub->id}: 无法从描述解析中转费，跳过。描述: {$txn->description}");
                $skipped++;
                continue;
            }

            $durationMonths = max(DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3), 1);
            $priceReduction = round($forwardFeePerMonth * $durationMonths, 2);
            $originalPrice = round($sub->price + $priceReduction, 2);

            if (abs($sub->price - $originalPrice) < 0.01) continue;

            $customer = $sub->customer;
            $customerName = $customer ? $customer->customer_name : "#{$sub->customer_id}";

            $rows[] = [
                $sub->id,
                $customerName,
                "¥{$sub->price}",
                "¥{$forwardFeePerMonth}/月 × {$durationMonths}月 = ¥{$priceReduction}",
                "¥{$originalPrice}",
                $sub->status,
                substr($txn->created_at, 0, 10),
            ];

            if (!$dryRun) {
                $sub->update(['price' => $originalPrice]);
                $fixed++;
            }
        }

        if (empty($rows)) {
            $this->info('所有降级订阅的价格已正确，无需修复。');
            if ($skipped > 0) $this->info("跳过 {$skipped} 条（重复或无法解析）");
            return 0;
        }

        $this->table(
            ['订阅ID', '客户', '当前价格', '被减金额', '还原价格', '状态', '降级日期'],
            $rows
        );

        $this->newLine();
        if ($skipped > 0) $this->info("跳过 {$skipped} 条（重复或无法解析）");

        if ($dryRun) {
            $this->warn("共 " . count($rows) . " 条需修复。去掉 --dry-run 执行实际修复。");
        } else {
            $this->info("修复完成，共修复 {$fixed} 条记录。");
        }

        return 0;
    }
}
