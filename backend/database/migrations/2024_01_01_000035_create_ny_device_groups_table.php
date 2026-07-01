<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NY 设备组本地缓存（每个面板同步一次）
 *
 * custom_connect_host: 管理员可覆盖 NY 原始 connect_host，
 *   用于给客户展示自定义域名（如 hk-node.sunipip.uk 解析到实际 NY 节点 IP）。
 * is_enabled: 仅启用的设备组会在下单时展示给业务员
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ny_device_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ny_panel_id')->constrained('ny_panels')->cascadeOnDelete();
            $table->unsignedInteger('remote_id')->comment('NY 面板返回的 id');
            $table->string('name', 200);
            $table->string('type', 50)->nullable();
            $table->string('original_connect_host', 255)->nullable();
            $table->string('custom_connect_host', 255)->nullable()->comment('管理员覆盖的域名/IP');
            $table->string('port_range', 100)->nullable();
            $table->tinyInteger('is_enabled')->default(0)->comment('仅勾选的设备组可用');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ny_panel_id', 'remote_id']);
            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ny_device_groups');
    }
};
