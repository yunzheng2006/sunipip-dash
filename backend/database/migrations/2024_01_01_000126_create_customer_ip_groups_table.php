<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ip_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->unique(['customer_id', 'name']);
        });

        Schema::create('customer_ip_group_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('proxy_ip_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('group_id')->references('id')->on('customer_ip_groups')->cascadeOnDelete();
            $table->foreign('proxy_ip_id')->references('id')->on('proxy_ips')->cascadeOnDelete();
            $table->unique(['group_id', 'proxy_ip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ip_group_items');
        Schema::dropIfExists('customer_ip_groups');
    }
};
