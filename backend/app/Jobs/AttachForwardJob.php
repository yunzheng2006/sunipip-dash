<?php

namespace App\Jobs;

use App\Models\ForwardRule;
use App\Services\Ny\NyApiRateLimitException;
use App\Services\Ny\NyForwardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 批量转发队列任务 - 处理单条 ForwardRule
 *
 * 调用方式：
 *   AttachForwardJob::dispatch($forwardRuleId);
 *
 * 重试策略：
 *   - 普通错误：failed 后不再重试（processRule 已记录错误）
 *   - 429 限流：最多 5 次，指数退避 (10s, 20s, 30s, 45s, 60s)
 */
class AttachForwardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 最大重试次数（包含首次执行） */
    public int $tries = 5;

    /** 单次执行最长 90 秒 */
    public int $timeout = 90;

    /**
     * 动态退避：第 N 次失败后等多久再重试
     * 第一次失败 → 等 10s；第二次 → 20s；以此类推
     */
    public function backoff(): array
    {
        return [10, 20, 30, 45, 60];
    }

    public function __construct(public int $forwardRuleId) {}

    public function handle(NyForwardService $service): void
    {
        $rule = ForwardRule::find($this->forwardRuleId);
        if (!$rule) {
            Log::warning("AttachForwardJob: rule #{$this->forwardRuleId} not found");
            return;
        }

        // 幂等：已经 active/deleted 的跳过（failed 允许重试时会重新拾起）
        if (in_array($rule->status, ['active', 'deleted'], true)) {
            return;
        }

        // 复位 failed → processing 让 processRule 继续处理
        if ($rule->status === 'failed') {
            $rule->update(['status' => 'pending']);
        }

        try {
            $updated = $service->processRule($rule);

            if ($updated->status === 'active' && (float) $updated->forward_fee > 0) {
                $sub = $updated->subscription;
                if ($sub && !$this->feeAlreadyInPrice($sub, $updated)) {
                    $sub->increment('price', (float) $updated->forward_fee);
                }
            }
        } catch (NyApiRateLimitException|LockTimeoutException $e) {
            $reason = $e instanceof NyApiRateLimitException ? '被限流' : '锁超时';
            Log::warning("AttachForwardJob #{$this->forwardRuleId} {$reason}, will retry");
            $rule->update([
                'status' => 'pending',
                'error_message' => "{$reason}，等待重试 (attempt {$this->attempts()}/{$this->tries})",
            ]);
            $this->release(
                $this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)] ?? 60
            );
            return;
        } catch (\Throwable $e) {
            // 其他错误：processRule 已经标 failed，不再重试
            $msg = $e->getMessage() ?: get_class($e);
            Log::warning("AttachForwardJob #{$this->forwardRuleId}: {$msg}", [
                'exception' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 300),
            ]);
        }
    }

    /**
     * 判断中转费是否已包含在 subscription.price 中（是则跳过 increment 避免重复加价）
     *
     * 判据（数据本身，而非时钟）：
     * 1. 该订阅的 SparkOrder 下单时带 forward_plan_id → 购买时 price 已含中转费
     * 2. 且当前规则是该订阅的首条转发规则（降级后重新升级的规则须正常加价）
     * 兜底：查不到订单时退回"订阅与规则同时创建（60秒内）"的时间戳判断
     */
    private function feeAlreadyInPrice($sub, ForwardRule $rule): bool
    {
        $isInitialRule = !ForwardRule::where('subscription_id', $sub->id)
            ->where('id', '<', $rule->id)
            ->exists();

        if ($isInitialRule && $sub->proxy_ip_id) {
            $orderFwdPlan = \Illuminate\Support\Facades\DB::table('spark_instances')
                ->join('spark_orders', 'spark_orders.id', '=', 'spark_instances.spark_order_id')
                ->where('spark_instances.proxy_ip_id', $sub->proxy_ip_id)
                ->value('spark_orders.request_data');
            if ($orderFwdPlan !== null) {
                $rd = json_decode($orderFwdPlan, true);
                return !empty($rd['forward_plan_id']);
            }
        }

        // 兜底：同时创建视为购买时已含中转费
        return $isInitialRule
            && abs(strtotime($sub->created_at) - strtotime($rule->created_at)) < 60;
    }

    public function failed(\Throwable $exception): void
    {
        $rule = ForwardRule::find($this->forwardRuleId);
        if ($rule && in_array($rule->status, ['pending', 'processing'], true)) {
            $rule->update([
                'status' => 'failed',
                'error_message' => '任务最终失败: ' . substr($exception->getMessage(), 0, 400),
            ]);
        }
    }
}
