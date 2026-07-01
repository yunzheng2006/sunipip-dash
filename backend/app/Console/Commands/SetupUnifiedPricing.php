<?php

namespace App\Console\Commands;

use App\Models\IpGroup;
use App\Models\ProductPricing;
use App\Models\SparkCountry;
use App\Models\SparkPricingRule;
use Illuminate\Console\Command;

class SetupUnifiedPricing extends Command
{
    protected $signature = 'pricing:setup {--dry-run : Show what would be done without making changes}';

    protected $description = 'Seed preset IP groups and migrate spark_pricing_rules to product_pricing';

    private const PRESET_IP_GROUPS = [
        [
            'name' => '单ISP-原生',
            'spark_isp_type' => 1,
            'spark_net_type' => 1,
            'display_name' => '单ISP 原生',
            'slug' => 'single-isp-native',
            'status' => 1,
            'sort_order' => 10,
        ],
        [
            'name' => '单ISP-广播',
            'spark_isp_type' => 1,
            'spark_net_type' => 2,
            'display_name' => '单ISP 广播',
            'slug' => 'single-isp-broadcast',
            'status' => 1,
            'sort_order' => 20,
        ],
        [
            'name' => '双ISP-原生',
            'spark_isp_type' => 2,
            'spark_net_type' => 1,
            'display_name' => '双ISP 原生',
            'slug' => 'dual-isp-native',
            'status' => 1,
            'sort_order' => 30,
        ],
        [
            'name' => '双ISP-广播',
            'spark_isp_type' => 2,
            'spark_net_type' => 2,
            'display_name' => '双ISP 广播',
            'slug' => 'dual-isp-broadcast',
            'status' => 1,
            'sort_order' => 40,
        ],
        [
            'name' => '原生ISP',
            'spark_isp_type' => 3,
            'spark_net_type' => 1,
            'display_name' => '原生ISP',
            'slug' => 'native-isp',
            'status' => 1,
            'sort_order' => 50,
        ],
        [
            'name' => '机房',
            'spark_isp_type' => 4,
            'spark_net_type' => null,
            'display_name' => '机房',
            'slug' => 'datacenter',
            'status' => 1,
            'sort_order' => 60,
        ],
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be made.');
        }

        // Step 1: Create/update preset IP groups
        $groupsCreated = 0;
        $groupsUpdated = 0;

        foreach (self::PRESET_IP_GROUPS as $preset) {
            $name = $preset['name'];

            if ($dryRun) {
                $existing = IpGroup::where('name', $name)->first();
                $this->line(($existing ? 'UPDATE' : 'CREATE') . " IP group: {$name}");
                $existing ? $groupsUpdated++ : $groupsCreated++;
                continue;
            }

            $existing = IpGroup::where('name', $name)->first();
            IpGroup::updateOrCreate(
                ['name' => $name],
                $preset,
            );
            $existing ? $groupsUpdated++ : $groupsCreated++;
        }

        $this->info("IP Groups: {$groupsCreated} created, {$groupsUpdated} updated.");

        // Step 2: Migrate spark_pricing_rules to product_pricing
        $rules = SparkPricingRule::all();
        $migrated = 0;
        $skipped = 0;

        foreach ($rules as $rule) {
            $countryCodes = $rule->country_codes ?? [];

            foreach ($countryCodes as $code) {
                $code = strtoupper($code);
                $countryName = SparkCountry::getNameByCode($code) ?? '';

                $attributes = [
                    'country_code' => $code,
                    'ip_group_id' => null,
                    'access_type' => 'dedicated',
                ];

                $values = [
                    'country_name' => $countryName,
                    'monthly_price' => $rule->monthly_price,
                    'cost_price' => $rule->cost_price,
                    'is_active' => $rule->is_active,
                ];

                if ($dryRun) {
                    $exists = ProductPricing::where($attributes)->exists();
                    if ($exists) {
                        $this->line("SKIP (exists): {$code} dedicated default");
                        $skipped++;
                    } else {
                        $this->line("MIGRATE: {$code} dedicated default -> {$rule->monthly_price} CNY");
                        $migrated++;
                    }
                    continue;
                }

                $pricing = ProductPricing::firstOrCreate($attributes, $values);

                if ($pricing->wasRecentlyCreated) {
                    $migrated++;
                } else {
                    $skipped++;
                }
            }
        }

        $this->info("Pricing rules: {$migrated} migrated, {$skipped} skipped (already exist).");

        return self::SUCCESS;
    }
}
