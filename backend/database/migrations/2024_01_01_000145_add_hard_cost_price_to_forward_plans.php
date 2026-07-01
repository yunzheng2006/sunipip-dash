<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `forward_plans` ADD COLUMN `hard_cost_price` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '硬成本（真实上游成本）' AFTER `cost_price`");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `forward_plans` DROP COLUMN `hard_cost_price`");
    }
};
