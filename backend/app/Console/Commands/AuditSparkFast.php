<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use App\Models\UpstreamProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuditSparkFast extends Command
{
    protected $signature = 'spark:audit-fast {--concurrency=30 : 并发查询数}';
    protected $description = '并发版 Spark 审计：批量查询实例状态';

    private string $apiUrl;
    private string $supplierNo;
    private string $aesKey;
    private string $version;

    public function handle(): int
    {
        $this->initCredentials();
        $concurrency = (int) $this->option('concurrency');

        // ── 1. 余额 ──
        $this->info('========== Spark 账户余额 ==========');
        try {
            $balance = $this->sparkRequest('GetBalance', []);
            $this->info('余额: ' . json_encode($balance, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->error('获取余额失败: ' . $e->getMessage());
        }

        // ── 2. 订单统计 ──
        $this->newLine();
        $this->info('========== 订单支出统计 ==========');
        $orders = SparkOrder::where('status', 2)->get();
        $byMethod = $orders->groupBy('method');
        foreach ($byMethod as $method => $group) {
            $this->line("  {$method}: {$group->count()} 单, 总花费 ¥{$group->sum('cost_amount')}");
        }
        $this->line("  总计: {$orders->count()} 单, 总花费 ¥" . $orders->sum('cost_amount'));

        // 最近7天明细
        $this->newLine();
        $this->info('---------- 最近 7 天订单明细 ----------');
        $recentOrders = SparkOrder::where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')->get();
        $rows = [];
        foreach ($recentOrders as $o) {
            $rows[] = [
                $o->id, $o->req_order_no, $o->method, $o->product_id ?: '-',
                $o->amount, $o->cost_amount ?: '-',
                ['1' => '处理中', '2' => '完成', '3' => '失败'][$o->status] ?? $o->status,
                $o->created_at->format('m-d H:i'),
            ];
        }
        $this->table(['ID', '订单号', '方法', '产品ID', '数量', '花费', '状态', '时间'], $rows);

        // ── 3. 并发查询本地活跃实例 ──
        $this->newLine();
        $this->info("========== 本地活跃实例 vs Spark（并发={$concurrency}） ==========");

        $localActive = SparkInstance::whereIn('status', [1, 2])
            ->with(['proxyIp', 'sparkOrder'])
            ->get();
        $this->info("本地活跃实例数: {$localActive->count()}");

        $issues = [];
        $apiActive = 0;
        $apiReleased = 0;
        $apiNotFound = 0;
        $apiError = 0;

        $chunks = $localActive->chunk($concurrency);
        $totalChunks = $chunks->count();
        $chunkIdx = 0;

        foreach ($chunks as $chunk) {
            $chunkIdx++;
            $this->output->write("\r  查询进度: {$chunkIdx}/{$totalChunks} 批...");

            $results = $this->batchGetInstance($chunk->values()->all());

            foreach ($results as $i => $res) {
                $inst = $chunk->values()[$i];
                if ($res['error'] ?? false) {
                    $apiError++;
                    if (stripos($res['error'], 'not found') !== false) {
                        $apiNotFound++;
                        $issues[] = [
                            'type' => 'LOCAL_ACTIVE_API_NOT_FOUND',
                            'instance_id' => $inst->instance_id,
                            'ip' => $inst->ip,
                            'local_status' => $inst->status,
                            'proxy_ip_id' => $inst->proxy_ip_id,
                        ];
                    }
                    continue;
                }
                $remote = $res['data'] ?? [];
                if (empty($remote)) {
                    $apiNotFound++;
                    $issues[] = [
                        'type' => 'LOCAL_ACTIVE_API_NOT_FOUND',
                        'instance_id' => $inst->instance_id,
                        'ip' => $inst->ip,
                        'local_status' => $inst->status,
                        'proxy_ip_id' => $inst->proxy_ip_id,
                        'expire_at' => $inst->expire_at?->format('Y-m-d'),
                    ];
                    continue;
                }
                $remoteStatus = (int) ($remote['status'] ?? 0);
                if ($remoteStatus >= 3) {
                    $apiReleased++;
                    $issues[] = [
                        'type' => 'LOCAL_ACTIVE_API_RELEASED',
                        'instance_id' => $inst->instance_id,
                        'ip' => $inst->ip,
                        'local_status' => $inst->status,
                        'remote_status' => $remoteStatus,
                        'proxy_ip_id' => $inst->proxy_ip_id,
                        'expire_at' => $remote['expireAt'] ?? $inst->expire_at?->format('Y-m-d'),
                    ];
                } else {
                    $apiActive++;
                }
            }
            usleep(50000); // 50ms between batches
        }

        $this->newLine();
        $this->info("  API确认活跃: {$apiActive}");
        $this->info("  API已释放但本地未更新: {$apiReleased}");
        $this->info("  API找不到: {$apiNotFound}");
        if ($apiError > 0) $this->warn("  API查询出错: {$apiError}");

        // ── 4. 订阅已结束但 Spark 仍活跃 ──
        $this->newLine();
        $this->info('========== 应释放但可能未释放的实例 ==========');

        $leakedInstances = SparkInstance::whereIn('status', [1, 2])
            ->whereHas('proxyIp', function ($q) {
                $q->whereHas('subscriptions', function ($sq) {
                    $sq->whereIn('status', ['expired', 'cancelled', 'refunded']);
                })->whereDoesntHave('subscriptions', function ($sq) {
                    $sq->where('status', 'active');
                });
            })
            ->with(['proxyIp.subscriptions' => fn($q) => $q->latest()->limit(1)])
            ->get();

        $leakRows = [];
        $leakedChunks = $leakedInstances->chunk($concurrency);
        foreach ($leakedChunks as $chunk) {
            $results = $this->batchGetInstance($chunk->values()->all());
            foreach ($results as $i => $res) {
                $inst = $chunk->values()[$i];
                $ip = $inst->proxyIp;
                $sub = $ip?->subscriptions->first();

                $sparkStatus = 'error';
                if (!($res['error'] ?? false)) {
                    $remote = $res['data'] ?? [];
                    $sparkStatus = !empty($remote) ? self::mapStatus((int) ($remote['status'] ?? 0)) : 'not_found';
                } elseif (stripos($res['error'], 'not found') !== false) {
                    $sparkStatus = 'not_found';
                }

                $leakRows[] = [
                    $inst->instance_id, $inst->ip, $ip?->status ?? '-',
                    $sub?->status ?? '-', $sub?->expires_at?->format('Y-m-d') ?? '-',
                    $sparkStatus, $inst->expire_at?->format('Y-m-d') ?? '-',
                ];

                if ($sparkStatus === 'active') {
                    $issues[] = [
                        'type' => 'SUBSCRIPTION_ENDED_SPARK_ACTIVE',
                        'instance_id' => $inst->instance_id,
                        'ip' => $inst->ip,
                        'subscription_status' => $sub?->status,
                        'subscription_expired' => $sub?->expires_at?->format('Y-m-d'),
                        'proxy_ip_status' => $ip?->status,
                        'spark_expire_at' => $inst->expire_at?->format('Y-m-d'),
                    ];
                }
            }
            usleep(50000);
        }

        if (!empty($leakRows)) {
            $this->table(['实例ID', 'IP', 'IP状态', '订阅状态', '订阅到期', 'Spark状态', 'Spark到期'], $leakRows);
        } else {
            $this->info('  无泄漏');
        }

        // ── 5. ProxyIp 已释放但 SparkInstance 仍活跃 ──
        $this->newLine();
        $this->info('========== ProxyIp 已释放但 SparkInstance 仍活跃 ==========');

        $ipReleasedButActive = SparkInstance::whereIn('status', [1, 2])
            ->whereHas('proxyIp', fn($q) => $q->whereIn('status', ['released', 'expired']))
            ->with('proxyIp:id,ip_address,status,assigned_customer_id')
            ->get();

        if ($ipReleasedButActive->isNotEmpty()) {
            $ghostRows = [];
            $ghostChunks = $ipReleasedButActive->chunk($concurrency);
            foreach ($ghostChunks as $chunk) {
                $results = $this->batchGetInstance($chunk->values()->all());
                foreach ($results as $i => $res) {
                    $inst = $chunk->values()[$i];
                    $sparkStatus = 'error';
                    if (!($res['error'] ?? false)) {
                        $remote = $res['data'] ?? [];
                        $sparkStatus = !empty($remote) ? self::mapStatus((int) ($remote['status'] ?? 0)) : 'not_found';
                    }

                    $ghostRows[] = [
                        $inst->instance_id, $inst->ip,
                        $inst->proxyIp?->status ?? '-', $sparkStatus,
                        $inst->expire_at?->format('Y-m-d') ?? '-',
                    ];

                    if ($sparkStatus === 'active') {
                        $issues[] = [
                            'type' => 'IP_RELEASED_SPARK_STILL_ACTIVE',
                            'instance_id' => $inst->instance_id,
                            'ip' => $inst->ip,
                            'proxy_ip_status' => $inst->proxyIp?->status,
                            'spark_expire_at' => $inst->expire_at?->format('Y-m-d'),
                        ];
                    }
                }
                usleep(50000);
            }
            $this->table(['实例ID', 'IP', 'IP状态', 'Spark状态', 'Spark到期'], $ghostRows);
        } else {
            $this->info('  无');
        }

        // ── 6. 续费审计 ──
        $this->newLine();
        $this->info('========== 续费订单审计（最近30天） ==========');

        $renewOrders = SparkOrder::where('method', 'RenewProxy')
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', 2)->orderBy('created_at', 'desc')->get();

        $this->info("  续费订单数: {$renewOrders->count()}, 总花费: ¥{$renewOrders->sum('cost_amount')}");

        $wastedRenews = [];
        foreach ($renewOrders as $ro) {
            $reqData = $ro->request_data ?? [];
            $instanceIds = [];
            if (!empty($reqData['instances'])) {
                $instanceIds = array_column($reqData['instances'], 'instanceId');
            }
            foreach ($instanceIds as $iid) {
                $si = SparkInstance::where('instance_id', $iid)->first();
                if (!$si || !$si->proxy_ip_id) continue;
                $sub = Subscription::where('proxy_ip_id', $si->proxy_ip_id)
                    ->orderBy('id', 'desc')->first();
                if ($sub && in_array($sub->status, ['expired', 'cancelled', 'refunded'])) {
                    $wastedRenews[] = [
                        $ro->req_order_no, $iid, $si->ip,
                        $sub->status, $sub->expires_at?->format('Y-m-d') ?? '-',
                        $ro->cost_amount ?: '-', $ro->created_at->format('m-d H:i'),
                    ];
                }
            }
        }

        if (!empty($wastedRenews)) {
            $this->warn("  发现 " . count($wastedRenews) . " 笔续费给了已结束的订阅：");
            $this->table(['订单号', '实例ID', 'IP', '订阅状态', '订阅到期', '花费', '续费时间'], $wastedRenews);
        } else {
            $this->info('  无浪费续费');
        }

        // ── 汇总 ──
        $this->newLine();
        $this->info('========== 问题汇总 ==========');
        if (empty($issues)) {
            $this->info('  ✓ 未发现问题');
        } else {
            $this->warn("  发现 " . count($issues) . " 个问题：");
            $grouped = collect($issues)->groupBy('type');
            foreach ($grouped as $type => $items) {
                $label = match ($type) {
                    'LOCAL_ACTIVE_API_NOT_FOUND' => '本地活跃但Spark找不到',
                    'LOCAL_ACTIVE_API_RELEASED' => '本地活跃但Spark已释放',
                    'SUBSCRIPTION_ENDED_SPARK_ACTIVE' => '订阅已结束但Spark仍扣费',
                    'IP_RELEASED_SPARK_STILL_ACTIVE' => 'IP已释放但Spark仍活跃',
                    default => $type,
                };
                $this->warn("  [{$label}] × {$items->count()}");
                foreach ($items as $item) {
                    $this->line("    - {$item['instance_id']} ({$item['ip']})");
                }
            }
        }

        // JSON 报告
        $outputPath = storage_path('app/spark-audit-fast-' . now()->format('Ymd-His') . '.json');
        file_put_contents($outputPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'local_active' => $localActive->count(),
                'api_confirmed_active' => $apiActive,
                'api_released' => $apiReleased,
                'api_not_found' => $apiNotFound,
                'leaked_instances' => $leakedInstances->count(),
                'issues_total' => count($issues),
            ],
            'issues' => $issues,
            'recent_orders' => $recentOrders->map(fn($o) => [
                'id' => $o->id, 'req_order_no' => $o->req_order_no,
                'method' => $o->method, 'product_id' => $o->product_id,
                'amount' => $o->amount, 'cost_amount' => $o->cost_amount,
                'status' => $o->status,
                'created_at' => $o->created_at->toIso8601String(),
                'request_data' => $o->request_data,
            ])->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("  详细报告已保存: {$outputPath}");

        return 0;
    }

    private function batchGetInstance(array $instances): array
    {
        $responses = Http::pool(function ($pool) use ($instances) {
            foreach ($instances as $inst) {
                $params = json_encode(['instanceId' => $inst->instance_id]);
                $encrypted = $this->encrypt($params);
                $pool->timeout(15)->post($this->apiUrl, [
                    'reqId' => Str::uuid()->toString(),
                    'version' => $this->version,
                    'timestamp' => time(),
                    'method' => 'GetInstance',
                    'supplierNo' => $this->supplierNo,
                    'params' => $encrypted,
                ]);
            }
        });

        $results = [];
        foreach ($responses as $i => $response) {
            try {
                if ($response instanceof \Throwable) {
                    $results[] = ['error' => $response->getMessage(), 'data' => []];
                    continue;
                }
                $body = $response->json();
                if (!$body || ($body['code'] ?? 0) !== 200) {
                    $msg = $body['message'] ?? $body['msg'] ?? 'unknown';
                    $results[] = ['error' => $msg, 'data' => []];
                    continue;
                }
                $data = [];
                if (!empty($body['data'])) {
                    $decrypted = $this->decrypt($body['data']);
                    $data = json_decode($decrypted, true) ?? [];
                }
                $results[] = ['data' => $data];
            } catch (\Throwable $e) {
                $results[] = ['error' => $e->getMessage(), 'data' => []];
            }
        }
        return $results;
    }

    private static function mapStatus(int $status): string
    {
        return match ($status) {
            1 => 'pending', 2 => 'active', 3 => 'released',
            4 => 'expired', 5 => 'releasing',
            default => "unknown({$status})",
        };
    }

    private function initCredentials(): void
    {
        try {
            $provider = UpstreamProvider::where('slug', 'spark')->where('is_active', true)->first();
            if ($provider && !empty($provider->credentials['aes_key'])) {
                $creds = $provider->credentials;
                $this->apiUrl = $provider->api_url ?: config('proxy.spark.api_url');
                $this->supplierNo = $creds['supplier_no'] ?? config('proxy.spark.supplier_no');
                $this->aesKey = $creds['aes_key'] ?? config('proxy.spark.aes_key');
                $this->version = $creds['version'] ?? config('proxy.spark.version', '2.0');
                return;
            }
        } catch (\Throwable $e) {}

        $this->apiUrl = config('proxy.spark.api_url');
        $this->supplierNo = config('proxy.spark.supplier_no');
        $this->aesKey = config('proxy.spark.aes_key');
        $this->version = config('proxy.spark.version', '2.0');
    }

    private function getCipher(): string
    {
        $len = strlen($this->aesKey);
        return match (true) {
            $len >= 32 => 'AES-256-CBC',
            $len >= 24 => 'AES-192-CBC',
            default => 'AES-128-CBC',
        };
    }

    private function encrypt(string $plainText): string
    {
        $cipher = $this->getCipher();
        $iv = substr($this->aesKey, 0, 16);
        $encrypted = openssl_encrypt($plainText, $cipher, $this->aesKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }

    private function decrypt(string $cipherText): string
    {
        $cipher = $this->getCipher();
        $iv = substr($this->aesKey, 0, 16);
        $decoded = base64_decode($cipherText);
        return openssl_decrypt($decoded, $cipher, $this->aesKey, OPENSSL_RAW_DATA, $iv);
    }

    private function sparkRequest(string $method, array $params): array
    {
        $body = [
            'reqId' => Str::uuid()->toString(),
            'version' => $this->version,
            'timestamp' => time(),
            'method' => $method,
            'supplierNo' => $this->supplierNo,
            'params' => $this->encrypt(json_encode($params)),
        ];

        $response = Http::timeout(30)->post($this->apiUrl, $body);
        $result = $response->json();

        if (!$result || ($result['code'] ?? 0) !== 200) {
            throw new \RuntimeException($result['message'] ?? 'API error');
        }

        if (!empty($result['data'])) {
            return json_decode($this->decrypt($result['data']), true) ?? [];
        }
        return [];
    }
}
