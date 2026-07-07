<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\BalanceController as CustomerBalanceController;
use App\Http\Controllers\Api\V1\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Api\V1\Customer\ProfileController as CustomerProfileController;
use App\Http\Controllers\Api\V1\Customer\ProxyIpController as CustomerProxyIpController;
use App\Http\Controllers\Api\V1\Customer\StoreController as CustomerStoreController;
use App\Http\Controllers\Api\V1\Customer\SubscriptionController as CustomerSubscriptionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\IpAssetGroupController;
use App\Http\Controllers\Api\V1\IpGroupController;
use App\Http\Controllers\Api\V1\AgentApiController;
use App\Http\Controllers\Api\V1\DnsMonitorController;
use App\Http\Controllers\Api\V1\FeishuSyncController;
use App\Http\Controllers\Api\V1\NyPanelController;
use App\Http\Controllers\Api\V1\QueueMonitorController;
use App\Http\Controllers\Api\V1\XuiPanelController;
use App\Http\Controllers\Api\V1\Payment\AlipayNotifyController;
use App\Http\Controllers\Api\V1\Payment\EPayNotifyController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Http\Controllers\Api\V1\PaymentRefundController;
use App\Http\Controllers\Api\V1\PricingRuleController;
use App\Http\Controllers\Api\V1\ProxyIpController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SparkController;
use App\Http\Controllers\Api\V1\IpipvController;
use App\Http\Controllers\Api\V1\UpstreamProviderController;
use App\Http\Controllers\Api\V1\ProductPricingController;
use App\Http\Controllers\Api\V1\SparkPricingRuleController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ForwardPlanController;
use App\Http\Controllers\Api\V1\CustomerSpecialPriceController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\SmsProviderController;
use App\Http\Controllers\Api\V1\RegistrationSettingsController;
use App\Http\Controllers\Api\V1\SalesStatsController;
use App\Http\Controllers\Api\V1\SalesStatsNewController;
use App\Http\Controllers\Api\V1\ManualPerformanceController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\BigDataController;
use App\Http\Controllers\Api\V1\Oidc\AuthorizationController as OidcAuthorizationController;
use App\Http\Controllers\Api\V1\Oidc\OauthClientController;
use App\Http\Controllers\Api\V1\Oidc\TokenController as OidcTokenController;
use App\Http\Controllers\Api\V1\Oidc\UserInfoController as OidcUserInfoController;
use App\Http\Controllers\Api\V1\RouterAgentController;
use App\Http\Controllers\Api\V1\RouterCatalogController;
use App\Http\Controllers\Api\V1\RouterDeviceController;
use App\Http\Controllers\Api\V1\WgServerController;
use App\Http\Controllers\Api\V1\Customer\RouterController as CustomerRouterController;
use Illuminate\Support\Facades\Route;

// 公开接口
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Spark 回调 (公开，不需要认证)
    Route::get('spark/notify', [SparkController::class, 'notify']);

    // IPIPV 回调 (公开，不需要认证)
    Route::get('ipipv/callback', [IpipvController::class, 'callback']);

    // 统一上游回调路由（兼容所有插件，按 slug 分发）
    Route::get('upstream/{slug}/callback', function (string $slug, \Illuminate\Http\Request $request) {
        $provider = \App\Models\UpstreamProvider::where('slug', $slug)->first();
        if (!$provider) {
            return response()->json(['code' => 'error', 'msg' => 'Unknown provider'], 404);
        }
        return match ($provider->driver) {
            'spark' => app(SparkController::class)->notify($request),
            'ipipv' => app(IpipvController::class)->callback($request),
            default => response()->json(['code' => 'error', 'msg' => 'Unsupported driver'], 422),
        };
    });

    // 客户自助面板 - 公开（带限速）
    Route::prefix('customer')->group(function () {
        Route::post('auth/register', [CustomerAuthController::class, 'register'])->middleware('throttle:5,10');
        Route::post('auth/login', [CustomerAuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('auth/login-sms', [CustomerAuthController::class, 'loginBySms'])->middleware('throttle:10,1');
    });

    // SMS验证码（公开）
    Route::post('customer/sms/send-code', [\App\Http\Controllers\Api\V1\Customer\SmsController::class, 'sendCode'])->middleware('throttle:10,1');
    Route::get('customer/sms/captcha', [\App\Http\Controllers\Api\V1\Customer\SmsController::class, 'captcha']);

    // 支付网关异步回调（公开路由，签名校验代替身份认证）
    // EPay 使用 GET 方式回调
    Route::match(['get', 'post'], 'payment/epay/notify/{gateway}', [EPayNotifyController::class, 'notify']);
    // Alipay 异步通知（POST）+ 同步回跳（GET）
    Route::match(['get', 'post'], 'payment/alipay/notify/{gateway}', [AlipayNotifyController::class, 'notify']);

    // DNS 容灾 Agent 接口（公开，X-Agent-Key header 鉴权）
    Route::post('agent/heartbeat', [AgentApiController::class, 'heartbeat']);
    Route::post('agent/report', [AgentApiController::class, 'report']);

    // 软路由 Agent 接口（公开，install_token / X-Agent-Key header 鉴权）
    Route::post('router-agent/register', [RouterAgentController::class, 'register']);
    Route::post('router-agent/heartbeat', [RouterAgentController::class, 'heartbeat']);
    Route::get('router-agent/config', [RouterAgentController::class, 'pullConfig']);
    Route::post('router-agent/ack-config', [RouterAgentController::class, 'ackConfig']);
    Route::post('router-agent/event', [RouterAgentController::class, 'reportEvent']);
    Route::post('router-agent/command-result', [RouterAgentController::class, 'commandResult']);
    Route::get('router-agent/download', [RouterAgentController::class, 'downloadBinary']);

    // 软路由安装脚本下载（公开，token 嵌入脚本）
    Route::get('router-install/{token}', [RouterAgentController::class, 'installScript']);

    // 软路由二进制下载（公开）
    Route::get('router-downloads/{filename}', function (string $filename) {
        $allowed = ['sunipip-router-agent-linux-amd64', 'router-frontend-dist.tar.gz', 'mihomo-amd64.gz', 'mihomo-amd64-compatible.gz'];
        if (!in_array($filename, $allowed)) {
            abort(404);
        }
        $path = storage_path("app/router-downloads/{$filename}");
        if (!file_exists($path)) {
            abort(404, '文件不存在，请联系管理员上传');
        }
        return response()->download($path);
    });

    // 网站信息（公开，供前端布局读取站名+Logo）
    Route::get('site-info', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'info']);

    // 页面访问打点（公开，无需登录，带 token 则记录 customer_id）
    Route::post('customer/track-visit', [AnalyticsController::class, 'trackVisit'])->middleware('throttle:60,1');

    // BigData 大屏看板（公开，自带鉴权：Sanctum token 或 ?key= 参数）
    Route::get('bigdata/dashboard', [BigDataController::class, 'dashboard']);

    // OIDC / OAuth2 token endpoint（公开，client credentials in body/header）
    Route::post('oauth/token', [OidcTokenController::class, 'token']);
});

// ===== 对外开放的公开 API（需 X-API-Key） =====
Route::prefix('public/v1')->group(function () {
    Route::get('products', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'products'])
        ->middleware('api.key:store.products');
    Route::get('stock-by-country', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'stockByCountry'])
        ->middleware('api.key:store.stock');
    Route::get('vip-tiers', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'vipTiers'])
        ->middleware('api.key:vip.tiers');
});

