<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修复 sms_providers.config 列类型
 * json → text，因为 encrypted:array cast 存的是加密字符串不是合法 JSON
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            $table->text('config')->comment('encrypted JSON')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            $table->json('config')->comment('access_key_id, access_key_secret, sign_name, template_code etc')->change();
        });
    }
};
