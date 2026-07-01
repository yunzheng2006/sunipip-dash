<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->comment('销售人员');
            $table->foreignId('customer_id')->constrained('customers')->comment('消费客户');
            $table->tinyInteger('level')->default(1)->comment('1=直客 2=直客的推荐客户');
            $table->string('trigger_type', 30)->comment('purchase/renew');
            $table->unsignedBigInteger('trigger_id')->nullable();
            $table->decimal('trigger_amount', 12, 2)->comment('触发金额');
            $table->decimal('commission_rate', 5, 2)->comment('提成比例%');
            $table->decimal('commission_amount', 12, 2);
            $table->string('status', 20)->default('pending')->comment('pending/credited');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('customer_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('commission_balance', 12, 2)->default(0)->after('auto_approve')
                ->comment('销售提成余额');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('commission_balance');
        });
    }
};
