<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\WebhookConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 通知分发服务
 *
 * 使用方式：
 *   app(NotificationService::class)->dispatch('subscription_expiring', [
 *       'title' => 'IP即将到期提醒',
 *       'content' => '...',           // Markdown 内容
 *       'related_type' => 'Subscription',
 *       'related_id' => $sub->id,
 *       'dedup_key' => "sub_exp_{$sub->id}_7d",  // 可选，用于去重
 *   ]);
 *
 * 事件键对应 webhook_configs.events 的 key。每个 webhook 订阅一部分事件。
 */
class NotificationService
{
    /**
     * 所有支持的事件（用于前端渲染勾选表 + 文档）
     */
    public const EVENTS = [
        'subscription_expiring' => [
            'label' => '订阅即将到期',
            'desc' => '客户订阅在指定天数内到期时触发',
            'has_days' => true,   // 前端展示"提前几天"配置
            'default_days' => [7, 3, 1],
        ],
        'subscription_expired' => [
            'label' => '订阅已到期',
            'desc' => '订阅到期未续费',
        ],
        'subscription_created' => [
            'label' => '订阅创建',
            'desc' => '新订阅开通',
        ],
        'subscription_renewed' => [
            'label' => '订阅续费',
            'desc' => '续费成功',
        ],
        'subscription_refunded' => [
            'label' => '订阅退订',
            'desc' => '客户申请退订',
        ],
        'customer_low_balance' => [
            'label' => '客户余额不足',
            'desc' => '客户余额低于阈值',
            'has_threshold' => true,
            'default_threshold' => 50,
        ],
        'customer_topup' => [
            'label' => '客户充值',
            'desc' => '客户账户充值',
        ],
        'ip_released' => [
            'label' => 'IP资产释放',
            'desc' => '任何原因导致的 IP 资产释放',
        ],
        'spark_order_failed' => [
            'label' => 'Spark开单失败',
            'desc' => '调用 Spark API 下单失败',
        ],
        'spark_order_stuck' => [
            'label' => 'Spark订单超时',
            'desc' => '订单提交后超过指定时间仍未开通完成',
        ],
        'dns_failover' => [
            'label' => 'DNS 切换到备机',
            'desc' => '主机被墙或不可达，自动切换到备机',
        ],
        'dns_failback' => [
            'label' => 'DNS 切回主机',
            'desc' => '主机恢复，已切回',
        ],
        'dns_probe_failed' => [
            'label' => 'DNS 探测失败',
            'desc' => '单次探测失败（未到切换阈值）',
        ],
        'approval_submitted' => [
            'label' => '审批提交',
            'desc' => '新的审批申请（开通订单/提现/认证等）',
        ],
        'withdrawal_request' => [
            'label' => '提现申请',
            'desc' => '客户申请提现返佣余额',
        ],
    ];

    /**
     * 分发事件到所有订阅了该事件的 webhook
     */
    public function dispatch(string $eventType, array $payload): void
    {
        if (!array_key_exists($eventType, self::EVENTS)) {
            Log::warning("Unknown notification event: {$eventType}");
            return;
        }

        $webhooks = WebhookConfig::where('is_active', 1)->get();

        foreach ($webhooks as $webhook) {
            $config = $webhook->events[$eventType] ?? null;
            if (!$config || empty($config['enabled'])) {
                continue;
            }

            // 去重：同一 webhook + 同一 dedup_key 24 小时内只发一次
            if (!empty($payload['dedup_key'])) {
                $alreadySent = NotificationLog::where('webhook_config_id', $webhook->id)
                    ->where('event_type', $eventType)
                    ->where('status', 'sent')
                    ->where('response', 'like', '%' . $payload['dedup_key'] . '%')
                    ->where('created_at', '>=', now()->subHours(24))
                    ->exists();
                if ($alreadySent) {
                    continue;
                }
            }

            $this->sendToWebhook($webhook, $eventType, $payload);
        }
    }

    /**
     * 向单个 webhook 发送通知
     */
    public function sendToWebhook(WebhookConfig $webhook, string $eventType, array $payload): NotificationLog
    {
        $log = NotificationLog::create([
            'webhook_config_id' => $webhook->id,
            'event_type' => $eventType,
            'channel' => $webhook->type,
            'title' => $payload['title'] ?? self::EVENTS[$eventType]['label'] ?? $eventType,
            'content' => $payload['content'] ?? '',
            'related_type' => $payload['related_type'] ?? null,
            'related_id' => $payload['related_id'] ?? null,
            'status' => 'pending',
        ]);

        try {
            $body = $this->buildMessageBody($webhook->type, $payload);
            $response = Http::timeout(10)->post($webhook->webhook_url, $body);

            $responseBody = $response->body();
            $isOk = $response->successful() && $this->isResponseOk($webhook->type, $response->json() ?? []);

            $log->update([
                'status' => $isOk ? 'sent' : 'failed',
                'response' => ($payload['dedup_key'] ?? '') . ' | ' . substr($responseBody, 0, 500),
                'sent_at' => $isOk ? now() : null,
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'response' => 'Exception: ' . $e->getMessage(),
            ]);
            Log::error("Webhook send failed: {$e->getMessage()}", ['webhook_id' => $webhook->id]);
        }

        return $log;
    }

    /**
     * 构造消息体（按渠道类型适配）
     */
    protected function buildMessageBody(string $type, array $payload): array
    {
        $title = $payload['title'] ?? '通知';
        $content = $payload['content'] ?? '';

        return match ($type) {
            'wechat_work' => [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => "### {$title}\n\n{$content}",
                ],
            ],
            'dingtalk' => [
                'msgtype' => 'markdown',
                'markdown' => [
                    'title' => $title,
                    'text' => "### {$title}\n\n{$content}",
                ],
            ],
            default => [
                'title' => $title,
                'content' => $content,
                'event' => $payload['event_type'] ?? null,
                'related' => [
                    'type' => $payload['related_type'] ?? null,
                    'id' => $payload['related_id'] ?? null,
                ],
            ],
        };
    }

    /**
     * 各渠道成功响应判断
     */
    protected function isResponseOk(string $type, array $data): bool
    {
        return match ($type) {
            'wechat_work' => ($data['errcode'] ?? -1) === 0,
            'dingtalk' => ($data['errcode'] ?? -1) === 0,
            default => true,
        };
    }
}
