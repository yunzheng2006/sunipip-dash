<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 xui_inbounds 加 batch_id 用于批量进度查询
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xui_inbounds', function (Blueprint $table) {
            if (!Schema::hasColumn('xui_inbounds', 'batch_id')) {
                $table->string('batch_id', 64)->nullable()->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('xui_inbounds', function (Blueprint $table) {
            if (Schema::hasColumn('xui_inbounds', 'batch_id')) {
                $table->dropColumn('batch_id');
            }
        });
    }
};
