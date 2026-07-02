<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('wifi_max_devices_per_account')->default(5)->after('wifi_version');
        });
    }

    public function down(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->dropColumn('wifi_max_devices_per_account');
        });
    }
};
