<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix expires_at column first (MySQL strict mode requires default for TIMESTAMP NOT NULL)
        DB::statement("ALTER TABLE `subscriptions` MODIFY COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE `subscriptions` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE `subscriptions` ADD COLUMN `test_reclaim_at` TIMESTAMP NULL DEFAULT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `subscriptions` DROP COLUMN `is_test`");
        DB::statement("ALTER TABLE `subscriptions` DROP COLUMN `test_reclaim_at`");
    }
};
