<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('操作用户');
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable()->comment('操作对象类型');
            $table->unsignedBigInteger('subject_id')->nullable()->comment('操作对象ID');
            $table->string('description', 500)->nullable();
            $table->json('properties')->nullable()->comment('变更属性详情');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
