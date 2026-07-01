<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_assignment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_ip_id')->constrained('proxy_ips');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('action', 20)->comment('assign/unassign/reassign');
            $table->foreignId('operated_by')->constrained('users');
            $table->text('remark')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('proxy_ip_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_assignment_logs');
    }
};
