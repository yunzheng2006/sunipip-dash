<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forward_plans', function (Blueprint $table) {
            $table->integer('device_limit')->default(0)->after('speed_limit_mbps')
                ->comment('设备数限制, 0=不限');
        });
    }

    public function down(): void
    {
        Schema::table('forward_plans', function (Blueprint $table) {
            $table->dropColumn('device_limit');
        });
    }
};
