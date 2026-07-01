<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')->where('type', 'subscription_purchase')->update(['type' => 'purchase']);
        DB::table('transactions')->where('type', 'subscription_renew')->update(['type' => 'renew']);
        DB::table('transactions')->where('type', 'withdraw')->update(['type' => 'withdrawal']);
        DB::table('transactions')->where('type', 'adjustment')->update(['type' => 'adjustment_out']);
    }

    public function down(): void
    {
        // 不可逆 — 无法区分原始来源
    }
};
