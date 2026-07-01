<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\WebhookConfig;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(protected NotificationService $notifier) {}

    /**
     * Webhook 列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = WebhookConfig::query()->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return $this->success($query->get());
    }

    /**
     * 支持的事件字典（给前端渲染用）
     */
    public function events(): JsonResponse
    {
        return $this->success(NotificationService::EVENTS);
    }

    public function show(WebhookConfig $webhook): JsonResponse
    {
        return $this->success($webhook);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $webhook = WebhookConfig::create($data);
        return $this->success($webhook, 'Webhook 创建成功');
    }

    public function update(Request $request, WebhookConfig $webhook): JsonResponse
    {
        $data = $this->validated($request);
        $webhook->update($data);
        return $this->success($webhook, 'Webhook 更新成功');
    }

    public function destroy(WebhookConfig $webhook): JsonResponse
    {
        $webhook->delete();
        return $this->success(null, '已删除');
    }

    /**
     * 发送测试通知
     */
    public function test(WebhookConfig $webhook): JsonResponse
    {
        $log = $this->notifier->sendToWebhook($webhook, 'subscription_expiring', [
            'title' => '【测试】Webhook 通知测试',
            'content' => "这是一条来自 **SuniPIP** 管理后台的测试消息。\n\n"
                . "> 如果你在群里看到这条消息，说明 webhook 配置成功 ✅\n\n"
                . "时间：" . now()->format('Y-m-d H:i:s'),
            'dedup_key' => 'test_' . time(),
        ]);

        if ($log->status === 'sent') {
            return $this->success(['log_id' => $log->id], '测试消息发送成功');
        }
        return $this->error('发送失败：' . $log->response, 500);
    }

    /**
     * 通知日志列表
     */
    public function logs(Request $request): JsonResponse
    {
        $query = NotificationLog::with('webhookConfig:id,name,type')
            ->orderByDesc('id');

        if ($request->filled('webhook_config_id')) {
            $query->where('webhook_config_id', $request->webhook_config_id);
        }
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }

    /**
     * 字段验证 + events 结构校验
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:wechat_work,dingtalk,custom',
            'webhook_url' => 'required|url|max:500',
            'secret_key' => 'nullable|string|max:255',
            'events' => 'required|array',
            'is_active' => 'nullable|boolean',
        ]);

        // 过滤 events：只保留已知 key
        $known = array_keys(NotificationService::EVENTS);
        $data['events'] = array_intersect_key(
            $data['events'],
            array_flip($known)
        );

        $data['is_active'] = $data['is_active'] ?? 1;
        return $data;
    }
}
