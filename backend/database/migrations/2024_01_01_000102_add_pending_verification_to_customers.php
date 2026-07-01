<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pending_biz_token', 100)->nullable()->after('verified_license_image');
            $table->string('pending_verify_name', 50)->nullable()->after('pending_biz_token');
            $table->string('pending_verify_id', 18)->nullable()->after('pending_verify_name');
            $table->timestamp('pending_verify_at')->nullable()->after('pending_verify_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['pending_biz_token', 'pending_verify_name', 'pending_verify_id', 'pending_verify_at']);
        });
    }
};
