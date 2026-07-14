<?php

namespace App\Services;

use App\Models\IpAssetGroup;
use App\Models\IpAssignmentLog;
use App\Models\IpipvInstance;
use App\Models\IpipvOrder;
use App\Models\ProxyIp;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IpipvProvisionService
{
    public function __construct(protected IpipvApiService $ipipv) {}

    /**
     * 调 IPIPV 下单开通
     *
     * @param array $params {
     *   product_no, product_name?, country_code?, city_code?,
     *   sale_price?, quantity, duration, unit, cycle_times,
     *   asset_group_id, ip_group_id?, customer_id?, created_by?
     * }
     */
    public function createOrder(array $params): array
    {
        $appOrderNo = IpipvOrder::generateAppOrderNo();

        // 上游只创建 1 个周期，后续由 cron 按月滚动续费
        $items = [[
            'productNo'  => $params['product_no'],
            'count'      => (int) $params['quantity'],
            'cycleTimes' => 1,
        ]];

        if (!empty($params['cidr_blocks'])) {
            $items[0]['cidrBlocks'] = $params['cidr_blocks'];
        }

        $result = $this->ipipv->createOrder($appOrderNo, $items);

        $order = IpipvOrder::create([
            'app_order_no'      => $appOrderNo,
            'ipipv_order_no'    => $result['orderNo'] ?? null,
            'method'            => 'open',
            'product_no'        => $params['product_no'],
            'amount'            => (int) $params['quantity'],
            'duration'          => (int) ($params['duration'] ?? 1),
            'unit'              => (int) ($params['unit'] ?? 3),
            'cycle_times'       => (int) ($params['cycle_times'] ?? 1),
            'cost_amount'       => $result['amount'] ?? null,
            'status'            => 1,
            'provision_order_id' => $params['provision_order_id'] ?? null,
            'request_data'      => $params,
            'response_data'     => $result,
        ]);

        return [
            'ipipv_order'      => $order,
            'subscription_ids' => [],
            'proxy_ip_ids'     => [],
            'status'           => 1,
            'message'          => 'IPIPV 订单已创建，等待异步回调',
        ];
    }

    /**
     * 同步订单状态（从回调或手动触发）
     */
    public function syncOrder(IpipvOrder $order): array
    {
        $orderData = $this->ipipv->getOrder(
            orderNo: $order->ipipv_order_no,
            appOrderNo: $order->app_order_no
        );

        $status = (int) ($orderData['status'] ?? $order->status);
        $instances = $orderData['instances'] ?? [];

        $order->update([
            'status'        => $status,
            'response_data' => $orderData,
        ]);

        $proxyIpIds = [];
        $subscriptionIds = [];

        if (!empty($instances)) {
            [$proxyIpIds, $subscriptionIds] = $this->processInstances($order, $instances);
        }

        return [
            'ipipv_order'      => $order->fresh(),
            'proxy_ip_ids'     => $proxyIpIds,
            'subscription_ids' => $subscriptionIds,
            'instance_count'   => count($instances),
        ];
    }

    /**
     * 将 IPIPV 返回的实例同步到本地
     */
    public function processInstances(IpipvOrder $order, array $instances): array
    {
        $params = $order->request_data ?? [];
        $customerId = $params['customer_id'] ?? null;
        $assetGroupId = $params['asset_group_id'] ?? null;
        $ipGroupId = $params['ip_group_id'] ?? null;
        $salePrice = $params['sale_price'] ?? null;
        $createdBy = $params['created_by'] ?? 1;

        $proxyIpIds = [];
        $subscriptionIds = [];

        DB::beginTransaction();
        try {
            foreach ($instances as $inst) {
                $instanceNo = $inst['instanceNo'] ?? null;
                if (!$instanceNo) continue;

                $existingInst = IpipvInstance::where('instance_no', $instanceNo)->first();
                if ($existingInst) {
                    $this->updateInstance($existingInst, $inst);
                    if ($existingInst->proxy_ip_id) {
                        $proxyIpIds[] = $existingInst->proxy_ip_id;
                        continue;
                    }
                    // 实例之前未 running，现在变成 running(3) 了，需要补建 ProxyIp + Subscription
                    $nowStatus = (int) ($inst['status'] ?? $existingInst->status);
                    if ($nowStatus !== 3) {
                        continue;
                    }
                    // 走下面的 ProxyIp 创建流程
                }

                $instanceStatus = (int) ($inst['status'] ?? 1);
                if (!in_array($instanceStatus, [3])) {
                    // 只处理 running(3) 状态的实例
                    IpipvInstance::create([
                        'ipipv_order_id' => $order->id,
                        'instance_no'    => $instanceNo,
                        'ip'             => $inst['ip'] ?? null,
                        'port'           => $inst['port'] ?? null,
                        'username'       => $inst['username'] ?? null,
                        'password'       => $inst['pwd'] ?? null,
                        'product_no'     => $inst['productNo'] ?? $order->product_no,
                        'country_code'   => $inst['countryCode'] ?? null,
                        'city_code'      => $inst['cityCode'] ?? null,
                        'protocol'       => $inst['protocol'] ?? null,
                        'status'         => $instanceStatus,
                        'flow_total'     => $inst['flowTotal'] ?? null,
                        'flow_balance'   => $inst['flowBalance'] ?? null,
                        'expire_at'      => isset($inst['userExpired']) && $inst['userExpired'] > 0
                            ? \Carbon\Carbon::createFromTimestamp($inst['userExpired'])
                            : null,
                    ]);
                    continue;
                }

                $ip = $inst['ip'] ?? null;
                $port = !empty($inst['port']) ? (int) $inst['port'] : null;
                $username = $inst['username'] ?? null;
                $password = $inst['pwd'] ?? null;
                $expireAt = isset($inst['userExpired']) && $inst['userExpired'] > 0
                    ? \Carbon\Carbon::createFromTimestamp($inst['userExpired'])
                    : null;

                $countryCode = $inst['countryCode'] ?? ($params['country_code'] ?? null);
                $protocol = IpipvApiService::mapProtocol($inst['protocol'] ?? '1');
                $resolved = CountryMapper::resolve($countryCode);
                $countryName = $params['country_cn'] ?? $resolved['cn'] ?? $countryCode;
                $countryCode = $resolved['iso3'] ?? $countryCode;

                // 检查 IP 是否已存在且分配给了其他客户
                $oldIp = ProxyIp::withTrashed()
                    ->where('ip_address', $ip ?? '')
                    ->where('port', $port ?? 0)
                    ->first();
                $isReProvision = $oldIp !== null;

                if ($oldIp && $customerId && $oldIp->assigned_customer_id && $oldIp->assigned_customer_id != $customerId) {
                    $oldSubs = Subscription::where('proxy_ip_id', $oldIp->id)
                        ->where('status', 'active')
                        ->get();
                    foreach ($oldSubs as $oldSub) {
                        $oldSub->update([
                            'status' => 'cancelled',
                            'remark' => trim(($oldSub->remark ?? '') . "\n[系统] IP被上游重新分配，自动取消"),
                        ]);
                    }
                    Log::warning('IpipvProvisionService: IP reassigned from different customer', [
                        'proxy_ip_id' => $oldIp->id,
                        'ip' => $oldIp->ip_address,
                        'old_customer' => $oldIp->assigned_customer_id,
                        'new_customer' => $customerId,
                        'cancelled_subs' => $oldSubs->pluck('id')->toArray(),
                    ]);
                }

                $proxyIp = ProxyIp::withTrashed()->updateOrCreate(
                    ['ip_address' => $ip ?? '', 'port' => $port ?? 0],
                    [
                        'asset_group_id'       => $assetGroupId,
                        'ip_group_id'          => $ipGroupId,
                        'socks5_info'          => $ip && $port && $port > 0 ? "{$ip}:{$port}" . ($username ? ":{$username}:{$password}" : '') : null,
                        'auth_username'        => $username,
                        'auth_password'        => $password,
                        'protocol'             => $protocol,
                        'asset_name'           => ($countryName ?: 'IP') . '-' . ($ip ?: $instanceNo),
                        'country_code'         => $countryCode,
                        'country_name'         => $countryName,
                        'ip_type'              => 'residential',
                        'nature'               => 'static',
                        'source_name'          => 'IPIPV',
                        'status'               => $customerId ? 'assigned' : 'available',
                        'assigned_customer_id' => $customerId,
                        'ipipv_instance_id'    => $instanceNo,
                        'upstream_expires_at'  => $expireAt,
                        'spark_release_status' => null,
                        'spark_release_order_no' => null,
                        'spark_released_at'    => null,
                        'spark_release_error'  => null,
                        'released_at'          => null,
                        'release_reason'       => null,
                        'released_by'          => null,
                        'extra_config'         => null,
                    ]
                );
                if ($proxyIp->trashed()) {
                    $proxyIp->restore();
                }

                if ($isReProvision) {
                    $proxyIp->timestamps = false;
                    $proxyIp->update(['created_at' => now()]);
                    $proxyIp->timestamps = true;
                }

                if ($existingInst) {
                    $existingInst->update(['proxy_ip_id' => $proxyIp->id]);
                } else {
                    IpipvInstance::create([
                        'ipipv_order_id' => $order->id,
                        'proxy_ip_id'    => $proxyIp->id,
                        'instance_no'    => $instanceNo,
                        'ip'             => $ip,
                        'port'           => $port,
                        'username'       => $username,
                        'password'       => $password,
                        'product_no'     => $inst['productNo'] ?? $order->product_no,
                        'country_code'   => $countryCode,
                        'city_code'      => $inst['cityCode'] ?? null,
                        'protocol'       => $inst['protocol'] ?? null,
                        'status'         => $instanceStatus,
                        'flow_total'     => $inst['flowTotal'] ?? null,
                        'flow_balance'   => $inst['flowBalance'] ?? null,
                        'expire_at'      => $expireAt,
                    ]);
                }

                $proxyIpIds[] = $proxyIp->id;

                if ($customerId && $salePrice !== null) {
                    $duration = $order->duration;
                    $unit = $order->unit;
                    $cycleTimes = $order->cycle_times;

                    $isTest = !empty($params['is_test']);
                    $testHours = $isTest ? (int) ($params['test_hours'] ?? 12) : 0;
                    $testReclaimAt = $isTest ? now()->addHours($testHours) : null;

                    // 自动查询官网原价和销售成本（月单价，统计时乘以 duration）
                    $listPrice = isset($params['list_price']) ? (float) $params['list_price'] : null;
                    $salesCost = isset($params['sales_cost']) ? (float) $params['sales_cost'] : null;
                    $costOverride = \App\Models\SystemConfig::get('cost.ipipv_hard_cost_override');
                    $hardCost = ($costOverride !== null && (float) $costOverride > 0)
                        ? (float) $costOverride
                        : null;
                    if ($hardCost === null && $order->product_no) {
                        $products = \App\Services\IpipvStockCacheService::products();
                        $ipipvProduct = collect($products)->firstWhere('product_no', $order->product_no);
                        if ($ipipvProduct && isset($ipipvProduct['cost_price']) && (float) $ipipvProduct['cost_price'] > 0) {
                            $hardCost = (float) $ipipvProduct['cost_price'];
                        }
                    }
                    if ($hardCost !== null) {
                        $productArr = ['cost_price' => $hardCost, 'source' => 'ipipv'];
                        if ($listPrice === null) {
                            $listPrice = \App\Models\PricingMultiplier::calcSalePrice($productArr);
                        }
                        if ($salesCost === null) {
                            $salesCost = $hardCost;
                        }
                    }

                    $startedAt = now();
                    // 订阅到期按客户购买时长计算，不依赖上游 expireAt（上游固定1周期，由 cron 滚动续费）
                    $expiresAt = $this->calcExpiry($startedAt, $duration, $unit, $cycleTimes);

                    $durationMonths = \App\Support\DurationHelper::toMonths($duration, $unit);
                    $totalPrice = round($salePrice * max($durationMonths, 1), 2);

                    $sub = Subscription::create([
                        'customer_id'        => $customerId,
                        'proxy_ip_id'        => $proxyIp->id,
                        'provision_order_id' => $order->provision_order_id,
                        'price'              => $totalPrice,
                        'admin_set_price'    => $createdBy > 1 ? $salePrice : null,
                        'list_price'         => $listPrice,
                        'sales_cost'         => $salesCost,
                        'hard_cost'          => $hardCost,
                        'duration'           => $duration,
                        'unit'               => $unit,
                        'started_at'         => $startedAt,
                        'expires_at'         => $isTest ? $testReclaimAt : $expiresAt,
                        'status'             => 'active',
                        'is_test'            => $isTest,
                        'test_reclaim_at'    => $testReclaimAt,
                        'created_by'         => $createdBy,
                        'remark'             => $isTest ? "测试订单 ({$testHours}h自动回收)" : null,
                    ]);

                    if ($isTest) {
                        \App\Jobs\ReclaimTestIpJob::dispatch($sub->id)
                            ->delay($testReclaimAt);
                    }
                    $subscriptionIds[] = $sub->id;

                    // 业绩流水账：余额扣款的正式订单记购买行（中转成本由挂载事件另记）
                    if (!$isTest && ($params['payment_method'] ?? null) === 'balance') {
                        $purchaseTxnId = DB::table('transactions')
                            ->where('customer_id', $customerId)
                            ->where('type', 'purchase')
                            ->where('amount', '<', 0)
                            ->whereBetween('created_at', [
                                $order->created_at->copy()->subSeconds(10),
                                $order->created_at->copy()->addSeconds(10),
                            ])
                            ->value('id');
                        \App\Services\PerformanceLedger::record([
                            'event_type' => \App\Services\PerformanceLedger::EVENT_PURCHASE,
                            'customer_id' => $customerId,
                            'subscription_id' => $sub->id,
                            'transaction_id' => $purchaseTxnId,
                            'revenue' => $totalPrice,
                            'sales_cost' => (float) ($salesCost ?? 0) * max($durationMonths, 1),
                            'hard_cost_ip' => (float) ($hardCost ?? 0) * max($durationMonths, 1),
                            'months' => max($durationMonths, 1),
                            'meta' => ['source' => 'ipipv', 'order_id' => $order->id],
                        ]);
                    }

                    IpAssignmentLog::create([
                        'proxy_ip_id'  => $proxyIp->id,
                        'customer_id'  => $customerId,
                        'action'       => 'assign',
                        'operated_by'  => $createdBy,
                        'remark'       => "IPIPV 开通: {$order->app_order_no}",
                    ]);
                }
            }

            if ($order->status === 1 && count($proxyIpIds) > 0) {
                $order->update(['status' => 3]);
            }

            DB::commit();

            // 转发规则复用 SparkProvisionService 逻辑（事务外，允许失败）
            if (!empty($subscriptionIds)) {
                try {
                    $sparkSvc = app(SparkProvisionService::class);
                    $sparkSvc->attachForwardsForSubscriptions($subscriptionIds, $proxyIpIds, $params);
                } catch (\Throwable $e) {
                    Log::warning('IPIPV forward attachment failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('IPIPV processInstances failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }

        return [$proxyIpIds, $subscriptionIds];
    }

    private function updateInstance(IpipvInstance $instance, array $data): void
    {
        $instance->update(array_filter([
            'ip'           => $data['ip'] ?? null,
            'port'         => $data['port'] ?? null,
            'username'     => $data['username'] ?? null,
            'password'     => $data['pwd'] ?? null,
            'status'       => isset($data['status']) ? (int) $data['status'] : null,
            'flow_total'   => $data['flowTotal'] ?? null,
            'flow_balance' => $data['flowBalance'] ?? null,
            'expire_at'    => isset($data['userExpired']) && $data['userExpired'] > 0
                ? \Carbon\Carbon::createFromTimestamp($data['userExpired'])
                : null,
        ], fn ($v) => $v !== null));
    }

    private function calcExpiry($start, int $duration, int $unit, int $cycleTimes): \Carbon\Carbon
    {
        $start = \Carbon\Carbon::parse($start);
        $totalDuration = $duration * $cycleTimes;
        return \App\Support\DurationHelper::addToDate($start, $totalDuration, $unit);
    }

}
