<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\XuiInbound;
use App\Models\XuiPanel;
use App\Services\Xui\XuiApiException;
use App\Services\Xui\XuiApiService;
use App\Services\Xui\XuiForwardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XuiPanelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = XuiPanel::with(['mirror:id,name,api_url,connect_host,is_active'])
            ->withCount(['inbounds as active_inbounds_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->orderByDesc('id');

        // 默认展示全部；include_mirror=0 时排除备机
        if ($request->boolean('exclude_mirror')) {
            $query->where('is_mirror', 0);
        }

        return $this->success($query->get());
    }

    /**
     * 对业务员开放的面板列表（用于创建订单和批量转发）
     * GET /xui-panels/usable
     *
     * 只返回：启用中的主面板（is_mirror=0, is_active=1），
     * 不包含密码/密钥等敏感字段
     */
    public function usable(): JsonResponse
    {
        $panels = XuiPanel::where('is_active', 1)
            ->where('is_mirror', 0)
            ->orderBy('name')
            ->get(['id', 'name', 'connect_host', 'api_url', 'description']);

        return $this->success($panels);
    }

    public function show(XuiPanel $xuiPanel): JsonResponse
    {
        $xuiPanel->loadCount(['inbounds as active_inbounds_count' => function ($q) {
            $q->where('status', 'active');
        }]);
        return $this->success($xuiPanel);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (empty($data['password'])) {
            return $this->error('请输入密码', 422);
        }

        $panel = XuiPanel::create($data);
        return $this->success($panel, '3x-ui 面板已创建');
    }

    public function update(Request $request, XuiPanel $xuiPanel): JsonResponse
    {
        $data = $this->validated($request);
        // 编辑时留空表示不改密码
        if (empty($data['password'])) {
            unset($data['password']);
        }

        // 改了连接配置后清空旧 cookie
        if (
            isset($data['api_url']) && $data['api_url'] !== $xuiPanel->api_url
            || isset($data['username']) && $data['username'] !== $xuiPanel->username
            || isset($data['password'])
        ) {
            $data['session_cookie'] = null;
            $data['cookie_expires_at'] = null;
        }

        $xuiPanel->update($data);
        return $this->success($xuiPanel->fresh(), '已保存');
    }

    public function destroy(XuiPanel $xuiPanel): JsonResponse
    {
        if ($xuiPanel->inbounds()->where('status', 'active')->exists()) {
            return $this->error('存在活跃的中转记录，不能删除');
        }
        $xuiPanel->delete();
        return $this->success(null, '已删除');
    }

    /**
     * 测试连接：登录 + 拉一次 inbounds
     * POST /xui-panels/{xuiPanel}/test
     */
    public function testConnection(XuiPanel $xuiPanel): JsonResponse
    {
        try {
            $api = new XuiApiService($xuiPanel);
            $api->login();
            $inbounds = $api->listInbounds();

            return $this->success([
                'ok' => true,
                'inbound_count' => count($inbounds),
                'sample' => array_slice(array_map(fn($i) => [
                    'id' => $i['id'] ?? null,
                    'remark' => $i['remark'] ?? '',
                    'port' => $i['port'] ?? null,
                    'protocol' => $i['protocol'] ?? '',
                ], $inbounds), 0, 5),
            ], '连接成功');
        } catch (\Throwable $e) {
            return $this->error('连接失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 给指定 ProxyIp 创建一条 vless+reality 中转
     * POST /xui-panels/{xuiPanel}/create-forward
     *
     * Body: { proxy_ip_id: int, subscription_id?: int, remark?: string }
     */
    public function createForward(Request $request, XuiPanel $xuiPanel): JsonResponse
    {
        $data = $request->validate([
            'proxy_ip_id' => 'required|integer|exists:proxy_ips,id',
            'subscription_id' => 'nullable|integer|exists:subscriptions,id',
            'remark' => 'nullable|string|max:191',
        ]);

        $proxyIp = ProxyIp::find($data['proxy_ip_id']);
        $subscription = isset($data['subscription_id']) ? Subscription::find($data['subscription_id']) : null;

        try {
            $service = app(XuiForwardService::class);
            $record = $service->createForward($xuiPanel, $proxyIp, $subscription, $data['remark'] ?? null);

            return $this->success([
                'record' => $record->fresh(),
                'vless_url' => $record->buildVlessUrl(),
                'connect_host' => $xuiPanel->connect_host,
            ], '中转已创建');
        } catch (XuiApiException $e) {
            return $this->error('3x-ui: ' . $e->getMessage(), 500);
        } catch (\Throwable $e) {
            return $this->error('创建失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 批量创建中转（走队列）
     * POST /xui-panels/{xuiPanel}/batch-create-forward
     *
     * Body: { proxy_ip_ids: [1,2,3] }
     *
     * 为每条 IP 创建 pending XuiInbound 记录 + dispatch XuiCreateForwardJob，
     * 立即返回 batch_id，前端可轮询 /xui-panels/{xuiPanel}/batch-status/{batchId}
     */
    public function batchCreateForward(Request $request, XuiPanel $xuiPanel): JsonResponse
    {
        $data = $request->validate([
            'proxy_ip_ids' => 'required|array|min:1|max:1000',
            'proxy_ip_ids.*' => 'integer|exists:proxy_ips,id',
        ]);

        $batchId = (string) \Illuminate\Support\Str::uuid();
        $queuedIds = [];
        $skipped = [];

        foreach ($data['proxy_ip_ids'] as $ipId) {
            $proxyIp = ProxyIp::find($ipId);
            if (!$proxyIp) {
                $skipped[] = ['id' => $ipId, 'reason' => '源 IP 不存在'];
                continue;
            }
            if (!$proxyIp->ip_address || !$proxyIp->port) {
                $skipped[] = ['id' => $ipId, 'reason' => 'IP 信息不完整'];
                continue;
            }

            // 跳过已有 active 中转的 IP（防重复）
            $exists = XuiInbound::where('xui_panel_id', $xuiPanel->id)
                ->where('proxy_ip_id', $proxyIp->id)
                ->where('status', 'active')
                ->exists();
            if ($exists) {
                $skipped[] = ['id' => $ipId, 'reason' => '该面板已有此 IP 的活跃中转'];
                continue;
            }

            $remark = $proxyIp->asset_name ?: "{$proxyIp->country_name}-{$proxyIp->ip_address}";

            $record = XuiInbound::create([
                'xui_panel_id' => $xuiPanel->id,
                'proxy_ip_id' => $proxyIp->id,
                'subscription_id' => $proxyIp->activeSubscription?->id,
                'remark' => $remark,
                'protocol' => 'vless',
                'server_name' => 'www.intel.com',
                'status' => 'pending',
                'batch_id' => $batchId,
            ]);

            \App\Jobs\XuiCreateForwardJob::dispatch($record->id);
            $queuedIds[] = $record->id;
        }

        return $this->success([
            'batch_id' => $batchId,
            'queued_count' => count($queuedIds),
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
        ], sprintf(
            '已入队 %d 条（跳过 %d 条），worker 将逐条处理',
            count($queuedIds),
            count($skipped)
        ));
    }

    /**
     * 查询批次进度
     * GET /xui-panels/{xuiPanel}/batch-status/{batchId}
     */
    public function batchStatus(XuiPanel $xuiPanel, string $batchId): JsonResponse
    {
        $records = XuiInbound::where('xui_panel_id', $xuiPanel->id)
            ->where('batch_id', $batchId)
            ->with(['proxyIp:id,asset_name,ip_address,port'])
            ->get();

        if ($records->isEmpty()) {
            return $this->error('批次不存在', 404);
        }

        $byStatus = $records->groupBy('status')->map->count();
        $total = $records->count();
        $pending = (int) $byStatus->get('pending', 0);
        $processing = (int) $byStatus->get('processing', 0);
        $active = (int) $byStatus->get('active', 0);
        $failed = (int) $byStatus->get('failed', 0);

        $finished = ($pending + $processing) === 0;

        $failedRows = [];
        if ($failed > 0) {
            $failedRows = $records->where('status', 'failed')->map(fn($r) => [
                'id' => $r->id,
                'remark' => $r->remark,
                'asset' => $r->proxyIp?->asset_name,
                'error' => $r->error_message,
            ])->values();
        }

        return $this->success([
            'batch_id' => $batchId,
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'active' => $active,
            'failed' => $failed,
            'finished' => $finished,
            'progress_pct' => $total > 0 ? round(($active + $failed) * 100 / $total, 1) : 0,
            'failed_rows' => $failedRows,
        ]);
    }

    /**
     * 当前面板的中转列表
     * GET /xui-panels/{xuiPanel}/inbounds
     */
    public function listInbounds(XuiPanel $xuiPanel): JsonResponse
    {
        $rows = $xuiPanel->inbounds()
            ->with(['proxyIp:id,asset_name,ip_address,port,country_name', 'subscription:id,customer_id', 'subscription.customer:id,customer_name'])
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $rows->each(function ($row) {
            $row->vless_url = $row->buildVlessUrl();
        });

        return $this->success($rows);
    }

    /**
     * 删除单条中转
     * DELETE /xui-panels/inbounds/{xuiInbound}
     */
    public function deleteInbound(XuiInbound $xuiInbound): JsonResponse
    {
        try {
            app(XuiForwardService::class)->deleteForward($xuiInbound);
            return $this->success(null, '已删除');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage(), 500);
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'api_url' => 'required|string|url|max:500',
            'username' => 'required|string|max:100',
            'password' => 'nullable|string|max:200',
            'connect_host' => 'nullable|string|max:200',
            'is_active' => 'nullable|integer|in:0,1',
            'mirror_panel_id' => 'nullable|integer|exists:xui_panels,id',
            'is_mirror' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
        ]);
    }

    /**
     * 手动重试主→备同步
     * POST /xui-panels/inbounds/{xuiInbound}/resync-mirror
     */
    public function resyncMirror(XuiInbound $xuiInbound): JsonResponse
    {
        $panel = $xuiInbound->panel;
        if (!$panel || !$panel->mirror_panel_id) {
            return $this->error('该面板未配置备机', 422);
        }
        if ($xuiInbound->status !== 'active') {
            return $this->error('仅可对活跃中转重新同步', 422);
        }
        \App\Jobs\XuiSyncToMirrorJob::dispatch($xuiInbound->id, 'sync');
        return $this->success(null, '已重新入队同步');
    }

    /**
     * 把当前面板所有 active 但未同步/失败的 inbound 全量重新入队到备机
     * POST /xui-panels/{xuiPanel}/sync-all-to-mirror
     */
    public function syncAllToMirror(XuiPanel $xuiPanel): JsonResponse
    {
        if (!$xuiPanel->mirror_panel_id) {
            return $this->error('该面板未配置备机', 422);
        }

        $rows = $xuiPanel->inbounds()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('mirror_sync_status')
                  ->orWhereIn('mirror_sync_status', ['failed', 'pending']);
            })
            ->get();

        foreach ($rows as $row) {
            \App\Jobs\XuiSyncToMirrorJob::dispatch($row->id, 'sync');
        }

        return $this->success([
            'queued' => $rows->count(),
        ], "已入队 {$rows->count()} 条同步任务");
    }
}
