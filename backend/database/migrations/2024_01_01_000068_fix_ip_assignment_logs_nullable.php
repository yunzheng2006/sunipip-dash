<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_assignment_logs', function (Blueprint $table) {
            $table->dropForeign(['proxy_ip_id']);
            $table->unsignedBigInteger('proxy_ip_id')->nullable()->change();
        });

        Schema::table('forward_rules', function (Blueprint $table) {
            // forward_rules.proxy_ip_id 也需要允许 NULL
            if (Schema::hasColumn('forward_rules', 'proxy_ip_id')) {
                $table->unsignedBigInteger('proxy_ip_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ip_assignment_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('proxy_ip_id')->nullable(false)->change();
            $table->foreign('proxy_ip_id')->references('id')->on('proxy_ips');
        });
    }
};
