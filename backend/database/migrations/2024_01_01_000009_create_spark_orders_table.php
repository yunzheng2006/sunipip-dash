<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spark_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provision_order_id')->nullable()->constrained('provision_orders')->nullOnDelete();
            $table->string('req_order_no', 100)->unique()->comment('我方发给Spark的订单号');
            $table->string('spark_order_no', 100)->nullable()->comment('Spark返回的订单号');
            $table->string('method', 30)->comment('CreateProxy/RenewProxy/DelProxy');
            $table->string('product_id', 100)->comment('Spark产品ID');
            $table->unsignedInteger('amount')->comment('数量');
            $table->unsignedInteger('duration')->comment('时长');
            $table->tinyInteger('unit')->comment('1=天 2=周 3=月 4=年');
            $table->decimal('cost_amount', 10, 2)->nullable()->comment('Spark扣费金额');
            $table->tinyInteger('status')->default(1)->comment('1=开通中 2=完成 3=失败');
            $table->json('request_data')->nullable()->comment('请求参数');
            $table->json('response_data')->nullable()->comment('响应数据');
            $table->timestamps();

            $table->index('spark_order_no');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spark_orders');
    }
};
