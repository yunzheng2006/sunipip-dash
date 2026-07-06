<?php

namespace App\Console\Commands;

use App\Models\SparkOrder;
use App\Services\SparkApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckRogueReleases extends Command
{
    protected $signature = 'spark:check-rogue
        {--fix : 执行 RenewProxy 恢复}
        {--concurrency=10 : 并发查询数}';

    protected $description = '从日志对比DB，找出这两天旧服务器误释放的实例';

    public function handle(SparkApiService $spark): int
    {
        $isDryRun = !$this->option('fix');
        $concurrency = (int) $this->option('concurrency');

        $this->info($isDryRun ? '[DRY RUN]' : '[FIX MODE]');

        // 1. 从日志提取 7/3-7/5 所有 DelProxy
        $logDir = storage_path('logs');
        $allDelProxy = [];

        foreach (['laravel-2026-07-03.log','laravel-2026-07-04.log','laravel-2026-07-05.log'] as $f) {
            $path = "$logDir/$f";
            if (!file_exists($path)) {
                $this->warn("$f 不存在");
                continue;
            }
            $handle = fopen($path, 'r');
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, 'Spark API Request: DelProxy') === false) continue;
                preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch);
                preg_match('/reqOrderNo":"([^"]+)"/', $line, $reqMatch);

                $time = $timeMatch[1] ?? '?';
                $reqNo = $reqMatch[1] ?? '?';

                $instanceIdPos = strpos($line, '"instanceIds"');
                if ($instanceIdPos !== false) {
                    $after = substr($line, $instanceIdPos);
                    preg_match_all('/"([a-f0-9]{32})"/', $after, $idMatches);
                    foreach ($idMatches[1] as $instId) {
                        $allDelProxy[] = ['time' => $time, 'reqNo' => $reqNo, 'instanceId' => $instId];
                    }
                }
            }
            fclose($handle);
        }

        $this->info("日志中 7/3-7/5 DelProxy 总条目: " . count($allDelProxy));

        // 2. 对比新服务器 DB
        $allReqNos = array_unique(array_column($allDelProxy, 'reqNo'));
        $inDb = DB::table('spark_orders')
            ->whereIn('req_order_no', array_values($allReqNos))
            ->pluck('req_order_no')
            ->toArray();

        $fromOld = [];
        $fromNewCount = 0;
        foreach ($allDelProxy as $entry) {
            if (in_array($entry['reqNo'], $inDb)) {
                $fromNewCount++;
            } else {
                $fromOld[] = $entry;
            }
        }

        $this->info("新服务器发出(DB有记录): {$fromNewCount}");
        $this->info("旧服务器发出(DB无记录): " . count($fromOld));

        if (empty($fromOld)) {
            $this->info('无旧服务器误释放记录');
            return 0;
        }

        // 3. 查本地订阅状态
        $this->newLine();
        $needFix = [];
        $rows = [];

        foreach ($fromOld as $entry) {
            $si = DB::table('spark_instances')->where('instance_id', $entry['instanceId'])->first();
            if (!$si) continue;

            $pip = DB::table('proxy_ips')->where('id', $si->proxy_ip_id)->first();
            $sub = DB::table('subscriptions')
                ->where('proxy_ip_id', $si->proxy_ip_id)
                ->orderByDesc('id')
                ->first();
            $cust = $sub ? DB::table('customers')->where('id', $sub->customer_id)->value('customer_name') : '?';

            $ipPort = $pip ? "{$pip->ip_address}:{$pip->port}" : '?';
            $subStatus = $sub->status ?? '?';
            $subExpires = $sub->expires_at ?? '?';
            $isActive = ($subStatus === 'active' && $subExpires > now()->toDateTimeString());

            $rows[] = [
                $entry['time'],
                $ipPort,
                $cust,
                $sub->id ?? '?',
                $subStatus,
                $subExpires,
                $isActive ? '需修复' : '已到期/正常',
            ];

            if ($isActive && !isset($needFix[$entry['instanceId']])) {
                $needFix[$entry['instanceId']] = [
                    'ip' => $ipPort,
                    'customer' => $cust,
                    'sub_id' => $sub->id,
                    'si_id' => $si->id,
                ];
            }
        }

        $this->table(
            ['时间', 'IP:Port', '客户', 'Sub#', '订阅状态', '订阅到期', '结论'],
            $rows
        );

        $this->newLine();
        $this->info("需要修复(订阅仍有效): " . count($needFix));

        if (empty($needFix)) {
            $this->info('无需修复的实例');
            return 0;
        }

        // 4. 并发查 Spark 确认当前状态
        $this->info("\n并发查询 Spark 确认状态...");
        $instIds = array_keys($needFix);
        $sparkResults = $spark->getInstancesConcurrently($instIds, $concurrency);

        $toFix = [];
        foreach ($sparkResults as $instId => $data) {
            $status = (int) ($data['status'] ?? 0);
            $info = $needFix[$instId];
            if ($status === 2) {
                $this->line("  {$info['ip']} → status=2 已恢复，无需操作");
            } elseif ($status === 4) {
                $this->error("  {$info['ip']} → status=4 需要恢复");
                $toFix[$instId] = $info;
            } elseif (isset($data['error'])) {
                $this->warn("  {$info['ip']} → 查询失败: {$data['error']}");
            } else {
                $this->warn("  {$info['ip']} → status={$status}");
            }
        }

        if (empty($toFix)) {
            $this->info("\n所有实例已恢复或无需操作");
            return 0;
        }

        $this->newLine();
        $this->info("确认需要 RenewProxy: " . count($toFix));

        if ($isDryRun) {
            $this->warn("使用 --fix 执行修复");
            return 1;
        }

        // 5. 修复
        $fixed = 0;
        $failed = 0;
        foreach ($toFix as $instId => $info) {
            try {
                $reqOrderNo = SparkOrder::generateReqOrderNo();
                $result = $spark->renewProxy($reqOrderNo, [
                    ['instanceId' => $instId, 'duration' => 1, 'unit' => 3],
                ]);

                SparkOrder::create([
                    'req_order_no' => $reqOrderNo,
                    'spark_order_no' => $result['orderNo'] ?? null,
                    'method' => 'RenewProxy',
                    'product_id' => '',
                    'amount' => 1,
                    'duration' => 1,
                    'unit' => 3,
                    'status' => (int) ($result['status'] ?? 1),
                    'request_data' => [
                        'trigger' => 'fix_rogue_release',
                        'instanceId' => $instId,
                        'proxy_ip' => $info['ip'],
                        'sub_id' => $info['sub_id'],
                    ],
                    'response_data' => $result,
                ]);

                $this->info("  ✓ {$info['ip']} → orderNo=" . ($result['orderNo'] ?? '?'));
                $fixed++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$info['ip']} → {$e->getMessage()}");
                $failed++;
            }
            usleep(200000);
        }

        $this->newLine();
        $this->info("完成: 修复 {$fixed}, 失败 {$failed}");
        return $failed > 0 ? 1 : 0;
    }
}
