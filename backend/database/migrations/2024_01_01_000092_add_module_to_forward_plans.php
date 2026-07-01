<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('forward_plans', function (Blueprint $table) {
            $table->string('module', 20)->nullable()->after('description')->index()
                ->comment('应用模块: video=视频专线, live_mobile=直播手机, live_pc=直播电脑');
        });
    }

    public function down(): void
    {
        Schema::table('forward_plans', function (Blueprint $table) {
            $table->dropColumn('module');
        });
    }
};
