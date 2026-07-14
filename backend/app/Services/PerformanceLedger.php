<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 业绩流水账写入口（performance_entries）。
 *
 * 原则：
 *   1. 在资金变动的同一个业务动作里调用（同事务内最佳），事件当下把
 *      确定值写下来——绝不留给统计层事后推理。
 *   2. 行只增不改；逆向操作（退款/降级退差价/佣金冲销）写负数行。
 *   3. 记账失败只记 ERROR 日志、不阻断业务（可通过 transactions 对账修补）。
 *
 * 用法：
 *   PerformanceLedger::record([
 *       'event_type' => 'renew',
 *       'customer_id' => $sub->customer_id,
 *       'subscription_id' => $sub->id,
 *       'transaction_id' => $txn->id,
 *       'revenue' => 300,
 *       'sales_cost' => 46, 'hard_cost_ip' => 38, 'hard_cost_fwd' => 16,
 *       'months' => 2,
 *   ]);
 */
class PerformanceLedger
{
    public const EVENT_PURCHASE = 'purchase';
    public const EVENT_RENEW = 'renew';
    public const EVENT_FORWARD_ATTACH = 'forward_attach';
    public const EVENT_DOWNGRADE_REFUND = 'downgrade_refund';
    public const EVENT_REFUND = 'refund';
    public const EVENT_CONVERT = 'convert';
    public const EVENT_COMMISSION = 'commission';
    public const EVENT_COMMISSION_REVERSAL = 'commission_reversal';

    public static function record(array $e): void
    {
        try {
            $customerId = (int) $e['customer_id'];

            $salesPerson = $e['sales_person']
                ?? DB::table('customers')->where('id', $customerId)->value('sales_person');

            DB::table('performance_entries')->insert([
                'event_type' => $e['event_type'],
                'customer_id' => $customerId,
                'sales_person' => $salesPerson,
                'subscription_id' => $e['subscription_id'] ?? null,
                'forward_rule_id' => $e['forward_rule_id'] ?? null,
                'transaction_id' => $e['transaction_id'] ?? null,
                'revenue' => round((float) ($e['revenue'] ?? 0), 2),
                'commission' => round((float) ($e['commission'] ?? 0), 2),
                'sales_cost' => round((float) ($e['sales_cost'] ?? 0), 2),
                'hard_cost_ip' => round((float) ($e['hard_cost_ip'] ?? 0), 2),
                'hard_cost_fwd' => round((float) ($e['hard_cost_fwd'] ?? 0), 2),
                'months' => isset($e['months']) ? round((float) $e['months'], 2) : null,
                'is_test' => (bool) ($e['is_test'] ?? false),
                'occurred_at' => $e['occurred_at'] ?? now(),
                'meta' => isset($e['meta']) ? json_encode($e['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $ex) {
            Log::error('PerformanceLedger: 记账失败（业务未受影响，需人工对账修补）', [
                'entry' => $e,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * 业绩归属客户：转移来的订阅归原客户（与统计口径 COALESCE(transferred_from, customer) 一致）
     */
    public static function attributionCustomerId($subscription): int
    {
        return (int) ($subscription->transferred_from_customer_id ?: $subscription->customer_id);
    }
}
