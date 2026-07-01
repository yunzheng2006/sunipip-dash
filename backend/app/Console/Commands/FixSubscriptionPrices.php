<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class FixSubscriptionPrices extends Command
{
    protected $signature = 'subscriptions:fix-prices {--dry-run} {--customer=} {--exclude-customer=*}';
    protected $description = '修正所有活跃订阅的存储价格，使其与定价表动态计算结果一致';

    public function handle(): int
    {
        $service = app(SubscriptionService::class);
        $dryRun = $this->option('dry-run');
        $filterCustomer = $this->option('customer');
        $excludeCustomers = $this->option('exclude-customer');

        $query = Subscription::with([
            'customer:id,customer_name,vip_tier_id',
            'customer.vipTier:id,name,discount_percent',
            'proxyIp:id,ip_address,country_code,ipipv_instance_id',
            'forwardRule:id,subscription_id,forward_plan_id,forward_fee',
            'forwardRule.forwardPlan:id,base_price,name,module,pricing_mode',
        ])->where('status', 'active');

        if ($filterCustomer) {
            $query->where('customer_id', $filterCustomer);
        }

        if (!empty($excludeCustomers)) {
            $query->whereNotIn('customer_id', $excludeCustomers);
        }

        $subs = $query->orderBy('customer_id')->get();
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "活跃订阅: {$subs->count()}");

        $fixed = $skipped = $errors = 0;

        foreach ($subs as $sub) {
            $customer = $sub->customer;
            if (!$customer) continue;

            try {
                $breakdown = $service->calcRenewalPriceBreakdown($customer, $sub);
                $correctPrice = (float) $breakdown['monthly_price'];
            } catch (\Throwable $e) {
                $errors++;
                continue;
            }

            $months = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
            $correctTotalPrice = round($correctPrice * max($months, 1), 2);
            $storedPrice = (float) $sub->price;
            if (abs($storedPrice - $correctTotalPrice) < 0.01) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  订阅#{$sub->id} {$sub->proxyIp?->ip_address} ({$customer->customer_name}): ¥{$storedPrice} → ¥{$correctTotalPrice} (月价 ¥{$correctPrice})");
            } else {
                $sub->update(['price' => $correctTotalPrice]);
            }
            $fixed++;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}已修正: {$fixed}, 无需修正: {$skipped}, 错误: {$errors}");

        return 0;
    }
}
