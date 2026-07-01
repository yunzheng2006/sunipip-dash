<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 修复：之前的回填只检查了 purchase 类型交易，漏掉了 deduction 类型
        // 将有 deduction 扣费交易但被错误标记为 balance_deducted=0 的订阅修正回 1
        DB::statement("
            UPDATE subscriptions s
            SET s.balance_deducted = 1
            WHERE s.balance_deducted = 0
              AND s.is_test = 0
              AND s.price > 0
              AND EXISTS (
                  SELECT 1 FROM transactions t
                  WHERE t.related_id = s.id
                    AND t.related_type LIKE '%Subscription'
                    AND t.type = 'deduction'
                    AND t.amount < 0
              )
        ");
    }

    public function down(): void
    {
        // 无法安全回滚
    }
};
