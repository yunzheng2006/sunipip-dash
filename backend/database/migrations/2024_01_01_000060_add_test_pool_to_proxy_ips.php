<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->boolean('is_test_pool')->default(false)->after('max_shared_users')
                ->comment('是否在测试IP池中');
            $table->timestamp('test_pool_added_at')->nullable()->after('is_test_pool');
            $table->unsignedBigInteger('test_pool_added_by')->nullable()->after('test_pool_added_at');
            $table->string('test_pool_reason', 500)->nullable()->after('test_pool_added_by');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropColumn(['is_test_pool', 'test_pool_added_at', 'test_pool_added_by', 'test_pool_reason']);
        });
    }
};
