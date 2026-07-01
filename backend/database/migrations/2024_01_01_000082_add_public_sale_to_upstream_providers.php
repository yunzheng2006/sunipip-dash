<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upstream_providers', function (Blueprint $table) {
            $table->boolean('public_sale')->default(false)->after('is_active')
                ->comment('是否允许客户自助下单');
        });
    }

    public function down(): void
    {
        Schema::table('upstream_providers', function (Blueprint $table) {
            $table->dropColumn('public_sale');
        });
    }
};
