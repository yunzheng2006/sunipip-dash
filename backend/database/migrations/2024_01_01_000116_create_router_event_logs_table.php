<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_device_id')->constrained('router_devices')->cascadeOnDelete();
            $table->string('event_type', 50)->index();
            $table->string('severity', 10)->default('info');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['router_device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_event_logs');
    }
};
