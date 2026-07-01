<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_group_id')->nullable()->constrained('ip_asset_groups')->nullOnDelete();
            $table->string('socks5_info', 500)->comment('完整连接串 ip:port:user:pass');
            $table->string('ip_address', 45)->comment('IP地址');
            $table->unsignedInteger('port')->comment('端口');
            $table->string('auth_username', 191)->nullable()->comment('认证用户名');
            $table->string('auth_password', 255)->nullable()->comment('认证密码');
            $table->string('protocol', 20)->default('socks5')->comment('socks5/http/https/vmess/trojan');
            $table->string('asset_name', 255)->comment('资产名称(如 轻语-巴西-200.234.165.249)');
            $table->char('country_code', 2)->comment('ISO 3166-1 alpha-2');
            $table->string('country_name', 100);
            $table->string('city', 100)->nullable();
            $table->string('ip_type', 20)->default('residential')->comment('residential/datacenter/isp');
            $table->string('nature', 20)->default('static')->comment('static/dynamic');
            $table->string('net_type', 20)->nullable()->comment('native/broadcast/unknown');
            $table->string('source_name', 100)->comment('IP归属(斯帕克/涛哥/木子/985/自有)');
            $table->string('status', 20)->default('available')->comment('available/assigned/expired/disabled');
            $table->foreignId('assigned_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->text('qr_code_data')->nullable()->comment('二维码内容(socks://...格式)');
            $table->string('spark_instance_id', 100)->nullable()->comment('Spark实例ID');
            $table->json('extra_config')->nullable()->comment('其他协议特定配置');
            $table->foreignId('import_batch_id')->nullable()->constrained('ip_import_logs')->nullOnDelete();
            $table->text('remark')->nullable();
            $table->timestamp('upstream_expires_at')->nullable()->comment('上游到期时间');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ip_address', 'port'], 'idx_proxy_ip_port');
            $table->index('status');
            $table->index('country_code');
            $table->index('asset_group_id');
            $table->index('assigned_customer_id');
            $table->index('source_name');
            $table->index('spark_instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_ips');
    }
};
