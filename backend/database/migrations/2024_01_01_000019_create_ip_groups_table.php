<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IP组：定价和分类的核心单元
        // 例如：双Cogent、原生ISP、广播IP、单ISP住宅 等
        Schema::create('ip_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('IP组名称(如 双Cogent, 原生ISP)');
            $table->string('slug', 100)->unique()->comment('标识符，批量导入用');
            $table->char('country_code', 2)->nullable()->comment('国家代码');
            $table->string('country_name', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('isp_type', 50)->nullable()->comment('ISP类型描述');
            $table->string('net_type', 20)->nullable()->comment('native/broadcast/unknown');
            $table->text('description')->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->timestamps();

            $table->index('country_code');
            $table->index('status');
        });

        // proxy_ips 加 ip_group_id
        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->foreignId('ip_group_id')->nullable()->after('asset_group_id')
                ->constrained('ip_groups')->nullOnDelete();
            $table->index('ip_group_id');
        });

        // pricing_rules 加 ip_group_id，改为按IP组定价
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->foreignId('ip_group_id')->nullable()->after('id')
                ->constrained('ip_groups')->cascadeOnDelete();
            $table->index('ip_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropForeign(['ip_group_id']);
            $table->dropColumn('ip_group_id');
        });

        Schema::table('proxy_ips', function (Blueprint $table) {
            $table->dropForeign(['ip_group_id']);
            $table->dropColumn('ip_group_id');
        });

        Schema::dropIfExists('ip_groups');
    }
};
