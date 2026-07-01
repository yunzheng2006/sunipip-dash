<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('sms_expiry_notify')->default(true)->change();
        });

        DB::table('customers')
            ->where('sms_expiry_notify', false)
            ->update(['sms_expiry_notify' => true]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('sms_expiry_notify')->default(false)->change();
        });
    }
};
