<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsTarget;
use App\Services\Dns\DnsFailoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 管理员：DNS 容灾监控配置
 */
class DnsMonitorController extends Controller
{
    // ========== Agents ==========

    public function agents(): JsonResponse
    {
        return $this->success(DnsAgent::orderByDesc('id')->get());
    }

    public function storeAgent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'location' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|integer|in:0,1',
        ]);
        $data['agent_key'] = Str::random(48);
        $data['is_active'] = $data['is_active'] ?? 1;
        $agent = DnsAgent::create($data);

        // 返回包含明文 key 的响应（仅此一次）
        $arr = $agent->toArray();
        $arr['agent_key'] = $data['agent_key'];
        return $this->success($arr, 'Agent 已创建，请保存 agent_key（仅此一次显示）');
    }

    public function deleteAgent(DnsAgent $dnsAgent): JsonResponse
    {
        $dnsAgent->delete();
        return $this->success(null, '已删除');
    }

    public function regenerateAgentKey(DnsAgent $dnsAgent): JsonResponse
    {
        $newKey = Str::random(48);
        $dnsAgent->update(['agent_key' => $newKey]);
        return $this->success(['agent_key' => $newKey], '已重置 key');
    }

    // ========== Targets ==========

    public function targets(Request $request): JsonResponse
    {
        $query = DnsTarget::with('xuiPanel:id,name,connect_host')
            ->orderByDesc('id');
        return $this->success($query->get());
    }

    public function storeTarget(Request $request): JsonResponse
    {
        $data = $this->validatedTarget($request);
        $target = DnsTarget::create($data);
        return $this->success($target, '已创建');
    }

    public function updateTarget(Request $request, DnsTarget $dnsTarget): JsonResponse
    {
        $data = $this->validatedTarget($request);
        // 编辑时如果 cf_api_token 为空表示不修改
        if (empty($data['cf_api_token'])) {
            unset($data['cf_api_token']);
        }
        $dnsTarget->update($data);
        return $this->success($dnsTarget->fresh(), '已保存');
    }

    public function deleteTarget(DnsTarget $dnsTarget): JsonResponse
    {
        $dnsTarget->delete();
        return $this->success(null, '已删除');
    }

    public function showTarget(DnsTarget $dnsTarget): JsonResponse
    {
        $dnsTarget->load(['xuiPanel:id,name,connect_host']);
        return $this->success($dnsTarget);
    }

    public function probeHistory(DnsTarget $dnsTarget, Request $request): JsonResponse
    {
        $limit = min(500, (int) $request->input('limit', 100));
        $results = $dnsTarget->probeResults()
            ->with('agent:id,name,location')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        return $this->success($results);
    }

    public function failoverHistory(DnsTarget $dnsTarget): JsonResponse
    {
        $events = $dnsTarget->failoverEvents()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        return $this->success($events);
    }

    // ========== Manual failover / failback ==========

    public function manualFailover(Request $request, DnsTarget $dnsTarget): JsonResponse
    {
        $reason = $request->input('reason') ?: '管理员手动切换';
        $result = app(DnsFailoverService::class)
            ->triggerFailover($dnsTarget, 'manual', $request->user()?->id, $reason);

        return $result['success']
            ? $this->success($dnsTarget->fresh(), $result['message'])
            : $this->error($result['message']);
    }

    public function manualFailback(Request $request, DnsTarget $dnsTarget): JsonResponse
    {
        $reason = $request->input('reason') ?: '管理员手动切回';
        $result = app(DnsFailoverService::class)
            ->triggerFailback($dnsTarget, 'manual', $request->user()?->id, $reason);

        return $result['success']
            ? $this->success($dnsTarget->fresh(), $result['message'])
            : $this->error($result['message']);
    }

    private function validatedTarget(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'xui_panel_id' => 'nullable|integer|exists:xui_panels,id',
            'cf_zone_id' => 'required|string|max:64',
            'cf_record_id' => 'required|string|max:64',
            'cf_record_name' => 'required|string|max:191',
            'cf_api_token' => 'nullable|string|max:500',
            'primary_ip' => 'required|ip',
            'backup_ip' => 'required|ip|different:primary_ip',
            'current_active' => 'nullable|string|in:primary,backup',
            'probe_port' => 'required|integer|min:1|max:65535',
            'probe_host' => 'nullable|string|max:191',
            'probe_interval_minutes' => 'nullable|integer|min:5|max:120',
            'failure_threshold' => 'nullable|integer|min:1|max:10',
            'probe_timeout_seconds' => 'nullable|integer|min:3|max:60',
            'probe_vless_url' => 'nullable|string|max:2000',
            'is_active' => 'nullable|integer|in:0,1',
        ]);
    }
}
