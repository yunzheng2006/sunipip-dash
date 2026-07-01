<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付网关配置（管理员在后台录入）
 *
 * 当前支持的 type：
 *   - epay       易支付（兼容第三方聚合网关，如 xypay / yizhifu / nodelay 等）
 *   - wechat     微信官方支付（未来）
 *   - alipay     支付宝官方（未来）
 *
 * config 字段（JSON）按 type 存储不同 schema：
 *   epay: {
 *     pid: "1001",          // 商户 ID
 *     key: "xxxxxx",        // 商户 KEY（密钥）
 *     api_url: "https://payments.nodelay.cloud",  // 网关对接域名（不含 /submit.php）
 *     methods: ["alipay","wxpay","qqpay"]  // 支持的子支付方式
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('显示名，如"易支付-主账号"');
            $table->string('type', 30)->comment('epay/wechat/alipay');
            $table->json('config')->comment('网关配置(JSON)');
            $table->tinyInteger('is_active')->default(1);
            $table->unsignedInteger('sort')->default(0)->comment('排序(客户端展示顺序)');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
