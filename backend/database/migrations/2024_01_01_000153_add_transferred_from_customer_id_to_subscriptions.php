<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('transferred_from_customer_id')->nullable()->after('keep_performance');
            $table->index('transferred_from_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['transferred_from_customer_id']);
            $table->dropColumn('transferred_from_customer_id');
        });
    }
};
