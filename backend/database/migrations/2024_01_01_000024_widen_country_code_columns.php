<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 将 country_code 从 CHAR(2) 放宽为 VARCHAR(10)。
 *
 * 背景：Spark API 返回的是 ISO 3166-1 alpha-3 三字母代码（USA/BRA/JPN），
 * 而我方原设计 CHAR(2)。MySQL 在 STRICT 模式下插入 'USA' 会抛 "Data too long"，
 * 非 STRICT 模式下静默截断为 'US'，无论哪种都会导致 Spark 开通的 IP 没有正确的国家信息。
 *
 * 相关表：proxy_ips / ip_asset_groups / ip_groups / pricing_rules / provision_order_items
 */
return new class extends Migration
{
    public function up(): void
    {
        // 使用原生 SQL 避免 doctrine/dbal 依赖；CHAR(2) → VARCHAR(10)
        // 索引已经存在于 country_code 列上，ALTER MODIFY 会保留索引
        DB::statement("ALTER TABLE proxy_ips MODIFY country_code VARCHAR(10) NOT NULL COMMENT 'ISO 3166-1 alpha-2/3'");
        DB::statement("ALTER TABLE ip_asset_groups MODIFY country_code VARCHAR(10) NULL COMMENT 'ISO 3166-1 alpha-2/3'");
        DB::statement("ALTER TABLE ip_groups MODIFY country_code VARCHAR(10) NULL COMMENT '国家代码'");
        DB::statement("ALTER TABLE pricing_rules MODIFY country_code VARCHAR(10) NOT NULL");
        DB::statement("ALTER TABLE provision_order_items MODIFY country_code VARCHAR(10) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE proxy_ips MODIFY country_code CHAR(2) NOT NULL");
        DB::statement("ALTER TABLE ip_asset_groups MODIFY country_code CHAR(2) NULL");
        DB::statement("ALTER TABLE ip_groups MODIFY country_code CHAR(2) NULL");
        DB::statement("ALTER TABLE pricing_rules MODIFY country_code CHAR(2) NOT NULL");
        DB::statement("ALTER TABLE provision_order_items MODIFY country_code CHAR(2) NOT NULL");
    }
};
