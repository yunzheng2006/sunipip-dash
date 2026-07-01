<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_config_id')->nullable()->constrained('webhook_configs')->nullOnDelete();
            $table->string('event_type', 50);
            $table->string('channel', 30)->comment('wechat_work/email/sms');
            $table->string('title', 255);
            $table->text('content');
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending/sent/failed');
            $table->text('response')->nullable()->comment('webhook API响应');
            $table->tinyInteger('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('status');
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
