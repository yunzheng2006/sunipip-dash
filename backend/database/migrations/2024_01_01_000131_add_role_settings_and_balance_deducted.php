<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('guard_name');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('balance_deducted')->default(true)->after('created_by');
        });

        // 回填：没有对应扣费交易的订阅标记为未扣余额
        // 检查 purchase 和 deduction 两种扣费类型
        DB::statement("
            UPDATE subscriptions s
            SET s.balance_deducted = 0
            WHERE s.is_test = 0
              AND s.price > 0
              AND s.balance_deducted = 1
              AND NOT EXISTS (
                  SELECT 1 FROM transactions t
                  WHERE t.related_id = s.id
                    AND t.related_type LIKE '%Subscription'
                    AND t.type IN ('purchase', 'deduction')
                    AND t.amount < 0
              )
              AND NOT EXISTS (
                  SELECT 1 FROM transactions t
                  WHERE t.customer_id = s.customer_id
                    AND t.type IN ('purchase', 'deduction')
                    AND t.amount < 0
                    AND t.created_at BETWEEN DATE_SUB(s.created_at, INTERVAL 5 MINUTE)
                                          AND DATE_ADD(s.created_at, INTERVAL 5 MINUTE)
              )
        ");
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('balance_deducted');
        });
    }
};
