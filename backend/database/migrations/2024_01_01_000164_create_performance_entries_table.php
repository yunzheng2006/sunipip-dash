<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 业绩流水账：每个资金相关业务事件在发生当下写入一行确定事实，
 * 行只增不改，逆向操作（退款/降级退差价/佣金冲销）写负数行。
 * 业绩统计 = 按时段/客户/业务员对本表求和，不做任何事后推理。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_entries', function (Blueprint $table) {
            $table->id();
            // purchase / renew / forward_attach / downgrade_refund / refund / convert / commission / commission_reversal
            $table->string('event_type', 32)->index();
            $table->unsignedBigInteger('customer_id')->comment('业绩归属客户（转移单归原客户）');
            $table->string('sales_person', 64)->nullable()->comment('事件时点的归属业务员（冻结值）');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('forward_rule_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable()->comment('锚定的资金流水');
            $table->decimal('revenue', 12, 2)->default(0)->comment('消费（退款为负）');
            $table->decimal('commission', 12, 2)->default(0)->comment('该客户消费触发的返佣（冲销为负）');
            $table->decimal('sales_cost', 12, 2)->default(0)->comment('销售成本（IP+中转，负数=冲销）');
            $table->decimal('hard_cost_ip', 12, 2)->default(0);
            $table->decimal('hard_cost_fwd', 12, 2)->default(0);
            $table->decimal('months', 8, 2)->nullable()->comment('本行覆盖的计费月数');
            $table->boolean('is_test')->default(false);
            $table->dateTime('occurred_at')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'occurred_at']);
            $table->index(['subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_entries');
    }
};
