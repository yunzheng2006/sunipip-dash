<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_stat_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('sales_person')->nullable()->index();
            $table->decimal('spending', 12, 2)->default(0)->comment('手动消费');
            $table->decimal('sales_cost', 12, 2)->default(0)->comment('手动销售成本');
            $table->date('entry_date')->index();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_stat_entries');
    }
};
