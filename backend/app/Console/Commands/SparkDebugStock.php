<?php

namespace App\Console\Commands;

use App\Services\SparkApiService;
use Illuminate\Console\Command;

class SparkDebugStock extends Command
{
    protected $signature = 'spark:debug-stock {--country= : 搜索国家关键词}';
    protected $description = '调试 Spark 库存拉取，查看原始数据';

    public function handle(): int
    {
        $spark = app(SparkApiService::class);
        $keyword = strtolower($this->option('country') ?: '');

        $allProducts = [];

        foreach ([103, 104] as $proxyType) {
            $page = 1;
            $fetched = 0;
            $typeLabel = $proxyType === 103 ? '静态住宅' : '动态住宅';

            do {
                $this->line("  拉取 {$typeLabel} page={$page}...");
                $data = $spark->getProductStock([
                    'proxyType' => $proxyType,
                    'page' => $page,
                    'pageSize' => 100,
                ]);

                $products = $data['products'] ?? [];
                $total = (int) ($data['total'] ?? 0);

                $this->line("    返回 " . count($products) . " 条, total={$total}, curPage=" . ($data['curPage'] ?? '?'));

                if (empty($products)) break;

                $allProducts = array_merge($allProducts, $products);
                $fetched += count($products);

                if ($fetched >= $total || count($products) < 100) break;
                $page++;
            } while ($page <= 50);

            $this->info("{$typeLabel}: 拉取 {$fetched}/{$total} 条");
        }

        $this->newLine();
        $this->info("总计拉取: " . count($allProducts) . " 个产品");

        // 按国家统计
        $byCountry = collect($allProducts)->groupBy('countryCode');
        $this->info("涉及国家: " . $byCountry->count() . " 个");

        // 如果指定了关键词，搜索
        if ($keyword) {
            $this->newLine();
            $this->warn("搜索关键词: {$keyword}");

            $matched = collect($allProducts)->filter(function ($p) use ($keyword) {
                $fields = strtolower(
                    ($p['countryCode'] ?? '') . ' ' .
                    ($p['productName'] ?? '') . ' ' .
                    ($p['areaCode'] ?? '') . ' ' .
                    ($p['cityCode'] ?? '')
                );
                return str_contains($fields, $keyword);
            });

            if ($matched->isEmpty()) {
                $this->error("未找到匹配的产品");
                $this->newLine();
                $this->info("所有国家代码:");
                $codes = $byCountry->keys()->sort()->values();
                $this->line($codes->chunk(15)->map(fn($c) => $c->join(', '))->join("\n"));
            } else {
                $this->info("找到 {$matched->count()} 个匹配产品:");
                foreach ($matched->take(10) as $p) {
                    $this->line(json_encode([
                        'productId' => $p['productId'] ?? null,
                        'productName' => $p['productName'] ?? null,
                        'countryCode' => $p['countryCode'] ?? null,
                        'areaCode' => $p['areaCode'] ?? null,
                        'cityCode' => $p['cityCode'] ?? null,
                        'inventory' => $p['inventory'] ?? 0,
                        'ispType' => $p['ispType'] ?? null,
                        'netType' => $p['netType'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
        } else {
            // 显示所有国家及库存
            $this->newLine();
            $summary = $byCountry->map(fn($items, $code) => [
                'code' => $code,
                'products' => $items->count(),
                'stock' => $items->sum('inventory'),
            ])->sortByDesc('stock')->values();

            $this->table(['国家代码', '产品数', '总库存'], $summary->map(fn($s) => [$s['code'], $s['products'], $s['stock']]));
        }

        return 0;
    }
}
