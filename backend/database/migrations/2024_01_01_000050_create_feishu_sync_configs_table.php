<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 飞书多维表格同步配置
 *
 * 每条记录 = 一个客户 ↔ 一个飞书多维表格的绑定关系
 *
 * 同步方向：平台 → 飞书（单向推送）
 * 触发方式：
 *   1. 手动同步（admin 面板点按钮）
 *   2. 定时同步（cron 每 30 分钟）
 *   3. IP 变动时自动同步（订阅创建/续费/退订/转发变更后触发）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feishu_sync_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('配置名，如 凯慕传媒-飞书表');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // 飞书应用凭证
            $table->string('app_id', 100);
            $table->text('app_secret')->comment('encrypted');

            // 多维表格定位
            $table->string('app_token', 100)->comment('飞书 bitable 的 app_token / wiki_token');
            $table->string('table_id', 100)->comment('表 ID，如 tblkc8jVTkpYHuRV');
            $table->string('view_id', 100)->nullable()->comment('视图 ID（可选）');

            // 字段映射（JSON）
            // 记录平台字段 → 飞书列名的对应关系
            // 默认映射可以自动推断，但允许 admin 自定义
            $table->json('field_mapping')->nullable()->comment('平台字段 → 飞书列名');

            // 同步状态
            $table->tinyInteger('is_active')->default(1);
            $table->integer('synced_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_error', 500)->nullable();

            // token 缓存
            $table->text('cached_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feishu_sync_configs');
    }
};
