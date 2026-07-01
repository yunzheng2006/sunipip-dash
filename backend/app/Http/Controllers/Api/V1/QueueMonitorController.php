<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 队列任务监控 — 让 admin 在面板查看队列的实时状态
 */
class QueueMonitorController extends Controller
{
    /**
     * GET /queue-monitor/stats
     *
     * 返回各队列 pending / reserved / failed 的数量统计
     */
    public function stats(): JsonResponse
    {
        // jobs 表（database 驱动）
        $pending = DB::table('jobs')
            ->whereNull('reserved_at')
            ->count();

        $reserved = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->count();

        // 按 queue 分组
        $byQueue = DB::table('jobs')
            ->selectRaw("queue, COUNT(*) as total, SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as processing")
            ->groupBy('queue')
            ->get();

        $failed = DB::table('failed_jobs')->count();

        // forward_rules 状态分布（NY 转发）
        $forwardStats = [];
        if (\Schema::hasTable('forward_rules')) {
            $forwardStats = DB::table('forward_rules')
                ->selectRaw('status, COUNT(*) as n')
                ->groupBy('status')
                ->pluck('n', 'status')
                ->toArray();
        }

        // xui_inbounds 状态分布（3x-ui 转发）
        $xuiStats = [];
        if (\Schema::hasTable('xui_inbounds')) {
            $xuiStats = DB::table('xui_inbounds')
                ->selectRaw('status, COUNT(*) as n')
                ->groupBy('status')
                ->pluck('n', 'status')
                ->toArray();
        }

        return $this->success([
            'jobs_pending' => $pending,
            'jobs_processing' => $reserved,
            'jobs_total' => $pending + $reserved,
            'failed_total' => $failed,
            'by_queue' => $byQueue,
            'forward_rules' => $forwardStats,
            'xui_inbounds' => $xuiStats,
        ]);
    }

    /**
     * GET /queue-monitor/failed
     *
     * 最近 50 条失败任务
     */
    public function failed(Request $request): JsonResponse
    {
        $rows = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit((int) $request->input('limit', 50))
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function ($row) {
                $payload = json_decode($row->payload, true);
                $row->job_class = $payload['displayName'] ?? '?';
                $row->data_summary = json_encode($payload['data']['command'] ?? '...', JSON_UNESCAPED_UNICODE);
                $row->exception_short = \Illuminate\Support\Str::limit($row->exception, 300);
                unset($row->payload, $row->exception);
                return $row;
            });

        return $this->success($rows);
    }

    /**
     * POST /queue-monitor/retry-all-failed
     *
     * 重试所有失败任务
     */
    public function retryAllFailed(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        if ($count === 0) {
            return $this->success(['retried' => 0], '无失败任务');
        }

        \Artisan::call('queue:retry', ['id' => ['all']]);

        return $this->success(['retried' => $count], "已重试 {$count} 条");
    }

    /**
     * POST /queue-monitor/flush-failed
     *
     * 清空所有失败任务
     */
    public function flushFailed(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();
        return $this->success(['flushed' => $count], "已清空 {$count} 条");
    }
}
