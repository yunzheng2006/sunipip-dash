<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_group_id')->nullable()->constrained('ip_asset_groups')->nullOnDelete();
            $table->string('source_type', 30)->comment('manual/csv/api');
            $table->string('file_name', 255)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->json('error_details')->nullable()->comment('逐行错误详情');
            $table->string('status', 20)->default('pending')->comment('pending/processing/completed/failed');
            $table->foreignId('imported_by')->constrained('users');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_import_logs');
    }
};
