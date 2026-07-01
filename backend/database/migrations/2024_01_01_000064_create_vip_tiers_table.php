<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vip_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('等级名称');
            $table->decimal('spending_threshold', 12, 2)->default(0)->comment('累计消费门槛(元)');
            $table->decimal('topup_threshold', 12, 2)->nullable()->comment('单次充值门槛(元), null=不支持充值达标');
            $table->integer('discount_percent')->default(100)->comment('折扣百分比, 70=7折');
            $table->integer('sort_order')->default(0)->comment('越大越高级');
            $table->tinyInteger('is_active')->default(1);
            $table->string('description', 500)->nullable();
            $table->string('badge_color', 20)->nullable()->comment('徽章颜色 #hex');
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('vip_tier_id')->nullable()->after('verified_credit_code');
            $table->decimal('total_spent', 12, 2)->default(0)->after('vip_tier_id')
                ->comment('累计消费(扣款总额,正数)');
            $table->decimal('max_single_topup', 12, 2)->default(0)->after('total_spent')
                ->comment('历史最大单次充值');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['vip_tier_id', 'total_spent', 'max_single_topup']);
        });
        Schema::dropIfExists('vip_tiers');
    }
};
