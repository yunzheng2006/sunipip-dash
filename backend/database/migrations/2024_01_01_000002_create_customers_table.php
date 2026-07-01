<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name', 100)->comment('内部可见名称');
            $table->string('username', 100)->unique()->comment('登录用户名(自动生成)');
            $table->string('password')->comment('登录密码(自动生成或手动)');
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('company_name', 200)->nullable();
            $table->string('company_id', 100)->nullable()->comment('公司编号/统一社会信用代码');
            $table->string('address', 500)->nullable();
            $table->decimal('balance', 12, 2)->default(0)->comment('账户余额(RMB)');
            $table->string('sales_person', 100)->nullable()->comment('业务归属');
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_name');
            $table->index('sales_person');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
