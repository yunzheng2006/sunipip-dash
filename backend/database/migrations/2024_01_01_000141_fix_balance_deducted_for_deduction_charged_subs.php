<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Fix subs charged via deduction transactions (测试转正, etc.)
        // that have balance_deducted=false despite actual balance deduction.
        $fixed = DB::update("
            UPDATE subscriptions s
            SET s.balance_deducted = 1
            WHERE s.balance_deducted = 0
              AND s.is_test = 0
              AND EXISTS (
                  SELECT 1 FROM transactions t
                  WHERE t.related_id = s.id
                    AND t.related_type = 'App\\\\Models\\\\Subscription'
                    AND t.type = 'deduction'
                    AND t.amount < 0
              )
        ");

        Log::info("fix_balance_deducted_deduction: set balance_deducted=true for {$fixed} subs with deduction transactions");
    }

    public function down(): void
    {
    }
};
