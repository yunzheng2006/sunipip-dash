<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = DB::select('SHOW INDEX FROM router_wifi_accounts WHERE Non_unique = 0');
        $vlanIndex = collect($indexes)
            ->where('Column_name', 'vlan_id')
            ->first();

        if ($vlanIndex) {
            Schema::table('router_wifi_accounts', function (Blueprint $table) use ($vlanIndex) {
                $table->dropUnique($vlanIndex->Key_name);
            });
        }
    }

    public function down(): void
    {
        Schema::table('router_wifi_accounts', function (Blueprint $table) {
            $table->unique(['router_device_id', 'vlan_id']);
        });
    }
};
