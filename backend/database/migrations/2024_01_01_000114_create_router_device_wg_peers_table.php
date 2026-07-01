<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_device_wg_peers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_device_id')->constrained('router_devices')->cascadeOnDelete();
            $table->foreignId('wg_server_id')->constrained('wg_servers')->cascadeOnDelete();
            $table->string('peer_public_key', 64);
            $table->text('peer_private_key');
            $table->string('assigned_ip', 20);
            $table->string('preshared_key', 64)->nullable();
            $table->unsignedInteger('persistent_keepalive')->default(25);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->unique(['router_device_id', 'wg_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_device_wg_peers');
    }
};
