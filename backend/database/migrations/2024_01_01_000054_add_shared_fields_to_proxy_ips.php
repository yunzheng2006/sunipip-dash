<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->string('access_type', 20)->default('dedicated')->after('status')
                ->comment('dedicated/shared');
            $table->integer('shared_user_count')->default(0)->after('access_type')
                ->comment('当前共享用户数');
            $table->integer('max_shared_users')->default(1)->after('shared_user_count')
                ->comment('最大共享用户数');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropColumn(['access_type', 'shared_user_count', 'max_shared_users']);
        });
    }
};
