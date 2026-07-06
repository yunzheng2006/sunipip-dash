<?php

namespace App\Console\Commands;

use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Services\SparkApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckSparkInstanceStatus extends Command
{
    protected $signature = 'spark:check-releases
        {--days=2 : 检查最近N天}
        {--fix : 对被误释放且订阅仍有效的实例执行 RenewProxy 恢复}
        {--concurrency=10 : 并发查询数}';

    protected $description = '并发检查活跃订阅的Spark实例状态，找出被旧服务器误释放的实例';

    public function handle(SparkApiService $spark): int
    {
        $days = (int) $this->option('days');
        $since = Carbon::now()->subDays($days)->startOfDay();
        $concurrency = (int) $this->option('concurrency');
        $isDryRun = !$this->option('fix');

        $this->info($isDryRun ? '[DRY RUN] 仅检查不修复' : '[FIX MODE] 将尝试修复');
        $this->newLine();

        // ====== 1. 本地操作记录概览 ======
        $this->info("=== 第1步: 本地操作记录 ({$since->toDateString()} 至今) ===");

        $orders = SparkOrder::where('created_at', '>=', $since)->orderBy('created_at')->get();
        $byMethod = $orders->groupBy('method');
        foreach ($byMethod as $method => $group) {
            $this->line("  {$method}: {$group->count()} 条");
        }
        $this->newLine();

        // ====== 2. 找出活跃订阅未到期的实例 ======
        $this->info('=== 第2步: 查询活跃订阅+未到期+已分配 ===');

        $suspects = DB::select("
            SELECT si.id as si_id, si.instance_id, si.expire_at as si_expire,
                   pip.ip_address, pip.port, pip.id as pip_id,
                   s.id as sub_id, s.expires_at as sub_expires,
                   c.customer_name
            FROM spark_instances si
            JOIN proxy_ips pip ON pip.id = si.proxy_ip_id
            JOIN subscriptions s ON s.proxy_ip_id = pip.id AND s.status = 'active'
            JOIN customers c ON c.id = s.customer_id
            WHERE pip.status = 'assigned'
              AND pip.deleted_at IS NULL
              AND s.expires_at > NOW()
        ");

        $this->line("  符合条件: " . count($suspects) . " 条");

        if (empty($suspects)) {
            $this->info('无活跃实例需要检查。');
            return 0;
        }

        // ====== 3. 并发查询 Spark API ======
        $this->info("=== 第3步: 并发查询 Spark (concurrency={$concurrency}) ===");

        $instanceIds = array_map(fn ($s) => $s->instance_id, $suspects);
        $suspectMap = [];
        foreach ($suspects as $s) {
            $suspectMap[$s->instance_id] = $s;
        }

        $sparkResults = $spark->getInstancesConcurrently($instanceIds, $concurrency);
        $this->line("  查询完成: " . count($sparkResults) . " 条结果");

        // ====== 4. 分析 ======
        $this->newLine();
        $this->info('=== 第4步: 分析结果 ===');

        $normal = 0;
        $released = [];
        $apiErrors = [];

        foreach ($sparkResults as $instId => $data) {
            $local = $suspectMap[$instId] ?? null;
            if (!$local) continue;

            if (isset($data['error'])) {
                $apiErrors[] = "{$local->ip_address}:{$local->port} → {$data['error']}";
                continue;
            }

            $status = (int) ($data['status'] ?? 0);
            if ($status === 2) {
                $normal++;
                continue;
            }

            $statusLabels = [1 => '开通中', 3 => '释放中', 4 => '释放完成'];
            $sparkExpire = isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : '?';

            $released[] = [
                'si_id' => $local->si_id,
                'instance_id' => $instId,
                'ip' => $local->ip_address,
                'port' => $local->port,
                'sub_id' => $local->sub_id,
                'customer' => $local->customer_name,
                'spark_status' => $status,
                'spark_label' => $statusLabels[$status] ?? "未知({$status})",
                'spark_expire' => $sparkExpire,
                'sub_expires' => $local->sub_expires,
            ];
        }

        $this->line("  正常(status=2): {$normal}");
        $this->line("  被释放:         " . count($released));
        $this->line("  查询失败:       " . count($apiErrors));

        if (!empty($apiErrors)) {
            $this->newLine();
            $this->warn('查询失败:');
            foreach (array_slice($apiErrors, 0, 10) as $e) {
                $this->line("  {$e}");
            }
            if (count($apiErrors) > 10) {
                $this->line("  ...及另外 " . (count($apiErrors) - 10) . " 条");
            }
        }

        if (empty($released)) {
            $this->newLine();
            $this->info('所有活跃订阅的实例状态正常!');
            return 0;
        }

        $this->newLine();
        $this->error('被误释放的实例:');
        $this->table(
            ['IP:Port', 'Sub#', '客户', 'Spark状态', 'Spark到期', '订阅到期'],
            array_map(fn ($r) => [
                "{$r['ip']}:{$r['port']}", $r['sub_id'], $r['customer'],
                "{$r['spark_status']}({$r['spark_label']})",
                $r['spark_expire'], $r['sub_expires'],
            ], $released)
        );

        if ($isDryRun) {
            $this->newLine();
            $this->warn("共 " . count($released) . " 个实例需要修复。使用 --fix 执行 RenewProxy 恢复。");
            return 1;
        }

        // ====== 5. 修复 ======
        $this->newLine();
        $this->info('=== 第5步: 执行 RenewProxy 恢复 ===');

        $fixed = 0;
        $failed = 0;

        foreach ($released as $r) {
            $label = "{$r['ip']}:{$r['port']} (sub#{$r['sub_id']} {$r['customer']})";
            try {
                $reqOrderNo = SparkOrder::generateReqOrderNo();
                $result = $spark->renewProxy($reqOrderNo, [
                    ['instanceId' => $r['instance_id'], 'duration' => 1, 'unit' => 3],
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
                        'trigger' => 'fix_old_server_release',
                        'instanceId' => $r['instance_id'],
                        'proxy_ip' => $r['ip'],
                        'sub_id' => $r['sub_id'],
                    ],
                    'response_data' => $result,
                ]);

                $this->info("  ✓ {$label} → orderNo=" . ($result['orderNo'] ?? '?'));
                $fixed++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$label} → {$e->getMessage()}");
                $failed++;
            }
            usleep(200000);
        }

        // 并发验证恢复结果
        if ($fixed > 0) {
            $this->newLine();
            $this->info('恢复后并发验证...');
            sleep(3);

            $fixedIds = array_column($released, 'instance_id');
            $verifyResults = $spark->getInstancesConcurrently($fixedIds, $concurrency);

            $verified = 0;
            foreach ($verifyResults as $instId => $data) {
                if (isset($data['error'])) continue;
                $status = (int) ($data['status'] ?? 0);
                if ($status === 2) {
                    SparkInstance::where('instance_id', $instId)->update([
                        'status' => 2,
                        'expire_at' => isset($data['expireAt']) ? date('Y-m-d H:i:s', $data['expireAt']) : null,
                    ]);
                    $verified++;
                } else {
                    $this->warn("  {$instId} 恢复后仍为 status={$status}");
                }
            }
            $this->info("  验证: {$verified}/{$fixed} 恢复成功");
        }

        $this->newLine();
        $this->info("完成: 修复 {$fixed}, 失败 {$failed}");
        return $failed > 0 ? 1 : 0;
    }
}
