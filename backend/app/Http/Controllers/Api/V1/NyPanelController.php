<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NyDeviceGroup;
use App\Models\NyPanel;
use App\Services\Ny\NyApiException;
use App\Services\Ny\NyApiService;
use App\Services\Ny\NyForwardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理后台 - NY 面板配置 + 设备组管理
 */
class NyPanelController extends Controller
{
    public function __construct(protected NyForwardService $forwardService) {}

    public function index(): JsonResponse
    {
        $panels = NyPanel::orderByDesc('id')
            ->withCount([
                'deviceGroups',
                'deviceGroups as enabled_device_groups_count' => fn($q) => $q->where('is_enabled', 1),
                'forwardRules as active_rules_count' => fn($q) => $q->where('status', 'active'),
            ])
            ->get();

        return $this->success($panels);
    }

    public function show(NyPanel $nyPanel): JsonResponse
    {
        $nyPanel->load(['deviceGroups' => fn($q) => $q->orderBy('remote_id')]);
        return $this->success($nyPanel);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'api_url' => 'required|url|max:500',
            'username' => 'required|string|max:100',
            'password' => 'required|string|max:200',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
        ]);

        $panel = NyPanel::create([
            'name' => $data['name'],
            'api_url' => rtrim($data['api_url'], '/'),
            'username' => $data['username'],
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? 1,
            'description' => $data['description'] ?? null,
        ]);

        return $this->success($panel, '面板已创建');
    }

    public function update(Request $request, NyPanel $nyPanel): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'api_url' => 'required|url|max:500',
            'username' => 'required|string|max:100',
            'password' => 'nullable|string|max:200',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
        ]);

        $updates = [
            'name' => $data['name'],
            'api_url' => rtrim($data['api_url'], '/'),
            'username' => $data['username'],
            'is_active' => $data['is_active'] ?? 1,
            'description' => $data['description'] ?? null,
        ];

        // 密码留空视为不修改
        if (!empty($data['password'])) {
            $updates['password'] = $data['password'];
            // 密码变了，token 失效
            $updates['last_token'] = null;
            $updates['token_expires_at'] = null;
        }

        $nyPanel->update($updates);

        return $this->success($nyPanel->fresh(), '已更新');
    }

    public function destroy(NyPanel $nyPanel): JsonResponse
    {
        if ($nyPanel->forwardRules()->where('status', 'active')->exists()) {
            return $this->error('该面板下仍有 active 转发规则，请先清空');
        }
        $nyPanel->delete();
        return $this->success(null, '已删除');
    }

    /**
     * POST /ny-panels/{nyPanel}/test
     * 测试登录 + 返回用户信息
     */
    public function testConnection(NyPanel $nyPanel): JsonResponse
    {
        try {
            $api = new NyApiService($nyPanel);
            $api->login();
            $info = $api->getUserInfo();
            return $this->success([
                'connected' => true,
                'user' => [
                    'username' => $info['username'] ?? null,
                    'group_name' => $info['group_name'] ?? null,
                    'plan_name' => $info['plan_name'] ?? null,
                    'max_rules' => $info['max_rules'] ?? null,
                    'traffic_enable' => $info['traffic_enable'] ?? null,
                    'traffic_used' => $info['traffic_used'] ?? null,
                ],
            ], '连接成功');
        } catch (NyApiException $e) {
            return $this->error('连接失败: ' . $e->getMessage(), 422);
        }
    }

    /**
     * POST /ny-panels/{nyPanel}/sync-device-groups
     */
    public function syncDeviceGroups(NyPanel $nyPanel): JsonResponse
    {
        try {
            $result = $this->forwardService->syncDeviceGroups($nyPanel);
            $nyPanel->load(['deviceGroups' => fn($q) => $q->orderBy('remote_id')]);
            return $this->success([
                'synced' => $result['synced'],
                'device_groups' => $nyPanel->deviceGroups,
            ], "已同步 {$result['synced']} 个设备组");
        } catch (NyApiException $e) {
            return $this->error('同步失败: ' . $e->getMessage(), 422);
        }
    }

    /**
     * PUT /ny-panels/{nyPanel}/device-groups
     * 批量更新设备组的 is_enabled + custom_connect_host
     *
     * Body: { items: [{ id, is_enabled, custom_connect_host }, ...] }
     */
    public function updateDeviceGroups(Request $request, NyPanel $nyPanel): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:ny_device_groups,id',
            'items.*.is_enabled' => 'required|boolean',
            'items.*.custom_connect_host' => 'nullable|string|max:255',
        ]);

        $updated = 0;
        foreach ($data['items'] as $item) {
            $row = NyDeviceGroup::where('id', $item['id'])
                ->where('ny_panel_id', $nyPanel->id)
                ->first();
            if (!$row) continue;
            $row->update([
                'is_enabled' => $item['is_enabled'] ? 1 : 0,
                'custom_connect_host' => $item['custom_connect_host'] ?? null,
            ]);
            $updated++;
        }

        return $this->success(['updated' => $updated], '已保存');
    }

    /**
     * GET /ny-panels/enabled-device-groups
     * 供创建订单页用：返回所有 active 面板下启用的设备组
     */
    public function enabledDeviceGroups(): JsonResponse
    {
        $groups = NyDeviceGroup::with('panel:id,name,is_active')
            ->whereHas('panel', fn($q) => $q->where('is_active', 1))
            ->where('is_enabled', 1)
            ->orderBy('ny_panel_id')
            ->orderBy('remote_id')
            ->get();

        return $this->success($groups);
    }
}
