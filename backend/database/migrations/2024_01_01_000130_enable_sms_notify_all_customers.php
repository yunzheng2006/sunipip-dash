<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->where('sms_expiry_notify', false)
            ->update(['sms_expiry_notify' => true]);
    }

    public function down(): void
    {
        // 不可逆
    }
};
