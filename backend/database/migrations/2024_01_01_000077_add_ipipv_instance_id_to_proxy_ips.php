<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->string('ipipv_instance_id', 100)->nullable()->after('spark_instance_id')
                ->comment('IPIPV 平台实例号');
            $table->index('ipipv_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropIndex(['ipipv_instance_id']);
            $table->dropColumn('ipipv_instance_id');
        });
    }
};
