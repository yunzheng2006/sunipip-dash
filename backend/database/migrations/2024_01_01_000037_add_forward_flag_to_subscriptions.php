<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subscriptions 增加 has_forward 便利字段
 *
 * 用于列表快速筛选 / 前端一眼判断是否需要展示转发地址。
 * 实际的 forward_rule 关联挂在 forward_rules 表上。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('has_forward')->default(false)->after('auto_renew');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('has_forward');
        });
    }
};
