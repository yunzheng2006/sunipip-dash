<?php

namespace App\Console\Commands;

use App\Services\SparkStockCacheService;
use Illuminate\Console\Command;

class RefreshSparkStock extends Command
{
    protected $signature = 'spark:refresh-stock';
    protected $description = '刷新 Spark 产品库存缓存';

    public function handle(): int
    {
        $t = microtime(true);
        $result = SparkStockCacheService::refresh();
        $ms = round((microtime(true) - $t) * 1000);

        $this->info("✓ 已刷新 {$result['count']} 个产品，覆盖 {$result['by_country_count']} 个国家 ({$ms}ms)");
        return self::SUCCESS;
    }
}
