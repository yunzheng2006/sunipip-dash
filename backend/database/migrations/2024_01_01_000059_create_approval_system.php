<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('supervisor_id')->nullable()->after('invite_code')
                ->comment('直属上级(users.id)');
        });

        Schema::create('provision_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique()->comment('审批单号');
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('customer_id')->constrained('customers');
            $table->json('order_data')->comment('开单参数快照');
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 20)->default('pending')
                ->comment('pending/approved/rejected/cancelled/executed');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_comment', 500)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('execution_result')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('submitted_by');
            $table->index('customer_id');
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_approvals');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('supervisor_id');
        });
    }
};
