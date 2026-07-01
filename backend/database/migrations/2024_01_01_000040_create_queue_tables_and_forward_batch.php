<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel 队列基础设施 + forward_rules 批次追踪
 *
 *  1. jobs         - 队列任务表（database 驱动）
 *  2. failed_jobs  - 失败任务归档
 *  3. forward_rules.batch_id - 批次 id，用于进度查询
 *
 * 部署后需要在 .env 把 QUEUE_CONNECTION 改为 database：
 *   QUEUE_CONNECTION=database
 *
 * 并配置 supervisor 跑 `php artisan queue:work database --tries=2 --timeout=60 --sleep=1`
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        Schema::table('forward_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('forward_rules', 'batch_id')) {
                $table->string('batch_id', 64)->nullable()->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('forward_rules', function (Blueprint $table) {
            if (Schema::hasColumn('forward_rules', 'batch_id')) {
                $table->dropColumn('batch_id');
            }
        });
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
