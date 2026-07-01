<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('withdraw_bank_name', 100)->nullable()->after('balance');
            $table->string('withdraw_bank_account', 50)->nullable()->after('withdraw_bank_name');
            $table->string('withdraw_account_holder', 50)->nullable()->after('withdraw_bank_account');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['withdraw_bank_name', 'withdraw_bank_account', 'withdraw_account_holder']);
        });
    }
};
