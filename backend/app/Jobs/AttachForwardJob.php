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
                if ($sub) {
                    $this->recordLedger($sub, $updated);
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
        // price 连单月中转费都不到，不可能已包含（管理员手动分配 IP 售价 0 + 单独收中转费的场景，
        // 订阅和规则同时创建会误中下方的时间戳兜底，导致成交价一直显示 0）
        if ((float) $sub->price < (float) $rule->forward_fee) {
            return false;
        }

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

    /**
     * 业绩流水账：中转挂载行。
     * revenue：当期扣费的取扣款金额（挂载 ±10 分钟内的"中转费用"deduction）；
     * 打包购买的挂载 revenue=0（钱已计在购买行），只记中转成本。
     * months = 挂载时点到订阅到期的覆盖月数（事件当下的确定值）。
     */
    private function recordLedger($sub, ForwardRule $rule): void
    {
        // 幂等：同一规则只记一次（重试/重复挂载不重复记账）
        $exists = \Illuminate\Support\Facades\DB::table('performance_entries')
            ->where('event_type', \App\Services\PerformanceLedger::EVENT_FORWARD_ATTACH)
            ->where('forward_rule_id', $rule->id)
            ->exists();
        if ($exists || $sub->is_test) {
            return;
        }

        $plan = $rule->forward_plan_id
            ? \Illuminate\Support\Facades\DB::table('forward_plans')
                ->where('id', $rule->forward_plan_id)
                ->first(['cost_price', 'hard_cost_price'])
            : null;
        $costM = (float) ($plan->cost_price ?? 0);
        $hardM = (float) ($plan->hard_cost_price ?? $plan->cost_price ?? 0);

        $months = 0.1;
        if ($sub->expires_at && $sub->expires_at->isFuture()) {
            $months = max(round(now()->floatDiffInDays($sub->expires_at) / 30, 2), 0.1);
        }

        // 匹配挂载伴随的扣款：后台"中转费用（本期）订阅#X"或客户自助"升级视频专线 订阅#X"
        $feeTxn = \Illuminate\Support\Facades\DB::table('transactions')
            ->where('customer_id', $sub->customer_id)
            ->where('type', 'deduction')
            ->where('amount', '<', 0)
            ->where('description', 'like', "%订阅#{$sub->id}%")
            ->whereBetween('created_at', [
                $rule->created_at->copy()->subMinutes(10),
                $rule->created_at->copy()->addMinutes(10),
            ])
            ->first(['id', 'amount']);

        \App\Services\PerformanceLedger::record([
            'event_type' => \App\Services\PerformanceLedger::EVENT_FORWARD_ATTACH,
            'customer_id' => \App\Services\PerformanceLedger::attributionCustomerId($sub),
            'subscription_id' => $sub->id,
            'forward_rule_id' => $rule->id,
            'transaction_id' => $feeTxn->id ?? null,
            'revenue' => $feeTxn ? abs((float) $feeTxn->amount) : 0,
            'sales_cost' => $costM * $months,
            'hard_cost_fwd' => $hardM * $months,
            'months' => $months,
            'meta' => ['fee' => (float) $rule->forward_fee],
        ]);
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
