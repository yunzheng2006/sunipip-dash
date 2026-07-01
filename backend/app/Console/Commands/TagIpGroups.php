<?php

namespace App\Console\Commands;

use App\Models\IpGroup;
use App\Models\ProxyIp;
use Illuminate\Console\Command;

/**
 * 将所有无 IP 组标签的历史数据统一打上「历史数据」标签
 *
 * 用法：
 *   php artisan ips:tag-groups --dry-run   # 预览
 *   php artisan ips:tag-groups             # 执行
 */
class TagIpGroups extends Command
{
    protected $signature = 'ips:tag-groups {--dry-run}';
    protected $description = '将无 IP 组的历史 IP 统一打上「历史数据」标签';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 创建或获取「历史数据」IP 组
        $group = IpGroup::firstOrCreate(
            ['slug' => 'legacy'],
            [
                'name' => '历史数据',
                'display_name' => '历史数据',
                'description' => '系统上线前导入的 IP，价格由销售单独定义，不走统一定价',
                'status' => 1,
                'sort_order' => 99,
            ]
        );

        $count = ProxyIp::whereNull('ip_group_id')->count();

        $this->info("IP 组「{$group->name}」(#{$group->id}, slug=legacy)");
        $this->info("待打标 IP: {$count} 条");

        if ($count === 0) {
            $this->info('没有需要处理的 IP。');
            return 0;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] 将把 {$count} 条 IP 标记为「历史数据」");

            // 按资产组展示分布
            $distribution = ProxyIp::whereNull('ip_group_id')
                ->selectRaw('asset_group_id, source_name, count(*) as cnt')
                ->groupBy('asset_group_id', 'source_name')
                ->orderByDesc('cnt')
                ->get();

            $this->table(['资产组ID', '归属', '数量'], $distribution->map(fn($r) => [
                $r->asset_group_id,
                $r->source_name ?: '-',
                $r->cnt,
            ]));

            return 0;
        }

        $updated = ProxyIp::whereNull('ip_group_id')
            ->update(['ip_group_id' => $group->id]);

        $this->info("完成，已将 {$updated} 条 IP 标记为「历史数据」");
        return 0;
    }
}
