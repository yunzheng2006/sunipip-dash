<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Migration 000137 was too broad: it set balance_deducted=true for all admin subs
        // regardless of actual payment method. Correct rule: balance_deducted=true ONLY when
        // customer actually paid from balance (customer self-service OR admin balance deduction).

        // Step 1: Reset admin-created subs to balance_deducted=false
        // (admin_set_price IS NOT NULL identifies admin-created subs)
        $reset = DB::table('subscriptions')
            ->whereNotNull('admin_set_price')
            ->where('balance_deducted', true)
            ->update(['balance_deducted' => false]);

        Log::info("fix_balance_deducted_v2: reset {$reset} admin subs to balance_deducted=false");

        // Step 2: Set balance_deducted=true for admin subs linked to spark_orders
        // with payment_method=balance in request_data
        // Join path: subscriptions.proxy_ip_id → spark_instances.proxy_ip_id → spark_orders.request_data
        $sparkFixed = DB::update("
            UPDATE subscriptions s
            INNER JOIN spark_instances si ON si.proxy_ip_id = s.proxy_ip_id
            INNER JOIN spark_orders so ON so.id = si.spark_order_id
            SET s.balance_deducted = 1
            WHERE s.admin_set_price IS NOT NULL
              AND s.balance_deducted = 0
              AND JSON_UNQUOTE(JSON_EXTRACT(so.request_data, '$.payment_method')) = 'balance'
        ");

        Log::info("fix_balance_deducted_v2: set {$sparkFixed} admin subs via spark_orders.payment_method=balance");

        // Step 3: For any remaining admin subs not linked via Spark, check for matching
        // unlinked purchase transactions (same customer, within 5 minutes of started_at)
        $txnFixed = DB::update("
            UPDATE subscriptions s
            SET s.balance_deducted = 1
            WHERE s.admin_set_price IS NOT NULL
              AND s.balance_deducted = 0
              AND s.is_test = 0
              AND EXISTS (
                  SELECT 1 FROM transactions t
                  WHERE t.customer_id = s.customer_id
                    AND t.type = 'purchase'
                    AND t.amount < 0
                    AND t.related_id IS NULL
                    AND t.description LIKE '开通订单扣费%'
                    AND ABS(TIMESTAMPDIFF(SECOND, t.created_at, s.started_at)) <= 300
              )
        ");

        Log::info("fix_balance_deducted_v2: set {$txnFixed} admin subs via matching purchase transactions");
    }

    public function down(): void
    {
    }
};
