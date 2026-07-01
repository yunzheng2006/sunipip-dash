<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->json('ap_discovery')->nullable()->after('ap_config');
            $table->boolean('ap_discover_requested')->default(false)->after('ap_discovery');
        });
    }

    public function down(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->dropColumn(['ap_discovery', 'ap_discover_requested']);
        });
    }
};
