<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nyanpass (NY) 面板配置
 *
 * 管理员在后台可配置多个 NY 面板，每个面板对应一个账户。
 * 下单时选择面板 → 选择设备组 → 自动创建转发规则。
 *
 * password 字段使用 encrypted cast，密文存储。
 * token 缓存有效期由 token_expires_at 控制（默认 12h），过期自动重新登录。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ny_panels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('显示名，如 Nyanpass-主账号');
            $table->string('api_url', 500)->comment('面板 base URL，不含 /api/v1');
            $table->string('username', 100);
            $table->text('password')->comment('encrypted');
            $table->text('last_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ny_panels');
    }
};
