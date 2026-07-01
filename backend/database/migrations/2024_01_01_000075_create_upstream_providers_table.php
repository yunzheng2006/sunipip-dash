<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upstream_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('显示名称');
            $table->string('slug', 50)->unique()->comment('唯一标识: spark, ipipv');
            $table->string('driver', 30)->comment('驱动类型，决定使用哪个 Service 类');
            $table->string('api_url', 500)->comment('API 基础 URL');
            $table->json('credentials')->comment('认证凭据 JSON');
            $table->string('callback_path', 200)->nullable()->comment('回调路径');
            $table->boolean('is_active')->default(true);
            $table->json('extra_config')->nullable()->comment('额外配置');
            $table->timestamps();
        });

        // 从 .env 初始化 Spark 插件
        \App\Models\UpstreamProvider::create([
            'name'          => 'Spark 代理',
            'slug'          => 'spark',
            'driver'        => 'spark',
            'api_url'       => config('proxy.spark.api_url', 'https://oapi.sparkproxy.com/v2/open/api'),
            'credentials'   => [
                'supplier_no' => config('proxy.spark.supplier_no', ''),
                'aes_key'     => config('proxy.spark.aes_key', ''),
                'version'     => config('proxy.spark.version', '2.0'),
            ],
            'callback_path' => '/api/v1/spark/notify',
            'is_active'     => true,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('upstream_providers');
    }
};
