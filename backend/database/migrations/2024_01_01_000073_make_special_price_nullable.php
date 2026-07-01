<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * special_price 改为可空 —— 支持"只批准中转特批、不改 IP 价"的场景。
 * 业务层校验：special_price 和 forward_price 至少填一个
 *
 * 用原生 SQL 避开 doctrine/dbal 依赖（Laravel 11 + MySQL 8）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_special_prices MODIFY COLUMN special_price DECIMAL(10,2) NULL COMMENT 'IP 特批月单价；与 forward_price 至少填一个'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customer_special_prices MODIFY COLUMN special_price DECIMAL(10,2) NOT NULL");
    }
};
