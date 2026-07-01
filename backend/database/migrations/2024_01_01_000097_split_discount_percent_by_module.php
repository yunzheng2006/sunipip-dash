<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->renameColumn('discount_percent', 'discount_percent_static');
        });

        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->decimal('discount_percent_video', 5, 2)->nullable()->after('discount_percent_static');
        });

        // 将已有的静态折扣复制到视频折扣
        DB::table('customer_special_prices')
            ->whereNotNull('discount_percent_static')
            ->update(['discount_percent_video' => DB::raw('discount_percent_static')]);
    }

    public function down(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->dropColumn('discount_percent_video');
        });

        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->renameColumn('discount_percent_static', 'discount_percent');
        });
    }
};
