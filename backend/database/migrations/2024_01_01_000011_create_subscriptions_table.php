<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('proxy_ip_id')->constrained('proxy_ips');
            $table->foreignId('provision_order_id')->nullable()->constrained('provision_orders')->nullOnDelete();
            $table->decimal('price', 10, 2)->comment('价格');
            $table->unsignedInteger('duration')->comment('时长数值');
            $table->tinyInteger('unit')->comment('1=天 2=周 3=月 4=年');
            $table->timestamp('started_at')->comment('开始时间');
            $table->timestamp('expires_at')->comment('到期时间');
            $table->tinyInteger('auto_renew')->default(0)->comment('0=手动 1=自动续费');
            $table->string('status', 20)->default('active')->comment('active/expired/cancelled/suspended');
            $table->unsignedInteger('renewed_count')->default(0)->comment('已续费次数');
            $table->timestamp('last_renewed_at')->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('customer_id');
            $table->index('proxy_ip_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
