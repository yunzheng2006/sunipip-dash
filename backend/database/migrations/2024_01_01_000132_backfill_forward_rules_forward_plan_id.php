<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 回填 forward_rules.forward_plan_id：通过 device_group_id + module 匹配
        DB::statement("
            UPDATE forward_rules fr
            INNER JOIN subscriptions s ON s.id = fr.subscription_id
            INNER JOIN forward_plans fp
                ON fp.device_group_id = fr.ny_device_group_id
                AND fp.type = 'ny'
                AND fp.module = s.purchased_module
            SET fr.forward_plan_id = fp.id
            WHERE fr.forward_plan_id IS NULL
              AND s.purchased_module IS NOT NULL
        ");

        // 兜底：purchased_module 为空时，按 device_group_id 匹配（取最小 plan id）
        DB::statement("
            UPDATE forward_rules fr
            INNER JOIN (
                SELECT device_group_id, MIN(id) as plan_id
                FROM forward_plans
                WHERE type = 'ny'
                GROUP BY device_group_id
            ) fp ON fp.device_group_id = fr.ny_device_group_id
            SET fr.forward_plan_id = fp.plan_id
            WHERE fr.forward_plan_id IS NULL
        ");
    }

    public function down(): void
    {
        // 无法安全回退回填操作
    }
};
