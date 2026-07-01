<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::with('user:id,username,name')
            ->orderByDesc('created_at');

        // 筛选
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('username')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('username', 'like', "%{$request->username}%")
                  ->orWhere('name', 'like', "%{$request->username}%");
            });
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('ip_address')) {
            $query->where('ip_address', 'like', "%{$request->ip_address}%");
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }
        if ($request->filled('is_system')) {
            if ($request->is_system) {
                $query->whereNull('user_id');
            } else {
                $query->whereNotNull('user_id');
            }
        }

        $paginator = $query->paginate($request->input('per_page', 30));

        return $this->paginated($paginator);
    }

    public function show(ActivityLog $activityLog): JsonResponse
    {
        $activityLog->load('user:id,username,name');
        return $this->success($activityLog);
    }

    /**
     * 清理N天前的日志
     */
    public function clean(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 90);
        $cutoff = now()->subDays($days);

        $count = ActivityLog::where('created_at', '<', $cutoff)->delete();

        ActivityLog::system('log.clean', "清理了 {$count} 条 {$days} 天前的日志");

        return $this->success(['deleted' => $count], "已清理 {$count} 条日志");
    }
}
