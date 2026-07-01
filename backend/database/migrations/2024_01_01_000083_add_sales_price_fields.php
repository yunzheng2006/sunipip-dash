<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('spark_pricing_rules', 'sales_price')) {
            Schema::table('spark_pricing_rules', function (Blueprint $table) {
                $table->decimal('sales_price', 10, 2)->nullable()->after('cost_price')
                    ->comment('销售价格（销售人员看到的成本价）');
            });
        }

        if (!Schema::hasColumn('product_pricing', 'sales_price')) {
            Schema::table('product_pricing', function (Blueprint $table) {
                $table->decimal('sales_price', 10, 2)->nullable()->after('cost_price')
                    ->comment('销售价格（销售人员看到的成本价）');
            });
        }

        if (!Schema::hasColumn('subscriptions', 'sales_cost')) {
            $currentMode = DB::selectOne('SELECT @@sql_mode as m')->m;
            DB::statement("SET sql_mode = ''");
            DB::statement("ALTER TABLE `subscriptions` ADD `sales_cost` DECIMAL(10,2) NULL COMMENT '开通时记录的销售成本价' AFTER `price`");
            DB::statement("SET sql_mode = '{$currentMode}'");
        }
    }

    public function down(): void
    {
        Schema::table('spark_pricing_rules', function (Blueprint $table) {
            $table->dropColumn('sales_price');
        });
        Schema::table('product_pricing', function (Blueprint $table) {
            $table->dropColumn('sales_price');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('sales_cost');
        });
    }
};
