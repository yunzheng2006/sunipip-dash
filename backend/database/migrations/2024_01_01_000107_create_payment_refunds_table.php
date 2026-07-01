<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_no', 40)->unique();
            $table->unsignedBigInteger('payment_order_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('gateway_id');
            $table->string('gateway_type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending')->index();
            $table->string('reason')->nullable();
            $table->string('provider_refund_no')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedBigInteger('operated_by');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
