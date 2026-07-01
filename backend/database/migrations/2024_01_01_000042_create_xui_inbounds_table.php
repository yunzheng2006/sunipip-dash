<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3x-ui 中转记录表（一条记录 = 一次转发）
 *
 * 一条 xui_inbound 对应 3x-ui 面板上的一个 vless+reality 入站，
 * 加上对应的 socks5 outbound 和 routing rule，整体指向一个源 IP。
 *
 * 字段设计考虑：审计完整、出问题能单独重试、便于查询对客展示信息。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xui_inbounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('xui_panel_id')->constrained('xui_panels')->cascadeOnDelete();
            $table->foreignId('proxy_ip_id')->nullable()->constrained('proxy_ips')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            // 3x-ui 侧的 id + 面板元信息
            $table->integer('remote_inbound_id')->nullable()->comment('3x-ui inbounds.id');
            $table->string('remark', 191)->comment('备注同时作为 client.email');
            $table->unsignedInteger('port')->nullable()->comment('3x-ui 分配的监听端口');
            $table->string('protocol', 20)->default('vless');

            // Reality / VLESS 细节
            $table->string('client_uuid', 64)->nullable();
            $table->text('private_key')->nullable();
            $table->text('public_key')->nullable();
            $table->string('short_id', 32)->nullable();
            $table->string('server_name', 191)->nullable()->default('www.intel.com');
            $table->string('flow', 32)->nullable();

            // 对应的 outbound + routing rule
            $table->string('outbound_tag', 64)->nullable();

            // 审计
            $table->string('status', 20)->default('pending')->comment('pending/active/failed/deleted');
            $table->text('error_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xui_inbounds');
    }
};
