<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pricing_multipliers 加成本匹配字段 cost_match
 *
 * 业务需求：根据 Spark 上游成本价划档定价
 *   - "所有 cost=19 的产品定价为 50"（global + cost_match=19）
 *   - "美国 cost=21 的产品定价为 55"（country=USA + cost_match=21）
 *
 * 匹配逻辑（见 PricingMultiplier::matchRule）：
 *   同一 scope 下，cost_match 非空的规则比 cost_match=NULL 的规则优先
 *   即 "美国 21→55" > "美国(任意成本)→2x 倍率"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_multipliers', function (Blueprint $table) {
            $table->decimal('cost_match', 10, 2)->nullable()
                ->after('product_id')
                ->comment('成本价匹配：产品 cost_price = 此值时才命中；NULL=不限成本');
            $table->index(['scope', 'country_code', 'cost_match'], 'idx_scope_country_cost');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_multipliers', function (Blueprint $table) {
            $table->dropIndex('idx_scope_country_cost');
            $table->dropColumn('cost_match');
        });
    }
};
