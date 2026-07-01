<?php

namespace App\Console\Commands;

use App\Models\IpipvInstance;
use App\Models\PricingMultiplier;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use Illuminate\Console\Command;

class BackfillSubscriptionCosts extends Command
{
    protected $signature = 'subscriptions:backfill-costs {--dry-run}';
    protected $description = '回填历史订阅的 list_price 和 sales_cost';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $stats = ['spark_api' => 0, 'ipipv_api' => 0, 'country_match' => 0, 'skipped' => 0];

        // 构建 ISO-2 → ISO-3 映射表
        $iso2to3 = [];
        $mapRef = new \ReflectionProperty(\App\Services\CountryMapper::class, 'map');
        $mapRef->setAccessible(true);
        foreach ($mapRef->getValue() as $iso3 => $info) {
            $iso2 = $info[1] ?? '';
            if ($iso2) $iso2to3[strtoupper($iso2)] = $iso3;
        }

        $sparkProducts = \App\Services\SparkStockCacheService::products();
        $sparkByProductId = collect($sparkProducts)->keyBy('product_id');
        $sparkByCountry = collect($sparkProducts)->groupBy('country_code')
            ->map(fn ($items) => $items->min('cost_price'));

        $ipipvProducts = \App\Services\IpipvStockCacheService::products();
        $ipipvByProductNo = collect($ipipvProducts)->keyBy('productNo');
        $ipipvByCountry = collect($ipipvProducts)->groupBy('country_code')
            ->map(fn ($items) => $items->min('cost_price'));

        $subs = Subscription::with('proxyIp')
            ->where(fn ($q) => $q->whereNull('sales_cost')->orWhereNull('list_price'))
            ->get();

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "待处理: {$subs->count()} 条");

        foreach ($subs as $sub) {
            $ip = $sub->proxyIp;
            if (!$ip) {
                $stats['skipped']++;
                continue;
            }

            $costPrice = null;
            $source = $ip->source_name;
            $matchMethod = 'none';

            // 1. Spark API IP — 通过 SparkInstance → SparkOrder → product_id 精确匹配
            if ($ip->spark_instance_id) {
                $instance = SparkInstance::where('instance_id', $ip->spark_instance_id)->first();
                if ($instance && $instance->spark_order_id) {
                    $order = SparkOrder::find($instance->spark_order_id);
                    if ($order && $order->product_id) {
                        $product = $sparkByProductId->get($order->product_id);
                        if ($product) {
                            $costPrice = (float) $product['cost_price'];
                            $matchMethod = 'spark_api';
                        }
                    }
                }
            }

            // 2. IPIPV API IP
            if (!$costPrice && $ip->ipipv_instance_id) {
                $ipipvInst = IpipvInstance::where('instance_no', $ip->ipipv_instance_id)->first();
                if ($ipipvInst && $ipipvInst->product_no) {
                    $product = $ipipvByProductNo->get($ipipvInst->product_no);
                    if ($product) {
                        $costPrice = (float) $product['cost_price'];
                        $matchMethod = 'ipipv_api';
                    }
                }
            }

            // 3. 按 country_code 匹配产品缓存的最低成本价（支持 ISO-2 → ISO-3 转换）
            if (!$costPrice && $ip->country_code) {
                $cc = strtoupper($ip->country_code);
                // 如果是 ISO-2，转成 ISO-3
                if (strlen($cc) === 2 && isset($iso2to3[$cc])) {
                    $cc = $iso2to3[$cc];
                }
                if ($sparkByCountry->has($cc)) {
                    $costPrice = (float) $sparkByCountry->get($cc);
                    $matchMethod = 'country_match';
                    $source = $source ?: 'spark';
                } elseif ($ipipvByCountry->has($cc)) {
                    $costPrice = (float) $ipipvByCountry->get($cc);
                    $matchMethod = 'country_match';
                    $source = $source ?: 'ipipv';
                }
            }

            if (!$costPrice) {
                $stats['skipped']++;
                continue;
            }

            $rawCc = strtoupper($ip->country_code ?? '');
            $iso3Cc = (strlen($rawCc) === 2 && isset($iso2to3[$rawCc])) ? $iso2to3[$rawCc] : $rawCc;

            $productArr = [
                'cost_price' => $costPrice,
                'country_code' => $iso3Cc,
                'source' => strtolower($source ?? 'spark'),
            ];

            $listPrice = $sub->list_price ?? PricingMultiplier::calcSalePrice($productArr);
            $salesCost = $sub->sales_cost ?? PricingMultiplier::calcSalesPrice($productArr);

            if ($listPrice === null && $salesCost === null) {
                $stats['skipped']++;
                continue;
            }

            $updates = [];
            if ($sub->list_price === null && $listPrice !== null) {
                $updates['list_price'] = $listPrice;
            }
            if ($sub->sales_cost === null && $salesCost !== null) {
                $updates['sales_cost'] = $salesCost;
            }

            if (empty($updates)) {
                $stats['skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->line("  Sub #{$sub->id} ({$matchMethod}) cost={$costPrice} → list=" . ($updates['list_price'] ?? 'skip') . " sales=" . ($updates['sales_cost'] ?? 'skip'));
            } else {
                $sub->update($updates);
            }
            $stats[$matchMethod] = ($stats[$matchMethod] ?? 0) + 1;
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}完成: Spark API匹配 {$stats['spark_api']}，IPIPV API匹配 {$stats['ipipv_api']}，国家匹配 {$stats['country_match']}，跳过 {$stats['skipped']}");

        return 0;
    }
}
