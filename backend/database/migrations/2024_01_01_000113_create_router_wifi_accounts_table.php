<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_wifi_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_device_id')->constrained('router_devices')->cascadeOnDelete();
            $table->string('username', 64);
            $table->string('password', 128);
            $table->string('label', 100)->nullable();
            $table->unsignedSmallInteger('vlan_id');
            $table->string('ip_prefix', 20);
            $table->string('gateway_ip', 45);
            $table->foreignId('proxy_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('proxy_mode', 20)->default('proxy');
            $table->tinyInteger('is_active')->default(1);
            $table->unsignedInteger('max_devices')->default(10);
            $table->timestamps();

            $table->unique(['router_device_id', 'username']);
            $table->unique(['router_device_id', 'vlan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_wifi_accounts');
    }
};
