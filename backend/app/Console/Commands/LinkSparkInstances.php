<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\Subscription;
use App\Services\SparkApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinkSparkInstances extends Command
{
    protected $signature = 'spark:link-instances
        {--customer= : 客户ID}
        {--dry-run : 只查询不写入}
        {--enable-auto-renew : 同时开启订阅自动续费}';

    protected $description = '批量通过 Spark API 查询 IP 获取 instanceId，关联到 proxy_ips 和 spark_instances';

    public function handle(SparkApiService $spark): int
    {
        $customerId = $this->option('customer');
        $dryRun = $this->option('dry-run');
        $enableAutoRenew = $this->option('enable-auto-renew');

        if (!$customerId) {
            $this->error('请指定 --customer=ID');
            return self::FAILURE;
        }

        $proxyIps = ProxyIp::where('assigned_customer_id', $customerId)
            ->whereNull('spark_instance_id')
            ->whereNotIn('status', ['released', 'expired'])
            ->whereNotNull('socks5_info')
            ->get();

        if ($proxyIps->isEmpty()) {
            $this->info('没有需要关联的 ProxyIp 记录');
            return self::SUCCESS;
        }

        $this->info("找到 {$proxyIps->count()} 个缺少 spark_instance_id 的 ProxyIp");

        // 先用第一个 IP 测试 API 是否支持 ip 查询
        $testIp = $proxyIps->first();
        $this->info("测试: 查询 IP {$testIp->ip_address} ...");

        $testResult = null;
        $queryMode = null;

        // 尝试 ip 查询
        try {
            $testResult = $spark->getInstance(['ip' => $testIp->ip_address]);
            if (!empty($testResult) && !empty($testResult['instanceId'])) {
                $queryMode = 'ip';
                $this->info("✓ IP 查询成功, instanceId={$testResult['instanceId']}");
            }
        } catch (\Throwable $e) {
            $this->warn("IP 查询失败: {$e->getMessage()}");
        }

        // 尝试 username 查询
        if (!$queryMode && $testIp->auth_username) {
            try {
                $testResult = $spark->getInstance(['username' => $testIp->auth_username]);
                if (!empty($testResult) && !empty($testResult['instanceId'])) {
                    $queryMode = 'username';
                    $this->info("✓ Username 查询成功, instanceId={$testResult['instanceId']}");
                }
            } catch (\Throwable $e) {
                $this->warn("Username 查询失败: {$e->getMessage()}");
            }
        }

        if (!$queryMode) {
            $this->error('Spark API 不支持 IP 或 Username 查询，无法批量关联');
            $this->line('可能需要手动从 Spark 后台导出 instanceId 列表');
            return self::FAILURE;
        }

        $this->info("使用 {$queryMode} 模式批量查询 {$proxyIps->count()} 个 IP...\n");

        if ($dryRun) {
            $this->warn('=== DRY RUN 模式，不会写入数据库 ===');
        }

        $linked = 0;
        $failed = 0;
        $alreadyExists = 0;

        // 并发批量查询 (10个一组)
        $chunks = $proxyIps->chunk(10);

        foreach ($chunks as $chunkIndex => $chunk) {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($chunk, $queryMode, $spark) {
                foreach ($chunk as $proxyIp) {
                    $queryValue = $queryMode === 'ip' ? $proxyIp->ip_address : $proxyIp->auth_username;
                    $params = [$queryMode => $queryValue];
                    $encryptedParams = $this->encrypt($spark, json_encode($params));

                    $pool->as("pip_{$proxyIp->id}")
                        ->timeout(30)
                        ->post($this->getApiUrl($spark), [
                            'reqId' => Str::uuid()->toString(),
                            'version' => $this->getVersion($spark),
                            'timestamp' => time(),
                            'method' => 'GetInstance',
                            'supplierNo' => $this->getSupplierNo($spark),
                            'params' => $encryptedParams,
                        ]);
                }
            });

            foreach ($chunk as $proxyIp) {
                $key = "pip_{$proxyIp->id}";
                try {
                    $response = $responses[$key];
                    if (!($response instanceof \Illuminate\Http\Client\Response) || !$response->successful()) {
                        $this->error("  ✗ {$proxyIp->ip_address}: HTTP 失败");
                        $failed++;
                        continue;
                    }

                    $body = $response->json();
                    if (($body['code'] ?? 0) !== 200 || empty($body['data'])) {
                        $msg = $body['message'] ?? 'no data';
                        $this->warn("  ✗ {$proxyIp->ip_address}: {$msg}");
                        $failed++;
                        continue;
                    }

                    $data = json_decode($this->decrypt($spark, $body['data']), true);
                    $instanceId = $data['instanceId'] ?? null;

                    if (!$instanceId) {
                        $this->warn("  ✗ {$proxyIp->ip_address}: 返回数据无 instanceId");
                        $failed++;
                        continue;
                    }

                    $this->info("  ✓ {$proxyIp->ip_address} => instanceId={$instanceId}, status={$data['status']}, expire=" . (isset($data['expireAt']) ? date('Y-m-d H:i', $data['expireAt']) : 'N/A'));

                    if ($dryRun) {
                        $linked++;
                        continue;
                    }

                    // 检查是否已有 SparkInstance 记录
                    $existing = SparkInstance::where('instance_id', $instanceId)->first();
                    if ($existing) {
                        if (!$existing->proxy_ip_id) {
                            $existing->update(['proxy_ip_id' => $proxyIp->id]);
                        }
                        $proxyIp->update(['spark_instance_id' => $existing->id]);
                        $alreadyExists++;
                        $this->line("    (SparkInstance 已存在, 已关联)");
                    } else {
                        $sparkInst = SparkInstance::create([
                            'instance_id' => $instanceId,
                            'proxy_ip_id' => $proxyIp->id,
                            'ip' => $data['ip'] ?? $proxyIp->ip_address,
                            'port' => $data['port'] ?? $proxyIp->port,
                            'username' => $data['username'] ?? $proxyIp->auth_username,
                            'password' => $data['password'] ?? $proxyIp->auth_password,
                            'type' => $data['type'] ?? 1,
                            'use_type' => $data['useType'] ?? 1,
                            'status' => $data['status'] ?? 2,
                            'flow' => $data['flow'] ?? null,
                            'balance_flow' => $data['balanceFlow'] ?? null,
                            'expire_at' => isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : null,
                        ]);
                        $proxyIp->update(['spark_instance_id' => $sparkInst->id]);
                        $linked++;
                    }

                } catch (\Throwable $e) {
                    $this->error("  ✗ {$proxyIp->ip_address}: {$e->getMessage()}");
                    $failed++;
                }
            }

            $this->line("  --- 已处理 " . (($chunkIndex + 1) * 10) . "/{$proxyIps->count()} ---");
        }

        $this->newLine();
        $this->info("=== 完成 ===");
        $this->info("成功关联: {$linked}");
        $this->info("已存在(重新关联): {$alreadyExists}");
        $this->info("失败: {$failed}");

        // 开启自动续费
        if ($enableAutoRenew && !$dryRun) {
            $updated = Subscription::where('customer_id', $customerId)
                ->where('status', 'active')
                ->where('auto_renew', false)
                ->update(['auto_renew' => true]);
            $this->info("已开启 {$updated} 个订阅的自动续费");
        }

        return self::SUCCESS;
    }

    private function encrypt(SparkApiService $spark, string $plainText): string
    {
        $ref = new \ReflectionMethod($spark, 'encrypt');
        $ref->setAccessible(true);
        return $ref->invoke($spark, $plainText);
    }

    private function decrypt(SparkApiService $spark, string $cipherText): string
    {
        $ref = new \ReflectionMethod($spark, 'decrypt');
        $ref->setAccessible(true);
        return $ref->invoke($spark, $cipherText);
    }

    private function getApiUrl(SparkApiService $spark): string
    {
        $ref = new \ReflectionProperty($spark, 'apiUrl');
        $ref->setAccessible(true);
        return $ref->getValue($spark);
    }

    private function getVersion(SparkApiService $spark): string
    {
        $ref = new \ReflectionProperty($spark, 'version');
        $ref->setAccessible(true);
        return $ref->getValue($spark);
    }

    private function getSupplierNo(SparkApiService $spark): string
    {
        $ref = new \ReflectionProperty($spark, 'supplierNo');
        $ref->setAccessible(true);
        return $ref->getValue($spark);
    }
}
