<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipipv_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provision_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app_order_no', 100)->unique()->comment('我方订单号');
            $table->string('ipipv_order_no', 100)->nullable()->comment('IPIPV 平台订单号');
            $table->string('method', 30)->comment('open/renew/release');
            $table->string('product_no', 100)->nullable();
            $table->integer('amount')->default(1);
            $table->integer('duration')->default(1);
            $table->integer('unit')->default(3);
            $table->integer('cycle_times')->default(1);
            $table->decimal('cost_amount', 10, 2)->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=pending 2=processing 3=success 4=failed 5=partial');
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();
        });

        Schema::create('ipipv_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ipipv_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proxy_ip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('instance_no', 100)->unique()->comment('IPIPV 平台实例号');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username', 191)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('product_no', 100)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('city_code', 20)->nullable();
            $table->string('protocol', 20)->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=pending 2=creating 3=running 6=stopped 10=closed 11=released');
            $table->decimal('flow_total', 12, 2)->nullable();
            $table->decimal('flow_balance', 12, 2)->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipipv_instances');
        Schema::dropIfExists('ipipv_orders');
    }
};
