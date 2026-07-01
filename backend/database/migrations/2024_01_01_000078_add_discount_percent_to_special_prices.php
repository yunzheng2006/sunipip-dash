<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()
                ->after('forward_price')
                ->comment('统一折扣百分比，如 85 表示 85折（打八五折）。与 special_price 互斥，special_price 优先');
        });
    }

    public function down(): void
    {
        Schema::table('customer_special_prices', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