// 客户自助面板 - 需登录
// auth:sanctum: 校验 token 合法
// ability:customer: 校验 token 签发时的 ability，仅 customer 登录的 token 能通过
// customer.auth: 额外校验 tokenable 是 Customer 实例且账号启用
Route::prefix('v1/customer')
    ->middleware(['auth:sanctum', 'ability:customer', 'customer.auth'])
    ->group(function () {

        // ── 无需实名：认证、资料、预览、实名流程本身 ──
        Route::get('auth/me', [CustomerAuthController::class, 'me']);
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
        Route::put('auth/password', [CustomerAuthController::class, 'changePassword']);

        Route::get('profile', [CustomerProfileController::class, 'show']);
        Route::put('profile', [CustomerProfileController::class, 'update']);

        Route::get('dashboard', [CustomerDashboardController::class, 'index']);

        // 商店预览（只看价格，不下单）
        Route::get('store/products', [CustomerStoreController::class, 'products']);
        Route::get('store/countries', [CustomerStoreController::class, 'products']);
        Route::get('store/countries/{code}', [CustomerStoreController::class, 'products']);

        // 实名认证
        Route::get('verification/status', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'status']);
        Route::post('verification/personal/init', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'initPersonal'])->middleware('throttle:5,1');
        Route::post('verification/personal/confirm', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'confirmPersonal'])->middleware('throttle:10,1');
        Route::post('verification/personal/poll', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'pollPersonal'])->middleware('throttle:30,1');
        Route::post('verification/personal/resume', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'resumePersonal'])->middleware('throttle:10,1');
        Route::post('verification/enterprise/ocr', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'ocrLicense'])->middleware('throttle:10,1');
        Route::post('verification/enterprise/verify', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'verifyEnterprise'])->middleware('throttle:5,1');
        Route::post('verification/enterprise/confirm', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'confirmEnterprise'])->middleware('throttle:10,1');
        Route::post('verification/enterprise/poll', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'pollEnterprise'])->middleware('throttle:30,1');
        Route::get('verification/info', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'info']);
        Route::post('verification/upgrade-enterprise/ocr', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'upgradeOcr'])->middleware('throttle:10,1');
        Route::post('verification/upgrade-enterprise/verify', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'upgradeVerify'])->middleware('throttle:5,1');
        Route::post('verification/upgrade-enterprise/poll', [\App\Http\Controllers\Api\V1\Customer\VerificationController::class, 'upgradePoll'])->middleware('throttle:30,1');

        // 中转套餐预览 / VIP 信息
        Route::get('forward-plans', [ForwardPlanController::class, 'activePlans']);
        Route::get('vip', function (\Illuminate\Http\Request $request) {
            $customer = $request->user();
            $customer->load('vipTier');
            $tiers = \App\Models\VipTier::where('is_active', 1)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'spending_threshold', 'topup_threshold', 'discount_percent', 'badge_color', 'description', 'sort_order']);

            $hasSpecialPricing = \App\Models\CustomerSpecialPrice::where('customer_id', $customer->id)
                ->where('is_active', 1)
                ->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'current_tier' => $customer->vipTier ? [
                        'id' => $customer->vipTier->id,
                        'name' => $customer->vipTier->name,
                        'discount_percent' => $customer->vipTier->discount_percent,
                        'badge_color' => $customer->vipTier->badge_color,
                    ] : null,
                    'has_special_pricing' => $hasSpecialPricing,
                    'total_spent' => (float) $customer->total_spent,
                    'max_single_topup' => (float) $customer->max_single_topup,
                    'all_tiers' => $tiers,
                    'sales_person' => $customer->sales_person,
                    'support_wechat' => \App\Models\SystemConfig::get('support.wechat'),
                    'support_phone' => \App\Models\SystemConfig::get('support.phone'),
                ],
            ]);
        });

        // ── 无需实名：查看/管理已有资产 ──
        // 软路由（查看）
        Route::get('router/devices', [CustomerRouterController::class, 'myDevices']);
        Route::get('router/devices/{id}', [CustomerRouterController::class, 'showDevice']);
        Route::get('router/devices/{id}/wifi-accounts', [CustomerRouterController::class, 'wifiAccounts']);
        Route::get('router/devices/{id}/available-subscriptions', [CustomerRouterController::class, 'availableSubscriptions']);
        Route::get('router/devices/{id}/status', [CustomerRouterController::class, 'deviceStatus']);
        Route::get('router/wifi-accounts/{id}/ios-profile', [CustomerRouterController::class, 'wifiProfile']);

        // 订阅（查看）
        Route::get('subscriptions', [CustomerSubscriptionController::class, 'index']);
        Route::post('subscriptions/identify-ips', [CustomerSubscriptionController::class, 'identifyIps']);
        Route::get('subscriptions/{id}', [CustomerSubscriptionController::class, 'show']);

        // 我的 IP（查看/导出）
        Route::get('ips', [CustomerProxyIpController::class, 'index']);
        Route::get('ips/export', [CustomerProxyIpController::class, 'export']);
        Route::get('ips/export-qr', [CustomerProxyIpController::class, 'exportQr']);
        Route::get('ips/{id}', [CustomerProxyIpController::class, 'show']);

        // IP 分组
        Route::get('ip-groups', [\App\Http\Controllers\Api\V1\Customer\IpGroupController::class, 'index']);
        Route::post('ip-groups', [\App\Http\Controllers\Api\V1\Customer\IpGroupController::class, 'store']);
        Route::put('ip-groups/{id}', [\App\Http\Controllers\Api\V1\Customer\IpGroupController::class, 'update']);
        Route::delete('ip-groups/{id}', [\App\Http\Controllers\Api\V1\Customer\IpGroupController::class, 'destroy']);
        Route::post('ip-groups/{id}/add-ips', [\App\Http\Controllers\Api\V1\Customer\IpGroupController::class, 'addIps']);
        Route::post('ip-groups/{id}/remove-ips', [\App\Http\Controllers\Api\V1\Customer\IpGroupController::class, 'removeIps']);

        // 账单（查看）
        Route::get('balance', [CustomerBalanceController::class, 'balance']);
        Route::get('transactions', [CustomerBalanceController::class, 'transactions']);
        Route::get('topup/methods', [CustomerBalanceController::class, 'topupMethods']);
        Route::get('topup/orders', [CustomerBalanceController::class, 'topupOrders']);
        Route::get('topup/orders/{orderNo}', [CustomerBalanceController::class, 'topupOrder']);

        // 备注（不需要实名）
        Route::patch('subscriptions/{id}/remark', [CustomerSubscriptionController::class, 'updateRemark']);

        // ── 需要实名认证才能操作（购买/充值/资金变动） ──
        Route::middleware('customer.verified')->group(function () {
            // 软路由（操作）
            Route::post('router/activate', [CustomerRouterController::class, 'activate']);
            Route::post('router/devices/{id}/wifi-accounts', [CustomerRouterController::class, 'createWifiAccount']);
            Route::put('router/wifi-accounts/{id}', [CustomerRouterController::class, 'updateWifiAccount']);
            Route::delete('router/wifi-accounts/{id}', [CustomerRouterController::class, 'deleteWifiAccount']);
            Route::post('router/devices/{id}/clean-stale-connections', [CustomerRouterController::class, 'cleanStaleConnections']);

            // 下单
            Route::post('store/checkout', [CustomerStoreController::class, 'checkout'])->middleware('throttle:10,1');

            // 订阅（操作）
            Route::put('subscriptions/batch-auto-renew', [CustomerSubscriptionController::class, 'batchToggleAutoRenew']);
            Route::post('subscriptions/batch-renew-by-ip', [CustomerSubscriptionController::class, 'batchRenewByIp'])->middleware('throttle:10,1');
            Route::get('subscriptions/{id}/upgrade-forward-preview', [CustomerSubscriptionController::class, 'upgradeForwardPreview']);
            Route::post('subscriptions/{id}/upgrade-forward', [CustomerSubscriptionController::class, 'upgradeForward'])->middleware('throttle:5,1');
            Route::post('subscriptions/{id}/renew', [CustomerSubscriptionController::class, 'renew']);
            Route::post('subscriptions/{id}/refund', [CustomerSubscriptionController::class, 'refund'])->middleware('throttle:5,1');
            Route::put('subscriptions/{id}/auto-renew', [CustomerSubscriptionController::class, 'toggleAutoRenew']);
            Route::post('subscriptions/{id}/redeem', [CustomerSubscriptionController::class, 'redeem'])->middleware('throttle:3,1');
            // 充值（操作）
            Route::post('topup/create', [CustomerBalanceController::class, 'createTopup'])->middleware('throttle:10,1');

            // 推广（查看 + 操作）
            Route::get('referral', [\App\Http\Controllers\Api\V1\Customer\ReferralController::class, 'index']);
            Route::get('referral/commissions', [\App\Http\Controllers\Api\V1\Customer\ReferralController::class, 'commissions']);
            Route::put('referral/withdraw-info', [\App\Http\Controllers\Api\V1\Customer\ReferralController::class, 'updateWithdrawInfo']);
            Route::post('referral/withdraw', [\App\Http\Controllers\Api\V1\Customer\ReferralController::class, 'requestWithdraw'])->middleware('throttle:5,1');
            Route::post('referral/transfer-to-balance', [\App\Http\Controllers\Api\V1\Customer\ReferralController::class, 'transferToBalance'])->middleware('throttle:10,1');
        });
    });

