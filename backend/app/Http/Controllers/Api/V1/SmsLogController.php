<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SmsLog::query()->orderByDesc('id');

        if ($phone = $request->input('phone')) {
            $query->where('phone', 'like', "%{$phone}%");
        }
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $logs = $query->paginate($request->input('per_page', 30));

        return $this->success($logs);
    }

    public function stats(): JsonResponse
    {
        $today = now()->startOfDay();

        return $this->success([
            'today_total' => SmsLog::where('created_at', '>=', $today)->count(),
            'today_sent' => SmsLog::where('created_at', '>=', $today)->where('status', 'sent')->count(),
            'today_failed' => SmsLog::where('created_at', '>=', $today)->where('status', 'failed')->count(),
            'today_verified' => SmsLog::where('created_at', '>=', $today)->where('status', 'verified')->count(),
        ]);
    }
}
