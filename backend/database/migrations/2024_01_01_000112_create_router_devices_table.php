<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 100)->unique();
            $table->string('hostname', 100)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('bound_module', 20)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->string('status', 20)->default('inventory')->index();
            $table->string('install_token', 128)->nullable()->unique();
            $table->timestamp('install_token_expires_at')->nullable();
            $table->string('agent_key', 255)->nullable()->unique();
            $table->string('agent_version', 30)->nullable();
            $table->string('wan_ip', 45)->nullable();
            $table->string('wg_ip_1', 45)->nullable();
            $table->string('wg_ip_2', 45)->nullable();
            $table->foreignId('wg_server_1_id')->nullable()->constrained('wg_servers')->nullOnDelete();
            $table->foreignId('wg_server_2_id')->nullable()->constrained('wg_servers')->nullOnDelete();
            $table->unsignedInteger('config_version')->default(0);
            $table->unsignedInteger('applied_config_version')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->string('last_heartbeat_ip', 45)->nullable();
            $table->json('system_info')->nullable();
            $table->tinyInteger('ap_management_enabled')->default(0);
            $table->string('ap_ip', 45)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_devices');
    }
};
