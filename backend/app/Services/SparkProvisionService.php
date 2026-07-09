<?php

namespace App\Services;

use App\Models\IpAssetGroup;
use App\Models\IpAssignmentLog;
use App\Models\ProxyIp;
use App\Models\SparkCountry;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spark 开通流程的可复用编排层。
 *
 * 抽离自原 SparkController::provision() / processInstances()，
 * 让管理后台和客户自助面板都能调用相同的逻辑。
 *
 * 使用场景：
 *   - admin: SparkController::provision() HTTP endpoint
 *   - customer: Services\Customer\CheckoutService::purchase()
 *   - notify: Spark 异步回调同步 IP 实例
 */
class SparkProvisionService
{
    public function __construct(protected SparkApiService $spark) {}

    /**
     * 调 Spark CreateProxy 下单，并在立即完成时同步实例。
     *
     * @param array $params {
     *   product_id: string (必须),
     *   product_name?: string,
     *   country_code?: string,   // alpha-3
     *   country_cn?: string,
     *   sale_price?: float,       // 每条 IP 的售价（记录到订阅）
     *   quantity: int,
     *   duration: int,
     *   unit: int,                // 1=day 2=week 3=month 4=year
     *   asset_group_id: int,
     *   ip_group_id?: int,
     *   customer_id?: int,        // 传入则自动分配 + 创建订阅
     *   created_by?: int,         // 操作人 user_id（customer checkout 时传客户侧占位 1）
     * }
     *
     * @return array{
     *   spark_order: SparkOrder,
     *   subscription_ids: int[],
     *   proxy_ip_ids: int[],
     *   status: int,
     *   message: string
     * }
     *
     * @throws \Exception Spark API 失败会 bubble up 给调用方处理
     */
    public function createOrder(array $params): array
    {
        $reqOrderNo = SparkOrder::generateReqOrderNo();

        // 上游只创建 1 个月，后续由 spark:upstream-renew cron 按月滚动续费
        $sparkDuration = 1;
        $sparkUnit = 3; // 月

        $result = $this->spark->createProxy(
            $reqOrderNo,
            $params['product_id'],
            $sparkDuration,
            $sparkUnit,
            (int) $params['quantity'],
            $params['cidr_blocks'] ?? null
        );

        $sparkOrder = SparkOrder::create([
            'req_order_no' => $reqOrderNo,
            'spark_order_no' => $result['orderNo'] ?? null,
            'method' => 'CreateProxy',
            'product_id' => $params['product_id'],
            'amount' => (int) $params['quantity'],
            'duration' => (int) $params['duration'],
            'unit' => (int) $params['unit'],
            'cost_amount' => $result['amount'] ?? null,
            'status' => (int) ($result['status'] ?? 1),
            'request_data' => $params,   // 含 customer_id/country/price 等，后续同步时复用
            'response_data' => $result,
        ]);

        $subscriptionIds = [];
        $proxyIpIds = [];

        // status=2 表示 Spark 立即完成开通；status=1 表示异步开通中
        // Spark 异步开通通常 1-5 秒内完成，轮询等待避免返回空结果
        $orderStatus = (int) ($result['status'] ?? 1);
        $ipInfo = [];

        if ($orderStatus === 2) {
            try {
                $pollResult = $this->spark->getOrder($reqOrderNo, $result['orderNo'] ?? null);
                $ipInfo = $pollResult['ipInfo'] ?? [];
            } catch (\Throwable) {}
        } elseif ($orderStatus === 1) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                usleep(2_000_000);
                try {
                    $pollResult = $this->spark->getOrder($reqOrderNo, $result['orderNo'] ?? null);
                    if (((int) ($pollResult['status'] ?? 1)) === 2 && !empty($pollResult['ipInfo'])) {
                        $orderStatus = 2;
                        $ipInfo = $pollResult['ipInfo'];
                        $sparkOrder->update(['status' => 2, 'response_data' => $pollResult]);
                        break;
                    }
                } catch (\Throwable) {}
            }
        }

        if ($orderStatus === 2 && !empty($ipInfo)) {
            try {
                $res = $this->processInstances($sparkOrder, $ipInfo, $params);
                $subscriptionIds = $res['subscription_ids'];
                $proxyIpIds = $res['proxy_ip_ids'];
            } catch (\Throwable $e) {
                Log::error("Spark processInstances failed, rolling back", [
                    'spark_order_id' => $sparkOrder->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return [
            'spark_order' => $sparkOrder,
            'subscription_ids' => $subscriptionIds,
            'proxy_ip_ids' => $proxyIpIds,
            'status' => $orderStatus,
            'message' => match ($orderStatus) {
                1 => '开通中，请稍后查看',
                2 => '开通完成',
                3 => '开通失败',
                default => '未知状态',
            },
        ];
    }

    /**
     * 同步 Spark 返回的 ipInfo 列表，落库 ProxyIp + SparkInstance + Subscription + 分配日志。
     *
     * 被调场景：
     *   - createOrder 立即返回 status=2
     *   - SparkController::syncOrder() 管理员手动同步
     *   - SparkController::notify() 异步回调
     *
     * @return array{subscription_ids: int[], proxy_ip_ids: int[]}
     */
    public function processInstances(SparkOrder $sparkOrder, array $ipInfoList, array $requestData): array
    {
        $createdSubIds = [];
        $createdIpIds = [];

        DB::transaction(function () use ($sparkOrder, $ipInfoList, $requestData, &$createdSubIds, &$createdIpIds) {
            $customerId = $requestData['customer_id'] ?? null;
            $countryCode = $requestData['country_code'] ?? '';
            $countryCn = $requestData['country_cn'] ?? '';
            $productName = $requestData['product_name'] ?? '';
            $salePrice = (float) ($requestData['sale_price'] ?? 0);
            $salesCost = isset($requestData['sales_cost']) ? (float) $requestData['sales_cost'] : null;
            $duration = (int) ($requestData['duration'] ?? 1);
            $unit = (int) ($requestData['unit'] ?? 3);
            $durationMonths = \App\Support\DurationHelper::toMonths($duration, $unit);
            $totalPrice = round($salePrice * max($durationMonths, 1), 2);
            $autoRenew = !empty($requestData['auto_renew']);
            $createdBy = (int) ($requestData['created_by'] ?? auth()->id() ?? 1);

            // 自动解析 ip_group_id：从 Spark 产品的 ispType/netType 匹配 IP 组
            $ipGroupId = $requestData['ip_group_id'] ?? null;
            if (!$ipGroupId && $sparkOrder->product_id) {
                $products = \App\Services\SparkStockCacheService::products();
                $product = collect($products)->firstWhere('product_id', $sparkOrder->product_id);
                if ($product) {
                    $groupQuery = \App\Models\IpGroup::where('spark_isp_type', $product['isp_type']);
                    if ($product['net_type']) {
                        $groupQuery->where('spark_net_type', $product['net_type']);
                    }
                    $ipGroupId = $groupQuery->value('id');
                }
            }

            // 兜底：从资产组读国家
            if (!$countryCode && !empty($requestData['asset_group_id'])) {
                $assetGroup = IpAssetGroup::find($requestData['asset_group_id']);
                if ($assetGroup && $assetGroup->country_code) {
                    $countryCode = $assetGroup->country_code;
                    $countryCn = $countryCn ?: $assetGroup->country_name;
                }
            }
            // 修正 Spark 将港台归入 CHN 的问题
            if ($countryCode === 'CHN' && $productName) {
                $nameUpper = strtoupper($productName);
                if (str_contains($nameUpper, 'TAIWAN') || str_contains($nameUpper, '台湾') || str_contains($nameUpper, '台灣')) {
                    $countryCode = 'TWN';
                    $countryCn = '台湾';
                } elseif (str_contains($nameUpper, 'HONGKONG') || str_contains($nameUpper, 'HONG KONG') || str_contains($nameUpper, '香港') || str_contains($nameUpper, 'HK-')) {
                    $countryCode = 'HKG';
                    $countryCn = '香港';
                }
            }

            // 兜底：country_cn 为空时查 area_country
            if ($countryCode && !$countryCn) {
                $countryCn = SparkCountry::getNameByCode($countryCode) ?: $countryCode;
            }

            foreach ($ipInfoList as $ipInfo) {
                $instanceId = $ipInfo['instanceId'];

                // 幂等：已存在则尝试补全缺失的凭证
                $existingInstance = SparkInstance::where('instance_id', $instanceId)->first();
                if ($existingInstance) {
                    $this->backfillInstance($existingInstance, $ipInfo);
                    if ($existingInstance->proxy_ip_id) {
                        $createdIpIds[] = $existingInstance->proxy_ip_id;
                    }
                    continue;
                }

                $socks5Parts = array_filter([
                    $ipInfo['ip'] ?? '',
                    $ipInfo['port'] ?? '',
                    $ipInfo['username'] ?? '',
                    $ipInfo['password'] ?? '',
                ]);
                $socks5Info = implode(':', $socks5Parts);

                // 每条实例的国家兜底：stateCode 前 3 位
                $ipCountryCode = $countryCode;
                $ipCountryCn = $countryCn;
                if (!$ipCountryCode && !empty($ipInfo['stateCode'])) {
                    $ipCountryCode = substr($ipInfo['stateCode'], 0, 3);
                }
                if ($ipCountryCode && !$ipCountryCn) {
                    $ipCountryCn = SparkCountry::getNameByCode($ipCountryCode) ?: $ipCountryCode;
                }

                $region = $ipCountryCn ?: $ipCountryCode ?: 'Unknown';
                $assetName = "{$region}-{$ipInfo['ip']}";

                // 检查 IP 是否已存在且分配给了其他客户 — 需要清理旧数据
                $existingIp = ProxyIp::withTrashed()
                    ->where('ip_address', $ipInfo['ip'] ?? '')
                    ->where('port', $ipInfo['port'] ?? 0)
                    ->first();
                $isReProvision = $existingIp !== null;

                if ($existingIp && $customerId && $existingIp->assigned_customer_id && $existingIp->assigned_customer_id != $customerId) {
                    $oldSubs = Subscription::where('proxy_ip_id', $existingIp->id)
                        ->where('status', 'active')
                        ->get();
                    foreach ($oldSubs as $oldSub) {
                        $oldSub->update([
                            'status' => 'cancelled',
                            'remark' => trim(($oldSub->remark ?? '') . "\n[系统] IP被上游重新分配，自动取消"),
                        ]);
                    }
                    Log::warning('SparkProvisionService: IP reassigned from different customer', [
                        'proxy_ip_id' => $existingIp->id,
                        'ip' => $existingIp->ip_address,
                        'old_customer' => $existingIp->assigned_customer_id,
                        'new_customer' => $customerId,
                        'cancelled_subs' => $oldSubs->pluck('id')->toArray(),
                    ]);
                }

                $proxyIp = ProxyIp::withTrashed()->updateOrCreate(
                    ['ip_address' => $ipInfo['ip'] ?? '', 'port' => $ipInfo['port'] ?? 0],
                    [
                        'asset_group_id' => $requestData['asset_group_id'] ?? null,
                        'ip_group_id' => $ipGroupId,
                        'socks5_info' => $socks5Info,
                        'auth_username' => $ipInfo['username'] ?? null,
                        'auth_password' => $ipInfo['password'] ?? null,
                        'protocol' => 'socks5',
                        'asset_name' => $assetName,
                        'country_code' => $ipCountryCode,
                        'country_name' => $ipCountryCn,
                        'ip_type' => 'residential',
                        'nature' => 'static',
                        'source_name' => '斯帕克',
                        'status' => $customerId ? 'assigned' : 'available',
                        'assigned_customer_id' => $customerId,
                        'spark_instance_id' => $instanceId,
                        'upstream_expires_at' => isset($ipInfo['expireAt']) ? date('Y-m-d H:i:s', $ipInfo['expireAt']) : null,
                        // 重新开通时清理旧状态
                        'spark_release_status' => null,
                        'spark_release_order_no' => null,
                        'spark_released_at' => null,
                        'spark_release_error' => null,
                        'released_at' => null,
                        'release_reason' => null,
                        'released_by' => null,
                        'extra_config' => null,
                    ]
                );
                if ($proxyIp->trashed()) {
                    $proxyIp->restore();
                }

                // 复用的 IP 重置 created_at，使其在列表中排在最前面
                if ($isReProvision) {
                    $proxyIp->timestamps = false;
                    $proxyIp->update(['created_at' => now()]);
                    $proxyIp->timestamps = true;
                }

                $createdIpIds[] = $proxyIp->id;

                SparkInstance::create([
                    'spark_order_id' => $sparkOrder->id,
                    'proxy_ip_id' => $proxyIp->id,
                    'instance_id' => $instanceId,
                    'ip' => $ipInfo['ip'] ?? null,
                    'port' => $ipInfo['port'] ?? null,
                    'username' => $ipInfo['username'] ?? null,
                    'password' => $ipInfo['password'] ?? null,
                    'type' => $ipInfo['type'] ?? 1,
                    'use_type' => $ipInfo['useType'] ?? 1,
                    'status' => $ipInfo['status'] ?? 1,
                    'flow' => $ipInfo['flow'] ?? null,
                    'balance_flow' => $ipInfo['balanceFlow'] ?? null,
                    'expire_at' => isset($ipInfo['expireAt']) ? date('Y-m-d H:i:s', $ipInfo['expireAt']) : null,
                ]);

                if ($customerId) {
                    // 订阅到期按客户购买时长计算，不依赖上游 expireAt（上游固定1个月，由 cron 滚动续费）
                    $expiresAt = \App\Support\DurationHelper::addToDate(now(), $duration, $unit);

                    // 自动查询销售成本和官网原价（如果前端未传）
                    // calcSalesPrice / calcSalePrice 返回月单价，存储也为月单价
                    // 统计时统一用 sales_cost * duration 得到总成本
                    $effectiveSalesCost = $salesCost;
                    $hardCost = null;
                    $listPrice = isset($requestData['list_price']) ? (float) $requestData['list_price'] : null;
                    if (($effectiveSalesCost === null || $listPrice === null || $hardCost === null) && $sparkOrder->product_id) {
                        $products = \App\Services\SparkStockCacheService::products();
                        $sparkProduct = collect($products)->firstWhere('product_id', $sparkOrder->product_id);
                        if ($sparkProduct) {
                            $hardCost = isset($sparkProduct['cost_price']) ? (float) $sparkProduct['cost_price'] : null;
                            if ($effectiveSalesCost === null) {
                                $effectiveSalesCost = \App\Models\PricingMultiplier::calcSalesPrice($sparkProduct);
                            }
                            if ($listPrice === null) {
                                $listPrice = \App\Models\PricingMultiplier::calcSalePrice($sparkProduct);
                            }
                        }
                    }

                    $isTest = !empty($requestData['is_test']);
                    $testHours = $isTest ? (int) ($requestData['test_hours'] ?? 12) : 0;
                    $testReclaimAt = $isTest ? now()->addHours($testHours) : null;

                    $purchasedModule = $requestData['purchased_module'] ?? null;
                    if (!$purchasedModule && !empty($requestData['forward_plan_id'])) {
                        $purchasedModule = \App\Models\ForwardPlan::where('id', $requestData['forward_plan_id'])->value('module');
                    }

                    $sub = Subscription::create([
                        'customer_id' => $customerId,
                        'proxy_ip_id' => $proxyIp->id,
                        'price' => $totalPrice,
                        'admin_set_price' => $createdBy > 1 ? $salePrice : null,
                        'list_price' => $listPrice,
                        'sales_cost' => $effectiveSalesCost,
                        'hard_cost' => $hardCost,
                        'duration' => $duration,
                        'unit' => $unit,
                        'started_at' => now(),
                        'expires_at' => $isTest ? $testReclaimAt : $expiresAt,
                        'status' => 'active',
                        'auto_renew' => $autoRenew ? 1 : 0,
                        'is_test' => $isTest,
                        'test_reclaim_at' => $testReclaimAt,
                        'balance_deducted' => ($requestData['payment_method'] ?? null) === 'balance',
                        'purchased_module' => $purchasedModule,
                        'created_by' => $createdBy,
                        'remark' => $isTest
                            ? "测试订单 ({$testHours}h自动回收)"
                            : (!empty($requestData['source_remark'])
                                ? $requestData['source_remark']
                                : 'Spark API 开通'),
                    ]);

                    if ($isTest) {
                        \App\Jobs\ReclaimTestIpJob::dispatch($sub->id)
                            ->delay($testReclaimAt);
                    }
                    $createdSubIds[] = $sub->id;

                    IpAssignmentLog::create([
                        'proxy_ip_id' => $proxyIp->id,
                        'customer_id' => $customerId,
                        'subscription_id' => $sub->id,
                        'action' => 'assign',
                        'operated_by' => $createdBy,
                        'remark' => 'Spark 自动开通分配',
                        'created_at' => now(),
                    ]);
                }
            }
        });

        // 转发编排（事务外）— 任何调用 processInstances 的入口都会自动挂转发
        // 注意：requestData 来自 spark_orders.request_data（createOrder 写入时已包含 forward / xui_forward）
        // 安全检查：只有 ProxyIp 凭证完整（port!=0）时才挂转发，否则等 backfill 补全后再挂
        if (!empty($createdSubIds)) {
            $allCredentialsReady = ProxyIp::whereIn('id', $createdIpIds)
                ->where(fn ($q) => $q->where('port', 0)->orWhereNull('port')->orWhereNull('auth_username')->orWhere('auth_username', ''))
                ->doesntExist();

            if ($allCredentialsReady) {
                if (!empty($requestData['forward'])) {
                    $this->attachForwards($createdSubIds, $requestData['forward']);
                }
                if (!empty($requestData['xui_forward'])) {
                    $this->attachXuiForwards($createdSubIds, $requestData['xui_forward'], $createdIpIds);
                }
                if (!empty($requestData['forward_plan_id'])) {
                    $this->attachForwardByPlan($createdSubIds, (int) $requestData['forward_plan_id'],
                        isset($requestData['forward_fee']) ? (float) $requestData['forward_fee'] : null);
                }
            } else {
                Log::info('SparkProvisionService: deferring forward attachment until credentials are backfilled', [
                    'subscription_ids' => $createdSubIds,
                    'proxy_ip_ids' => $createdIpIds,
                ]);
            }
        }

        // 部分开通自动退差额：订单已终态（2完成/3失败）但实际开通数 < 下单数时，
        // 按缺口数 × 单条总价退回余额（幂等：按订单号只退一次）
        $this->refundShortfall($sparkOrder, $requestData);

        return [
            'subscription_ids' => $createdSubIds,
            'proxy_ip_ids' => $createdIpIds,
        ];
    }

    /**
     * 部分成功/失败订单的差额自动退款（Spark 文档明确要求调用方执行部分退款逆向流程）
     */
    private function refundShortfall(SparkOrder $sparkOrder, array $requestData): void
    {
        try {
            $customerId = $requestData['customer_id'] ?? null;
            $paidByBalance = ($requestData['payment_method'] ?? null) === 'balance';
            if (!$customerId || !$paidByBalance) return;
            if (!in_array((int) $sparkOrder->fresh()->status, [2, 3], true)) return; // 仅终态

            $provisioned = SparkInstance::where('spark_order_id', $sparkOrder->id)->count();
            $ordered = (int) $sparkOrder->amount;
            $shortfall = $ordered - $provisioned;
            if ($shortfall <= 0) return;

            $salePrice = (float) ($requestData['sale_price'] ?? 0); // 单月单价
            $durationMonths = max(\App\Support\DurationHelper::toMonths((int) ($requestData['duration'] ?? 1), (int) ($requestData['unit'] ?? 3)), 1);
            $refund = round($shortfall * $salePrice * $durationMonths, 2);
            if ($refund <= 0) return;

            $marker = "订单{$sparkOrder->req_order_no}未开通部分退款";
            $already = \App\Models\Transaction::where('customer_id', $customerId)
                ->where('type', \App\Models\Transaction::TYPE_REFUND)
                ->where('description', 'like', "%{$marker}%")
                ->exists();
            if ($already) return;

            DB::transaction(function () use ($customerId, $refund, $shortfall, $ordered, $marker) {
                $customer = \App\Models\Customer::lockForUpdate()->find($customerId);
                if (!$customer) return;
                $before = (float) $customer->balance;
                $customer->increment('balance', $refund);
                \App\Models\Transaction::create([
                    'customer_id' => $customer->id,
                    'type' => \App\Models\Transaction::TYPE_REFUND,
                    'amount' => $refund,
                    'balance_before' => $before,
                    'balance_after' => round($before + $refund, 2),
                    'description' => "{$marker}：下单{$ordered}条实开" . ($ordered - $shortfall) . "条，退{$shortfall}条",
                    'operated_by' => null,
                ]);
            });
            Log::warning('SparkProvisionService: partial provision auto-refunded', [
                'order_id' => $sparkOrder->id, 'ordered' => $ordered, 'shortfall' => $shortfall, 'refund' => $refund,
            ]);
        } catch (\Throwable $e) {
            Log::error('SparkProvisionService: refundShortfall failed', [
                'order_id' => $sparkOrder->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 已存在的 SparkInstance 回补缺失凭证 → 同步更新 ProxyIp
     */
    private function backfillInstance(SparkInstance $instance, array $ipInfo): void
    {
        $updates = array_filter([
            'ip'       => $ipInfo['ip'] ?? null,
            'port'     => $ipInfo['port'] ?? null,
            'username' => $ipInfo['username'] ?? null,
            'password' => $ipInfo['password'] ?? null,
            'status'   => $ipInfo['status'] ?? null,
            'expire_at' => isset($ipInfo['expireAt']) ? date('Y-m-d H:i:s', $ipInfo['expireAt']) : null,
        ], fn($v) => $v !== null && $v !== '');

        if (!empty($updates)) {
            $instance->update($updates);
        }

        $proxyIp = $instance->proxyIp;
        if (!$proxyIp) return;

        $ip   = $ipInfo['ip'] ?? $instance->ip;
        $port = $ipInfo['port'] ?? $instance->port;
        $user = $ipInfo['username'] ?? $instance->username;
        $pass = $ipInfo['password'] ?? $instance->password;

        $needsUpdate = !$proxyIp->ip_address
            || !$proxyIp->port
            || !$proxyIp->auth_username
            || !$proxyIp->auth_password;

        if (!$needsUpdate) return;

        $socks5Parts = array_filter([$ip, $port, $user, $pass]);
        $proxyIp->update(array_filter([
            'ip_address'    => $ip ?: null,
            'port'          => $port ?: null,
            'auth_username' => $user ?: null,
            'auth_password' => $pass ?: null,
            'socks5_info'   => implode(':', $socks5Parts),
            'upstream_expires_at' => isset($ipInfo['expireAt']) ? date('Y-m-d H:i:s', $ipInfo['expireAt']) : null,
        ], fn($v) => $v !== null));

        Log::info('SparkProvisionService: backfilled ProxyIp credentials', [
            'proxy_ip_id' => $proxyIp->id,
            'instance_id' => $instance->instance_id,
        ]);

        // 凭证补全后，检查是否有被推迟的转发需要执行
        if ($proxyIp->port && $proxyIp->ip_address) {
            $this->retryDeferredForwards($instance, $proxyIp);
        }
    }

    /**
     * backfill 后重试之前因凭证不全而跳过/失败的转发
     */
    private function retryDeferredForwards(SparkInstance $instance, ProxyIp $proxyIp): void
    {
        // 查找此 IP 关联的订阅，且没有 active 转发规则的
        $subscription = Subscription::where('proxy_ip_id', $proxyIp->id)
            ->where('status', 'active')
            ->first();
        if (!$subscription) return;

        // 已有 active 转发规则则不重复处理
        $hasActiveForward = \App\Models\ForwardRule::where('subscription_id', $subscription->id)
            ->where('status', 'active')
            ->exists();
        if ($hasActiveForward) return;

        // 重试已有的 failed/pending 规则
        $failedRules = \App\Models\ForwardRule::where('subscription_id', $subscription->id)
            ->whereIn('status', ['failed', 'pending'])
            ->get();

        if ($failedRules->isNotEmpty()) {
            $service = app(\App\Services\Ny\NyForwardService::class);
            foreach ($failedRules as $rule) {
                try {
                    // 更新 dest 为正确的 port
                    $rule->update([
                        'dest_host' => $proxyIp->ip_address,
                        'dest_port' => (int) $proxyIp->port,
                        'status' => 'pending',
                        'error_message' => null,
                    ]);
                    $service->processRule($rule);
                    Log::info('SparkProvisionService: retried deferred forward after backfill', [
                        'rule_id' => $rule->id,
                        'subscription_id' => $subscription->id,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('SparkProvisionService: retry forward still failed after backfill', [
                        'rule_id' => $rule->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return;
        }

        // 没有任何转发规则 — 说明 processInstances 跳过了创建，需要从 spark_order.request_data 重新挂
        $sparkOrder = $instance->sparkOrder;
        if (!$sparkOrder) return;

        $requestData = $sparkOrder->request_data ?? [];
        $subIds = [$subscription->id];
        $ipIds = [$proxyIp->id];

        if (!empty($requestData['forward'])) {
            $this->attachForwards($subIds, $requestData['forward']);
        }
        if (!empty($requestData['xui_forward'])) {
            $this->attachXuiForwards($subIds, $requestData['xui_forward'], $ipIds);
        }
        if (!empty($requestData['forward_plan_id'])) {
            $this->attachForwardByPlan($subIds, (int) $requestData['forward_plan_id'],
                isset($requestData['forward_fee']) ? (float) $requestData['forward_fee'] : null);
        }

        if (!empty($requestData['forward']) || !empty($requestData['xui_forward']) || !empty($requestData['forward_plan_id'])) {
            Log::info('SparkProvisionService: attached deferred forwards after backfill', [
                'subscription_id' => $subscription->id,
                'proxy_ip_id' => $proxyIp->id,
            ]);
        }
    }

    /**
     * 公共方法：为一批订阅挂转发（供 IPIPV 等外部 provision service 复用）
     */
    public function attachForwardsForSubscriptions(array $subscriptionIds, array $proxyIpIds, array $requestData): void
    {
        if (!empty($requestData['forward'])) {
            $this->attachForwards($subscriptionIds, $requestData['forward']);
        }
        if (!empty($requestData['xui_forward'])) {
            $this->attachXuiForwards($subscriptionIds, $requestData['xui_forward'], $proxyIpIds);
        }
        if (!empty($requestData['forward_plan_id'])) {
            $this->attachForwardByPlan($subscriptionIds, (int) $requestData['forward_plan_id'],
                isset($requestData['forward_fee']) ? (float) $requestData['forward_fee'] : null);
        }
    }

    /**
     * 为新创建的一批订阅挂 3x-ui vless+reality 中转
     *
     * @param int[] $subscriptionIds
     * @param int[] $proxyIpIds 平行数组，和 subscriptionIds 一一对应（按 processInstances 创建顺序）
     * @param array $xuiForward { xui_panel_id: int }
     */
    private function attachXuiForwards(array $subscriptionIds, array $xuiForward, array $proxyIpIds): void
    {
        $panelId = (int) ($xuiForward['xui_panel_id'] ?? 0);
        if (!$panelId) {
            return;
        }

        $panel = \App\Models\XuiPanel::find($panelId);
        if (!$panel || !$panel->is_active || $panel->is_mirror) {
            Log::warning('SparkProvisionService: invalid xui panel', ['panel_id' => $panelId]);
            return;
        }

        $service = app(\App\Services\Xui\XuiForwardService::class);
        foreach ($subscriptionIds as $idx => $subId) {
            $sub = \App\Models\Subscription::find($subId);
            if (!$sub) continue;
            $proxyIp = \App\Models\ProxyIp::find($proxyIpIds[$idx] ?? 0);
            if (!$proxyIp) {
                $proxyIp = $sub->proxyIp;
            }
            if (!$proxyIp) continue;

            try {
                $service->createForward($panel, $proxyIp, $sub);
            } catch (\Throwable $e) {
                Log::error('SparkProvisionService: attach xui forward failed', [
                    'subscription_id' => $subId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 为新创建的一批订阅挂转发规则（事务外调用）
     *
     * @param int[] $subscriptionIds
     * @param array $forward {
     *   device_group_id: int (ny_device_groups.id 本地),
     *   speed_limit_mbps: int|null,
     *   forward_fee: float (单条单月费用)
     * }
     */
    /**
     * 按 ForwardPlan 挂转发（客户自助下单用）
     * 根据 plan.type 走 NY 或 XUI 路径
     */
    private function attachForwardByPlan(array $subscriptionIds, int $planId, ?float $feeOverride = null): void
    {
        $plan = \App\Models\ForwardPlan::find($planId);
        if (!$plan || !$plan->is_active) {
            Log::warning('attachForwardByPlan: plan not found or inactive', ['plan_id' => $planId]);
            return;
        }

        if ($plan->type === 'ny' && $plan->device_group_id) {
            $this->attachForwards($subscriptionIds, [
                'device_group_id' => $plan->device_group_id,
                'speed_limit_mbps' => $plan->speed_limit_mbps ?: null,
                // 优先用客户实付的单月费（特价客户按实收退款），无记录时回退套餐原价
                'forward_fee' => $feeOverride !== null ? $feeOverride : (float) $plan->base_price,
            ]);
            // 补充 forward_plan_id 到创建的 ForwardRule
            \App\Models\ForwardRule::whereIn('subscription_id', $subscriptionIds)
                ->update([
                    'forward_plan_id' => $plan->id,
                    'traffic_limit_bytes' => $plan->included_traffic_gb * 1073741824,
                ]);
        } elseif ($plan->type === 'xui' && $plan->panel_id) {
            // 获取 IP IDs
            $proxyIpIds = Subscription::whereIn('id', $subscriptionIds)->pluck('proxy_ip_id')->toArray();
            $this->attachXuiForwards($subscriptionIds, [
                'xui_panel_id' => $plan->panel_id,
            ], $proxyIpIds);
        }
    }

    private function attachForwards(array $subscriptionIds, array $forward): void
    {
        $deviceGroupId = (int) ($forward['device_group_id'] ?? 0);
        if (!$deviceGroupId) {
            return;
        }

        $deviceGroup = \App\Models\NyDeviceGroup::find($deviceGroupId);
        if (!$deviceGroup || !$deviceGroup->is_enabled) {
            Log::warning('SparkProvisionService: invalid device_group_id for forward', [
                'device_group_id' => $deviceGroupId,
            ]);
            return;
        }

        $speedMbps = isset($forward['speed_limit_mbps']) && $forward['speed_limit_mbps'] !== null
            ? (int) $forward['speed_limit_mbps']
            : null;
        $fee = (float) ($forward['forward_fee'] ?? 0);

        $service = app(\App\Services\Ny\NyForwardService::class);
        foreach ($subscriptionIds as $subId) {
            $sub = \App\Models\Subscription::find($subId);
            if (!$sub) continue;
            try {
                $service->attachToSubscription($sub, $deviceGroup, $speedMbps, $fee);
            } catch (\Throwable $e) {
                Log::error('SparkProvisionService: attach forward failed', [
                    'subscription_id' => $subId,
                    'error' => $e->getMessage(),
                ]);
                // 失败的 forward 会标 status=failed，admin 可手动重试
            }
        }
    }
}
