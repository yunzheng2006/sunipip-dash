<?php

namespace App\Console\Commands;

use App\Models\PricingMultiplier;
use Illuminate\Console\Command;

/**
 * 导入"按成本价"分档定价规则
 *
 * 用法：
 *   php artisan pricing:seed-cost-based              实际执行
 *   php artisan pricing:seed-cost-based --dry-run   只预览不写入
 *   php artisan pricing:seed-cost-based --reset     先清掉所有 cost_match 非空的旧规则再导入
 */
class SeedCostBasedPricing extends Command
{
    protected $signature = 'pricing:seed-cost-based {--dry-run} {--reset}';
    protected $description = '按成本价分档导入对客定价（如 USA cost=21 → 售 ¥55）';

    /**
     * 数据源：每行 [scope, country_code, cost_match, fixed_price, remark]
     * country_code=null 表示 scope=global
     */
    private array $rules = [
        // 全局默认：所有 cost=19 的产品 → 50
        ['global',  null,  19, 50, '全局：cost=19 → ¥50'],

        // 各国家的成本档
        ['country', 'USA', 21, 55, '美国 cost=21 → ¥55'],
        ['country', 'CAN', 25, 65, '加拿大 cost=25 → ¥65'],
        ['country', 'MEX', 36, 95, '墨西哥 cost=36 → ¥95'],
        ['country', 'VNM', 29, 75, '越南 cost=29 → ¥75'],
        ['country', 'TWN', 28, 75, '台湾 cost=28 → ¥75'],
        ['country', 'JPN', 29, 75, '日本(东京) cost=29 → ¥75'],
        ['country', 'IND', 28, 75, '印度 cost=28 → ¥75'],
        ['country', 'KOR', 30, 80, '韩国 cost=30 → ¥80'],
        ['country', 'MYS', 32, 85, '马来西亚 cost=32 → ¥85'],
        ['country', 'HKG', 25, 65, '香港 cost=25 → ¥65'],
        ['country', 'SGP', 33, 85, '新加坡 cost=33 → ¥85'],
        ['country', 'DEU', 23, 60, '德国 cost=23 → ¥60'],
        ['country', 'GBR', 25, 65, '英国 cost=25 → ¥65'],
        ['country', 'FRA', 25, 65, '法国 cost=25 → ¥65'],
        ['country', 'BRA', 30, 80, '巴西 cost=30 → ¥80'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reset  = (bool) $this->option('reset');

        if ($reset && !$dryRun) {
            $this->warn('--reset: 删除所有 cost_match 非空的现有规则');
            $deleted = PricingMultiplier::whereNotNull('cost_match')->delete();
            $this->info("  删除 {$deleted} 条旧规则");
        }

        $created = 0;
        $updated = 0;

        foreach ($this->rules as [$scope, $country, $costMatch, $salePrice, $remark]) {
            $query = PricingMultiplier::where('scope', $scope)
                ->where('cost_match', $costMatch);
            if ($country) {
                $query->where('country_code', $country);
            } else {
                $query->whereNull('country_code');
            }
            $existing = $query->first();

            $payload = [
                'scope'        => $scope,
                'country_code' => $country,
                'cost_match'   => $costMatch,
                'multiplier'   => 1.00,       // 用 fixed_price 时 multiplier 无实际作用
                'fixed_price'  => $salePrice,
                'is_active'    => 1,
                'remark'       => $remark,
            ];

            $label = $country ? "{$country} cost={$costMatch} → ¥{$salePrice}" : "GLOBAL cost={$costMatch} → ¥{$salePrice}";

            if ($existing) {
                if ($dryRun) {
                    $this->line("  [DRY] 更新 #{$existing->id}: {$label}");
                } else {
                    $existing->update($payload);
                    $this->line("  ✓ 更新 #{$existing->id}: {$label}");
                }
                $updated++;
            } else {
                if ($dryRun) {
                    $this->line("  [DRY] 新建: {$label}");
                } else {
                    $r = PricingMultiplier::create($payload);
                    $this->line("  ✓ 新建 #{$r->id}: {$label}");
                }
                $created++;
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->newLine();
        $this->info("{$prefix}完成：新建 {$created} 条，更新 {$updated} 条");

        if ($dryRun) {
            $this->warn('要实际写入请去掉 --dry-run');
        } else {
            $this->comment('提示：新规则立即生效，客户刷新 /store 即可看到新价格');
        }

        return 0;
    }
}
