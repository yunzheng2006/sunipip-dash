<?php

namespace App\Console\Commands;

use App\Services\SparkApiService;
use Illuminate\Console\Command;

/**
 * 调试工具：直接调 Spark API 查看原始返回数据
 *
 * 用法：
 *   php artisan spark:debug stock              # 拉取库存（第1页）
 *   php artisan spark:debug stock --country=USA # 指定国家
 *   php artisan spark:debug stock --full        # 拉取全部产品的原始字段
 *   php artisan spark:debug instance --ip=1.2.3.4  # 查实例
 *   php artisan spark:debug instance --id=xxx      # 按实例ID查
 *   php artisan spark:debug order --req=xxx --spark=xxx  # 查订单
 */
class SparkApiDebug extends Command
{
    protected $signature = 'spark:debug
        {action=stock : stock|instance|order}
        {--country= : 国家代码(stock)}
        {--area= : 州代码(stock)}
        {--city= : 城市代码(stock)}
        {--product= : 产品ID(stock)}
        {--page=1 : 页码}
        {--size=10 : 每页数量}
        {--full : 拉取全部(stock)}
        {--ip= : IP地址(instance)}
        {--id= : 实例ID(instance)}
        {--username= : 用户名(instance)}
        {--req= : 合作方订单号(order)}
        {--spark= : Spark订单号(order)}';

    protected $description = '直接调 Spark API 查看原始返回数据';

    public function handle(): int
    {
        $spark = app(SparkApiService::class);
        $action = $this->argument('action');

        try {
            match ($action) {
                'stock' => $this->debugStock($spark),
                'instance' => $this->debugInstance($spark),
                'order' => $this->debugOrder($spark),
                default => $this->error("未知操作: {$action}"),
            };
        } catch (\Throwable $e) {
            $this->error("API 调用失败: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }

    private function debugStock(SparkApiService $spark): void
    {
        $filters = [
            'page' => (int) $this->option('page'),
            'pageSize' => (int) $this->option('size'),
        ];
        if ($this->option('country')) $filters['countryCode'] = $this->option('country');
        if ($this->option('area')) $filters['areaCode'] = $this->option('area');
        if ($this->option('city')) $filters['cityCode'] = $this->option('city');
        if ($this->option('product')) $filters['productId'] = $this->option('product');

        if ($this->option('full')) {
            // 拉全部
            $this->info('拉取全部产品...');
            $all = [];
            foreach ([103, 104] as $type) {
                $filters['proxyType'] = $type;
                $filters['page'] = 1;
                $filters['pageSize'] = 100;
                $data = $spark->getProductStock($filters);
                $total = $data['total'] ?? 0;
                $products = $data['products'] ?? [];
                $all = array_merge($all, $products);
                $this->info("  proxyType={$type}: total={$total}, fetched=" . count($products));
            }

            $this->newLine();
            $this->info("总计 " . count($all) . " 个产品");
            $this->newLine();

            // 输出第一个产品的完整字段结构
            if (!empty($all[0])) {
                $this->warn('=== 第一个产品的完整原始字段 ===');
                $this->line(json_encode($all[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            // 汇总有哪些字段
            $this->newLine();
            $this->warn('=== 所有产品中出现的字段名 ===');
            $allKeys = [];
            foreach ($all as $p) {
                foreach (array_keys($p) as $k) {
                    $allKeys[$k] = ($allKeys[$k] ?? 0) + 1;
                }
            }
            ksort($allKeys);
            $this->table(['字段名', '出现次数'], collect($allKeys)->map(fn($cnt, $k) => [$k, $cnt])->values());

            // 汇总唯一值
            $this->newLine();
            $this->warn('=== ispType 分布 ===');
            $ispDist = collect($all)->countBy('ispType')->sortKeys();
            foreach ($ispDist as $v => $cnt) $this->line("  {$v} => {$cnt}");

            $this->warn('=== netType 分布 ===');
            $netDist = collect($all)->countBy('netType')->sortKeys();
            foreach ($netDist as $v => $cnt) $this->line("  {$v} => {$cnt}");

            $this->warn('=== bandWidthType 分布 ===');
            $bwDist = collect($all)->countBy('bandWidthType')->sortKeys();
            foreach ($bwDist as $v => $cnt) $this->line("  {$v} => {$cnt}");

            $this->warn('=== sellLimit 分布 ===');
            $slDist = collect($all)->countBy('sellLimit')->sortKeys();
            foreach ($slDist as $v => $cnt) $this->line("  {$v} => {$cnt}");

            $this->warn('=== useLimit 分布 ===');
            $ulDist = collect($all)->countBy('useLimit')->sortKeys();
            foreach ($ulDist as $v => $cnt) $this->line("  {$v} => {$cnt}");

            $this->warn('=== protocol 分布 ===');
            $prDist = collect($all)->countBy('protocol')->sortKeys();
            foreach ($prDist as $v => $cnt) $this->line("  {$v} => {$cnt}");

            $this->warn('=== 国家分布 (top 20) ===');
            $ccDist = collect($all)->countBy('countryCode')->sortByDesc(fn($v) => $v)->take(20);
            foreach ($ccDist as $v => $cnt) $this->line("  {$v} => {$cnt} 个产品");

        } else {
            $data = $spark->getProductStock($filters);
            $this->info("total: " . ($data['total'] ?? '?') . " | curPage: " . ($data['curPage'] ?? '?'));
            $products = $data['products'] ?? [];
            $this->info("本页返回: " . count($products) . " 条");

            foreach ($products as $i => $p) {
                $this->newLine();
                $this->warn("--- 产品 #" . ($i + 1) . " ---");
                $this->line(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
    }

    private function debugInstance(SparkApiService $spark): void
    {
        $filters = [];
        if ($this->option('id')) $filters['instanceId'] = $this->option('id');
        if ($this->option('ip')) $filters['ip'] = $this->option('ip');
        if ($this->option('username')) $filters['username'] = $this->option('username');

        if (empty($filters)) {
            $this->error('请指定 --id, --ip, 或 --username');
            return;
        }

        $data = $spark->getInstance($filters);
        $this->warn('=== 实例原始返回 ===');
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function debugOrder(SparkApiService $spark): void
    {
        $req = $this->option('req');
        $sparkNo = $this->option('spark');

        if (!$req || !$sparkNo) {
            $this->error('请指定 --req 和 --spark');
            return;
        }

        $data = $spark->getOrder($req, $sparkNo);
        $this->warn('=== 订单原始返回 ===');
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
