<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 删除 Laravel 默认创建的 users 表，用我们的结构替代
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100)->unique()->comment('登录用户名');
            $table->string('password');
            $table->string('name', 100)->comment('显示名称');
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // 重新创建 Laravel 需要的辅助表
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
