<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_history', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 128)->unique();
            $table->string('source', 20)->default('spark');
            $table->json('product_data')->nullable();
            $table->timestamp('first_stocked_at')->useCurrent();
            $table->timestamp('last_stocked_at')->useCurrent();
        });

        // Seed from existing Spark orders
        DB::statement("
            INSERT IGNORE INTO product_stock_history (product_id, source, first_stocked_at, last_stocked_at)
            SELECT DISTINCT product_id, 'spark', MIN(created_at), MAX(created_at)
            FROM spark_orders
            WHERE product_id IS NOT NULL AND product_id != ''
            GROUP BY product_id
        ");

        // Seed from existing IPIPV orders
        if (Schema::hasTable('ipipv_orders')) {
            DB::statement("
                INSERT IGNORE INTO product_stock_history (product_id, source, first_stocked_at, last_stocked_at)
                SELECT DISTINCT product_no, 'ipipv', MIN(created_at), MAX(created_at)
                FROM ipipv_orders
                WHERE product_no IS NOT NULL AND product_no != ''
                GROUP BY product_no
            ");
        }

        // Seed product_data from current stock cache
        try {
            $sparkProducts = \App\Services\SparkStockCacheService::products();
            \App\Services\SparkStockCacheService::recordStockedProducts($sparkProducts, 'spark');

            $ipipvProducts = \App\Services\IpipvStockCacheService::products();
            \App\Services\SparkStockCacheService::recordStockedProducts($ipipvProducts, 'ipipv');
        } catch (\Throwable $e) {
            // Will be populated on next scheduled refresh
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_history');
    }
};
