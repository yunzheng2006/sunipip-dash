<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\SalesStatsNewController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 固化上月业绩统计快照
 *
 * 业绩页面的实时计算依赖当前数据（订阅状态、expires_at、成本价等），
 * 后续的退款/续费/改价会让历史月份的数字漂移。每月 1 日把上月数据
 * 固化到 sales_stats_snapshots，历史整月查询走快照，口径永久稳定。
 */
class SnapshotSalesStats extends Command
{
    protected $signature = 'stats:snapshot {--month= : 指定月份 YYYY-MM，默认上个月} {--force : 已有快照时覆盖重建}';

    protected $description = '固化指定月份的业绩统计快照（默认上月）';

    public function handle(): int
    {
        $month = $this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('月份格式应为 YYYY-MM');
            return 1;
        }

        $periodStart = Carbon::parse($month . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        if ($periodEnd->isFuture()) {
            $this->error("{$month} 尚未结束，不能固化");
            return 1;
        }

        $exists = DB::table('sales_stats_snapshots')->where('period', $month)->exists();
        if ($exists && !$this->option('force')) {
            $this->warn("{$month} 快照已存在，使用 --force 覆盖重建");
            return 1;
        }

        // 用超管身份跑一遍实时计算（与页面完全同一套逻辑）
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first()
            ?? User::orderBy('id')->first();
        if (!$admin) {
            $this->error('找不到可用的管理员账号');
            return 1;
        }

        $request = Request::create('/internal/stats-snapshot', 'GET', [
            'date_from' => $periodStart->toDateString(),
            'date_to' => $periodEnd->toDateString(),
        ]);
        $request->setUserResolver(fn () => $admin);

        $this->info("计算 {$month} ({$periodStart->toDateString()} ~ {$periodEnd->toDateString()}) ...");
        $response = app(SalesStatsNewController::class)->index($request);
        $payload = $response->getData(true);
        $rows = $payload['data']['customers'] ?? [];

        if (empty($rows)) {
            $this->warn('无数据，不写入快照');
            return 0;
        }

        $now = now();
        DB::transaction(function () use ($rows, $month, $now) {
            DB::table('sales_stats_snapshots')->where('period', $month)->delete();
            $inserts = [];
            foreach ($rows as $row) {
                $inserts[] = [
                    'period' => $month,
                    'customer_id' => $row['id'],
                    'sales_person' => $row['sales_person'] ?? null,
                    'data' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'snapshotted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('sales_stats_snapshots')->insert($chunk);
            }
        });

        $this->info("已固化 {$month} 快照：" . count($rows) . " 个客户");
        return 0;
    }
}
