<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pricing_multipliers', 'priority')) {
            Schema::table('pricing_multipliers', function (Blueprint $table) {
                $table->integer('priority')->default(0)->after('scope')
                    ->comment('优先级，数值越大越优先，同优先级按 scope 粒度决定');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pricing_multipliers', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
