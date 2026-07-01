<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // proxy_ips: 释放标记
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('upstream_expires_at')
                ->comment('释放时间：释放后不再可分配，保留历史记录');
            $table->string('release_reason', 255)->nullable()->after('released_at')
                ->comment('释放原因');
            $table->foreignId('released_by')->nullable()->after('release_reason')
                ->constrained('users')->nullOnDelete();
        });

        // subscriptions: 退订标记
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('last_renewed_at')
                ->comment('退订时间');
            $table->string('refund_reason', 500)->nullable()->after('refunded_at');
            $table->decimal('refund_amount', 12, 2)->nullable()->after('refund_reason')
                ->comment('退款金额');
            $table->foreignId('refunded_by')->nullable()->after('refund_amount')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_at', 'release_reason', 'released_by']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['refunded_by']);
            $table->dropColumn(['refunded_at', 'refund_reason', 'refund_amount', 'refunded_by']);
        });
    }
};