// OIDC OAuth2 authorize（需客户登录）
Route::prefix('v1/oauth')
    ->middleware(['auth:sanctum', 'ability:customer', 'customer.auth'])
    ->group(function () {
        Route::get('authorize', [OidcAuthorizationController::class, 'authorize']);
        Route::post('authorize', [OidcAuthorizationController::class, 'approveOrDeny']);
    });

// OIDC userinfo（Bearer token 鉴权）
Route::prefix('v1/oauth')
    ->middleware('oauth.bearer')
    ->group(function () {
        Route::get('userinfo', [OidcUserInfoController::class, 'userinfo']);
    });

// 需要认证的接口 + 活动日志记录
// ability:admin 确保只接受 admin token（阻止 customer token 误入管理 API）
Route::prefix('v1')->middleware(['auth:sanctum', 'ability:admin', 'log.activity'])->group(function () {

    // 认证（所有登录用户可访问）
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);

    // 仪表盘
    Route::get('dashboard/stats', [DashboardController::class, 'stats'])->middleware('perm:dashboard.view');
    Route::get('dashboard/expiring', [DashboardController::class, 'expiring'])->middleware('perm:dashboard.view');
    Route::get('dashboard/recent', [DashboardController::class, 'recent'])->middleware('perm:dashboard.view');

    // 客户管理
    Route::get('customers', [CustomerController::class, 'index'])->middleware('perm:customer.view');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('perm:customer.create');
    // 注意：merge 必须放在 {customer} 之前，否则会被 route-model-binding 拦截
    Route::post('customers/merge-preview', [CustomerController::class, 'mergePreview'])->middleware('perm:customer.edit');
    Route::post('customers/merge', [CustomerController::class, 'merge'])->middleware('perm:customer.edit');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('perm:customer.view');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('perm:customer.edit');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('perm:customer.delete');
    Route::post('customers/{customer}/topup', [CustomerController::class, 'topup'])->middleware('perm:customer.topup');
    Route::post('customers/{customer}/adjust-balance', [CustomerController::class, 'adjustBalance'])->middleware('perm:customer.topup');
    Route::post('customers/{customer}/reset-password', [CustomerController::class, 'resetPassword'])->middleware('perm:customer.edit');
    Route::post('customers/{customer}/impersonate', [CustomerController::class, 'impersonate'])->middleware('perm:customer.view');
    Route::post('customers/{customer}/set-referrer', [CustomerController::class, 'setReferrer'])->middleware('perm:customer.edit');
    Route::post('customers/{customer}/clear-referrer', [CustomerController::class, 'clearReferrer'])->middleware('perm:customer.edit');
    Route::post('customers/{customer}/transfer-referrer', [CustomerController::class, 'transferReferrer'])->middleware('perm:customer.edit');
    Route::post('customers/{customer}/change-sales', [CustomerController::class, 'changeSales'])->middleware('perm:customer.change_sales');
    Route::get('customers/{customer}/verification-info', [CustomerController::class, 'verificationInfo'])->middleware('perm:customer.view_verification');
    Route::post('customers/{customer}/manual-verify', [CustomerController::class, 'manualVerify'])->middleware('perm:customer.reset_verification');
    Route::post('customers/{customer}/reset-verification', [CustomerController::class, 'resetVerification'])->middleware('perm:customer.reset_verification');

    // IP资产管理
    Route::get('proxy-ips', [ProxyIpController::class, 'index'])->middleware('perm:ip.view');
    Route::post('proxy-ips', [ProxyIpController::class, 'store'])->middleware('perm:ip.create');
    Route::post('proxy-ips/batch', [ProxyIpController::class, 'batchStore'])->middleware('perm:ip.create');
    Route::post('proxy-ips/batch-assign', [ProxyIpController::class, 'batchAssign'])->middleware('perm:ip.assign');
    Route::post('proxy-ips/batch-release', [ProxyIpController::class, 'batchRelease'])->middleware('perm:ip.delete');
    Route::post('proxy-ips/batch-delete', [ProxyIpController::class, 'batchDestroy'])->middleware('perm:ip.delete');
    Route::post('proxy-ips/batch-move-group', [ProxyIpController::class, 'batchMoveGroup'])->middleware('perm:ip.edit');
    Route::post('proxy-ips/import', [ProxyIpController::class, 'import'])->middleware('perm:ip.import');
    Route::get('proxy-ips/test-pool', [ProxyIpController::class, 'testPool'])->middleware('perm:ip.view');
    Route::post('proxy-ips/batch-test-pool', [ProxyIpController::class, 'batchAddToTestPool'])->middleware('perm:ip.edit');
    Route::post('proxy-ips/batch-remove-test-pool', [ProxyIpController::class, 'batchRemoveFromTestPool'])->middleware('perm:ip.edit');
    Route::post('proxy-ips/test-pool-assign', [ProxyIpController::class, 'testPoolAssign'])->middleware('perm:ip.assign');
    Route::post('proxy-ips/test-pool-unassign', [ProxyIpController::class, 'testPoolUnassign'])->middleware('perm:ip.assign');
    Route::get('proxy-ips/{proxy_ip}', [ProxyIpController::class, 'show'])->middleware('perm:ip.view');
    Route::put('proxy-ips/{proxy_ip}', [ProxyIpController::class, 'update'])->middleware('perm:ip.edit');
    Route::delete('proxy-ips/{proxy_ip}', [ProxyIpController::class, 'destroy'])->middleware('perm:ip.delete');
    Route::post('proxy-ips/{proxy_ip}/assign', [ProxyIpController::class, 'assign'])->middleware('perm:ip.assign');
    Route::post('proxy-ips/{proxy_ip}/unassign', [ProxyIpController::class, 'unassign'])->middleware('perm:ip.unassign');
    Route::post('proxy-ips/{proxy_ip}/release', [ProxyIpController::class, 'release'])->middleware('perm:ip.delete');
    Route::post('proxy-ips/{proxy_ip}/verify-spark-release', [ProxyIpController::class, 'verifySparkRelease'])->middleware('perm:spark.manage');
    Route::post('proxy-ips/{proxy_ip}/retry-spark-release', [ProxyIpController::class, 'retrySparkRelease'])->middleware('perm:spark.manage');
    Route::get('proxy-ips-stats', [ProxyIpController::class, 'stats'])->middleware('perm:ip.view');

    // 资产组管理
    Route::get('asset-groups/all', [IpAssetGroupController::class, 'all'])->middleware('perm:asset_group.view');
    Route::get('asset-groups', [IpAssetGroupController::class, 'index'])->middleware('perm:asset_group.view');
    Route::post('asset-groups', [IpAssetGroupController::class, 'store'])->middleware('perm:asset_group.create');
    Route::post('asset-groups/merge', [IpAssetGroupController::class, 'merge'])->middleware('perm:asset_group.edit');
    Route::get('asset-groups/{ipAssetGroup}', [IpAssetGroupController::class, 'show'])->middleware('perm:asset_group.view');
    Route::put('asset-groups/{ipAssetGroup}', [IpAssetGroupController::class, 'update'])->middleware('perm:asset_group.edit');
    Route::delete('asset-groups/{ipAssetGroup}', [IpAssetGroupController::class, 'destroy'])->middleware('perm:asset_group.delete');

    // IP组管理
    Route::get('ip-groups/all', [IpGroupController::class, 'all'])->middleware('perm:asset_group.view');
    Route::get('ip-groups', [IpGroupController::class, 'index'])->middleware('perm:asset_group.view');
    Route::get('ip-groups/{ipGroup}', [IpGroupController::class, 'show'])->middleware('perm:asset_group.view');
    Route::post('ip-groups', [IpGroupController::class, 'store'])->middleware('perm:asset_group.create');
    Route::put('ip-groups/{ipGroup}', [IpGroupController::class, 'update'])->middleware('perm:asset_group.edit');
    Route::delete('ip-groups/{ipGroup}', [IpGroupController::class, 'destroy'])->middleware('perm:asset_group.delete');

    // Spark API
    Route::get('spark/debug', [SparkController::class, 'debug'])->middleware('perm:spark.view');
    Route::get('spark/products', [SparkController::class, 'products'])->middleware('perm:spark.view_stock');
    Route::get('spark/stock-by-country', [SparkController::class, 'stockByCountry'])->middleware('perm:spark.view_stock');
    Route::get('spark/balance', [SparkController::class, 'balance'])->middleware('perm:spark.view');
    Route::post('spark/reset-password', [SparkController::class, 'resetPassword'])->middleware('perm:spark.manage');
    Route::get('spark/ip-segments', [SparkController::class, 'ipSegments'])->middleware('perm:spark.view_stock');
    Route::post('spark/provision', [SparkController::class, 'provision'])->middleware('perm:spark.manage');
    Route::post('spark/sync-order/{sparkOrder}', [SparkController::class, 'syncOrder'])->middleware('perm:spark.manage');
    Route::post('spark/renew', [SparkController::class, 'renew'])->middleware('perm:spark.manage');
    Route::post('spark/release', [SparkController::class, 'release'])->middleware('perm:spark.manage');
    Route::get('spark/orders', [SparkController::class, 'orders'])->middleware('perm:spark.view');
    Route::post('spark/match', [SparkController::class, 'matchInstance'])->middleware('perm:spark.manage');
    Route::post('spark/bulk-match', [SparkController::class, 'bulkMatch'])->middleware('perm:spark.manage');
    Route::post('spark/sync-all', [SparkController::class, 'syncAll'])->middleware('perm:spark.manage');

    // Spark 产品屏蔽
    Route::get('spark/product-blocks', [\App\Http\Controllers\Api\V1\SparkProductBlockController::class, 'index'])->middleware('perm:spark.manage');
    Route::get('spark/product-blocks/all-products', [\App\Http\Controllers\Api\V1\SparkProductBlockController::class, 'allProducts'])->middleware('perm:spark.manage');
    Route::post('spark/product-blocks', [\App\Http\Controllers\Api\V1\SparkProductBlockController::class, 'store'])->middleware('perm:spark.manage');
    Route::delete('spark/product-blocks/{sparkProductBlock}', [\App\Http\Controllers\Api\V1\SparkProductBlockController::class, 'destroy'])->middleware('perm:spark.manage');
    Route::post('spark/product-blocks/bulk-destroy', [\App\Http\Controllers\Api\V1\SparkProductBlockController::class, 'bulkDestroy'])->middleware('perm:spark.manage');

    // Spark 地区查询（基础数据，所有登录用户可查）
    Route::get('spark/areas/countries', [SparkController::class, 'countries']);
    Route::get('spark/areas/states', [SparkController::class, 'states']);
    Route::get('spark/areas/cities', [SparkController::class, 'cities']);
    Route::post('spark/areas/translate', [SparkController::class, 'translateCountries']);

    // IPIPV API
    Route::get('ipipv/products', [IpipvController::class, 'products'])->middleware('perm:spark.view_stock');
    Route::get('ipipv/balance', [IpipvController::class, 'balance'])->middleware('perm:spark.view');
    Route::post('ipipv/provision', [IpipvController::class, 'provision'])->middleware('perm:spark.manage');
    Route::post('ipipv/sync-order/{ipipvOrder}', [IpipvController::class, 'syncOrder'])->middleware('perm:spark.manage');
    Route::post('ipipv/renew', [IpipvController::class, 'renew'])->middleware('perm:spark.manage');
    Route::post('ipipv/release', [IpipvController::class, 'release'])->middleware('perm:spark.manage');
    Route::get('ipipv/orders', [IpipvController::class, 'orders'])->middleware('perm:spark.view');
    Route::get('ipipv/areas', [IpipvController::class, 'areas'])->middleware('perm:spark.view');
    Route::get('ipipv/cities', [IpipvController::class, 'cities'])->middleware('perm:spark.view');
    Route::get('ipipv/stock-by-country', [IpipvController::class, 'stockByCountry'])->middleware('perm:spark.view_stock');

    // 上游 API 管理
    Route::get('upstream-providers/display-names', [UpstreamProviderController::class, 'displayNames'])->middleware('perm:spark.view_stock');
    Route::get('upstream-providers', [UpstreamProviderController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('upstream-providers', [UpstreamProviderController::class, 'store'])->middleware('perm:setting.manage');
    Route::put('upstream-providers/{upstreamProvider}', [UpstreamProviderController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('upstream-providers/{upstreamProvider}', [UpstreamProviderController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('upstream-providers/{upstreamProvider}/test', [UpstreamProviderController::class, 'test'])->middleware('perm:setting.manage');

    // 订阅管理
    Route::get('subscriptions/expiring', [SubscriptionController::class, 'expiring'])->middleware('perm:subscription.view');
    Route::get('subscriptions/available-ips', [SubscriptionController::class, 'availableIps'])->middleware('perm:subscription.view');
    Route::post('subscriptions/create-order', [SubscriptionController::class, 'createOrder'])->middleware('perm:subscription.create');
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->middleware('perm:subscription.view');
    // 注意：静态路径必须放在 {subscription} 之前，否则会被 route-model-binding 拦截
    Route::get('subscriptions/batch-forward-status/{batchId}', [SubscriptionController::class, 'batchForwardStatus'])->middleware('perm:subscription.view');
    Route::get('subscriptions/batch-xui-forward-status/{batchId}', [SubscriptionController::class, 'batchXuiForwardStatus'])->middleware('perm:subscription.view');
    Route::post('subscriptions/bulk-renew', [SubscriptionController::class, 'bulkRenew'])->middleware('perm:subscription.renew');
    Route::post('subscriptions/batch-attach-forward', [SubscriptionController::class, 'batchAttachForward'])->middleware('perm:subscription.edit_price');
    Route::post('subscriptions/batch-attach-xui-forward', [SubscriptionController::class, 'batchAttachXuiForward'])->middleware('perm:subscription.create');
    Route::post('subscriptions/batch-update-expiry', [SubscriptionController::class, 'batchUpdateExpiry'])->middleware('perm:subscription.update_expiry');
    Route::post('subscriptions/batch-update-price', [SubscriptionController::class, 'batchUpdatePrice'])->middleware('perm:subscription.edit_price');
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])->middleware('perm:subscription.view');
    Route::post('subscriptions/{subscription}/renew', [SubscriptionController::class, 'renew'])->middleware('perm:subscription.renew');
    Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->middleware('perm:subscription.cancel');
    Route::post('subscriptions/{subscription}/refund', [SubscriptionController::class, 'refund'])->middleware('perm:subscription.refund');
    Route::post('subscriptions/{subscription}/partial-refund', [SubscriptionController::class, 'partialRefund'])->middleware('perm:subscription.refund');
    Route::post('subscriptions/{subscription}/convert-test', [SubscriptionController::class, 'convertTest'])->middleware('perm:subscription.create');
    Route::post('subscriptions/{subscription}/downgrade', [SubscriptionController::class, 'downgrade'])->middleware('perm:subscription.refund');
    Route::post('subscriptions/{subscription}/transfer', [SubscriptionController::class, 'transfer'])->middleware('perm:subscription.transfer');
    Route::patch('subscriptions/{subscription}/remark', [SubscriptionController::class, 'updateRemark'])->middleware('perm:subscription.view');

    // 中转套餐管理
    Route::get('forward-plans', [ForwardPlanController::class, 'index'])->middleware('perm:forward.view');
    Route::post('forward-plans', [ForwardPlanController::class, 'store'])->middleware('perm:forward.manage');
    Route::put('forward-plans/{forwardPlan}', [ForwardPlanController::class, 'update'])->middleware('perm:forward.manage');
    Route::delete('forward-plans/{forwardPlan}', [ForwardPlanController::class, 'destroy'])->middleware('perm:forward.manage');

    // 客户特批价
    Route::get('customer-special-prices', [CustomerSpecialPriceController::class, 'index'])->middleware('perm:pricing.manage,pricing.set_discount');
    Route::get('customer-special-prices/debug-match', [CustomerSpecialPriceController::class, 'debugMatch'])->middleware('perm:pricing.manage');
    Route::post('customer-special-prices', [CustomerSpecialPriceController::class, 'store'])->middleware('perm:pricing.manage,pricing.set_discount');
    Route::put('customer-special-prices/{customerSpecialPrice}', [CustomerSpecialPriceController::class, 'update'])->middleware('perm:pricing.manage');
    Route::delete('customer-special-prices/{customerSpecialPrice}', [CustomerSpecialPriceController::class, 'destroy'])->middleware('perm:pricing.manage');

    // VIP会员等级
    Route::get('vip-tiers', [\App\Http\Controllers\Api\V1\VipTierController::class, 'index'])->middleware('perm:pricing.view');
    Route::post('vip-tiers', [\App\Http\Controllers\Api\V1\VipTierController::class, 'store'])->middleware('perm:pricing.manage');
    Route::post('vip-tiers/recalculate-all', [\App\Http\Controllers\Api\V1\VipTierController::class, 'recalculateAll'])->middleware('perm:pricing.manage');
    Route::put('vip-tiers/{vipTier}', [\App\Http\Controllers\Api\V1\VipTierController::class, 'update'])->middleware('perm:pricing.manage');
    Route::delete('vip-tiers/{vipTier}', [\App\Http\Controllers\Api\V1\VipTierController::class, 'destroy'])->middleware('perm:pricing.manage');

    // 审批中心
    Route::get('approvals/stats', [ApprovalController::class, 'stats'])->middleware('perm:approval.view');
    Route::get('approvals', [ApprovalController::class, 'index'])->middleware('perm:approval.view');
    Route::post('approvals', [ApprovalController::class, 'submit'])->middleware('perm:subscription.submit_approval');
    Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->middleware('perm:approval.view');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->middleware('perm:approval.review');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->middleware('perm:approval.review');
    Route::post('approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->middleware('perm:approval.view');

    // 交易流水
    Route::get('transactions', [TransactionController::class, 'index'])->middleware('perm:transaction.view');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->middleware('perm:transaction.view');

    // 定价规则
    Route::get('pricing-rules/lookup', [PricingRuleController::class, 'lookup'])->middleware('perm:pricing.view');
    Route::get('pricing-rules', [PricingRuleController::class, 'index'])->middleware('perm:pricing.view');
    Route::get('pricing-rules/{pricingRule}', [PricingRuleController::class, 'show'])->middleware('perm:pricing.view');
    Route::post('pricing-rules', [PricingRuleController::class, 'store'])->middleware('perm:pricing.manage');
    Route::put('pricing-rules/{pricingRule}', [PricingRuleController::class, 'update'])->middleware('perm:pricing.manage');
    Route::delete('pricing-rules/{pricingRule}', [PricingRuleController::class, 'destroy'])->middleware('perm:pricing.manage');

    // Spark IP 定价（按国家代码绑定）
    Route::get('spark-pricing/countries', [SparkPricingRuleController::class, 'countries'])->middleware('perm:pricing.view');
    Route::get('spark-pricing/lookup', [SparkPricingRuleController::class, 'lookup'])->middleware('perm:pricing.view');
    Route::get('spark-pricing', [SparkPricingRuleController::class, 'index'])->middleware('perm:pricing.view');
    Route::post('spark-pricing', [SparkPricingRuleController::class, 'store'])->middleware('perm:pricing.manage');
    Route::get('spark-pricing/{sparkPricingRule}', [SparkPricingRuleController::class, 'show'])->middleware('perm:pricing.view');
    Route::put('spark-pricing/{sparkPricingRule}', [SparkPricingRuleController::class, 'update'])->middleware('perm:pricing.manage');
    Route::delete('spark-pricing/{sparkPricingRule}', [SparkPricingRuleController::class, 'destroy'])->middleware('perm:pricing.manage');

    // 统一产品定价
    Route::get('product-pricing/countries-overview', [ProductPricingController::class, 'countriesOverview'])->middleware('perm:pricing.view');
    Route::get('product-pricing/country/{code}', [ProductPricingController::class, 'countryPricing'])->middleware('perm:pricing.view');
    Route::post('product-pricing/save-country', [ProductPricingController::class, 'saveCountryPricing'])->middleware('perm:pricing.manage');
    Route::post('product-pricing/batch-set', [ProductPricingController::class, 'batchSet'])->middleware('perm:pricing.manage');
    Route::post('product-pricing/sync-spark-cost', [ProductPricingController::class, 'syncSparkCost'])->middleware('perm:pricing.manage');
    Route::get('product-pricing', [ProductPricingController::class, 'index'])->middleware('perm:pricing.view');
    Route::post('product-pricing', [ProductPricingController::class, 'store'])->middleware('perm:pricing.manage');
    Route::get('product-pricing/{productPricing}', [ProductPricingController::class, 'show'])->middleware('perm:pricing.view');
    Route::put('product-pricing/{productPricing}', [ProductPricingController::class, 'update'])->middleware('perm:pricing.manage');
    Route::delete('product-pricing/{productPricing}', [ProductPricingController::class, 'destroy'])->middleware('perm:pricing.manage');

    // 销售倍率定价 (v3)
    Route::get('pricing-multipliers/preview', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'preview'])->middleware('perm:pricing.view');
    Route::get('pricing-multipliers/product-list', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'productList'])->middleware('perm:pricing.view');
    Route::get('pricing-multipliers/debug-match', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'debugMatch'])->middleware('perm:pricing.view');
    Route::post('pricing-multipliers/batch-set', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'batchSet'])->middleware('perm:pricing.manage');
    Route::get('pricing-multipliers', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'index'])->middleware('perm:pricing.view');
    Route::post('pricing-multipliers', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'store'])->middleware('perm:pricing.manage');
    Route::put('pricing-multipliers/{pricingMultiplier}', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'update'])->middleware('perm:pricing.manage');
    Route::delete('pricing-multipliers/{pricingMultiplier}', [\App\Http\Controllers\Api\V1\PricingMultiplierController::class, 'destroy'])->middleware('perm:pricing.manage');

    // 后台用户管理
    Route::get('users', [UserController::class, 'index'])->middleware('perm:user.view');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('perm:user.view');
    Route::post('users', [UserController::class, 'store'])->middleware('perm:user.create');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('perm:user.edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('perm:user.delete');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('perm:user.edit');
    Route::post('users/{user}/generate-invite-code', [UserController::class, 'generateInviteCode'])->middleware('perm:user.edit');
    Route::post('users/{user}/regenerate-invite-code', [UserController::class, 'regenerateInviteCode'])->middleware('perm:user.edit');
    Route::put('users/{user}/auto-approve', [UserController::class, 'setAutoApprove'])->middleware('perm:user.set_auto_approve');

    // 短信服务管理
    Route::get('sms-providers', [SmsProviderController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('sms-providers', [SmsProviderController::class, 'store'])->middleware('perm:setting.manage');
    Route::put('sms-providers/{smsProvider}', [SmsProviderController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('sms-providers/{smsProvider}', [SmsProviderController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('sms-providers/{smsProvider}/test', [SmsProviderController::class, 'test'])->middleware('perm:setting.manage');
    Route::post('sms-providers/{smsProvider}/test-expiry', [SmsProviderController::class, 'testExpiry'])->middleware('perm:setting.manage');

    // 短信发送记录
    Route::get('sms-logs', [\App\Http\Controllers\Api\V1\SmsLogController::class, 'index'])->middleware('perm:setting.manage');
    Route::get('sms-logs/stats', [\App\Http\Controllers\Api\V1\SmsLogController::class, 'stats'])->middleware('perm:setting.manage');

    // API Keys 管理
    Route::get('api-keys', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('api-keys', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'store'])->middleware('perm:setting.manage');
    Route::put('api-keys/{apiKey}', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('api-keys/{apiKey}', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('api-keys/{apiKey}/regenerate', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'regenerateSecret'])->middleware('perm:setting.manage');

    // 网站设置（站名 + Logo）
    Route::get('settings/site', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('settings/site', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'update'])->middleware('perm:setting.manage');
    Route::post('settings/site/logo', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'uploadLogo'])->middleware('perm:setting.manage');
    Route::delete('settings/site/logo', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'deleteLogo'])->middleware('perm:setting.manage');
    Route::post('settings/site/favicon', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'uploadFavicon'])->middleware('perm:setting.manage');
    Route::delete('settings/site/favicon', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'deleteFavicon'])->middleware('perm:setting.manage');
    Route::get('settings/store-banner', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'getStoreBanner'])->middleware('perm:setting.manage');
    Route::put('settings/store-banner', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'updateStoreBanner'])->middleware('perm:setting.manage');
    Route::post('settings/store-banner/upload-image', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'uploadBannerImage'])->middleware('perm:setting.manage');
    Route::get('settings/float-contact', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'getFloatContact'])->middleware('perm:setting.manage');
    Route::put('settings/float-contact', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'updateFloatContact'])->middleware('perm:setting.manage');
    Route::post('settings/float-contact/upload-image', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'uploadFloatContactImage'])->middleware('perm:setting.manage');
    Route::get('settings/partnership-contact', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'getPartnershipContact'])->middleware('perm:setting.manage');
    Route::post('settings/partnership-contact/image', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'uploadPartnershipContactImage'])->middleware('perm:setting.manage');
    Route::delete('settings/partnership-contact/image', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'deletePartnershipContactImage'])->middleware('perm:setting.manage');

    Route::get('settings/image-config', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'getImageConfig'])->middleware('perm:setting.manage');
    Route::post('settings/image-config', [\App\Http\Controllers\Api\V1\SiteSettingsController::class, 'saveImageConfig'])->middleware('perm:setting.manage');

    // 注册设置
    Route::get('settings/registration', [RegistrationSettingsController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('settings/registration', [RegistrationSettingsController::class, 'update'])->middleware('perm:setting.manage');

    // 推广设置
    Route::get('settings/referral', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('settings/referral', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'update'])->middleware('perm:setting.manage');
    Route::get('referral-stats', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'stats'])->middleware('perm:pricing.view');
    Route::get('referral-commissions', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'commissions'])->middleware('perm:pricing.view');
    Route::post('referral-commissions/{referralCommission}/credit', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'creditCommission'])->middleware('perm:pricing.manage');
    Route::get('sales-commissions', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'salesCommissions'])->middleware('perm:pricing.view');
    Route::post('sales-commissions/{salesCommission}/credit', [\App\Http\Controllers\Api\V1\ReferralSettingsController::class, 'creditSalesCommission'])->middleware('perm:pricing.manage');

    // 业绩检索
    Route::get('performance/search', [\App\Http\Controllers\Api\V1\PerformanceController::class, 'search'])->middleware('perm:performance.view');

    // 实名认证设置
    Route::get('settings/verification', [\App\Http\Controllers\Api\V1\VerificationSettingsController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('settings/verification', [\App\Http\Controllers\Api\V1\VerificationSettingsController::class, 'update'])->middleware('perm:setting.manage');
    Route::post('settings/verification/test', [\App\Http\Controllers\Api\V1\VerificationSettingsController::class, 'test'])->middleware('perm:setting.manage');

    // 实名认证接口管理 (CRUD)
    Route::put('verification-providers/global-settings', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'updateGlobalSettings'])->middleware('perm:setting.manage');
    Route::get('verification-providers', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('verification-providers', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'store'])->middleware('perm:setting.manage');
    Route::put('verification-providers/{id}', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('verification-providers/{id}', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('verification-providers/{id}/toggle', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'toggle'])->middleware('perm:setting.manage');
    Route::post('verification-providers/{id}/test', [\App\Http\Controllers\Api\V1\VerificationProviderController::class, 'test'])->middleware('perm:setting.manage');

    // 角色与权限管理
    Route::get('roles/all-permissions', [RoleController::class, 'allPermissions'])->middleware('perm:user.assign_role');
    Route::get('roles', [RoleController::class, 'index'])->middleware('perm:user.assign_role');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('perm:user.assign_role');
    Route::post('roles', [RoleController::class, 'store'])->middleware('perm:user.assign_role');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('perm:user.assign_role');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('perm:user.assign_role');

    // 活动日志
    Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('perm:activity_log.view');
    Route::get('activity-logs/{activityLog}', [ActivityLogController::class, 'show'])->middleware('perm:activity_log.view');
    Route::post('activity-logs/clean', [ActivityLogController::class, 'clean'])->middleware('perm:activity_log.view_all');

    // NY 面板管理（超级管理员）
    Route::get('ny-panels/enabled-device-groups', [NyPanelController::class, 'enabledDeviceGroups'])->middleware('perm:subscription.create,subscription.submit_approval');
    Route::get('ny-panels', [NyPanelController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('ny-panels', [NyPanelController::class, 'store'])->middleware('perm:setting.manage');
    Route::get('ny-panels/{nyPanel}', [NyPanelController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('ny-panels/{nyPanel}', [NyPanelController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('ny-panels/{nyPanel}', [NyPanelController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('ny-panels/{nyPanel}/test', [NyPanelController::class, 'testConnection'])->middleware('perm:setting.manage');
    Route::post('ny-panels/{nyPanel}/sync-device-groups', [NyPanelController::class, 'syncDeviceGroups'])->middleware('perm:setting.manage');
    Route::put('ny-panels/{nyPanel}/device-groups', [NyPanelController::class, 'updateDeviceGroups'])->middleware('perm:setting.manage');

    // 3x-ui 面板管理
    // 注意：xui_inbound 模型必须走显式参数名 {xuiInbound} 才能被 Laravel 自动 RMB
    Route::delete('xui-panels/inbounds/{xuiInbound}', [XuiPanelController::class, 'deleteInbound'])->middleware('perm:setting.manage');
    // 低权限入口：业务员下单 / 批量转发时调（只返回启用中的主面板，不含密钥）
    Route::get('xui-panels/usable', [XuiPanelController::class, 'usable'])->middleware('perm:subscription.create,subscription.submit_approval');
    Route::get('xui-panels', [XuiPanelController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('xui-panels', [XuiPanelController::class, 'store'])->middleware('perm:setting.manage');
    Route::get('xui-panels/{xuiPanel}', [XuiPanelController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('xui-panels/{xuiPanel}', [XuiPanelController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('xui-panels/{xuiPanel}', [XuiPanelController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('xui-panels/{xuiPanel}/test', [XuiPanelController::class, 'testConnection'])->middleware('perm:setting.manage');
    Route::post('xui-panels/{xuiPanel}/create-forward', [XuiPanelController::class, 'createForward'])->middleware('perm:setting.manage');
    Route::post('xui-panels/{xuiPanel}/batch-create-forward', [XuiPanelController::class, 'batchCreateForward'])->middleware('perm:setting.manage');
    Route::get('xui-panels/{xuiPanel}/batch-status/{batchId}', [XuiPanelController::class, 'batchStatus'])->middleware('perm:setting.manage');
    Route::post('xui-panels/{xuiPanel}/sync-all-to-mirror', [XuiPanelController::class, 'syncAllToMirror'])->middleware('perm:setting.manage');
    Route::post('xui-panels/inbounds/{xuiInbound}/resync-mirror', [XuiPanelController::class, 'resyncMirror'])->middleware('perm:setting.manage');
    Route::get('xui-panels/{xuiPanel}/inbounds', [XuiPanelController::class, 'listInbounds'])->middleware('perm:setting.manage');

    // DNS 容灾监控（超级管理员）
    Route::get('dns-agents', [DnsMonitorController::class, 'agents'])->middleware('perm:setting.manage');
    Route::post('dns-agents', [DnsMonitorController::class, 'storeAgent'])->middleware('perm:setting.manage');
    Route::delete('dns-agents/{dnsAgent}', [DnsMonitorController::class, 'deleteAgent'])->middleware('perm:setting.manage');
    Route::post('dns-agents/{dnsAgent}/regenerate-key', [DnsMonitorController::class, 'regenerateAgentKey'])->middleware('perm:setting.manage');

    Route::get('dns-targets', [DnsMonitorController::class, 'targets'])->middleware('perm:setting.manage');
    Route::post('dns-targets', [DnsMonitorController::class, 'storeTarget'])->middleware('perm:setting.manage');
    Route::get('dns-targets/{dnsTarget}', [DnsMonitorController::class, 'showTarget'])->middleware('perm:setting.manage');
    Route::put('dns-targets/{dnsTarget}', [DnsMonitorController::class, 'updateTarget'])->middleware('perm:setting.manage');
    Route::delete('dns-targets/{dnsTarget}', [DnsMonitorController::class, 'deleteTarget'])->middleware('perm:setting.manage');
    Route::get('dns-targets/{dnsTarget}/probes', [DnsMonitorController::class, 'probeHistory'])->middleware('perm:setting.manage');
    Route::get('dns-targets/{dnsTarget}/events', [DnsMonitorController::class, 'failoverHistory'])->middleware('perm:setting.manage');
    Route::post('dns-targets/{dnsTarget}/failover', [DnsMonitorController::class, 'manualFailover'])->middleware('perm:setting.manage');
    Route::post('dns-targets/{dnsTarget}/failback', [DnsMonitorController::class, 'manualFailback'])->middleware('perm:setting.manage');

    // 飞书多维表格同步
    Route::get('feishu-sync', [FeishuSyncController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('feishu-sync', [FeishuSyncController::class, 'store'])->middleware('perm:setting.manage');
    Route::get('feishu-sync/{feishuSyncConfig}', [FeishuSyncController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('feishu-sync/{feishuSyncConfig}', [FeishuSyncController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('feishu-sync/{feishuSyncConfig}', [FeishuSyncController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('feishu-sync/{feishuSyncConfig}/test', [FeishuSyncController::class, 'testConnection'])->middleware('perm:setting.manage');
    Route::post('feishu-sync/{feishuSyncConfig}/sync', [FeishuSyncController::class, 'triggerSync'])->middleware('perm:setting.manage');
    Route::get('feishu-sync/{feishuSyncConfig}/preview', [FeishuSyncController::class, 'preview'])->middleware('perm:setting.manage');

    // 队列监控（超级管理员）
    Route::get('queue-monitor/stats', [QueueMonitorController::class, 'stats'])->middleware('perm:setting.manage');
    Route::get('queue-monitor/failed', [QueueMonitorController::class, 'failed'])->middleware('perm:setting.manage');
    Route::post('queue-monitor/retry-all-failed', [QueueMonitorController::class, 'retryAllFailed'])->middleware('perm:setting.manage');
    Route::post('queue-monitor/flush-failed', [QueueMonitorController::class, 'flushFailed'])->middleware('perm:setting.manage');

    // 支付网关管理（超级管理员）
    Route::get('payment-gateways/domain-settings', [PaymentGatewayController::class, 'getDomainSettings'])->middleware('perm:setting.manage');
    Route::put('payment-gateways/domain-settings', [PaymentGatewayController::class, 'updateDomainSettings'])->middleware('perm:setting.manage');
    Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('payment-gateways', [PaymentGatewayController::class, 'store'])->middleware('perm:setting.manage');
    Route::get('payment-gateways/{paymentGateway}', [PaymentGatewayController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('payment-gateways/{paymentGateway}', [PaymentGatewayController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('payment-gateways/{paymentGateway}', [PaymentGatewayController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('payment-gateways/{paymentGateway}/test-sign', [PaymentGatewayController::class, 'testSign'])->middleware('perm:setting.manage');

    // 充值订单 & 原路退款
    Route::get('payment-orders', [PaymentRefundController::class, 'orders'])->middleware('perm:transaction.view');
    Route::post('payment-orders/{paymentOrder}/refund', [PaymentRefundController::class, 'refundOrder'])->middleware('perm:payment.gateway_refund');
    Route::get('payment-refunds', [PaymentRefundController::class, 'index'])->middleware('perm:transaction.view');
    Route::get('customers/{customer}/refundable-orders', [PaymentRefundController::class, 'refundableOrders'])->middleware('perm:payment.gateway_refund');

    // Webhook 通知管理
    Route::get('webhooks/events', [WebhookController::class, 'events'])->middleware('perm:webhook.view');
    Route::get('webhooks/logs', [WebhookController::class, 'logs'])->middleware('perm:notification.view');
    Route::get('webhooks', [WebhookController::class, 'index'])->middleware('perm:webhook.view');
    Route::post('webhooks', [WebhookController::class, 'store'])->middleware('perm:webhook.manage');
    Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])->middleware('perm:webhook.view');
    Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->middleware('perm:webhook.manage');
    Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->middleware('perm:webhook.manage');
    Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('perm:webhook.test');

    // 销售统计
    Route::get('sales-stats', [SalesStatsController::class, 'index'])->middleware('perm:customer.view');

    // 手动业绩
    Route::get('manual-performances', [ManualPerformanceController::class, 'index'])->middleware('perm:performance.view');
    Route::post('manual-performances', [ManualPerformanceController::class, 'store'])->middleware('perm:performance.manage');
    Route::delete('manual-performances/{manualPerformance}', [ManualPerformanceController::class, 'destroy'])->middleware('perm:performance.manage');

    // 业绩统计（新版）
    Route::get('sales-stats-new', [SalesStatsNewController::class, 'index'])->middleware('perm:customer.view');
    Route::get('sales-stats-new/manual-entries', [SalesStatsNewController::class, 'manualEntries'])->middleware('perm:performance.view');
    Route::post('sales-stats-new/manual-entries', [SalesStatsNewController::class, 'storeManualEntry'])->middleware('perm:performance.manage');
    Route::delete('sales-stats-new/manual-entries/{manualStatEntry}', [SalesStatsNewController::class, 'destroyManualEntry'])->middleware('perm:performance.manage');

    // 财务总览
    Route::get('finance/overview', [FinanceController::class, 'overview'])->middleware('perm:transaction.view');
    Route::get('finance/trend', [FinanceController::class, 'trend'])->middleware('perm:transaction.view');
    Route::get('finance/ranking', [FinanceController::class, 'ranking'])->middleware('perm:transaction.view');

    // 数据看板
    Route::get('analytics/marketing', [AnalyticsController::class, 'marketing'])->middleware('perm:analytics.view');
    Route::get('analytics/pricing', [AnalyticsController::class, 'pricing'])->middleware('perm:analytics.view');
    Route::get('analytics/products', [AnalyticsController::class, 'products'])->middleware('perm:analytics.view');
    Route::get('analytics/customer-detail/{id}', [AnalyticsController::class, 'customerDetail'])->middleware('perm:analytics.view');

    // 软路由设备管理
    Route::get('router-devices/stats', [RouterDeviceController::class, 'stats'])->middleware('perm:router.view');
    Route::get('router-devices/agent-version', [RouterDeviceController::class, 'getAgentVersion'])->middleware('perm:router.edit');
    Route::post('router-devices/agent-upload', [RouterDeviceController::class, 'uploadAgentBinary'])->middleware('perm:router.edit');
    Route::get('router-devices', [RouterDeviceController::class, 'index'])->middleware('perm:router.view');
    Route::post('router-devices', [RouterDeviceController::class, 'store'])->middleware('perm:router.create');
    Route::get('router-devices/{routerDevice}', [RouterDeviceController::class, 'show'])->middleware('perm:router.view');
    Route::put('router-devices/{routerDevice}', [RouterDeviceController::class, 'update'])->middleware('perm:router.edit');
    Route::delete('router-devices/{routerDevice}', [RouterDeviceController::class, 'destroy'])->middleware('perm:router.delete');
    Route::post('router-devices/{routerDevice}/install-token', [RouterDeviceController::class, 'generateInstallToken'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/reauthorize', [RouterDeviceController::class, 'reauthorize'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/bind', [RouterDeviceController::class, 'bind'])->middleware('perm:router.bind');
    Route::post('router-devices/{routerDevice}/unbind', [RouterDeviceController::class, 'unbind'])->middleware('perm:router.bind');
    Route::post('router-devices/{routerDevice}/push-config', [RouterDeviceController::class, 'pushConfig'])->middleware('perm:router.edit');
    Route::get('router-devices/{routerDevice}/events', [RouterDeviceController::class, 'events'])->middleware('perm:router.view');
    Route::get('router-devices/{routerDevice}/wifi-accounts', [RouterDeviceController::class, 'wifiAccounts'])->middleware('perm:router.view');
    Route::get('router-devices/{routerDevice}/available-subscriptions', [RouterDeviceController::class, 'availableSubscriptions'])->middleware('perm:router.view');
    Route::post('router-devices/{routerDevice}/wifi-accounts', [RouterDeviceController::class, 'createWifiAccount'])->middleware('perm:router.edit');
    Route::put('router-wifi-accounts/{accountId}', [RouterDeviceController::class, 'updateWifiAccount'])->middleware('perm:router.edit');
    Route::delete('router-wifi-accounts/{accountId}', [RouterDeviceController::class, 'deleteWifiAccount'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/reboot', [RouterDeviceController::class, 'rebootDevice'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/restart-service', [RouterDeviceController::class, 'restartService'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/toggle-trunk-dhcp', [RouterDeviceController::class, 'toggleTrunkDhcp'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/clean-stale-connections', [RouterDeviceController::class, 'cleanStaleConnections'])->middleware('perm:router.edit');
    Route::post('router-devices/{routerDevice}/send-command', [RouterDeviceController::class, 'sendCommand'])->middleware('perm:router.edit');
    Route::get('router-devices/{routerDevice}/commands', [RouterDeviceController::class, 'commandHistory'])->middleware('perm:router.view');
    // 路由器产品目录（型号/AP/套餐）
    Route::get('router-catalog/options', [RouterCatalogController::class, 'options'])->middleware('perm:router.view');
    Route::get('router-catalog/router-models', [RouterCatalogController::class, 'routerModels'])->middleware('perm:router.view');
    Route::post('router-catalog/router-models', [RouterCatalogController::class, 'storeRouterModel'])->middleware('perm:router.create');
    Route::put('router-catalog/router-models/{routerModel}', [RouterCatalogController::class, 'updateRouterModel'])->middleware('perm:router.edit');
    Route::delete('router-catalog/router-models/{routerModel}', [RouterCatalogController::class, 'destroyRouterModel'])->middleware('perm:router.delete');
    Route::get('router-catalog/ap-models', [RouterCatalogController::class, 'apModels'])->middleware('perm:router.view');
    Route::post('router-catalog/ap-models', [RouterCatalogController::class, 'storeApModel'])->middleware('perm:router.create');
    Route::put('router-catalog/ap-models/{apModel}', [RouterCatalogController::class, 'updateApModel'])->middleware('perm:router.edit');
    Route::delete('router-catalog/ap-models/{apModel}', [RouterCatalogController::class, 'destroyApModel'])->middleware('perm:router.delete');
    Route::get('router-catalog/bundles', [RouterCatalogController::class, 'bundles'])->middleware('perm:router.view');
    Route::post('router-catalog/bundles', [RouterCatalogController::class, 'storeBundle'])->middleware('perm:router.create');
    Route::put('router-catalog/bundles/{routerBundle}', [RouterCatalogController::class, 'updateBundle'])->middleware('perm:router.edit');
    Route::delete('router-catalog/bundles/{routerBundle}', [RouterCatalogController::class, 'destroyBundle'])->middleware('perm:router.delete');

    // WireGuard 服务器管理
    Route::get('wg-servers', [WgServerController::class, 'index'])->middleware('perm:router.wg_manage');
    Route::post('wg-servers', [WgServerController::class, 'store'])->middleware('perm:router.wg_manage');
    Route::get('wg-servers/{wgServer}', [WgServerController::class, 'show'])->middleware('perm:router.wg_manage');
    Route::put('wg-servers/{wgServer}', [WgServerController::class, 'update'])->middleware('perm:router.wg_manage');
    Route::delete('wg-servers/{wgServer}', [WgServerController::class, 'destroy'])->middleware('perm:router.wg_manage');
    Route::get('wg-servers/{wgServer}/config', [WgServerController::class, 'serverConfig'])->middleware('perm:router.wg_manage');
    Route::post('wg-servers/{wgServer}/sync-peers', [WgServerController::class, 'syncPeers'])->middleware('perm:router.wg_manage');
    Route::post('wg-servers/{wgServer}/deploy-peer', [WgServerController::class, 'deployPeer'])->middleware('perm:router.wg_manage');

    // OAuth 客户端管理
    Route::get('oauth-clients', [OauthClientController::class, 'index'])->middleware('perm:setting.manage');
    Route::post('oauth-clients', [OauthClientController::class, 'store'])->middleware('perm:setting.manage');
    Route::get('oauth-clients/{oauthClient}', [OauthClientController::class, 'show'])->middleware('perm:setting.manage');
    Route::put('oauth-clients/{oauthClient}', [OauthClientController::class, 'update'])->middleware('perm:setting.manage');
    Route::delete('oauth-clients/{oauthClient}', [OauthClientController::class, 'destroy'])->middleware('perm:setting.manage');
    Route::post('oauth-clients/{oauthClient}/regenerate-secret', [OauthClientController::class, 'regenerateSecret'])->middleware('perm:setting.manage');
});
