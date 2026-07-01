<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add invite_code to users table (for staff/admin)
        Schema::table('users', function (Blueprint $table) {
            $table->string('invite_code', 20)->nullable()->unique()->after('status')
                ->comment('业务员邀请码');
        });

        // Add registration fields to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable()->after('customer_name')
                ->comment('唯一标识/显示名');
            $table->boolean('is_company')->default(false)->after('company_name');
            $table->string('business_license', 100)->nullable()->after('is_company')
                ->comment('营业执照号');
            $table->unsignedBigInteger('invited_by')->nullable()->after('sales_person')
                ->comment('邀请人(users.id)');
            $table->string('invite_code_used', 20)->nullable()->after('invited_by');
        });

        // Create system_configs table if not exists (for registration settings)
        if (!Schema::hasTable('system_configs')) {
            Schema::create('system_configs', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->text('value')->nullable();
                $table->string('type', 20)->default('string')->comment('string/json/boolean/integer');
                $table->string('group', 50)->default('general');
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('invite_code');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'display_name',
                'is_company',
                'business_license',
                'invited_by',
                'invite_code_used',
            ]);
        });

        Schema::dropIfExists('system_configs');
    }
};
