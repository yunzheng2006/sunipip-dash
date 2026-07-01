<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique()->comment('我方订单号');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('status', 20)->default('pending')
                ->comment('pending/processing/completed/partial/failed/cancelled');
            $table->decimal('total_amount', 12, 2)->default(0)->comment('订单总金额');
            $table->text('remark')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_orders');
    }
};
