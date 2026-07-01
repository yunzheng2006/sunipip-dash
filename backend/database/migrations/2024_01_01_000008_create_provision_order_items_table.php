<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('provision_orders')->cascadeOnDelete();
            $table->foreignId('asset_group_id')->constrained('ip_asset_groups');
            $table->char('country_code', 2);
            $table->string('country_name', 100);
            $table->string('city', 100)->nullable();
            $table->unsignedInteger('quantity')->comment('数量');
            $table->unsignedInteger('duration')->comment('时长数值');
            $table->tinyInteger('unit')->comment('1=天 2=周 3=月 4=年');
            $table->decimal('unit_price', 10, 2)->comment('单价');
            $table->decimal('subtotal', 12, 2)->comment('小计');
            $table->string('status', 20)->default('pending')->comment('pending/completed/failed');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_order_items');
    }
};
