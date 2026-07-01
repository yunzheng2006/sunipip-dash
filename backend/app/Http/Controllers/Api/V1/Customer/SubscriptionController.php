<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\IpAssignmentLog;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\SparkReleaseService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 客户自助面板 - 订阅管理
 *
 * 所有查询和操作都限制在 `customer_id = auth user` 内。
 */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subService) {}

    /**
     * GET /customer/subscriptions
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $query = Subscription::with([
                'proxyIp:id,asset_name,ip_address,port,auth_username,auth_password,country_code,country_name,city,source_name,spark_instance_id,ipipv_instance_id',
                'forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
                'forwardRule.forwardPlan:id,name,module,display_host,base_price,pricing_mode',
            ])
            ->where('customer_id', $customer->id);

        if ($request->filled('status')) {
            $allowed = ['active', 'expired', 'refunded', 'cancelled'];
            if (in_array($request->input('status'), $allowed)) {
                $query->where('status', $request->input('status'));
            }
        }
        if ($request->filled('expiring_soon')) {
            $days = max(1, min(365, (int) $request->input('expiring_soon')));
            $query->where('status', 'active')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays($days));
        }
        // 关键词搜索（资产名/IP地址）
        if ($request->filled('keyword')) {
            $kw = $request->input('keyword');
            $query->whereHas('proxyIp', function ($q) use ($kw) {
                $q->where('asset_name', 'like', "%{$kw}%")
                  ->orWhere('ip_address', 'like', "%{$kw}%");
            });
        }
        // 地区筛选
        if ($request->filled('country')) {
            $country = $request->input('country');
            $query->whereHas('proxyIp', function ($q) use ($country) {
                $q->where('country_name', 'like', "%{$country}%")
                  ->orWhere('country_code', $country);
            });
        }
        // 自动续费筛选
        if ($request->filled('auto_renew')) {
            $query->where('auto_renew', (int) $request->input('auto_renew'));
        }

        // 排序：支持客户自定义，默认 活跃在前+最新创建在前
        $sort = $request->input('sort');
        $query->orderByRaw("FIELD(status, 'active', 'expired', 'refunded', 'cancelled')");
        switch ($sort) {
            case 'expires_desc':
                $query->orderBy('expires_at', 'desc'); break;
            case 'expires_asc':
                $query->orderBy('expires_at', 'asc'); break;
            case 'created_asc':
                $query->orderBy('id', 'asc'); break;
            case 'price_desc':
                $query->orderBy('price', 'desc'); break;
            case 'price_asc':
                $query->orderBy('price', 'asc'); break;
            case 'created_desc':
            default:
                $query->orderBy('id', 'desc'); break;
        }

        $paginated = $query->paginate(min((int) $request->input('per_page', 20), 100));

        // 为每条记录添加 redeemable 标记（Spark IP 3天宽限期内可赎回）+ VIP续费价
        $paginated->getCollection()->transform(function ($sub) use ($customer) {
            $sub->redeemable = false;
            if ($sub->status === 'expired' && $sub->proxyIp?->spark_instance_id) {
                $daysSinceExpiry = $sub->expires_at?->diffInDays(now()) ?? 999;
                $sub->redeemable = $daysSinceExpiry <= 3;
                $sub->grace_days_left = max(0, 3 - $daysSinceExpiry);
            }
            $sub->renewal_price = $this->calcRenewalMonthlyPrice($customer, $sub);
            return $sub;
        });

        return $this->paginated($paginated);
    }

    /**
     * GET /customer/subscriptions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::with([
                'proxyIp',
                'forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
                'forwardRule.forwardPlan:id,name,module,display_host,base_price,pricing_mode',
            ])
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        return $this->success($sub);
    }

    /**
     * POST /customer/subscriptions/{id}/renew
     */
    public function renew(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::where('customer_id', $customer->id)->findOrFail($id);

        $isExpired = $sub->status === 'expired';
        if ($sub->status !== 'active' && !$isExpired) {
            return $this->error('只能续费活跃或近期过期的订阅', 422);
        }
        if ($isExpired && $sub->expires_at->diffInDays(now()) > 3) {
            return $this->error('该订阅已过期超过3天，无法续费', 422);
        }

        $data = $request->validate([
            'duration' => 'nullable|integer|min:1|max:12',
            'unit' => 'nullable|integer|in:3', // 仅月
        ]);

        try {
            $renewDuration = $data['duration'] ?? 1;
            $monthlyPrice = $this->calcRenewalMonthlyPrice($customer, $sub);
            $result = $this->subService->renewOne($sub, [
                'duration' => $renewDuration,
                'unit' => $data['unit'] ?? 3,
                'price' => round($monthlyPrice * $renewDuration, 2),
                'reactivate' => $isExpired,
            ], null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        try {
            app(\App\Services\VipService::class)->recalculate($customer->fresh());
            \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($customer->id);
        } catch (\Throwable) {}

        return $this->success([
            'subscription' => $result,
            'new_balance' => (float) $customer->fresh()->balance,
        ], '续费成功');
    }

    /**
     * POST /customer/subscriptions/{id}/refund
     *
     * 退订规则：
     *   - 仅限客户自助下单的订阅（created_by=1）可自助退订
     *   - 管理员开单的需联系销售/经理操作
     *   - 12h 内可退订（Spark 释放窗口）
     *   - 退款金额 = 订阅价格 - ¥1 释放手续费
     *   - 自动调 Spark API 释放 IP
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        if (!\App\Models\SystemConfig::get('customer.self_refund_enabled', false)) {
            return $this->error('自助退款功能暂未开放，如需退款请联系客服', 403);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $releaseFee = 1.00;

        $sub = Subscription::with('proxyIp')
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (!$sub) {
            return $this->error('订阅不存在', 404);
        }
        if ($sub->status !== 'active') {
            return $this->error('只能退订状态为激活的订阅', 422);
        }
        if ((int) $sub->created_by !== 1) {
            return $this->error('该订阅由管理员开通，如需退订请联系您的客户经理', 422);
        }

        $ageMinutes = $sub->started_at?->diffInMinutes(now()) ?? 999999;
        if ($ageMinutes > 12 * 60) {
            $ageHours = intdiv($ageMinutes, 60);
            return $this->error("订阅已开通 {$ageHours} 小时，超过 12 小时退订时限", 422);
        }

        $proxyIp = $sub->proxyIp;
        $originalPrice = (float) $sub->price;
        $refundAmount = max(0, $originalPrice - $releaseFee);

        // ── 第1步：API 释放（必须成功才退款）──
        $sparkResult = null;
        if ($proxyIp && $proxyIp->spark_instance_id) {
            $sparkResult = SparkReleaseService::releaseInstance($proxyIp, 'customer_self_refund');
            if ($sparkResult['status'] === 'failed') {
                return $this->error('IP 释放失败，退订中止: ' . $sparkResult['message'], 422);
            }
        }
        if ($proxyIp && $proxyIp->ipipv_instance_id) {
            try {
                $ipipv = app(\App\Services\IpipvApiService::class);
                $orderNo = \App\Models\IpipvOrder::generateAppOrderNo();
                $ipipv->releaseProxy($orderNo, [$proxyIp->ipipv_instance_id]);
                \App\Models\IpipvOrder::create([
                    'app_order_no' => $orderNo, 'method' => 'release', 'status' => 1,
                    'request_data' => ['reason' => '客户自助退订', 'proxy_ip_id' => $proxyIp->id],
                    'response_data' => [],
                ]);
            } catch (\Throwable $e) {
                return $this->error('IP 释放失败，退订中止: ' . $e->getMessage(), 422);
            }
        }

        // ── 第2步：清理转发 ──
        if ($sub->has_forward) {
            try { app(\App\Services\Ny\NyForwardService::class)->deleteForSubscription($sub); } catch (\Throwable) {}
        }
        try { app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($sub); } catch (\Throwable) {}

        // ── 第3步：API 释放成功 → 执行退订+退款 ──
        DB::transaction(function () use ($sub, $customer, $data, $refundAmount, $releaseFee, $originalPrice, $proxyIp) {
            $sub->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reason' => $data['reason'] ?? '客户自助退订',
                'refund_amount' => $refundAmount,
                'refunded_by' => null,
            ]);

            $fresh = \App\Models\Customer::where('id', $customer->id)->lockForUpdate()->first();
            $balanceBefore = (float) $fresh->balance;

            if ($refundAmount > 0) {
                $fresh->increment('balance', $refundAmount);
                Transaction::create([
                    'customer_id' => $fresh->id,
                    'type' => Transaction::TYPE_REFUND,
                    'amount' => $refundAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore + $refundAmount,
                    'related_type' => Subscription::class,
                    'related_id' => $sub->id,
                    'description' => sprintf(
                        '自助退订 #%d (原价 ¥%.2f - 释放手续费 ¥%.2f): %s',
                        $sub->id, $originalPrice, $releaseFee, $data['reason'] ?? ''
                    ),
                    'operated_by' => null,
                ]);
            }

            if ($proxyIp) {
                $proxyIp->update([
                    'assigned_customer_id' => null,
                    'status' => 'released',
                    'released_at' => now(),
                    'release_reason' => '客户自助退订',
                    'released_by' => null,
                ]);
                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $sub->customer_id,
                    'subscription_id' => $sub->id,
                    'action' => 'unassign',
                    'operated_by' => 1,
                    'remark' => "客户自助退订（退款 ¥{$refundAmount}，手续费 ¥{$releaseFee}）",
                    'created_at' => now(),
                ]);
            }

            // 回收关联的推荐返佣 + 销售佣金
            try {
                app(\App\Services\ReferralService::class)
                    ->reverseCommissions($sub->customer_id, $sub->id);
            } catch (\Throwable $e) {
                \Log::warning('customer refund: commission reversal failed', [
                    'subscription_id' => $sub->id, 'error' => $e->getMessage(),
                ]);
            }
        });

        try { \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($customer->id); } catch (\Throwable) {}

        return $this->success([
            'refunded' => true,
            'original_price' => $originalPrice,
            'release_fee' => $releaseFee,
            'refund_amount' => $refundAmount,
            'new_balance' => (float) $customer->fresh()->balance,
            'spark_release' => $sparkResult,
        ], sprintf('已退订，退款 ¥%.2f（扣除释放手续费 ¥%.2f）', $refundAmount, $releaseFee));
    }

    /**
     * PUT /customer/subscriptions/{id}/auto-renew
     */
    public function toggleAutoRenew(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::where('customer_id', $customer->id)->findOrFail($id);

        $data = $request->validate(['enabled' => 'required|boolean']);

        $sub->update(['auto_renew' => $data['enabled'] ? 1 : 0]);
        return $this->success(['auto_renew' => (bool) $sub->auto_renew], $data['enabled'] ? '已开启自动续费' : '已关闭自动续费');
    }

    /**
     * PUT /customer/subscriptions/batch-auto-renew
     * 批量切换自动续费
     */
    public function batchToggleAutoRenew(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
            'enabled' => 'required|boolean',
        ]);

        $updated = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->whereIn('id', $data['ids'])
            ->update(['auto_renew' => $data['enabled'] ? 1 : 0]);

        $msg = $data['enabled'] ? "已为 {$updated} 条订阅开启自动续费" : "已为 {$updated} 条订阅关闭自动续费";
        return $this->success(['updated' => $updated], $msg);
    }

    /**
     * POST /customer/subscriptions/{id}/redeem
     * 赎回已过期的 Spark IP（3天宽限期内）
     *
     * 不直接续费，而是提交赎回请求到管理面板 + 企业微信通知
     */
    public function redeem(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::with('proxyIp')
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        if ($sub->status !== 'expired') {
            return $this->error('只能赎回已过期的订阅', 422);
        }

        if (!$sub->proxyIp?->spark_instance_id) {
            return $this->error('仅 Spark IP 支持赎回', 422);
        }

        $daysSinceExpiry = $sub->expires_at?->diffInDays(now()) ?? 999;
        if ($daysSinceExpiry > 3) {
            return $this->error('已超过 3 天宽限期，无法赎回', 422);
        }

        // 创建赎回审批单
        $approval = \App\Models\ProvisionApproval::create([
            'order_no' => \App\Models\ProvisionApproval::generateOrderNo(),
            'type' => 'redeem',
            'submitted_by' => 1, // 系统占位（客户发起）
            'customer_id' => $customer->id,
            'order_data' => [
                'subscription_id' => $sub->id,
                'proxy_ip_id' => $sub->proxy_ip_id,
                'ip_address' => $sub->proxyIp?->ip_address,
                'asset_name' => $sub->proxyIp?->asset_name,
                'country_name' => $sub->proxyIp?->country_name,
                'spark_instance_id' => $sub->proxyIp?->spark_instance_id,
                'original_price' => (float) $sub->price,
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'grace_days_left' => max(0, 3 - $daysSinceExpiry),
                'remark' => '客户申请赎回过期 IP',
            ],
            'total_amount' => (float) $sub->price,
            'status' => 'pending',
        ]);

        // 企业微信通知
        try {
            app(\App\Services\NotificationService::class)->dispatch('ip_redeem_request', [
                'title' => '🔄 客户申请赎回过期 IP',
                'content' => sprintf(
                    "**客户**：%s\n**IP**：%s (%s)\n**地区**：%s\n**月价**：¥%.2f\n**过期时间**：%s\n**宽限剩余**：%d 天\n\n请尽快处理，宽限期结束后将无法通过 API 续费。",
                    $customer->customer_name,
                    $sub->proxyIp?->asset_name ?? '-',
                    $sub->proxyIp?->ip_address ?? '-',
                    $sub->proxyIp?->country_name ?? '-',
                    round((float) $sub->price / max(\App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3), 1), 2),
                    $sub->expires_at?->format('Y-m-d H:i') ?? '-',
                    max(0, 3 - $daysSinceExpiry)
                ),
                'related_type' => 'ProvisionApproval',
                'related_id' => $approval->id,
                'dedup_key' => "redeem_{$sub->id}_" . now()->format('Ymd'),
            ]);
        } catch (\Throwable) {}

        return $this->success([
            'approval_no' => $approval->order_no,
            'grace_days_left' => max(0, 3 - $daysSinceExpiry),
        ], '赎回请求已提交，我们会尽快处理');
    }

    /**
     * POST /customer/subscriptions/batch-renew-by-ip
     * 批量续费：通过粘贴 IP 地址列表识别订阅并续费
     */
    public function batchRenewByIp(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'ips' => 'required|array|min:1|max:1000',
            'ips.*' => 'required|string',
            'duration' => 'required|integer|min:1|max:12',
        ]);

        $results = [
            'success' => [],
            'failed' => [],
            'not_found' => [],
        ];

        $totalCost = 0;

        foreach ($data['ips'] as $rawIp) {
            $ip = $this->extractIpAddress($rawIp);
            if (!$ip) {
                $results['not_found'][] = [
                    'input' => $rawIp,
                    'reason' => '无法识别 IP 地址',
                ];
                continue;
            }

            $proxyIp = \App\Models\ProxyIp::where('ip_address', $ip)
                ->where('assigned_customer_id', $customer->id)
                ->first();

            if (!$proxyIp) {
                $results['not_found'][] = [
                    'input' => $rawIp,
                    'ip' => $ip,
                    'reason' => '未找到该 IP 或不属于您',
                ];
                continue;
            }

            $subscription = Subscription::where('proxy_ip_id', $proxyIp->id)
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['active', 'expired'])
                ->first();

            if ($subscription && $subscription->status === 'expired' && $subscription->expires_at->diffInDays(now()) > 3) {
                $subscription = null;
            }

            if (!$subscription) {
                $results['not_found'][] = [
                    'input' => $rawIp,
                    'ip' => $ip,
                    'asset_name' => $proxyIp->asset_name,
                    'reason' => '未找到活跃订阅',
                ];
                continue;
            }

            try {
                $monthlyPrice = $this->calcRenewalMonthlyPrice($customer, $subscription);
                $totalPrice = round($monthlyPrice * $data['duration'], 2);
                $renewed = $this->subService->renewOne($subscription, [
                    'duration' => $data['duration'],
                    'unit' => 3,
                    'price' => $totalPrice,
                    'reactivate' => $subscription->status === 'expired',
                ], null);

                $cost = $totalPrice;
                $totalCost += $cost;

                $results['success'][] = [
                    'ip' => $ip,
                    'asset_name' => $proxyIp->asset_name,
                    'country_name' => $proxyIp->country_name,
                    'subscription_id' => $subscription->id,
                    'price' => (float) $subscription->price,
                    'new_expires_at' => $renewed->expires_at?->toIso8601String(),
                    'cost' => $cost,
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'ip' => $ip,
                    'asset_name' => $proxyIp->asset_name,
                    'subscription_id' => $subscription->id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        try {
            app(\App\Services\VipService::class)->recalculate($customer->fresh());
            \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($customer->id);
        } catch (\Throwable) {}

        return $this->success([
            'results' => $results,
            'total_cost' => $totalCost,
            'new_balance' => (float) $customer->fresh()->balance,
            'summary' => [
                'success' => count($results['success']),
                'failed' => count($results['failed']),
                'not_found' => count($results['not_found']),
            ],
        ]);
    }

    /**
     * POST /customer/subscriptions/identify-ips
     * 识别粘贴的 IP 列表 —— 返回匹配到的订阅信息（不执行续费）
     */
    public function identifyIps(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'ips' => 'required|array|min:1|max:1000',
            'ips.*' => 'required|string',
        ]);

        $matched = [];
        $unmatched = [];

        foreach ($data['ips'] as $rawIp) {
            $ip = $this->extractIpAddress($rawIp);
            if (!$ip) {
                $unmatched[] = ['input' => $rawIp, 'reason' => '无法识别 IP 地址'];
                continue;
            }

            $proxyIp = \App\Models\ProxyIp::where('ip_address', $ip)
                ->where('assigned_customer_id', $customer->id)
                ->first();

            if (!$proxyIp) {
                $unmatched[] = ['input' => $rawIp, 'ip' => $ip, 'reason' => '未找到该 IP 或不属于您'];
                continue;
            }

            $subscription = Subscription::where('proxy_ip_id', $proxyIp->id)
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->first();

            if (!$subscription) {
                $unmatched[] = [
                    'input' => $rawIp, 'ip' => $ip,
                    'asset_name' => $proxyIp->asset_name,
                    'reason' => '未找到活跃订阅',
                ];
                continue;
            }

            $renewalPrice = $this->calcRenewalMonthlyPrice($customer, $subscription);
            $matched[] = [
                'ip' => $ip,
                'asset_name' => $proxyIp->asset_name,
                'country_name' => $proxyIp->country_name,
                'subscription_id' => $subscription->id,
                'price' => (float) $subscription->price,
                'renewal_price' => $renewalPrice,
                'expires_at' => $subscription->expires_at?->toIso8601String(),
                'auto_renew' => (bool) $subscription->auto_renew,
            ];
        }

        return $this->success([
            'matched' => $matched,
            'unmatched' => $unmatched,
        ]);
    }

    /**
     * 委托给 SubscriptionService 的统一续费价格计算
     */
    private function calcRenewalMonthlyPrice($customer, Subscription $sub): float
    {
        return $this->subService->calcRenewalMonthlyPrice($customer, $sub);
    }

    private function extractIpAddress(string $raw): ?string
    {
        $raw = trim($raw);
        if (!$raw) return null;

        if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $raw, $matches)) {
            $ip = $matches[1];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * GET /customer/subscriptions/{id}/upgrade-forward-preview
     */
    public function upgradeForwardPreview(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::with('proxyIp')
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        $check = $this->validateUpgradeEligibility($sub);
        if ($check) return $check;

        $proxyIp = $sub->proxyIp;
        $forwardPlan = \App\Models\ForwardPlan::where('module', 'video')
            ->where('is_active', 1)->first();
        if (!$forwardPlan) {
            return $this->error('暂无可用的视频专线套餐', 422);
        }

        $deviceGroup = $forwardPlan->device_group_id
            ? \App\Models\NyDeviceGroup::with('panel')->find($forwardPlan->device_group_id)
            : null;
        if (!$deviceGroup || !$deviceGroup->is_enabled || !$deviceGroup->panel?->is_active) {
            return $this->error('视频专线服务暂不可用', 422);
        }

        $pricing = $this->calcUpgradeForwardPricing($customer, $sub, $forwardPlan);

        return $this->success([
            'subscription_id' => $sub->id,
            'ip_address' => $proxyIp->ip_address,
            'asset_name' => $proxyIp->asset_name,
            'country_name' => $proxyIp->country_name ?? $proxyIp->country_code,
            'expires_at' => $sub->expires_at->toDateTimeString(),
            'remaining_days' => $pricing['remaining_days'],
            'forward_plan' => [
                'id' => $forwardPlan->id,
                'name' => $forwardPlan->name,
                'module' => $forwardPlan->module,
                'base_price' => (float) $forwardPlan->base_price,
            ],
            'monthly_fee' => $pricing['monthly_fee'],
            'remaining_months' => $pricing['remaining_months'],
            'total_charge' => $pricing['total_charge'],
            'customer_balance' => (float) $customer->balance,
            'balance_sufficient' => (float) $customer->balance >= $pricing['total_charge'],
        ]);
    }

    /**
     * POST /customer/subscriptions/{id}/upgrade-forward
     */
    public function upgradeForward(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::with('proxyIp')
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        $check = $this->validateUpgradeEligibility($sub);
        if ($check) return $check;

        $proxyIp = $sub->proxyIp;
        $forwardPlan = \App\Models\ForwardPlan::where('module', 'video')
            ->where('is_active', 1)->first();
        if (!$forwardPlan) {
            return $this->error('暂无可用的视频专线套餐', 422);
        }

        $deviceGroup = $forwardPlan->device_group_id
            ? \App\Models\NyDeviceGroup::with('panel')->find($forwardPlan->device_group_id)
            : null;
        if (!$deviceGroup || !$deviceGroup->is_enabled || !$deviceGroup->panel?->is_active) {
            return $this->error('视频专线服务暂不可用', 422);
        }

        $pricing = $this->calcUpgradeForwardPricing($customer, $sub, $forwardPlan);
        $totalCharge = $pricing['total_charge'];
        $monthlyFee = $pricing['monthly_fee'];

        try {
            $ruleId = DB::transaction(function () use (
                $customer, $sub, $proxyIp, $forwardPlan, $deviceGroup, $totalCharge, $monthlyFee
            ) {
                $fresh = \App\Models\Customer::lockForUpdate()->findOrFail($customer->id);
                if ((float) $fresh->balance < $totalCharge) {
                    throw new \Exception(sprintf('余额不足：当前 ¥%.2f，需要 ¥%.2f', $fresh->balance, $totalCharge));
                }

                $balanceBefore = $fresh->balance;
                $fresh->decrement('balance', $totalCharge);
                $balanceAfter = bcsub($balanceBefore, $totalCharge, 2);

                Transaction::create([
                    'customer_id' => $fresh->id,
                    'type' => Transaction::TYPE_DEDUCTION,
                    'amount' => -$totalCharge,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'related_type' => Subscription::class,
                    'related_id' => $sub->id,
                    'description' => "升级视频专线 订阅#{$sub->id} ({$proxyIp->ip_address})",
                    'operated_by' => null,
                ]);

                $rule = \App\Models\ForwardRule::create([
                    'subscription_id' => $sub->id,
                    'proxy_ip_id' => $proxyIp->id,
                    'ny_panel_id' => $deviceGroup->ny_panel_id,
                    'ny_device_group_id' => $deviceGroup->id,
                    'forward_plan_id' => $forwardPlan->id,
                    'name' => sprintf('SNP-S%d-%s:%d', $sub->id, $proxyIp->ip_address, $proxyIp->port),
                    'dest_host' => $proxyIp->ip_address,
                    'dest_port' => (int) $proxyIp->port,
                    'speed_limit_mbps' => $forwardPlan->speed_limit_mbps,
                    'forward_fee' => $monthlyFee,
                    'status' => 'pending',
                ]);

                $sub->update([
                    'has_forward' => true,
                    'purchased_module' => $forwardPlan->module,
                    'balance_deducted' => true,
                ]);

                return $rule->id;
            });
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        \App\Jobs\AttachForwardJob::dispatch($ruleId);

        try {
            $fwdBasePriceTotal = round((float) $forwardPlan->base_price * $pricing['remaining_months'], 2);
            $referralService = app(\App\Services\ReferralService::class);
            $referralService->processCommission($customer, 'forward', $totalCharge, $sub->id, $fwdBasePriceTotal ?: $totalCharge);
        } catch (\Throwable) {}

        return $this->success([
            'subscription_id' => $sub->id,
            'charged' => $totalCharge,
            'new_balance' => (float) \App\Models\Customer::find($customer->id)->balance,
        ], '升级成功，视频专线正在开通中（约1-3分钟）');
    }

    private function validateUpgradeEligibility(Subscription $sub): ?JsonResponse
    {
        if ($sub->status !== 'active') {
            return $this->error('只能升级活跃的订阅', 422);
        }
        if (!$sub->proxyIp) {
            return $this->error('订阅未关联 IP', 422);
        }
        if ($sub->proxyIp->ipipv_instance_id) {
            return $this->error('IPIPV 订阅不支持此升级', 422);
        }

        $hasForward = \App\Models\ForwardRule::where('subscription_id', $sub->id)
            ->whereNotIn('status', ['deleted', 'failed'])->exists();
        if ($hasForward) {
            return $this->error('该订阅已有转发规则，无需升级', 422);
        }

        $remainingDays = max(0, now()->diffInDays($sub->expires_at, false));
        if ($remainingDays < 3) {
            return $this->error('订阅剩余时间不足3天，请先续费后再升级', 422);
        }

        return null;
    }

    private function calcUpgradeForwardPricing($customer, Subscription $sub, \App\Models\ForwardPlan $forwardPlan): array
    {
        $baseFee = (float) $forwardPlan->base_price;
        $proxyIp = $sub->proxyIp;

        $product = $proxyIp ? [
            'country_code' => $proxyIp->country_code ?? null,
            'city_code' => $proxyIp->city ?? null,
            'area_code' => null,
            'product_id' => null,
        ] : [];
        $specialTrace = \App\Models\CustomerSpecialPrice::findPriceTrace($customer->id, $product, 'video');

        $monthlyFee = $baseFee;
        if ($specialTrace['forward_price'] !== null) {
            $monthlyFee = (float) $specialTrace['forward_price'];
        } elseif ($specialTrace['discount_percent'] !== null) {
            $monthlyFee = round($baseFee * (float) $specialTrace['discount_percent'] / 100, 2);
        }

        $remainingDays = max(0, now()->diffInDays($sub->expires_at, false));
        $remainingMonths = round($remainingDays / 30, 2);
        $totalCharge = round($monthlyFee * $remainingMonths, 2);

        return [
            'monthly_fee' => $monthlyFee,
            'remaining_days' => $remainingDays,
            'remaining_months' => $remainingMonths,
            'total_charge' => $totalCharge,
        ];
    }

    /**
     * PATCH /customer/subscriptions/{id}/remark
     */
    public function updateRemark(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $sub = Subscription::where('customer_id', $customer->id)->findOrFail($id);

        $data = $request->validate([
            'customer_remark' => 'nullable|string|max:500',
        ]);

        $sub->update(['customer_remark' => $data['customer_remark'] ?? null]);

        return $this->success(['customer_remark' => $sub->customer_remark], '备注已更新');
    }
}
