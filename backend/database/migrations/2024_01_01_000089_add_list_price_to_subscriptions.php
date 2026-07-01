<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `subscriptions` ADD COLUMN `list_price` DECIMAL(10,2) NULL COMMENT '官网原价' AFTER `price`");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `subscriptions` DROP COLUMN `list_price`");
    }
};
