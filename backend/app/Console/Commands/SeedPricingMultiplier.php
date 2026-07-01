<?php

namespace App\Console\Commands;

use App\Models\PricingMultiplier;
use Illuminate\Console\Command;

class SeedPricingMultiplier extends Command
{
    protected $signature = 'pricing:seed-multiplier {--multiplier=2.0 : 全局默认倍率}';
    protected $description = '初始化全局销售倍率';

    public function handle(): int
    {
        $multiplier = (float) $this->option('multiplier');

        PricingMultiplier::firstOrCreate(
            ['scope' => 'global'],
            [
                'multiplier' => $multiplier,
                'is_active' => 1,
                'remark' => '全局默认倍率',
            ]
        );

        $this->info("全局倍率已设置为 {$multiplier}x");
        $this->info("客户售价 = Spark 成本 × {$multiplier}");
        $this->info("可在后台「销售倍率」页面调整各国家/地区的独立倍率");

        return 0;
    }
}
