<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 销售倍率定价表
 *
 * 逻辑：客户售价 = Spark 成本价 × 倍率
 * 优先级：产品级 > 城市级 > 州级 > 国家级 > 全局默认
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_multipliers', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 30)->default('global')
                ->comment('global/country/area/city/product');
            $table->string('country_code', 10)->nullable();
            $table->string('area_code', 50)->nullable();
            $table->string('city_code', 50)->nullable();
            $table->string('product_id', 100)->nullable()->comment('Spark productId');
            $table->decimal('multiplier', 5, 2)->default(2.00)->comment('销售倍率');
            $table->decimal('min_price', 10, 2)->nullable()->comment('最低售价（兜底）');
            $table->decimal('fixed_price', 10, 2)->nullable()->comment('固定售价（优先于倍率）');
            $table->tinyInteger('is_active')->default(1);
            $table->string('remark', 255)->nullable();
            $table->timestamps();

            $table->unique(['scope', 'country_code', 'area_code', 'city_code', 'product_id'], 'uniq_scope');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_multipliers');
    }
};
