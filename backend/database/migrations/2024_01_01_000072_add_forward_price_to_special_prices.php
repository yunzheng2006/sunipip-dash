<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客户特批价支持单独的「中转/转发价」
 * forward_price 为 null 表示不特批中转（走默认中转价），非 null 则覆盖默认
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->decimal('forward_price', 10, 2)->nullable()
                ->after('special_price')
                ->comment('特批中转价（每条IP每月），null=按默认中转定价');
        });
    }

    public function down(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->dropColumn('forward_price');
        });
    }
};
