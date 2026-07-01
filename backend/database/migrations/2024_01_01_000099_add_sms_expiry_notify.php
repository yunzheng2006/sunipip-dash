<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('sms_expiry_notify')->default(false)->after('auto_renew_default')
                ->comment('到期短信提醒开关');
        });

        Schema::table('sms_providers', function (Blueprint $table) {
            $table->string('expiry_template_code', 100)->nullable()->after('config')
                ->comment('到期提醒短信模板Code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('sms_expiry_notify');
        });
        Schema::table('sms_providers', function (Blueprint $table) {
            $table->dropColumn('expiry_template_code');
        });
    }
};
