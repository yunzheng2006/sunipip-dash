<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `manual_stat_entries` ADD COLUMN `hard_cost` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '手动硬成本' AFTER `sales_cost`");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `manual_stat_entries` DROP COLUMN `hard_cost`");
    }
};
