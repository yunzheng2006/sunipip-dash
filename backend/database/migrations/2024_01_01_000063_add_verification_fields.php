<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('verified_type', 20)->nullable()->after('forward_certified_by')
                ->comment('none/personal/enterprise');
            $table->timestamp('verified_at')->nullable()->after('verified_type');
            $table->string('verified_name', 100)->nullable()->after('verified_at')
                ->comment('认证姓名/法人姓名');
            $table->string('verified_id_number', 30)->nullable()->after('verified_name')
                ->comment('身份证号(脱敏)');
            $table->string('verified_enterprise_name', 200)->nullable()->after('verified_id_number');
            $table->string('verified_credit_code', 30)->nullable()->after('verified_enterprise_name')
                ->comment('统一社会信用代码');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'verified_type', 'verified_at', 'verified_name',
                'verified_id_number', 'verified_enterprise_name', 'verified_credit_code',
            ]);
        });
    }
};
