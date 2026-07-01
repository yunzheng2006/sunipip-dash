<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ip_assignment_logs.customer_id 改为可空。
 *
 * 原因：release 操作针对没有客户分配的 IP 时，customer_id 必然为空，
 * 但原表定义为 NOT NULL，导致释放失败。
 *
 * 用原生 SQL 避免 doctrine/dbal 依赖。保留 FK 约束。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 先删外键，再改列，再加回外键
        DB::statement("ALTER TABLE ip_assignment_logs DROP FOREIGN KEY ip_assignment_logs_customer_id_foreign");
        DB::statement("ALTER TABLE ip_assignment_logs MODIFY customer_id BIGINT UNSIGNED NULL");
        DB::statement("ALTER TABLE ip_assignment_logs ADD CONSTRAINT ip_assignment_logs_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE ip_assignment_logs DROP FOREIGN KEY ip_assignment_logs_customer_id_foreign");
        DB::statement("ALTER TABLE ip_assignment_logs MODIFY customer_id BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE ip_assignment_logs ADD CONSTRAINT ip_assignment_logs_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES customers(id)");
    }
};
