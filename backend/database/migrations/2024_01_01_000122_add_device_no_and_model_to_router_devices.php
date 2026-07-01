<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->string('device_no', 20)->nullable()->unique()->after('id');
            $table->foreignId('router_model_id')->nullable()->after('serial_number')->constrained('router_models')->nullOnDelete();
            $table->foreignId('ap_model_id')->nullable()->after('router_model_id')->constrained('ap_models')->nullOnDelete();
            $table->foreignId('bundle_id')->nullable()->after('ap_model_id')->constrained('router_bundles')->nullOnDelete();
        });

        // Backfill existing devices with device_no
        $devices = DB::table('router_devices')->whereNull('device_no')->get();
        foreach ($devices as $device) {
            $no = 'SunIPIP-' . str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
            while (DB::table('router_devices')->where('device_no', $no)->exists()) {
                $no = 'SunIPIP-' . str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
            }
            DB::table('router_devices')->where('id', $device->id)->update(['device_no' => $no]);
        }
    }

    public function down(): void
    {
        Schema::table('router_devices', function (Blueprint $table) {
            $table->dropForeign(['router_model_id']);
            $table->dropForeign(['ap_model_id']);
            $table->dropForeign(['bundle_id']);
            $table->dropColumn(['device_no', 'router_model_id', 'ap_model_id', 'bundle_id']);
        });
    }
};
