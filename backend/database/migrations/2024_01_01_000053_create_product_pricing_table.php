<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 10)->comment('ISO alpha-3: USA, BRA');
            $table->string('country_name', 100)->default('');
            $table->foreignId('ip_group_id')->nullable()->constrained('ip_groups')->nullOnDelete();
            $table->string('access_type', 20)->default('dedicated')->comment('dedicated/shared');
            $table->decimal('monthly_price', 10, 2)->comment('月售价(CNY)');
            $table->decimal('cost_price', 10, 2)->nullable()->comment('参考成本价');
            $table->integer('own_stock')->default(0)->comment('自有库存（手动设置）');
            $table->integer('max_shared_users')->default(1)->comment('共享IP最大用户数');
            $table->tinyInteger('is_active')->default(1);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'ip_group_id', 'access_type'], 'uniq_pricing');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_pricing');
    }
};
