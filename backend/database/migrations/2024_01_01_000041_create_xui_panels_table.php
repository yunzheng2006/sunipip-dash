<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3x-ui 中转面板配置
 *
 * 与 ny_panels 类似，但认证走 cookie（3x-ui 返回 Set-Cookie）而非 Bearer token。
 *
 * api_url 是完整 base URL（含自定义 basePath），如：
 *   https://vps.example.com:54321/abc123/
 * 所有接口会在其后追加 /login、/panel/api/inbounds/list 等。
 *
 * connect_host 是对客户可见的中转服务器地址（可以是域名或 IP），
 * 用于生成 vless:// 链接和展示。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xui_panels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('显示名，如 中转-主号');
            $table->string('api_url', 500)->comment('含 basePath 的完整 URL');
            $table->string('username', 100);
            $table->text('password')->comment('encrypted');
            $table->string('connect_host', 200)->nullable()->comment('对客连接地址（域名/IP）');
            $table->text('session_cookie')->nullable()->comment('3x-ui 登录返回的 session cookie');
            $table->timestamp('cookie_expires_at')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xui_panels');
    }
};
