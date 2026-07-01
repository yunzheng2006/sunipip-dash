<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_multipliers', function (Blueprint $table) {
            $table->decimal('sales_multiplier', 5, 2)->nullable()->after('fixed_price')
                ->comment('销售倍率：销售看到的成本 = Spark成本 × 此值');
            $table->decimal('sales_fixed_price', 10, 2)->nullable()->after('sales_multiplier')
                ->comment('销售固定成本：设置后忽略 sales_multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_multipliers', function (Blueprint $table) {
            $table->dropColumn(['sales_multiplier', 'sales_fixed_price']);
        });
    }
};
