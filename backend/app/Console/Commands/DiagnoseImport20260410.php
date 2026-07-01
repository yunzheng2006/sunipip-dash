<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 诊断 2026-04-10 批次导入的结果
 *
 * 对比 import_data_2026_04_10.json 和数据库中的 proxy_ips 表，
 * 找出哪些源数据的 (ip, port) 没有出现在数据库，以及原因。
 */
class DiagnoseImport20260410 extends Command
{
    protected $signature = 'diagnose:import-20260410 {--fix : 直接补录缺失的 IP}';
    protected $description = '诊断 2026-04-10 批次导入情况';

    public function handle(): int
    {
        $jsonPath = database_path('seeders/import_data_2026_04_10.json');
        if (!file_exists($jsonPath)) {
            $this->error("JSON 不存在：{$jsonPath}");
            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($jsonPath), true);
        $this->info("JSON 总行数: " . count($rows));

        // 去重提取 unique (ip, port)
        $uniqueKeys = [];
        foreach ($rows as $i => $r) {
            $socks5 = trim($r[2] ?? '');
            $parts = explode(':', $socks5);
            $ip = trim($parts[0] ?? '');
            $port = (int) trim($parts[1] ?? '0');
            if (!$ip || $port <= 0) continue;
            $key = "{$ip}:{$port}";
            if (!isset($uniqueKeys[$key])) {
                $uniqueKeys[$key] = [
                    'row' => $i + 2,
                    'ip' => $ip,
                    'port' => $port,
                    'customer' => $r[0] ?? '',
                    'country' => $r[1] ?? '',
                    'socks5' => $socks5,
                    'asset_name' => $r[3] ?? '',
                    'expires' => $r[4] ?? '',
                    'sales' => $r[5] ?? '',
                    'source' => $r[6] ?? '',
                ];
            }
        }
        $this->info("去重后唯一 (ip, port): " . count($uniqueKeys));

        // 查 DB：分批构造 (ip, port) 对，避免 IN 里拼字符串性能差
        $ips = array_map(fn($k) => $uniqueKeys[$k]['ip'], array_keys($uniqueKeys));
        $existing = ProxyIp::withTrashed()
            ->whereIn('ip_address', $ips)
            ->get(['id', 'ip_address', 'port', 'status', 'deleted_at', 'source_name', 'assigned_customer_id']);

        // 过滤出真正匹配 (ip, port) 的
        $existing = $existing->filter(fn($ip) => isset($uniqueKeys["{$ip->ip_address}:{$ip->port}"]));

        $this->info("数据库中匹配的记录: " . $existing->count() . " (含已软删除)");

        $existingKeys = $existing->map(fn($ip) => "{$ip->ip_address}:{$ip->port}")->toArray();
        $missing = array_diff(array_keys($uniqueKeys), $existingKeys);

        $this->newLine();
        $this->line("===== 按状态分布（数据库中的） =====");
        $byStatus = $existing->groupBy('status')->map->count();
        foreach ($byStatus as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        $trashed = $existing->whereNotNull('deleted_at')->count();
        $this->line("  (软删除): {$trashed}");

        $this->newLine();
        $this->line("===== 缺失在数据库中的 (ip:port) =====");
        $this->line("缺失数量: " . count($missing));
        if (!empty($missing)) {
            $this->line("前 20 条样本：");
            foreach (array_slice($missing, 0, 20) as $key) {
                $row = $uniqueKeys[$key];
                $this->line(sprintf(
                    "  row#%-4d %-25s  客户=%s  归属=%s  到期=%s",
                    $row['row'], $key, $row['customer'], $row['source'], $row['expires']
                ));
            }
        }

        // 额外：查所有 proxy_ips 总数
        $this->newLine();
        $this->line("===== 整体统计 =====");
        $this->line("proxy_ips 总数（含软删）: " . ProxyIp::withTrashed()->count());
        $this->line("proxy_ips 未软删: " . ProxyIp::count());
        $statusCounts = ProxyIp::groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');
        foreach ($statusCounts as $st => $n) {
            $this->line("  status={$st}: {$n}");
        }

        // 默认列表能看到的数量（排除 released）
        $listVisible = ProxyIp::where('status', '!=', 'released')->count();
        $this->line("管理后台 /proxy-ips 默认可见（排除 released）: {$listVisible}");

        // ===== 订阅统计 =====
        $this->newLine();
        $this->line("===== Subscriptions 统计 =====");
        $totalSubs = \App\Models\Subscription::count();
        $this->line("subscriptions 总数: {$totalSubs}");
        $subByStatus = \App\Models\Subscription::groupBy('status')
            ->selectRaw('status, count(*) as n')->pluck('n', 'status');
        foreach ($subByStatus as $st => $n) {
            $this->line("  status={$st}: {$n}");
        }

        // proxy_ips without any subscription
        $ipIdsWithSub = \App\Models\Subscription::distinct()->pluck('proxy_ip_id')->filter()->all();
        $ipsWithoutSub = \App\Models\ProxyIp::whereNotIn('id', $ipIdsWithSub)
            ->where('status', '!=', 'released')
            ->get(['id', 'ip_address', 'port', 'assigned_customer_id', 'source_name', 'upstream_expires_at', 'asset_name']);
        $this->newLine();
        $this->line("===== 无订阅的 ProxyIp =====");
        $this->line("数量: " . $ipsWithoutSub->count());
        if ($ipsWithoutSub->count() > 0) {
            $withoutCust = $ipsWithoutSub->whereNull('assigned_customer_id')->count();
            $withoutExpiry = $ipsWithoutSub->whereNull('upstream_expires_at')->count();
            $this->line("  其中未分配客户: {$withoutCust}");
            $this->line("  其中无到期时间: {$withoutExpiry}");

            $this->line("\n前 10 条样本：");
            foreach ($ipsWithoutSub->take(10) as $ip) {
                $this->line(sprintf(
                    "  IP#%-4d %-21s  客户=%s  来源=%s  到期=%s",
                    $ip->id,
                    $ip->ip_address . ':' . $ip->port,
                    $ip->assigned_customer_id ?: '(无)',
                    $ip->source_name ?: '-',
                    $ip->upstream_expires_at?->format('Y-m-d') ?: '(无)'
                ));
            }
        }

        // ===== 重复订阅检测 =====
        $this->newLine();
        $this->line("===== 同一 (customer, proxy_ip) 多个订阅 =====");
        $dupes = \DB::table('subscriptions')
            ->selectRaw('customer_id, proxy_ip_id, count(*) as n')
            ->groupBy('customer_id', 'proxy_ip_id')
            ->having('n', '>', 1)
            ->get();
        $this->line("数量: " . $dupes->count());
        if ($dupes->count() > 0) {
            foreach ($dupes->take(5) as $d) {
                $this->line("  customer={$d->customer_id} proxy_ip={$d->proxy_ip_id} × {$d->n}");
            }
        }

        // --fix 补录缺失的订阅
        if ($this->option('fix') && $ipsWithoutSub->count() > 0) {
            if (!$this->confirm("确认为 " . $ipsWithoutSub->count() . " 条 IP 补建订阅？")) {
                return self::SUCCESS;
            }

            $adminId = \App\Models\User::where('username', 'admin')->value('id') ?? 1;
            $created = 0;
            $skipped = 0;

            foreach ($ipsWithoutSub as $ip) {
                if (!$ip->assigned_customer_id) {
                    $skipped++;
                    continue;
                }
                $expiresAt = $ip->upstream_expires_at ?: now()->addMonth();
                \App\Models\Subscription::create([
                    'customer_id' => $ip->assigned_customer_id,
                    'proxy_ip_id' => $ip->id,
                    'price' => 0,
                    'duration' => 1,
                    'unit' => 3,
                    'started_at' => now(),
                    'expires_at' => $expiresAt,
                    'status' => $expiresAt->gt(now()) ? 'active' : 'expired',
                    'created_by' => $adminId,
                    'remark' => '诊断补建 2026-04-11',
                ]);
                $created++;
            }
            $this->info("补建完成：创建 {$created}，跳过 {$skipped}（未分配客户）");
        }

        return self::SUCCESS;
    }
}
