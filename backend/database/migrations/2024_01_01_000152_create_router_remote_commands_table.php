<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_remote_commands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('router_device_id');
            $table->text('command');
            $table->unsignedInteger('timeout')->default(30);
            $table->enum('status', ['pending', 'sent', 'completed', 'failed', 'expired'])->default('pending');
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['router_device_id', 'status']);
            $table->foreign('router_device_id')->references('id')->on('router_devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_remote_commands');
    }
};
