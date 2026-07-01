<?php

namespace App\Services\Feishu;

use App\Models\FeishuSyncConfig;
use Illuminate\Support\Facades\Log;

/**
 * 飞书同步事件触发器
 *
 * 在任何影响客户 IP 的操作后调用 triggerForCustomer($customerId)，
 * 会找到该客户绑定的所有 active 飞书配置并执行同步。
 *
 * 调用是同步的（不走队列），因为飞书 API 很快（<5s for 1000条），
 * 且客户要求"操作后立刻在飞书看到变化"。
 */
class FeishuSyncTrigger
{
    /**
     * 触发指定客户的所有飞书同步
     *
     * @return int 触发的配置数量
     */
    public static function triggerForCustomer(int $customerId): int
    {
        $configs = FeishuSyncConfig::where('customer_id', $customerId)
            ->where('is_active', 1)
            ->get();

        if ($configs->isEmpty()) {
            return 0;
        }

        $service = app(FeishuSyncService::class);
        $triggered = 0;

        foreach ($configs as $config) {
            try {
                $service->sync($config);
                $triggered++;
            } catch (\Throwable $e) {
                Log::warning("FeishuSyncTrigger: sync config #{$config->id} failed: {$e->getMessage()}");
            }
        }

        return $triggered;
    }
}
