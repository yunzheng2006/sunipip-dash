<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feishu_sync_configs', function (Blueprint $table) {
            $table->string('real_app_token', 100)->nullable()
                ->after('app_token')
                ->comment('Wiki 嵌入时解析出的真实 bitable token（文件上传用）');
        });
    }

    public function down(): void
    {
        Schema::table('feishu_sync_configs', function (Blueprint $table) {
            $table->dropColumn('real_app_token');
        });
    }
};
