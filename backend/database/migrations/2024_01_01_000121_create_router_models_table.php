<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('cpu', 100)->nullable();
            $table->unsignedInteger('ram_mb')->nullable();
            $table->unsignedInteger('storage_gb')->nullable();
            $table->unsignedSmallInteger('ports')->default(4);
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('sell_price', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ap_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('band', 50)->nullable();
            $table->json('specs')->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('sell_price', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('router_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('router_model_id')->constrained('router_models')->cascadeOnDelete();
            $table->foreignId('ap_model_id')->nullable()->constrained('ap_models')->nullOnDelete();
            $table->decimal('bundle_price', 10, 2);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_bundles');
        Schema::dropIfExists('ap_models');
        Schema::dropIfExists('router_models');
    }
};
