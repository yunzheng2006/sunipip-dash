<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('spark_product_blocks');

        Schema::create('spark_product_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 64);
            $table->string('cidr', 50);
            $table->string('product_name')->default('');
            $table->string('country_code', 10)->default('');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'cidr']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spark_product_blocks');

        Schema::create('spark_product_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 64)->unique();
            $table->string('product_name')->default('');
            $table->string('country_code', 10)->default('');
            $table->json('cidr_samples')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->timestamps();
            $table->index('country_code');
        });
    }
};
