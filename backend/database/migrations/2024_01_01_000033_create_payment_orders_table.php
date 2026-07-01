<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 充值订单表（每次客户发起充值创建一条）
 *
 * 状态流转：
 *   pending → paid          （成功回调 + 入账）
 *   pending → failed        （网关明确失败）
 *   pending → expired       （超时未支付）
 *   pending → cancelled     （客户主动取消）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 64)->unique()->comment('我方订单号 PAY{timestamp}{rand}');
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('gateway_id')->constrained('payment_gateways');
            $table->string('gateway_type', 30)->comment('冗余 epay/wechat/alipay，方便统计');
            $table->string('method', 20)->nullable()->comment('子方式 alipay/wxpay/qqpay');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('CNY');
            $table->string('status', 20)->default('pending');
            $table->string('provider_trade_no', 191)->nullable()->comment('网关返回的订单号');
            $table->json('provider_payload')->nullable()->comment('最后一次回调完整参数');
            $table->string('client_ip', 45)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('status');
            $table->index('provider_trade_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
