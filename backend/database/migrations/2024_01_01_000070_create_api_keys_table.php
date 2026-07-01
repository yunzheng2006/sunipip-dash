<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('标识/备注，如 分销商A');
            $table->string('key', 64)->unique()->comment('公开的 API Key');
            $table->string('secret', 128)->comment('签名密钥（不返回给前端）');
            $table->json('scopes')->nullable()->comment('允许的权限范围，如 ["store.products"]');
            $table->decimal('price_markup', 5, 2)->default(1.00)->comment('价格加成倍数，默认 1.00 = 原价');
            $table->unsignedInteger('rate_limit')->default(60)->comment('每分钟请求上限');
            $table->unsignedBigInteger('request_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable()->comment('过期时间');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('key');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
