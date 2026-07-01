<?php

namespace App\Console\Commands;

use App\Models\SparkCountry;
use App\Services\SparkStockCacheService;
use Illuminate\Console\Command;

/**
 * 诊断 Spark 产品的 countryCode 与本地 area_country 表的映射
 * 找出不匹配的国家代码
 */
class DebugSparkCountries extends Command
{
    protected $signature = 'spark:debug-countries';
    protected $description = '诊断 Spark 产品 countryCode 映射问题';

    public function handle(): int
    {
        $products = SparkStockCacheService::products(true);
        $this->info('Spark 产品总数: ' . count($products));

        // 收集所有 countryCode
        $codes = collect($products)->pluck('country_code')->unique()->sort()->values();
        $this->info('国家代码总数: ' . $codes->count());

        $this->table(
            ['countryCode', 'DB code', 'DB cname', 'DB name', '产品数', '示例产品'],
            $codes->map(function ($code) use ($products) {
                $dbCountry = SparkCountry::where('code', $code)->first();
                $matching = collect($products)->where('country_code', $code);
                $example = $matching->first();
                return [
                    $code,
                    $dbCountry?->code ?? '❌ 未找到',
                    $dbCountry?->cname ?? '-',
                    $dbCountry?->name ?? '-',
                    $matching->count(),
                    substr($example['product_name'] ?? '-', 0, 50),
                ];
            })
        );

        // 特别检查 HKG 和 TWN 相关
        $this->newLine();
        $this->info('=== HKG/TWN 详细检查 ===');
        foreach (['HKG', 'TWN', 'TW', 'CHN'] as $checkCode) {
            $dbEntry = SparkCountry::where('code', $checkCode)->first();
            $productCount = collect($products)->where('country_code', $checkCode)->count();
            $this->line(sprintf(
                '  %s: DB=%s, 产品数=%d',
                $checkCode,
                $dbEntry ? "{$dbEntry->cname} ({$dbEntry->name})" : '❌ 不存在',
                $productCount
            ));
        }

        // 列出所有含 Taiwan/台湾 的产品名
        $this->newLine();
        $this->info('=== 产品名含 Taiwan/台灣/台湾 的 ===');
        $twProducts = collect($products)->filter(function ($p) {
            $name = strtolower($p['product_name'] ?? '');
            return str_contains($name, 'taiwan') || str_contains($name, '台湾') || str_contains($name, '台灣');
        });
        foreach ($twProducts as $p) {
            $this->line(sprintf(
                '  countryCode=%s, productId=%s, name=%s',
                $p['country_code'], $p['product_id'], $p['product_name']
            ));
        }

        // 列出所有 HKG 产品
        $this->newLine();
        $this->info('=== countryCode=HKG 的全部产品 ===');
        $hkgProducts = collect($products)->where('country_code', 'HKG');
        foreach ($hkgProducts as $p) {
            $this->line(sprintf(
                '  productId=%s, name=%s, areaCode=%s, cityCode=%s',
                $p['product_id'], $p['product_name'], $p['area_code'] ?? '-', $p['city_code'] ?? '-'
            ));
        }

        return 0;
    }
}
