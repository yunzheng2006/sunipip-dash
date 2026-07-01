<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spark_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spark_order_id')->constrained('spark_orders')->cascadeOnDelete();
            $table->foreignId('proxy_ip_id')->nullable()->constrained('proxy_ips')->nullOnDelete();
            $table->string('instance_id', 100)->unique()->comment('Spark实例ID');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username', 191)->nullable();
            $table->string('password', 255)->nullable();
            $table->tinyInteger('type')->default(1)->comment('1=ipv4 2=ipv6 3=随机');
            $table->tinyInteger('use_type')->default(1)->comment('1=账密 2=白名单 3=uuid');
            $table->tinyInteger('status')->default(1)->comment('1=开通中 2=正常 3=释放中 4=释放完成');
            $table->unsignedInteger('flow')->nullable()->comment('流量MB');
            $table->unsignedInteger('balance_flow')->nullable()->comment('余额流量');
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spark_instances');
    }
};
