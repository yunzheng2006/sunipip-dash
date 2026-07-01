<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->unsignedInteger('wifi_ip_next_index')->default(2)->after('ap_discover_requested');
            $table->unsignedTinyInteger('wifi_version')->default(1)->after('wifi_ip_next_index');
            $table->string('target_agent_version', 30)->nullable()->after('agent_version');
        });

        Schema::table('router_wifi_accounts', function (Blueprint $table) {
            $table->unsignedInteger('ip_start_index')->default(0)->after('max_devices');
            $table->dropUnique(['router_device_id', 'vlan_id']);
        });
    }

    public function down(): void
    {
        Schema::table('router_wifi_accounts', function (Blueprint $table) {
            $table->dropColumn('ip_start_index');
            $table->unique(['router_device_id', 'vlan_id']);
        });

        Schema::table('router_devices', function (Blueprint $table) {
            $table->dropColumn(['wifi_ip_next_index', 'wifi_version', 'target_agent_version']);
        });
    }
};
