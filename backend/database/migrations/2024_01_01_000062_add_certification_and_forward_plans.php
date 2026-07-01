<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Customer certification fields
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('forward_certified')->default(false)->after('auto_renew_default')
                ->comment('是否通过中转认证');
            $table->timestamp('forward_certified_at')->nullable()->after('forward_certified');
            $table->unsignedBigInteger('forward_certified_by')->nullable()->after('forward_certified_at');
        });

        // 2. Forward plans (中转套餐)
        Schema::create('forward_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('套餐名');
            $table->string('type', 20)->comment('ny/xui');
            $table->unsignedBigInteger('panel_id')->nullable()->comment('NY或XUI面板ID');
            $table->unsignedBigInteger('device_group_id')->nullable()->comment('NY设备组ID');
            $table->integer('speed_limit_mbps')->default(0)->comment('限速Mbps, 0=不限');
            $table->decimal('base_price', 10, 2)->comment('月基础费(含流量包)');
            $table->integer('included_traffic_gb')->default(0)->comment('包含流量GB');
            $table->decimal('overage_price_per_gb', 10, 2)->default(1.00)->comment('超额单价/GB');
            $table->tinyInteger('is_active')->default(1);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // 3. Forward rules add traffic tracking
        Schema::table('forward_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('forward_plan_id')->nullable()->after('forward_fee')
                ->comment('关联中转套餐');
            $table->bigInteger('traffic_used_bytes')->default(0)->after('forward_plan_id');
            $table->bigInteger('traffic_limit_bytes')->default(0)->after('traffic_used_bytes');
            $table->decimal('overage_charged', 10, 2)->default(0)->after('traffic_limit_bytes');
        });

        // 4. Customer special prices (特批价)
        Schema::create('customer_special_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 10)->nullable();
            $table->string('area_code', 50)->nullable();
            $table->string('city_code', 50)->nullable();
            $table->string('product_id', 100)->nullable()->comment('Spark productId, null=该地区通用');
            $table->decimal('special_price', 10, 2)->comment('特批月单价');
            $table->unsignedBigInteger('approved_by')->nullable()->comment('审批人');
            $table->string('remark', 500)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->index(['customer_id', 'country_code', 'is_active']);
        });

        // 5. Add type to provision_approvals for certification vs other
        Schema::table('provision_approvals', function (Blueprint $table) {
            $table->string('type', 30)->default('certification')->after('order_no')
                ->comment('certification/custom_price/other');
        });
    }

    public function down(): void
    {
        Schema::table('provision_approvals', function (Blueprint $table) {
            $table->dropColumn('type');
        });
        Schema::dropIfExists('customer_special_prices');
        Schema::table('forward_rules', function (Blueprint $table) {
            $table->dropColumn(['forward_plan_id', 'traffic_used_bytes', 'traffic_limit_bytes', 'overage_charged']);
        });
        Schema::dropIfExists('forward_plans');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['forward_certified', 'forward_certified_at', 'forward_certified_by']);
        });
    }
};
