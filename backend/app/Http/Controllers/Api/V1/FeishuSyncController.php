<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeishuSyncConfig;
use App\Services\Feishu\FeishuBitableService;
use App\Services\Feishu\FeishuSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeishuSyncController extends Controller
{
    public function index(): JsonResponse
    {
        $configs = FeishuSyncConfig::with('customer:id,customer_name')
            ->orderByDesc('id')
            ->get();
        return $this->success($configs);
    }

    public function show(FeishuSyncConfig $feishuSyncConfig): JsonResponse
    {
        $feishuSyncConfig->load('customer:id,customer_name');
        return $this->success($feishuSyncConfig);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $config = FeishuSyncConfig::create($data);
        return $this->success($config->load('customer:id,customer_name'), '已创建');
    }

    public function update(Request $request, FeishuSyncConfig $feishuSyncConfig): JsonResponse
    {
        $data = $this->validated($request);
        if (empty($data['app_secret'])) {
            unset($data['app_secret']);
        }
        if (isset($data['app_token']) && $data['app_token'] !== $feishuSyncConfig->app_token) {
            $data['real_app_token'] = null;
        }
        $feishuSyncConfig->update($data);
        return $this->success($feishuSyncConfig->fresh(), '已保存');
    }

    public function destroy(FeishuSyncConfig $feishuSyncConfig): JsonResponse
    {
        $feishuSyncConfig->delete();
        return $this->success(null, '已删除');
    }

    /**
     * 测试连接：验证飞书凭证 + 拉取表字段 + 自动检测 wiki token
     */
    public function testConnection(FeishuSyncConfig $feishuSyncConfig): JsonResponse
    {
        try {
            $api = new FeishuBitableService($feishuSyncConfig);
            $api->getToken(force: true);
            $fields = $api->listFields();

            $realToken = $api->detectRealAppToken();
            $isWikiToken = $realToken && $realToken !== $feishuSyncConfig->app_token;

            // 自动保存解析出的真实 token（QR 上传需要）
            if ($isWikiToken) {
                $feishuSyncConfig->update(['real_app_token' => $realToken]);
            } elseif (!$feishuSyncConfig->real_app_token) {
                $feishuSyncConfig->update(['real_app_token' => $feishuSyncConfig->app_token]);
            }

            $result = [
                'ok' => true,
                'field_count' => count($fields),
                'fields' => collect($fields)->map(fn($f) => [
                    'field_id' => $f['field_id'],
                    'field_name' => $f['field_name'],
                    'type' => $f['type'],
                    'ui_type' => $f['ui_type'] ?? null,
                ])->values(),
            ];

            $msg = '连接成功';
            if ($isWikiToken) {
                $result['warning'] = "检测到 Wiki token，已自动解析真实 bitable token: {$realToken}";
                $result['real_app_token'] = $realToken;
                $msg .= "。已自动设置真实 bitable token（二维码上传用）";
            }

            return $this->success($result, $msg);
        } catch (\Throwable $e) {
            return $this->error('连接失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 手动触发一次完整同步
     * POST /feishu-sync/{config}/sync
     */
    public function triggerSync(Request $request, FeishuSyncConfig $feishuSyncConfig): JsonResponse
    {
        $deleteOrphans = $request->boolean('delete_orphans', false);

        @set_time_limit(300);

        try {
            $service = app(FeishuSyncService::class);
            $result = $service->sync($feishuSyncConfig, $deleteOrphans);

            return $this->success($result, sprintf(
                '同步完成：创建 %d / 更新 %d / 未变 %d / 删除 %d',
                $result['created'], $result['updated'], $result['unchanged'], $result['deleted']
            ));
        } catch (\Throwable $e) {
            return $this->error('同步失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 预览：对比平台数据和飞书数据，不实际写入
     */
    public function preview(FeishuSyncConfig $feishuSyncConfig): JsonResponse
    {
        try {
            $api = new FeishuBitableService($feishuSyncConfig);
            $feishuRecords = $api->listAllRecords();

            // 平台侧数据
            $platformCount = \App\Models\ProxyIp::where('assigned_customer_id', $feishuSyncConfig->customer_id)
                ->where('status', 'assigned')
                ->count();

            return $this->success([
                'platform_count' => $platformCount,
                'feishu_count' => count($feishuRecords),
                'customer_name' => $feishuSyncConfig->customer?->customer_name,
            ]);
        } catch (\Throwable $e) {
            return $this->error('预览失败: ' . $e->getMessage(), 500);
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'customer_id' => 'required|integer|exists:customers,id',
            'app_id' => 'required|string|max:100',
            'app_secret' => 'nullable|string|max:500',
            'app_token' => 'required|string|max:100',
            'real_app_token' => 'nullable|string|max:100',
            'table_id' => 'required|string|max:100',
            'view_id' => 'nullable|string|max:100',
            'field_mapping' => 'nullable|array',
            'is_active' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
        ]);
    }
}
