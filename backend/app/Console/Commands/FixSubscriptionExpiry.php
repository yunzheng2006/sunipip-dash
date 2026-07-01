<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Support\DurationHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FixSubscriptionExpiry extends Command
{
    protected $signature = 'subscriptions:fix-expiry
        {--dry-run : 只检查不修改}
        {--sync-upstream : 把到期时间同步到上游 upstream_expires_at}';

    protected $description = '修复因 addMonths 导致的订阅到期时间偏差（月=30天）';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $syncUpstream = $this->option('sync-upstream');

        $subs = Subscription::where('status', 'active')
            ->where('unit', 3)
            ->with('proxyIp')
            ->get();

        $this->info("活跃月订阅总数: {$subs->count()}");

        $fixed = 0;
        $synced = 0;
        $skipped = 0;
        $rows = [];

        foreach ($subs as $sub) {
            $ip = $sub->proxyIp;
            $currentExpiry = Carbon::parse($sub->expires_at);

            // 方式1: 同步到上游到期时间
            if ($syncUpstream && $ip?->upstream_expires_at) {
                $upstreamExpiry = Carbon::parse($ip->upstream_expires_at);
                $diffHours = $currentExpiry->diffInHours($upstreamExpiry, false);

                if (abs($diffHours) > 12) {
                    $rows[] = [
                        $sub->id,
                        $ip->ip_address ?? '-',
                        $currentExpiry->toDateTimeString(),
                        $upstreamExpiry->toDateTimeString(),
                        round($diffHours / 24, 1) . '天',
                        'sync-upstream',
                    ];

                    if (!$dryRun) {
                        $sub->update(['expires_at' => $upstreamExpiry]);
                    }
                    $synced++;
                }
                continue;
            }

            // 方式2: 根据 started_at + renewed 重算30天制到期时间
            $baseDate = $sub->last_renewed_at
                ? Carbon::parse($sub->last_renewed_at)
                : Carbon::parse($sub->started_at);

            $correctExpiry = DurationHelper::addToDate($baseDate, (int) $sub->duration, 3);
            $diffHours = $currentExpiry->diffInHours($correctExpiry, false);

            if (abs($diffHours) < 6) {
                continue; // 偏差不到6小时，不需要修
            }

            if (abs($diffHours) > 72) {
                $skipped++;
                continue; // 偏差超过3天，可能有其他原因，跳过
            }

            $rows[] = [
                $sub->id,
                $ip->ip_address ?? '-',
                $currentExpiry->toDateTimeString(),
                $correctExpiry->toDateTimeString(),
                round($diffHours / 24, 1) . '天',
                'recalc',
            ];

            if (!$dryRun) {
                $sub->update(['expires_at' => $correctExpiry]);
            }
            $fixed++;
        }

        if (count($rows)) {
            $this->table(
                ['订阅ID', 'IP', '原到期', '新到期', '偏差', '方式'],
                $rows
            );
        }

        $label = $dryRun ? '(dry-run) 需修复' : '已修复';
        $this->info("{$label}: 重算 {$fixed} 条, 同步上游 {$synced} 条, 偏差过大跳过 {$skipped} 条");

        return 0;
    }
}
