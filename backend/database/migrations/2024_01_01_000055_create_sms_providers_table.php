<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 30)->default('aliyun')->comment('aliyun/tencent');
            $table->text('config')->comment('encrypted JSON: access_key_id, access_key_secret, sign_name, template_code');
            $table->tinyInteger('is_active')->default(1);
            $table->integer('sort')->default(0);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_providers');
    }
};
