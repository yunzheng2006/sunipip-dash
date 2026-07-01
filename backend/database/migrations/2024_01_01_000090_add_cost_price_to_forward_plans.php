<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `forward_plans` ADD COLUMN `cost_price` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '销售软成本' AFTER `base_price`");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `forward_plans` DROP COLUMN `cost_price`");
    }
};
