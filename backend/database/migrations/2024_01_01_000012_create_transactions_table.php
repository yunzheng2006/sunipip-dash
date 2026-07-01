<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('type', 20)->comment('topup/deduction/refund/adjustment');
            $table->decimal('amount', 12, 2)->comment('正=入账 负=扣费');
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('related_type', 100)->nullable()->comment('多态关联类型');
            $table->unsignedBigInteger('related_id')->nullable()->comment('多态关联ID');
            $table->string('description', 500)->nullable();
            $table->foreignId('operated_by')->constrained('users');
            $table->timestamps();

            $table->index('customer_id');
            $table->index('type');
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
