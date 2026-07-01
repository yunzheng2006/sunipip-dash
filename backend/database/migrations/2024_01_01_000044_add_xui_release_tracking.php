<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * xui_inbounds 增加释放追踪字段
 *
 *   release_status:
 *     NULL         = 未发起
 *     confirmed    = 已从 3x-ui 面板确认移除
 *     failed       = 尝试过但失败，需要重试或人工核对
 *
 * 与 NY 的 spark_release_status 字段作用类似。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xui_inbounds', function (Blueprint $table) {
            if (!Schema::hasColumn('xui_inbounds', 'release_status')) {
                $table->string('release_status', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('xui_inbounds', 'release_error')) {
                $table->string('release_error', 500)->nullable()->after('release_status');
            }
            if (!Schema::hasColumn('xui_inbounds', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('release_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('xui_inbounds', function (Blueprint $table) {
            $table->dropColumn(['release_status', 'release_error', 'released_at']);
        });
    }
};
