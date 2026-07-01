<?php

namespace App\Console\Commands;

use App\Models\IpipvInstance;
use App\Models\IpipvOrder;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Services\IpipvApiService;
use App\Services\SparkApiService;
use Illuminate\Console\Command;

class ExportUpstreamApiData extends Command
{
    protected $signature = 'api:export-upstream {--provider=all : spark|ipipv|all} {--concurrency=10 : 并发数}';
    protected $description = '从 Spark/IPIPV 上游 API 并发拉取所有数据并导出 CSV';

    public function handle(): int
    {
        $provider = $this->option('provider');
        $dir = storage_path('app/api-export-' . now()->format('Ymd-His'));
        @mkdir($dir, 0755, true);
        $this->info("导出目录: {$dir}");

        if (in_array($provider, ['all', 'spark'])) {
            $this->exportSpark($dir);
        }
        if (in_array($provider, ['all', 'ipipv'])) {
            $this->exportIpipv($dir);
        }

        $this->newLine();
        $this->info("完成！文件在: {$dir}");
        return 0;
    }

    private function exportSpark(string $dir): void
    {
        $this->info('=== Spark API ===');
        $spark = app(SparkApiService::class);
        $concurrency = (int) $this->option('concurrency');

        // 1. Balance
        $this->info('拉取余额...');
        try {
            $balance = $spark->getBalance();
            file_put_contents("{$dir}/spark_balance.json", json_encode($balance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('  余额: ' . json_encode($balance));
        } catch (\Throwable $e) {
            $this->error('  余额拉取失败: ' . $e->getMessage());
        }

        // 2. Products/Stock
        $this->info('拉取产品库存...');
        try {
            $allProducts = [];
            $page = 1;
            do {
                $data = $spark->getProductStock(['page' => $page, 'pageSize' => 100]);
                $list = $data['list'] ?? $data['rows'] ?? (isset($data[0]) ? $data : []);
                if (empty($list)) break;
                $allProducts = array_merge($allProducts, $list);
                $page++;
            } while (count($list) >= 100);
            $this->info("  产品: " . count($allProducts) . " 条");
            $this->writeCsv("{$dir}/spark_products.csv", $allProducts);
        } catch (\Throwable $e) {
            $this->error('  产品拉取失败: ' . $e->getMessage());
        }

        // 3. Orders from DB
        $this->info('拉取订单...');
        $orders = SparkOrder::orderBy('id')->get();
        $this->info("  本地订单: {$orders->count()} 条");

        $orderRows = [];
        foreach ($orders as $o) {
            $orderRows[] = [
                'id' => $o->id,
                'req_order_no' => $o->req_order_no,
                'spark_order_no' => $o->spark_order_no,
                'method' => $o->method,
                'product_id' => $o->product_id,
                'amount' => $o->amount,
                'duration' => $o->duration,
                'unit' => $o->unit,
                'cost_amount' => $o->cost_amount,
                'status' => $o->status,
                'created_at' => $o->created_at?->toDateTimeString(),
            ];
        }
        $this->writeCsv("{$dir}/spark_orders.csv", $orderRows);

        // 4. Instances - concurrent API pull
        $this->info("拉取实例状态 (并发={$concurrency})...");
        $instances = SparkInstance::orderBy('id')->get();
        $instanceIds = $instances->pluck('instance_id')->filter()->values()->toArray();
        $this->info("  本地实例: " . count($instanceIds) . " 条");

        $bar = $this->output->createProgressBar(count($instanceIds));
        $instanceRows = [];

        $allResults = $spark->getInstancesConcurrently($instanceIds, $concurrency);

        foreach ($allResults as $instanceId => $apiData) {
            if (isset($apiData['error'])) {
                $instanceRows[] = [
                    'instance_id' => $instanceId,
                    'ip' => '',
                    'error' => $apiData['error'],
                ];
            } else {
                $inst = is_array($apiData) && isset($apiData[0]) ? $apiData[0] : $apiData;
                $instanceRows[] = [
                    'instance_id' => $instanceId,
                    'ip' => $inst['ip'] ?? '',
                    'port' => $inst['port'] ?? '',
                    'username' => $inst['username'] ?? '',
                    'status' => $inst['status'] ?? '',
                    'status_text' => isset($inst['status']) ? $spark->mapInstanceStatus((int) $inst['status']) : '',
                    'proxy_type' => $inst['proxyType'] ?? '',
                    'country_code' => $inst['countryCode'] ?? '',
                    'city_code' => $inst['cityCode'] ?? '',
                    'expire_time' => isset($inst['expireTime']) && $inst['expireTime'] > 0
                        ? date('Y-m-d H:i:s', $inst['expireTime'] / 1000)
                        : '',
                    'cost_price' => $inst['costPrice'] ?? '',
                    'product_id' => $inst['productId'] ?? '',
                ];
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->writeCsv("{$dir}/spark_instances.csv", $instanceRows);
        $this->info("  实例导出: " . count($instanceRows) . " 条");
    }

    private function exportIpipv(string $dir): void
    {
        $this->info('=== IPIPV API ===');
        $ipipv = app(IpipvApiService::class);

        if (!$ipipv->isConfigured()) {
            $this->warn('IPIPV 未配置，跳过');
            return;
        }

        // 1. Account info/balance
        $this->info('拉取账户信息...');
        try {
            $info = $ipipv->getAppInfo();
            file_put_contents("{$dir}/ipipv_account.json", json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('  账户: ' . json_encode($info));
        } catch (\Throwable $e) {
            $this->error('  账户拉取失败: ' . $e->getMessage());
        }

        // 2. Products
        $this->info('拉取产品...');
        try {
            $products = $ipipv->getProducts([]);
            $list = is_array($products) ? (isset($products[0]) ? $products : ($products['list'] ?? [])) : [];
            $this->writeCsv("{$dir}/ipipv_products.csv", $list);
            $this->info("  产品: " . count($list) . " 条");
        } catch (\Throwable $e) {
            $this->error('  产品拉取失败: ' . $e->getMessage());
        }

        // 3. Orders from DB
        $this->info('拉取订单...');
        $orders = IpipvOrder::orderBy('id')->get();
        $this->info("  本地订单: {$orders->count()} 条");

        $orderRows = [];
        foreach ($orders as $o) {
            $orderRows[] = [
                'id' => $o->id,
                'app_order_no' => $o->app_order_no,
                'ipipv_order_no' => $o->ipipv_order_no,
                'method' => $o->method,
                'product_no' => $o->product_no,
                'amount' => $o->amount,
                'duration' => $o->duration,
                'unit' => $o->unit,
                'cycle_times' => $o->cycle_times,
                'cost_amount' => $o->cost_amount,
                'status' => $o->status,
                'created_at' => $o->created_at?->toDateTimeString(),
            ];
        }
        $this->writeCsv("{$dir}/ipipv_orders.csv", $orderRows);

        // 4. Instances - batch query (IPIPV supports batch natively)
        $this->info('拉取实例状态...');
        $instances = IpipvInstance::orderBy('id')->get();
        $this->info("  本地实例: {$instances->count()} 条");

        $instanceRows = [];
        $batches = $instances->pluck('instance_no')->filter()->chunk(50);
        $bar = $this->output->createProgressBar(max($batches->count(), 1));

        foreach ($batches as $batch) {
            try {
                $apiData = $ipipv->getInstance($batch->values()->toArray());
                $list = is_array($apiData) && isset($apiData[0]) ? $apiData : [$apiData];
                foreach ($list as $inst) {
                    if (!is_array($inst)) continue;
                    $instanceRows[] = [
                        'instance_no' => $inst['instanceNo'] ?? '',
                        'ip' => $inst['ip'] ?? '',
                        'port' => $inst['port'] ?? '',
                        'username' => $inst['username'] ?? '',
                        'status' => $inst['status'] ?? '',
                        'proxy_type' => $inst['proxyType'] ?? '',
                        'country_code' => $inst['countryCode'] ?? '',
                        'city_code' => $inst['cityCode'] ?? '',
                        'flow_total' => $inst['flowTotal'] ?? '',
                        'flow_balance' => $inst['flowBalance'] ?? '',
                        'expire_time' => isset($inst['userExpired']) && $inst['userExpired'] > 0
                            ? date('Y-m-d H:i:s', $inst['userExpired'])
                            : '',
                        'product_no' => $inst['productNo'] ?? '',
                        'cost_price' => $inst['costPrice'] ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                foreach ($batch as $no) {
                    $instanceRows[] = ['instance_no' => $no, 'error' => $e->getMessage()];
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->writeCsv("{$dir}/ipipv_instances.csv", $instanceRows);
        $this->info("  实例导出: " . count($instanceRows) . " 条");
    }

    private function writeCsv(string $path, array $rows): void
    {
        if (empty($rows)) {
            file_put_contents($path, '');
            return;
        }

        $fp = fopen($path, 'w');
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

        $headers = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $k) {
                    if (!in_array($k, $headers)) $headers[] = $k;
                }
            }
        }
        fputcsv($fp, $headers);

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $line = [];
            foreach ($headers as $h) {
                $val = $row[$h] ?? '';
                $line[] = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
            }
            fputcsv($fp, $line);
        }
        fclose($fp);
    }
}
