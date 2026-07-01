<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use App\Services\SparkApiService;
use Illuminate\Console\Command;

class AuditSparkInstances extends Command
{
    protected $signature = 'spark:audit {--fix : 自动释放应释放但未释放的实例}';
    protected $description = '审计 Spark 实例状态：对比本地数据库与 Spark API 的真实状态，找出泄漏';

    public function handle(): int
    {
        $spark = app(SparkApiService::class);

        // ── 1. Spark 余额 ──
        $this->info('========== Spark 账户余额 ==========');
        try {
            $balance = $spark->getBalance();
            $this->info('余额: ' . json_encode($balance, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->error('获取余额失败: ' . $e->getMessage());
        }

        // ── 2. 订单支出统计 ──
        $this->newLine();
        $this->info('========== 订单支出统计 ==========');

        $orders = SparkOrder::where('status', 2)->get();

        $byMethod = $orders->groupBy('method');
        foreach ($byMethod as $method => $group) {
            $totalCost = $group->sum('cost_amount');
            $count = $group->count();
            $this->line("  {$method}: {$count} 单, 总花费 ¥{$totalCost}");
        }
        $this->line("  总计: {$orders->count()} 单, 总花费 ¥" . $orders->sum('cost_amount'));

        // 最近7天明细
        $this->newLine();
        $this->info('---------- 最近 7 天订单明细 ----------');
        $recentOrders = SparkOrder::where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->get();

        $rows = [];
        foreach ($recentOrders as $o) {
            $rows[] = [
                $o->id,
                $o->req_order_no,
                $o->method,
                $o->product_id ?: '-',
                $o->amount,
                $o->cost_amount ?: '-',
                ['1' => '处理中', '2' => '完成', '3' => '失败'][$o->status] ?? $o->status,
                $o->created_at->format('m-d H:i'),
            ];
        }
        $this->table(['ID', '订单号', '方法', '产品ID', '数量', '花费', '状态', '时间'], $rows);

        // ── 3. 本地实例与 Spark 实际状态对比 ──
        $this->newLine();
        $this->info('========== 本地活跃实例 vs Spark 实际状态 ==========');

        $localActive = SparkInstance::whereIn('status', [1, 2])
            ->with(['proxyIp', 'sparkOrder'])
            ->get();
        $this->info("本地活跃实例数: {$localActive->count()}");

        $issues = [];
        $apiActive = 0;
        $apiReleased = 0;
        $apiNotFound = 0;
        $apiError = 0;

        foreach ($localActive as $inst) {
            try {
                $remote = $spark->getInstance(['instanceId' => $inst->instance_id]);
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
            } catch (\Throwable $e) {
                $apiError++;
                if (stripos($e->getMessage(), 'data not found') !== false) {
                    $apiNotFound++;
                    $issues[] = [
                        'type' => 'LOCAL_ACTIVE_API_NOT_FOUND',
                        'instance_id' => $inst->instance_id,
                        'ip' => $inst->ip,
                        'local_status' => $inst->status,
                        'proxy_ip_id' => $inst->proxy_ip_id,
                    ];
                }
            }
            usleep(100000); // 100ms 节流
        }

        $this->info("  API确认活跃: {$apiActive}");
        $this->info("  API已释放但本地未更新: {$apiReleased}");
        $this->info("  API找不到: {$apiNotFound}");
        if ($apiError > 0) $this->warn("  API查询出错: {$apiError}");

        // ── 4. 检查应释放但未释放的（订阅已过期/取消但Spark仍活跃） ──
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
            ->with(['proxyIp.subscriptions' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->get();

        $leakRows = [];
        foreach ($leakedInstances as $inst) {
            $ip = $inst->proxyIp;
            $sub = $ip?->subscriptions->first();

            // 验证 Spark 那边是否真的还活跃
            $sparkStatus = '?';
            try {
                $remote = $spark->getInstance(['instanceId' => $inst->instance_id]);
                $sparkStatus = !empty($remote) ? SparkApiService::mapInstanceStatus((int) ($remote['status'] ?? 0)) : 'not_found';
            } catch (\Throwable $e) {
                $sparkStatus = stripos($e->getMessage(), 'not found') !== false ? 'not_found' : 'error';
            }
            usleep(100000);

            $leakRows[] = [
                $inst->instance_id,
                $inst->ip,
                $ip?->status ?? '-',
                $sub?->status ?? '-',
                $sub?->expires_at?->format('Y-m-d') ?? '-',
                $sparkStatus,
                $inst->expire_at?->format('Y-m-d') ?? '-',
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

        if (!empty($leakRows)) {
            $this->table(
                ['实例ID', 'IP', 'IP状态', '订阅状态', '订阅到期', 'Spark状态', 'Spark到期'],
                $leakRows
            );
        } else {
            $this->info('  无泄漏');
        }

        // ── 5. 检查 ProxyIp 已释放但 SparkInstance 未标记释放的 ──
        $this->newLine();
        $this->info('========== ProxyIp 已释放但 SparkInstance 仍活跃 ==========');

        $ipReleasedButInstanceActive = SparkInstance::whereIn('status', [1, 2])
            ->whereHas('proxyIp', function ($q) {
                $q->whereIn('status', ['released', 'expired']);
            })
            ->with('proxyIp:id,ip_address,status,assigned_customer_id')
            ->get();

        if ($ipReleasedButInstanceActive->isNotEmpty()) {
            $ghostRows = [];
            foreach ($ipReleasedButInstanceActive as $inst) {
                $sparkStatus = '?';
                try {
                    $remote = $spark->getInstance(['instanceId' => $inst->instance_id]);
                    $sparkStatus = !empty($remote) ? SparkApiService::mapInstanceStatus((int) ($remote['status'] ?? 0)) : 'not_found';
                } catch (\Throwable $e) {
                    $sparkStatus = 'error';
                }
                usleep(100000);

                $ghostRows[] = [
                    $inst->instance_id,
                    $inst->ip,
                    $inst->proxyIp?->status ?? '-',
                    $sparkStatus,
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
            $this->table(['实例ID', 'IP', 'IP状态', 'Spark状态', 'Spark到期'], $ghostRows);
        } else {
            $this->info('  无');
        }

        // ── 6. 续费订单审计 ──
        $this->newLine();
        $this->info('========== 续费订单审计（最近30天） ==========');

        $renewOrders = SparkOrder::where('method', 'RenewProxy')
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', 2)
            ->orderBy('created_at', 'desc')
            ->get();

        $renewTotal = $renewOrders->sum('cost_amount');
        $this->info("  续费订单数: {$renewOrders->count()}, 总花费: ¥{$renewTotal}");

        // 检查续费的实例是否仍然需要（关联订阅是否仍活跃）
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
                    ->orderBy('id', 'desc')
                    ->first();
                if ($sub && in_array($sub->status, ['expired', 'cancelled', 'refunded'])) {
                    $wastedRenews[] = [
                        $ro->req_order_no,
                        $iid,
                        $si->ip,
                        $sub->status,
                        $sub->expires_at?->format('Y-m-d') ?? '-',
                        $ro->cost_amount ?: '-',
                        $ro->created_at->format('m-d H:i'),
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

            // 输出 JSON 供本地使用
            $outputPath = storage_path('app/spark-audit-' . now()->format('Ymd-His') . '.json');
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
                    'id' => $o->id,
                    'req_order_no' => $o->req_order_no,
                    'method' => $o->method,
                    'product_id' => $o->product_id,
                    'amount' => $o->amount,
                    'cost_amount' => $o->cost_amount,
                    'status' => $o->status,
                    'created_at' => $o->created_at->toIso8601String(),
                    'request_data' => $o->request_data,
                ])->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("  详细报告已保存: {$outputPath}");
        }

        return 0;
    }
}
