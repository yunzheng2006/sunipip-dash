<?php

namespace App\Console\Commands;

use App\Models\IpGroup;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Services\SparkStockCacheService;
use Illuminate\Console\Command;

class BackfillIpGroups extends Command
{
    protected $signature = 'ips:backfill-groups {--dry-run : Show what would be done without making changes}';

    protected $description = 'Backfill ip_group_id on historical Spark proxy IPs based on product ispType/netType';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be made.');
        }

        // Preload products from cache
        $products = SparkStockCacheService::products();
        $productMap = collect($products)->keyBy('product_id');

        if ($productMap->isEmpty()) {
            $this->error('No products found in cache. Run spark:refresh-stock first.');
            return self::FAILURE;
        }

        // Preload IP groups indexed by "ispType:netType"
        $ipGroups = IpGroup::whereNotNull('spark_isp_type')->get();
        $groupIndex = [];
        foreach ($ipGroups as $group) {
            $key = $group->spark_isp_type . ':' . ($group->spark_net_type ?? 'null');
            $groupIndex[$key] = $group;
        }

        if (empty($groupIndex)) {
            $this->error('No IP groups with spark_isp_type found. Run pricing:setup first.');
            return self::FAILURE;
        }

        // Find IPs needing backfill
        $query = ProxyIp::whereNotNull('spark_instance_id')
            ->whereNull('ip_group_id');

        $total = $query->count();
        $this->info("Found {$total} Spark IPs without ip_group_id.");

        if ($total === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;
        $noInstance = 0;
        $noOrder = 0;
        $noProduct = 0;
        $noGroup = 0;

        $query->chunkById(200, function ($ips) use (
            $dryRun, $productMap, $groupIndex,
            &$updated, &$skipped, &$noInstance, &$noOrder, &$noProduct, &$noGroup,
        ) {
            foreach ($ips as $ip) {
                $instance = SparkInstance::where('proxy_ip_id', $ip->id)->first();
                if (!$instance) {
                    $noInstance++;
                    $skipped++;
                    continue;
                }

                $sparkOrder = SparkOrder::find($instance->spark_order_id);
                if (!$sparkOrder || !$sparkOrder->product_id) {
                    $noOrder++;
                    $skipped++;
                    continue;
                }

                $product = $productMap->get($sparkOrder->product_id);
                if (!$product) {
                    $noProduct++;
                    $skipped++;
                    continue;
                }

                $ispType = $product['isp_type'] ?? null;
                $netType = $product['net_type'] ?? null;

                // Try exact match first, then fallback with null net_type
                $key = $ispType . ':' . ($netType ?? 'null');
                $ipGroup = $groupIndex[$key] ?? null;

                if (!$ipGroup && $netType !== null) {
                    // Fallback: try without net_type
                    $fallbackKey = $ispType . ':null';
                    $ipGroup = $groupIndex[$fallbackKey] ?? null;
                }

                if (!$ipGroup) {
                    $noGroup++;
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("WOULD SET ip #{$ip->id} ({$ip->ip_address}) -> group '{$ipGroup->name}' (id={$ipGroup->id})");
                } else {
                    $ip->update(['ip_group_id' => $ipGroup->id]);
                }

                $updated++;
            }
        });

        $this->newLine();
        $this->info('Summary:');
        $this->info("  Updated:      {$updated}");
        $this->info("  Skipped:      {$skipped}");

        if ($noInstance > 0) {
            $this->warn("    - No SparkInstance found: {$noInstance}");
        }
        if ($noOrder > 0) {
            $this->warn("    - No SparkOrder/product_id: {$noOrder}");
        }
        if ($noProduct > 0) {
            $this->warn("    - Product not in cache:     {$noProduct}");
        }
        if ($noGroup > 0) {
            $this->warn("    - No matching IP group:     {$noGroup}");
        }

        return self::SUCCESS;
    }
}
