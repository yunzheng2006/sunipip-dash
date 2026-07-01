<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为客户自助面板（user.sunipip.uk）补齐 customers 表字段。
 *
 * 新增：
 *   - email_verified_at: 未来可选的邮箱验证（当前不强制）
 *   - last_login_at / last_login_ip: 登录追踪
 *   - auto_renew_default: 新订阅默认是否自动续费
 *
 * 另外给 subscriptions 加自动续费扫描索引。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->boolean('auto_renew_default')->default(false)->after('last_login_ip')
                ->comment('新订阅默认开启自动续费');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['auto_renew', 'status', 'expires_at'], 'idx_auto_renew_scan');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('idx_auto_renew_scan');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified_at',
                'last_login_at',
                'last_login_ip',
                'auto_renew_default',
            ]);
        });
    }
};
