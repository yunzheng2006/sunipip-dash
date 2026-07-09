<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_stats_snapshots', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->comment('快照月份 YYYY-MM');
            $table->unsignedBigInteger('customer_id');
            $table->string('sales_person')->nullable()->index();
            $table->json('data')->comment('该客户当月完整统计行（与实时接口同结构）');
            $table->timestamp('snapshotted_at');
            $table->timestamps();

            $table->unique(['period', 'customer_id']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_stats_snapshots');
    }
};
