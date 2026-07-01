<?php

namespace App\Console\Commands;

use App\Models\ProxyIp;
use Illuminate\Console\Command;

/**
 * 批量修复 asset_name：从「客户名-地区-IP」改为「地区-IP」
 *
 * 用法：
 *   php artisan ips:fix-asset-names --dry-run   # 预览
 *   php artisan ips:fix-asset-names              # 执行
 */
class FixAssetNames extends Command
{
    protected $signature = 'ips:fix-asset-names {--dry-run}';
    protected $description = '批量修复 IP 资产名为「地区-IP」格式';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $fixed = 0;

        // 查所有 asset_name 包含至少两个 - 的记录（可能是 客户名-地区-IP 格式）
        ProxyIp::withTrashed()
            ->whereNotNull('ip_address')
            ->whereNotNull('country_name')
            ->where('country_name', '!=', '')
            ->chunkById(200, function ($ips) use ($dryRun, &$fixed) {
                foreach ($ips as $ip) {
                    $expected = "{$ip->country_name}-{$ip->ip_address}";

                    // 已经是目标格式就跳过
                    if ($ip->asset_name === $expected) continue;

                    // 检查是否以 IP 地址结尾且包含国家名
                    // 典型旧格式：轻语-美国-1.2.3.4 或 陈小同-巴西-5.6.7.8
                    if (!str_ends_with($ip->asset_name ?? '', $ip->ip_address)) continue;
                    if (!str_contains($ip->asset_name ?? '', $ip->country_name)) continue;

                    if ($dryRun) {
                        $this->line("  #{$ip->id} 「{$ip->asset_name}」→「{$expected}」");
                    } else {
                        $ip->update(['asset_name' => $expected]);
                    }
                    $fixed++;
                }
            });

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}修复 {$fixed} 条资产名");
        return 0;
    }
}
