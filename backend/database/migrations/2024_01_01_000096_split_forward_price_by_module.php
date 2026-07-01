<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->decimal('forward_price_video', 10, 2)->nullable()->after('forward_price')
                ->comment('视频专线特批价');
            $table->decimal('forward_price_live_mobile', 10, 2)->nullable()->after('forward_price_video')
                ->comment('直播手机特批价');
            $table->decimal('forward_price_live_pc', 10, 2)->nullable()->after('forward_price_live_mobile')
                ->comment('直播电脑特批价');
        });

        // 迁移数据：旧 forward_price 全部是视频专线的价格
        DB::table('customer_special_prices')
            ->whereNotNull('forward_price')
            ->update(['forward_price_video' => DB::raw('forward_price')]);

        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->dropColumn('forward_price');
        });
    }

    public function down(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->decimal('forward_price', 10, 2)->nullable()->after('special_price');
        });

        DB::table('customer_special_prices')
            ->whereNotNull('forward_price_video')
            ->update(['forward_price' => DB::raw('forward_price_video')]);

        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->dropColumn(['forward_price_video', 'forward_price_live_mobile', 'forward_price_live_pc']);
        });
    }
};
