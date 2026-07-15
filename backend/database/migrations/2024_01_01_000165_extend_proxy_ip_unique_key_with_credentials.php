<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 同一域名/IP + 端口下，账号或密码不同的属于独立资产（外部供应商常见），
     * 唯一键从 (ip_address, port) 扩展为含凭证的四元组。
     */
    public function up(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropUnique('idx_proxy_ip_port');
            $table->unique(['ip_address', 'port', 'auth_username', 'auth_password'], 'idx_proxy_ip_port_cred');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropUnique('idx_proxy_ip_port_cred');
            $table->unique(['ip_address', 'port'], 'idx_proxy_ip_port');
        });
    }
};
