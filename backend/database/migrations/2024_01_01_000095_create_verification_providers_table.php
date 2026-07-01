<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 30)->comment('aliyun/tencent');
            $table->json('credentials')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_providers');
    }
};
