<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 转发规则本地记录
 *
 * 一个 Subscription 最多对应一条 active 的 ForwardRule。
 * 退订 / 取消时会自动调 NY 删除接口并置 status=deleted。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forward_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('proxy_ip_id')->constrained('proxy_ips')->cascadeOnDelete();
            $table->foreignId('ny_panel_id')->constrained('ny_panels');
            $table->foreignId('ny_device_group_id')->constrained('ny_device_groups');

            $table->unsignedInteger('remote_rule_id')->nullable()->comment('NY 面板返回的 rule id');
            $table->string('name', 200);
            $table->string('dest_host', 100)->comment('转发目标 IP');
            $table->unsignedInteger('dest_port')->comment('转发目标端口');
            $table->unsignedInteger('listen_port')->nullable()->comment('NY 分配的监听端口');
            $table->unsignedInteger('speed_limit_mbps')->nullable()->comment('NULL=不限速');
            $table->decimal('forward_fee', 10, 2)->default(0)->comment('单月转发费(元)，仅记录，实际扣费在 subscription.price');
            $table->string('status', 20)->default('pending')->comment('pending/active/failed/deleted');
            $table->text('error_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forward_rules');
    }
};
