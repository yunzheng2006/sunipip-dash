<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer referral fields
        Schema::table('customers', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->unique()->after('max_single_topup')
                ->comment('客户自己的推广码');
            $table->unsignedBigInteger('referred_by_customer')->nullable()->after('referral_code')
                ->comment('推荐人客户ID');
        });

        // Referral commission log
        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id')->comment('推荐人ID(customers)');
            $table->unsignedBigInteger('referee_id')->comment('被推荐人ID(customers)');
            $table->string('trigger_type', 30)->comment('purchase/renewal/topup');
            $table->unsignedBigInteger('trigger_id')->nullable()->comment('触发的订单/交易ID');
            $table->decimal('trigger_amount', 12, 2)->comment('触发金额');
            $table->decimal('commission_rate', 5, 2)->comment('佣金比例(%)');
            $table->decimal('commission_amount', 12, 2)->comment('佣金金额');
            $table->string('status', 20)->default('pending')->comment('pending/credited/cancelled');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->index('referrer_id');
            $table->index('referee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referred_by_customer']);
        });
    }
};
