<?php

namespace App\Console\Commands;

use App\Support\DurationHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillInitialDuration extends Command
{
    protected $signature = 'fix:backfill-initial-duration {--dry-run : 仅显示受影响记录}';
    protected $description = '回填 subscriptions 表的 initial_duration 和 initial_unit（修复续费覆写导致的成本计算错误）';

    private array $customerNames = [];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');

        $this->customerNames = DB::table('customers')->pluck('customer_name', 'id')->all();

        // 1. 未续费的订阅：initial = current（duration/unit 从未被覆写）
        $neverRenewed = DB::table('subscriptions')
            ->whereNull('initial_duration')
            ->where('renewed_count', 0)
            ->count();

        if (!$dryRun && $neverRenewed > 0) {
            DB::table('subscriptions')
                ->whereNull('initial_duration')
                ->where('renewed_count', 0)
                ->update([
                    'initial_duration' => DB::raw('duration'),
                    'initial_unit' => DB::raw('unit'),
                ]);
        }
        $this->info("未续费订阅：{$neverRenewed} 条（从未续费，当前值就是原始值）");
        $this->newLine();

        // 2. 已续费的订阅
        $renewed = DB::table('subscriptions')
            ->whereNull('initial_duration')
            ->where('renewed_count', '>', 0)
            ->select('id', 'customer_id', 'proxy_ip_id', 'duration', 'unit',
                     'started_at', 'expires_at', 'created_at', 'renewed_count')
            ->orderBy('id')
            ->get();

        if ($renewed->isEmpty()) {
            $this->info('没有需要回填的已续费订阅。');
            return 0;
        }

        $this->info("已续费订阅：{$renewed->count()} 条");

        // ── 预加载上游订单数据 ──
        $proxyIpIds = $renewed->pluck('proxy_ip_id')->filter()->unique()->values()->all();
        $orderMap = $this->loadOrderMap($proxyIpIds);

        $unitLabels = [1 => '天', 2 => '周', 3 => '月', 4 => '年'];
        $fmt = fn($d, $u) => "{$d}" . ($unitLabels[$u] ?? "?{$u}");

        $orderMatched = 0;
        $calcMatched = 0;
        $unresolved = 0;
        $orderRows = [];
        $calcRows = [];
        $unresolvedRows = [];

        foreach ($renewed as $sub) {
            $origDuration = null;
            $origUnit = null;
            $source = null;
            $customerName = ($this->customerNames[$sub->customer_id] ?? '?') . " (#{$sub->customer_id})";

            // 方式1：从上游订单记录查（精确）
            $orderInfo = $orderMap[$sub->proxy_ip_id] ?? null;
            if ($orderInfo) {
                $origDuration = (int) $orderInfo['duration'];
                $origUnit = (int) $orderInfo['unit'];
                $source = $orderInfo['source'];

                $orderRows[] = [
                    $sub->id, $customerName,
                    $fmt($origDuration, $origUnit), $fmt($sub->duration, $sub->unit),
                    $sub->renewed_count, $source,
                ];
                $orderMatched++;
            }

            // 方式2：从 started_at + expires_at 反算（精确计算，非估算）
            // 原理：expires_at = started_at + 原始天数 + 续费次数 × 每次续费天数
            //       原始天数 = expires_at - started_at - 续费次数 × 每次续费天数
            if (!$origDuration && $sub->started_at && $sub->expires_at) {
                $startTs = strtotime($sub->started_at);
                $expiresTs = strtotime($sub->expires_at);
                $renewalDaysPerTime = DurationHelper::toDays((int) $sub->duration, (int) $sub->unit);
                $totalSpanDays = ($expiresTs - $startTs) / 86400;
                $originalDays = (int) round($totalSpanDays - $sub->renewed_count * $renewalDaysPerTime);

                if ($originalDays >= 1 && $originalDays <= 400) {
                    $origDuration = $originalDays;
                    $origUnit = 1; // 天
                    $source = "反算({$originalDays}天)";

                    $calcRows[] = [
                        $sub->id, $customerName,
                        $fmt($origDuration, $origUnit), $fmt($sub->duration, $sub->unit),
                        $sub->renewed_count, $source,
                        substr($sub->started_at, 0, 10), substr($sub->expires_at, 0, 10),
                    ];
                    $calcMatched++;
                }
            }

            if ($origDuration) {
                if (!$dryRun) {
                    DB::table('subscriptions')
                        ->where('id', $sub->id)
                        ->update([
                            'initial_duration' => $origDuration,
                            'initial_unit' => $origUnit,
                        ]);
                }
            } else {
                $unresolvedRows[] = [
                    $sub->id, $customerName,
                    $sub->proxy_ip_id ?: '无',
                    $fmt($sub->duration, $sub->unit),
                    $sub->renewed_count,
                    substr($sub->started_at ?: $sub->created_at, 0, 16),
                    substr($sub->expires_at ?? '', 0, 16),
                ];
                $unresolved++;
            }
        }

        if (!empty($orderRows)) {
            $this->info("方式1 - 从上游订单匹配 ({$orderMatched}):");
            $this->table(['订阅ID', '客户', '原始时长', '当前时长', '续费次数', '来源'], $orderRows);
        }

        if (!empty($calcRows)) {
            $this->newLine();
            $this->info("方式2 - 从 开通时间+到期时间 反算 ({$calcMatched}):");
            $this->table(['订阅ID', '客户', '原始时长', '当前时长', '续费次数', '计算方式', '开通', '到期'], $calcRows);
        }

        if (!empty($unresolvedRows)) {
            $this->newLine();
            $this->warn("无法确定 ({$unresolved}):");
            $this->table(['订阅ID', '客户', 'proxy_ip_id', '当前时长', '续费次数', '开通时间', '到期时间'], $unresolvedRows);
        }

        $this->newLine();
        $this->info("订单匹配: {$orderMatched}, 反算匹配: {$calcMatched}, 无法确定: {$unresolved}, 未续费: {$neverRenewed}");

        if ($dryRun) {
            $this->warn('去掉 --dry-run 执行回填。');
        } else {
            $this->info('回填完成。');
            if ($unresolved > 0) {
                $this->warn("无法确定的 {$unresolved} 条未修改。");
            }
        }

        return 0;
    }

    private function loadOrderMap(array $proxyIpIds): array
    {
        if (empty($proxyIpIds)) {
            return [];
        }

        $map = [];

        // Spark 路径1: proxy_ips.spark_instance_id → spark_instances.instance_id → spark_orders
        $sparkRows = DB::table('proxy_ips')
            ->join('spark_instances', 'spark_instances.instance_id', '=', 'proxy_ips.spark_instance_id')
            ->join('spark_orders', 'spark_orders.id', '=', 'spark_instances.spark_order_id')
            ->whereIn('proxy_ips.id', $proxyIpIds)
            ->whereNotNull('proxy_ips.spark_instance_id')
            ->where('spark_orders.method', 'CreateProxy')
            ->select('proxy_ips.id as proxy_ip_id', 'spark_orders.duration', 'spark_orders.unit')
            ->get();
        foreach ($sparkRows as $row) {
            if ($row->duration && $row->unit) {
                $map[$row->proxy_ip_id] = ['duration' => $row->duration, 'unit' => $row->unit, 'source' => 'spark订单'];
            }
        }

        // Spark 路径2: spark_instances.proxy_ip_id
        $missingPids = array_diff($proxyIpIds, array_keys($map));
        if (!empty($missingPids)) {
            $sparkRows2 = DB::table('spark_instances')
                ->join('spark_orders', 'spark_orders.id', '=', 'spark_instances.spark_order_id')
                ->whereIn('spark_instances.proxy_ip_id', $missingPids)
                ->where('spark_orders.method', 'CreateProxy')
                ->select('spark_instances.proxy_ip_id', 'spark_orders.duration', 'spark_orders.unit')
                ->get();
            foreach ($sparkRows2 as $row) {
                if ($row->duration && $row->unit) {
                    $map[$row->proxy_ip_id] = ['duration' => $row->duration, 'unit' => $row->unit, 'source' => 'spark订单(备用路径)'];
                }
            }
        }

        // IPIPV 路径
        $missingPids = array_diff($proxyIpIds, array_keys($map));
        if (!empty($missingPids)) {
            $ipipvRows = DB::table('proxy_ips')
                ->join('ipipv_instances', 'ipipv_instances.instance_no', '=', 'proxy_ips.ipipv_instance_id')
                ->join('ipipv_orders', 'ipipv_orders.id', '=', 'ipipv_instances.ipipv_order_id')
                ->whereIn('proxy_ips.id', $missingPids)
                ->whereNotNull('proxy_ips.ipipv_instance_id')
                ->where('ipipv_orders.method', 'create')
                ->select('proxy_ips.id as proxy_ip_id', 'ipipv_orders.duration', 'ipipv_orders.unit')
                ->get();
            foreach ($ipipvRows as $row) {
                if ($row->duration && $row->unit) {
                    $map[$row->proxy_ip_id] = ['duration' => $row->duration, 'unit' => $row->unit, 'source' => 'ipipv订单'];
                }
            }
        }

        return $map;
    }
}
