<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_groups', function (Blueprint $table) {
            $table->tinyInteger('spark_isp_type')->nullable()->after('net_type')
                ->comment('Spark ispType: 1=单ISP 2=双ISP 3=原生ISP 4=机房');
            $table->tinyInteger('spark_net_type')->nullable()->after('spark_isp_type')
                ->comment('Spark netType: 0=未知 1=原生 2=广播');
            $table->string('display_name', 100)->nullable()->after('spark_net_type')
                ->comment('客户面板显示名');
            $table->integer('sort_order')->default(0)->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('ip_groups', function (Blueprint $table) {
            $table->dropColumn(['spark_isp_type', 'spark_net_type', 'display_name', 'sort_order']);
        });
    }
};
