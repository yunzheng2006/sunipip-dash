<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * transactions.operated_by 改为可空。
 *
 * 原因：客户自助下单时没有对应的 admin user 操作人。
 * 客户自助流水的 operated_by = NULL 代表"客户自助"。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE transactions DROP FOREIGN KEY transactions_operated_by_foreign");
        DB::statement("ALTER TABLE transactions MODIFY operated_by BIGINT UNSIGNED NULL");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_operated_by_foreign FOREIGN KEY (operated_by) REFERENCES users(id) ON DELETE SET NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE transactions DROP FOREIGN KEY transactions_operated_by_foreign");
        DB::statement("ALTER TABLE transactions MODIFY operated_by BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_operated_by_foreign FOREIGN KEY (operated_by) REFERENCES users(id)");
    }
};
