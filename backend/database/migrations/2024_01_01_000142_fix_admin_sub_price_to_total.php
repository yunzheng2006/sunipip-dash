<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Fix subscriptions where price stores monthly instead of total for the period.
        // Step 1: Admin subs (admin_set_price is the reliable monthly reference)
        $fixedAdmin = DB::update("
            UPDATE subscriptions
            SET price = ROUND(admin_set_price * CASE unit
                WHEN 1 THEN GREATEST(CEIL(duration / 30.0), 1)
                WHEN 2 THEN GREATEST(CEIL(duration * 7 / 30.0), 1)
                WHEN 3 THEN duration
                WHEN 4 THEN duration * 12
                ELSE 1
            END, 2)
            WHERE admin_set_price IS NOT NULL
              AND (
                  (unit = 3 AND duration > 1)
                  OR (unit = 4)
                  OR (unit = 1 AND duration > 30)
                  OR (unit = 2 AND duration > 4)
              )
        ");

        Log::info("fix_admin_sub_price_to_total: updated {$fixedAdmin} admin multi-month subscriptions");

        // Step 2: Customer multi-month subs that were renewed (renewOne stored monthly as price)
        // For these, price was divided by durationMonths during renewal. Multiply back.
        // Only fix subs where admin_set_price IS NULL (customer-created) and renewed_count > 0
        $fixedCustomer = DB::update("
            UPDATE subscriptions
            SET price = ROUND(price * CASE unit
                WHEN 1 THEN GREATEST(CEIL(duration / 30.0), 1)
                WHEN 2 THEN GREATEST(CEIL(duration * 7 / 30.0), 1)
                WHEN 3 THEN duration
                WHEN 4 THEN duration * 12
                ELSE 1
            END, 2)
            WHERE admin_set_price IS NULL
              AND renewed_count > 0
              AND (
                  (unit = 3 AND duration > 1)
                  OR (unit = 4)
                  OR (unit = 1 AND duration > 30)
                  OR (unit = 2 AND duration > 4)
              )
        ");

        Log::info("fix_admin_sub_price_to_total: updated {$fixedCustomer} customer renewed multi-month subscriptions");
    }

    public function down(): void
    {
    }
};
