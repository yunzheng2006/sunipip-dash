<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_asset_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->comment('组名(如 手动-美国-西雅图01)');
            $table->string('source_type', 30)->comment('manual/spark_api/third_party_api/self_owned');
            $table->string('source_name', 100)->comment('来源名称(斯帕克/涛哥/木子/自有)');
            $table->char('country_code', 2)->nullable()->comment('ISO 3166-1 alpha-2');
            $table->string('country_name', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('description')->nullable();
            $table->json('api_config')->nullable()->comment('API组的额外配置');
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('source_type');
            $table->index('source_name');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_asset_groups');
    }
};
