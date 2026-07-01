<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 容灾：xui_panels.mirror_panel_id 指向备机
 *
 *   主机每次成功写操作后 replay 到备机
 *   主机 mirror_panel_id = 备机 id
 *   备机 mirror_panel_id = null（防止循环）
 *   备机 is_mirror = 1 标识（客户端 UI 不默认显示给业务员选）
 *
 * xui_inbounds 新增 mirror_remote_id：备机上对应 inbound 的 id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xui_panels', function (Blueprint $table) {
            $table->foreignId('mirror_panel_id')
                ->nullable()
                ->after('is_active')
                ->constrained('xui_panels')
                ->nullOnDelete();
            $table->tinyInteger('is_mirror')
                ->default(0)
                ->after('mirror_panel_id')
                ->comment('1=作为备机，不参与业务员选择');
        });

        Schema::table('xui_inbounds', function (Blueprint $table) {
            $table->integer('mirror_remote_id')
                ->nullable()
                ->after('remote_inbound_id')
                ->comment('备机 3x-ui inbounds.id');
            $table->string('mirror_sync_status', 20)
                ->nullable()
                ->after('release_error')
                ->comment('pending/synced/failed');
            $table->string('mirror_sync_error', 500)
                ->nullable()
                ->after('mirror_sync_status');
            $table->timestamp('mirror_synced_at')
                ->nullable()
                ->after('mirror_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('xui_inbounds', function (Blueprint $table) {
            $table->dropColumn(['mirror_remote_id', 'mirror_sync_status', 'mirror_sync_error', 'mirror_synced_at']);
        });
        Schema::table('xui_panels', function (Blueprint $table) {
            $table->dropForeign(['mirror_panel_id']);
            $table->dropColumn(['mirror_panel_id', 'is_mirror']);
        });
    }
};
