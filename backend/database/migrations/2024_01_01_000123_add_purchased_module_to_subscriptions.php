<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('purchased_module', 20)->nullable()->after('has_forward')
                ->comment('static/video/live_mobile/live_pc');
        });

        // Back-fill existing subscriptions that have forward rules with a plan
        DB::statement("
            UPDATE subscriptions s
            INNER JOIN forward_rules fr ON fr.subscription_id = s.id AND fr.status != 'deleted'
            INNER JOIN forward_plans fp ON fp.id = fr.forward_plan_id
            SET s.purchased_module = fp.module
            WHERE s.purchased_module IS NULL AND fp.module IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('purchased_module');
        });
    }
};
