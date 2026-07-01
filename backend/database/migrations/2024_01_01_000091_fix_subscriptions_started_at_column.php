<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Remove ON UPDATE CURRENT_TIMESTAMP from started_at
        // This was causing started_at to auto-update whenever the row was modified
        DB::statement("ALTER TABLE `subscriptions` MODIFY COLUMN `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '开始时间'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `subscriptions` MODIFY COLUMN `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '开始时间'");
    }
};
