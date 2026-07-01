<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forward_plans', function (Blueprint $table) {
            $table->string('display_host', 200)->nullable()->after('device_limit')
                ->comment('对客展示的连接域名, null=用设备组默认');
            $table->string('pricing_mode', 20)->default('addon')->after('display_host')
                ->comment('addon=IP价+套餐费, fixed=套餐价即总价(含IP)');
        });

        // 给已有套餐填默认 display_host
        \App\Models\ForwardPlan::query()->update(['display_host' => 'hr.sunipip.com']);
    }

    public function down(): void
    {
        Schema::table('forward_plans', function (Blueprint $table) {
            $table->dropColumn(['display_host', 'pricing_mode']);
        });
    }
};
