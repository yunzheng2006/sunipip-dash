<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DNS 容灾监控 - 数据库表
 *
 * 核心实体：
 *   - dns_agents        中国大陆 Agent 注册表
 *   - dns_targets       需要监控的节点（通常就是 xui_panels 的 connect_host + port）
 *   - dns_probe_results 每次探测结果（用于判定连续失败）
 *   - dns_failover_events 切换事件审计
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('agent_key', 64)->unique()->comment('Agent 鉴权 key');
            $table->string('location', 100)->nullable()->comment('部署位置，如 "中国-上海-联通"');
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('dns_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('展示名');

            // 关联 xui_panel（可选，关联后切换就能自动改 xui_panel.is_mirror 等状态）
            $table->foreignId('xui_panel_id')->nullable()->constrained('xui_panels')->nullOnDelete();

            // 用于切换的 DNS 记录
            $table->string('cf_zone_id', 64)->comment('Cloudflare zone id');
            $table->string('cf_record_id', 64)->comment('Cloudflare record id');
            $table->string('cf_record_name', 191)->comment('如 hr.sunipip.com');
            $table->string('cf_api_token', 500)->comment('encrypted CF API token');

            // 主备 IP
            $table->string('primary_ip', 45);
            $table->string('backup_ip', 45);
            $table->string('current_active', 20)->default('primary')->comment('primary | backup');

            // 探测参数
            $table->integer('probe_port')->comment('探测用的端口，vless 监听端口');
            $table->string('probe_host', 191)->nullable()->comment('探测用 host，留空则用 cf_record_name');
            $table->integer('probe_interval_minutes')->default(25);
            $table->integer('failure_threshold')->default(3)->comment('连续失败多少次才切换');
            $table->integer('probe_timeout_seconds')->default(8);

            // Reality handshake 参数（从 xui_inbounds 随机挑一条作为探测目标）
            $table->text('probe_vless_url')->nullable()->comment('加密存，agent 拿到后做真握手');

            // 状态
            $table->string('status', 20)->default('healthy')->comment('healthy | degraded | failed | switched');
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('last_probe_at')->nullable();
            $table->timestamp('last_switched_at')->nullable();

            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->index('current_active');
            $table->index('status');
        });

        Schema::create('dns_probe_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_agent_id')->nullable()->constrained('dns_agents')->nullOnDelete();
            $table->string('probed_host', 191);
            $table->integer('probed_port');
            $table->tinyInteger('success');
            $table->integer('latency_ms')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('probed_at')->useCurrent();

            $table->index(['dns_target_id', 'probed_at']);
        });

        Schema::create('dns_failover_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_target_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30)->comment('failover | failback');
            $table->string('from_ip', 45);
            $table->string('to_ip', 45);
            $table->string('trigger', 30)->comment('auto | manual');
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('cf_response')->nullable();
            $table->tinyInteger('success')->default(1);
            $table->timestamps();

            $table->index('dns_target_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_failover_events');
        Schema::dropIfExists('dns_probe_results');
        Schema::dropIfExists('dns_targets');
        Schema::dropIfExists('dns_agents');
    }
};
