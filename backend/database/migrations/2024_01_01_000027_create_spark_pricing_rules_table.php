<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spark IP 定价表
 *
 * 设计：一条价格规则 = 一个月单价 + 一组它覆盖的 asset_group。
 * 用 pivot 表表达多对多（一个价格可以绑多个资产组；同一资产组只能绑一个启用中的规则）。
 *
 * 举例：
 *   - 规则 "北美高端住宅-80/月" 绑定 [美国住宅原生, 加拿大住宅原生]
 *   - 规则 "东南亚普通-30/月" 绑定 [新加坡机房, 马来机房]
 *
 * 后续客户自助下单时：
 *   根据 IP 的 asset_group_id 反查这张表找到对应的月单价。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spark_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('规则名称，如"北美高端住宅"');
            $table->decimal('monthly_price', 10, 2)->comment('月单价(元)');
            $table->decimal('cost_price', 10, 2)->nullable()->comment('平均成本价(选填)');
            $table->text('description')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('spark_pricing_rule_asset_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spark_pricing_rule_id')->constrained('spark_pricing_rules')->cascadeOnDelete();
            $table->foreignId('asset_group_id')->constrained('ip_asset_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['spark_pricing_rule_id', 'asset_group_id'], 'uniq_spr_ag');
            $table->index('asset_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spark_pricing_rule_asset_groups');
        Schema::dropIfExists('spark_pricing_rules');
    }
};
