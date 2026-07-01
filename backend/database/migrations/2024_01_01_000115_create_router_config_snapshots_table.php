<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_config_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_device_id')->constrained('router_devices')->cascadeOnDelete();
            $table->unsignedInteger('config_version');
            $table->string('config_type', 30)->default('full');
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['router_device_id', 'config_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_config_snapshots');
    }
};
