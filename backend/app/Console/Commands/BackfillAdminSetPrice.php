<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Console\Command;

class BackfillAdminSetPrice extends Command
{
    protected $signature = 'subscriptions:backfill-admin-price {--dry-run}';
    protected $description = '回填 admin_set_price：为业务员创建的订阅设置初始成交价';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $subs = Subscription::where('created_by', '>', 1)
            ->whereNull('admin_set_price')
            ->where('status', 'active')
            ->with('proxyIp:id,ipipv_instance_id')
            ->get();

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "业务员创建的活跃订阅（无 admin_set_price）: {$subs->count()}");

        $set = $skipped = 0;

        foreach ($subs as $sub) {
            // IPIPV 走原价续费，不需要 admin_set_price
            if ($sub->proxyIp && $sub->proxyIp->ipipv_instance_id) {
                $skipped++;
                continue;
            }

            // 优先从首笔续费/开通交易获取初始价格
            $firstTxn = Transaction::where('related_id', $sub->id)
                ->where('related_type', Subscription::class)
                ->whereIn('type', [Transaction::TYPE_PURCHASE, Transaction::TYPE_RENEW])
                ->orderBy('id')
                ->first();

            if ($firstTxn && abs((float) $firstTxn->amount) > 0) {
                // 交易金额是负数（扣款），取绝对值
                // 如果是多月订阅，需要除以月数
                $totalPaid = abs((float) $firstTxn->amount);
                $durationInMonths = match ((int) $sub->unit) {
                    1 => max(1, $sub->duration / 30),
                    4 => $sub->duration * 12,
                    default => $sub->duration,
                };
                $monthlyPrice = $durationInMonths > 1
                    ? round($totalPaid / $durationInMonths, 2)
                    : $totalPaid;
            } else {
                // Fallback: price 存的是总价，转为月单价
                $months = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
                $monthlyPrice = round((float) $sub->price / max($months, 1), 2);
            }

            if ($dryRun) {
                $this->line("  订阅#{$sub->id} created_by={$sub->created_by}: admin_set_price=¥{$monthlyPrice}");
            } else {
                $sub->update(['admin_set_price' => $monthlyPrice]);
            }
            $set++;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}已设置: {$set}, 跳过(IPIPV): {$skipped}");

        return 0;
    }
}
