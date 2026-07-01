<?php

namespace App\Console\Commands;

use App\Models\SparkCountry;
use App\Models\SparkState;
use App\Models\SparkCity;
use App\Services\SparkStockCacheService;
use App\Support\SparkContinents;
use Illuminate\Console\Command;

/**
 * 导出 Spark API 的国家/州/城市代码对照表
 * 用于配置定价倍率时参考
 *
 * php artisan spark:export-codes
 * php artisan spark:export-codes --csv    # 输出 CSV 文件
 */
class ExportSparkCodes extends Command
{
    protected $signature = 'spark:export-codes {--csv : 输出到 CSV 文件}';
    protected $description = '导出 Spark API 国家/州/城市代码对照表';

    public function handle(): int
    {
        $this->info('正在拉取 Spark 产品列表...');
        $products = SparkStockCacheService::products();
        $this->info('产品总数: ' . count($products));

        // 收集所有用到的代码
        $rows = [];
        foreach ($products as $p) {
            $countryCode = $p['country_code'] ?? '';
            $areaCode = $p['area_code'] ?? '';
            $cityCode = $p['city_code'] ?? '';

            if (!$countryCode) continue;

            $country = SparkCountry::where('code', $countryCode)->first();
            $continent = SparkContinents::CONTINENTS[$country?->continent_id ?? 0] ?? ['name' => '其他'];

            $stateCn = '';
            if ($areaCode) {
                $stateCn = SparkState::where('code_full', $areaCode)->value('cname') ?? '';
            }

            $cityCn = '';
            if ($cityCode) {
                $cityCn = SparkCity::where('code', $cityCode)->value('cname') ?? '';
            }

            // 从产品名提取英文城市名（作为参考）
            $cityEn = '';
            $pName = $p['product_name'] ?? '';
            if (preg_match('/[-@]([A-Za-z\s]+)$/', $pName, $m)) {
                $extracted = trim($m[1]);
                $countryEn = $country?->name ?? '';
                if ($extracted && strcasecmp($extracted, $countryEn) !== 0) {
                    $cityEn = $extracted;
                }
            }

            $rows[] = [
                'continent' => $continent['name'],
                'country_code' => $countryCode,
                'country_cn' => $country?->cname ?? '',
                'country_en' => $country?->name ?? '',
                'area_code' => $areaCode,
                'area_cn' => $stateCn,
                'city_code' => $cityCode,
                'city_cn' => $cityCn,
                'city_en' => $cityEn,
                'product_id' => $p['product_id'],
                'product_name' => $pName,
                'isp_type' => $p['isp_type'] ?? 0,
                'net_type' => $p['net_type'] ?? 0,
                'cost_price' => $p['cost_price'] ?? '',
                'stock' => $p['inventory'] ?? 0,
            ];
        }

        // 排序：洲 → 国家 → 州 → 城市
        usort($rows, function ($a, $b) {
            return strcmp($a['continent'] . $a['country_code'] . $a['area_code'] . $a['city_code'],
                         $b['continent'] . $b['country_code'] . $b['area_code'] . $b['city_code']);
        });

        $ispLabels = [1 => '单ISP', 2 => '双ISP', 3 => '原生ISP', 4 => '机房'];
        $netLabels = [1 => '原生', 2 => '广播'];

        if ($this->option('csv')) {
            $path = storage_path('app/spark_codes_' . now()->format('Ymd_His') . '.csv');
            $fp = fopen($path, 'w');
            fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($fp, ['大洲', '国家代码', '国家(中)', '国家(英)', '州代码', '州(中)', '城市代码', '城市(中)', '城市(英)', '产品ID', '产品名', 'ISP类型', '网络类型', '成本价', '库存']);

            foreach ($rows as $r) {
                fputcsv($fp, [
                    $r['continent'], $r['country_code'], $r['country_cn'], $r['country_en'],
                    $r['area_code'], $r['area_cn'], $r['city_code'], $r['city_cn'], $r['city_en'],
                    $r['product_id'], $r['product_name'],
                    $ispLabels[$r['isp_type']] ?? $r['isp_type'],
                    $netLabels[$r['net_type']] ?? $r['net_type'],
                    $r['cost_price'], $r['stock'],
                ]);
            }
            fclose($fp);
            $this->info("已导出到: {$path}");
        } else {
            // 终端表格输出（精简版）
            $this->table(
                ['大洲', '国家代码', '国家', '州代码', '州', '城市代码', '城市', 'ISP', '网络', '库存'],
                collect($rows)->map(fn($r) => [
                    $r['continent'], $r['country_code'], $r['country_cn'],
                    $r['area_code'], $r['area_cn'] ?: '-',
                    $r['city_code'], $r['city_cn'] ?: $r['city_en'] ?: '-',
                    $ispLabels[$r['isp_type']] ?? '-',
                    $netLabels[$r['net_type']] ?? '-',
                    $r['stock'],
                ])
            );

            // 汇总
            $this->newLine();
            $this->info('=== 汇总 ===');
            $countries = collect($rows)->pluck('country_code')->unique();
            $this->line("国家: {$countries->count()} 个");
            $areas = collect($rows)->pluck('area_code')->filter()->unique();
            $this->line("州/地区: {$areas->count()} 个");
            $cities = collect($rows)->pluck('city_code')->filter()->unique();
            $this->line("城市: {$cities->count()} 个");
            $this->line("产品: " . count($rows) . " 个");
            $this->newLine();
            $this->info('提示: 加 --csv 参数可导出完整 CSV 文件（含产品ID、成本价等）');
        }

        return 0;
    }
}
