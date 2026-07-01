<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 添加 Spark 释放追踪字段，用于审计"是否真正释放成功"。
 *
 *   spark_release_status   NULL=未发起 / pending=已发请求等待Spark处理 / confirmed=已确认释放 / failed=释放失败
 *   spark_release_order_no DelProxy 的 req_order_no，可通过 spark_orders 关联查状态
 *   spark_released_at      Spark 确认释放的时间（不是发起请求的时间）
 *   spark_release_error    失败原因
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->string('spark_release_status', 20)->nullable()->after('spark_instance_id');
            $table->string('spark_release_order_no', 100)->nullable()->after('spark_release_status');
            $table->timestamp('spark_released_at')->nullable()->after('spark_release_order_no');
            $table->string('spark_release_error', 500)->nullable()->after('spark_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropColumn([
                'spark_release_status',
                'spark_release_order_no',
                'spark_released_at',
                'spark_release_error',
            ]);
        });
    }
};
