<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * 把外部导入的IP的订阅到期时间同步成IP自身的上游到期时间
 *
 * 背景：早期批量分配时，订阅的 expires_at 是按 duration/unit 推算的（默认1个月），
 * 但外部导入的IP有自身的 upstream_expires_at（从上游/Excel 导入带来），
 * 客户拿到的订阅到期时间应该等于 IP 实际到期时间。
 *
 * 用法：
 *   php artisan subs:sync-expiry-from-ip --dry-run   预览要改动哪些
 *   php artisan subs:sync-expiry-from-ip             实际执行
 *   php artisan subs:sync-expiry-from-ip --diff-days=1  只处理差异大于1天的（跳过小偏差）
 *   php artisan subs:sync-expiry-from-ip --only-shorter 只缩短不延长（只处理IP比订阅先到期的情况）
 *
 * 规则：
 *   - 只处理 status=active 的订阅
 *   - 只处理 proxyIp.upstream_expires_at 有值的（手工导入/外部IP）
 *   - 只处理 upstream_expires_at 仍在未来的（不制造已过期订阅）
 *   - 默认双向同步（IP到期时间可长可短都同步）
 *   - --only-shorter 时：只把订阅到期时间缩短到IP到期（避免意外延长已经按周期计费的订阅）
 *   - --diff-days 时：当 |ip_expires - sub_expires| <= N 天时跳过
 */
class SyncSubscriptionExpiryFromIp extends Command
{
    protected $signature = 'subs:sync-expiry-from-ip
                            {--dry-run : 只预览不改动}
                            {--diff-days=0 : 差异小于等于N天时跳过}
                            {--only-shorter : 只缩短订阅到期时间，不延长}';

    protected $description = '把订阅的到期时间同步到IP的上游到期时间（仅对外部导入的IP生效）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $diffDays = (int) $this->option('diff-days');
        $onlyShorter = (bool) $this->option('only-shorter');

        $updated = 0;
        $skippedSame = 0;
        $skippedNoUpstream = 0;
        $skippedExpiredUpstream = 0;
        $skippedSpark = 0;
        $skippedDiffSmall = 0;
        $skippedLonger = 0;

        $this->info('扫描 active 订阅…');

        Subscription::query()
            ->where('status', 'active')
            ->with('proxyIp:id,ip_address,upstream_expires_at,spark_instance_id')
            ->chunkById(500, function ($subs) use (
                $dryRun, $diffDays, $onlyShorter,
                &$updated, &$skippedSame, &$skippedNoUpstream,
                &$skippedExpiredUpstream, &$skippedSpark, &$skippedDiffSmall, &$skippedLonger
            ) {
                foreach ($subs as $sub) {
                    $ip = $sub->proxyIp;
                    if (!$ip) continue;

                    if (!$ip->upstream_expires_at) { $skippedNoUpstream++; continue; }
                    if ($ip->upstream_expires_at->isPast()) { $skippedExpiredUpstream++; continue; }

                    // 跳过 Spark API 开通的订阅：上游按月滚动续费，upstream_expires_at 不代表客户购买时长
                    if ($ip->spark_instance_id) { $skippedSpark++; continue; }

                    $target = $ip->upstream_expires_at;
                    $current = $sub->expires_at;

                    // 完全相等跳过（秒级）
                    if ($current && $target->equalTo($current)) { $skippedSame++; continue; }

                    // 差异小跳过
                    if ($current && $diffDays > 0 && abs($target->diffInDays($current, false)) <= $diffDays) {
                        $skippedDiffSmall++;
                        continue;
                    }

                    // 只缩短模式：如果 target > current 就跳过
                    if ($onlyShorter && $current && $target->greaterThan($current)) {
                        $skippedLonger++;
                        continue;
                    }

                    $oldStr = $current ? $current->format('Y-m-d H:i') : 'null';
                    $newStr = $target->format('Y-m-d H:i');

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  订阅#%d  IP %s  %s → %s',
                            $sub->id, $ip->ip_address, $oldStr, $newStr
                        ));
                    } else {
                        $sub->update(['expires_at' => $target]);
                    }
                    $updated++;
                }
            });

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->newLine();
        $this->info("{$prefix}扫描完成：");
        $this->line("  ✓ 需要同步：{$updated} 条");
        $this->line("  - 已同步（时间一致）：{$skippedSame} 条");
        $this->line("  - IP无上游到期（Spark早期/空值）：{$skippedNoUpstream} 条");
        $this->line("  - IP上游已过期：{$skippedExpiredUpstream} 条");
        $this->line("  - Spark API 开通（跳过）：{$skippedSpark} 条");
        if ($diffDays > 0) {
            $this->line("  - 差异≤{$diffDays}天跳过：{$skippedDiffSmall} 条");
        }
        if ($onlyShorter) {
            $this->line("  - 跳过延长（--only-shorter）：{$skippedLonger} 条");
        }

        return 0;
    }
}
