<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 30);
            $table->string('code', 10)->nullable();
            $table->string('type', 30)->default('register')->comment('register/login/reset');
            $table->string('provider', 30)->nullable();
            $table->string('status', 20)->default('sent')->comment('sent/verified/expired/failed');
            $table->string('biz_id', 100)->nullable()->comment('provider message id');
            $table->string('error', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'code', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
