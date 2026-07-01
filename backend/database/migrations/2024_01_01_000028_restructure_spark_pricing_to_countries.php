<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 重构 Spark 定价逻辑：从绑定 asset_group 改为绑定 country_code。
 *
 * 原因：Spark 库存是动态的，客户自助面板直接按"国家"查库存和价格更自然。
 * asset_group 是内部账本概念，对外不应暴露。
 *
 * 新结构：spark_pricing_rules.country_codes 为 JSON 数组 ['USA','CAN','MEX']。
 * 对应 area_country.code（ISO 3166-1 alpha-3）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 删除旧的 pivot 表
        Schema::dropIfExists('spark_pricing_rule_asset_groups');

        // 加 country_codes JSON 列
        Schema::table('spark_pricing_rules', function (Blueprint $table) {
            $table->json('country_codes')->nullable()->after('description')->comment('ISO alpha-3 国家代码数组');
        });
    }

    public function down(): void
    {
        Schema::table('spark_pricing_rules', function (Blueprint $table) {
            $table->dropColumn('country_codes');
        });

        Schema::create('spark_pricing_rule_asset_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spark_pricing_rule_id')->constrained('spark_pricing_rules')->cascadeOnDelete();
            $table->foreignId('asset_group_id')->constrained('ip_asset_groups')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['spark_pricing_rule_id', 'asset_group_id'], 'uniq_spr_ag');
        });
    }
};
