<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 30)->default('wechat_work')->comment('wechat_work/dingtalk/custom');
            $table->string('webhook_url', 500);
            $table->string('secret_key', 255)->nullable();
            $table->json('events')->comment('订阅事件列表: subscription_expiring/expired/low_balance等');
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_configs');
    }
};
