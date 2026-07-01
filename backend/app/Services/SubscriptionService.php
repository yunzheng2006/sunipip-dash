<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\ForwardRule;
use App\Models\IpipvInstance;
use App\Models\IpipvOrder;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\SparkOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 订阅续费的统一入口
 *
 * 目的：让管理后台批量续费 / 客户自助续费 / auto-renew cron 走同一份逻辑。
 */
class SubscriptionService
{
    /**
     * 统一续费月单价计算 — 所有续费路径（管理后台/客户自助/自动续费）必须走这里。
     *
     * 逻辑与 CheckoutService.purchaseByProducts 保持一致：
     *   1. IP 底价 = subscription.list_price（若无则尝试从 PricingMultiplier 查）
     *   2. 中转底价 = ForwardPlan.base_price（非 ForwardRule.forward_fee，后者是创建时的折后价）
     *   3. 优先用 CustomerSpecialPrice（固定价/折扣/中转特批价）
     *   4. 无特批时 fallback 到 VIP 等级折扣（仅作用于 IP，不含中转）
     */
    public function calcRenewalMonthlyPrice($customer, Subscription $sub): float
    {
        return $this->calcRenewalPriceBreakdown($customer, $sub)['monthly_price'];
    }

    /**
     * 返回续费价格明细（供管理后台展示分解）
     */
    public function calcRenewalPriceBreakdown($customer, Subscription $sub): array
    {
        $proxyIp = $sub->proxyIp;

        $durationMonths = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
        $monthlyFromPrice = round((float) $sub->price / max($durationMonths, 1), 2);

        // IPIPV 订阅：无折扣体系，原价续费
        if ($proxyIp && $proxyIp->ipipv_instance_id) {
            return [
                'monthly_price' => $monthlyFromPrice,
                'ip_price' => $monthlyFromPrice,
                'forward_price' => 0,
                'ip_list_price' => $monthlyFromPrice,
                'forward_base_price' => 0,
                'discount_source' => 'none',
                'discount_percent' => null,
            ];
        }

        // ── 0. 手动导入/管理员创建的订阅：无 list_price 且非 Spark，price 即全包价 ──
        $isManualAllInPrice = !$sub->list_price
            && $sub->admin_set_price === null
            && $proxyIp
            && !$proxyIp->spark_instance_id
            && !SparkInstance::where('proxy_ip_id', $proxyIp->id)->exists();

        if ($isManualAllInPrice) {
            return [
                'monthly_price'     => $monthlyFromPrice,
                'ip_price'          => $monthlyFromPrice,
                'forward_price'     => 0,
                'ip_list_price'     => $monthlyFromPrice,
                'forward_base_price' => 0,
                'discount_source'   => 'admin_set',
                'discount_percent'  => null,
            ];
        }

        // ── 1. IP 底价（未折扣月单价） ──
        $ipListPrice = $this->resolveIpListPrice($sub);

        // ── 2. 中转信息 ──
        // has_forward=false 表示已降级为单IP，不再查中转规则
        $forwardRule = null;
        if ($sub->has_forward) {
            $forwardRule = $sub->relationLoaded('forwardRule') && $sub->forwardRule
                ? $sub->forwardRule
                : ForwardRule::with('forwardPlan:id,module,base_price,pricing_mode')
                    ->where('subscription_id', $sub->id)
                    ->orderByRaw("status = 'active' DESC")
                    ->first();
        }

        $forwardModule = $forwardRule
            ? ($forwardRule->forwardPlan?->module ?? 'video')
            : 'static';

        $isFixedPricing = $forwardRule?->forwardPlan?->isFixedPricing() ?? false;

        // 中转底价 = 套餐原始定价（非 forward_fee，后者是创建时已折后的值）
        $forwardBasePrice = 0.0;
        if ($forwardRule) {
            $forwardBasePrice = $forwardRule->forwardPlan
                ? (float) $forwardRule->forwardPlan->base_price
                : (float) ($forwardRule->forward_fee ?: 0);
        }

        // ── 3. 特批价查询 ──
        $product = $proxyIp ? [
            'country_code' => $proxyIp->country_code ?? null,
            'city_code'    => $proxyIp->city ?? null,
            'area_code'    => null,
            'product_id'   => $this->resolveSparkProductId($proxyIp),
        ] : [];
        $specialTrace = CustomerSpecialPrice::findPriceTrace(
            $customer->id, $product, $forwardModule
        );

        // ── 4. 计算 IP 售价 ──
        $ipPrice = $ipListPrice;
        $discountSource = 'none';
        $discountPercent = null;
        $hasSpecialPricing = false;

        if ($specialTrace['price'] !== null) {
            // 固定特批 IP 价
            $ipPrice = (float) $specialTrace['price'];
            $discountSource = 'special_fixed';
            $hasSpecialPricing = true;
        } elseif ($specialTrace['discount_percent'] !== null) {
            // 百分比折扣
            $discountPercent = (float) $specialTrace['discount_percent'];
            $ipPrice = round($ipListPrice * $discountPercent / 100, 2);
            $discountSource = 'special_discount';
            $hasSpecialPricing = true;
        }

        // ── 5. 计算中转售价 ──
        $forwardPrice = $forwardBasePrice;
        if ($forwardRule) {
            if ($specialTrace['forward_price'] !== null) {
                // 固定特批中转价
                $forwardPrice = (float) $specialTrace['forward_price'];
            } elseif ($specialTrace['discount_percent'] !== null) {
                // 折扣同样作用于中转（与 checkout 一致）
                $forwardPrice = round($forwardBasePrice * (float) $specialTrace['discount_percent'] / 100, 2);
            }
            // 无特批 → 中转保持底价（VIP 折扣不作用于中转，与 checkout 一致）
        }

        // ── 6. VIP 折扣 fallback（仅当该产品无任何特批时） ──
        if (!$hasSpecialPricing) {
            // 确保 customer 有完整属性（index 查询可能只 select 部分字段）
            if (!isset($customer->vip_tier_id)) {
                $customer = Customer::find($customer->id);
            }
            $vipDiscount = \App\Services\VipService::getDiscount($customer);
            if ($vipDiscount < 100) {
                $ipPrice = round($ipListPrice * $vipDiscount / 100, 2);
                $discountSource = 'vip';
                $discountPercent = $vipDiscount;
                // VIP 折扣不作用于中转（与 checkout 一致）
            }
        }

        if ($isFixedPricing) {
            $ipPrice = 0;
        }

        $standardPrice = round($ipPrice + $forwardPrice, 2);

        return [
            'monthly_price'     => $standardPrice,
            'ip_price'          => $ipPrice,
            'forward_price'     => $forwardPrice,
            'ip_list_price'     => $ipListPrice,
            'forward_base_price' => $forwardBasePrice,
            'discount_source'   => $discountSource,
            'discount_percent'  => $discountPercent,
        ];
    }

    /**
     * 解析 IP 底价（未折扣月单价）
     */
    private function resolveIpListPrice(Subscription $sub): float
    {
        // 优先从 PricingMultiplier 查当前官网价（续费按最新定价）
        $proxyIp = $sub->proxyIp;
        if ($proxyIp) {
            try {
                $productId = null;
                $sparkInstance = SparkInstance::where('proxy_ip_id', $proxyIp->id)->first();
                if ($sparkInstance) {
                    $productId = $sparkInstance->sparkOrder?->product_id;
                }
                if ($productId) {
                    $products = \App\Services\SparkStockCacheService::products();
                    $sparkProduct = collect($products)->firstWhere('product_id', $productId);
                    if ($sparkProduct) {
                        $listPrice = \App\Models\PricingMultiplier::calcSalePrice($sparkProduct);
                        if ($listPrice !== null && $listPrice > 0) {
                            return (float) $listPrice;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('resolveIpListPrice: PricingMultiplier lookup failed', [
                    'subscription_id' => $sub->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: 开通时记录的官网原价
        if ($sub->list_price && (float) $sub->list_price > 0) {
            return (float) $sub->list_price;
        }

        // 终极 fallback：用存量 price 换算月单价（可能已含折扣+中转，不精确但不会更差）
        $months = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
        return round((float) $sub->price / max($months, 1), 2);
    }

    private function resolveSparkProductId(ProxyIp $proxyIp): ?string
    {
        if (!$proxyIp->spark_instance_id) {
            return null;
        }
        try {
            $si = SparkInstance::where('instance_id', $proxyIp->spark_instance_id)->first();
            if (!$si || !$si->spark_order_id) {
                return null;
            }
            $order = SparkOrder::find($si->spark_order_id);
            $rd = $order?->request_data;
            if (is_string($rd)) {
                $rd = json_decode($rd, true);
            }
            return $rd['product_id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 单条续费（事务内 + 余额扣款 + 写流水）
     *
     * @throws \Exception 余额不足时抛
     */
    public function renewOne(Subscription $subscription, array $opts, ?int $userId = null): Subscription
    {
        $duration = (int) $opts['duration'];
        $unit = (int) $opts['unit'];
        $newPrice = isset($opts['price']) && $opts['price'] !== null
            ? (float) $opts['price']
            : (float) $subscription->price;
        $isAutoRenew = !empty($opts['auto_renew_triggered']);
        $skipDeduct = !empty($opts['skip_deduct']);
        $reactivate = !empty($opts['reactivate']);

        return DB::transaction(function () use ($subscription, $duration, $unit, $newPrice, $userId, $isAutoRenew, $skipDeduct, $reactivate) {
            $customer = Customer::lockForUpdate()->findOrFail($subscription->customer_id);

            $balanceBefore = $customer->balance;

            if ($skipDeduct) {
                $balanceAfter = $balanceBefore;
            } else {
                if ((float) $customer->balance < $newPrice) {
                    throw new \Exception("余额不足（当前 ¥{$customer->balance}，需要 ¥{$newPrice}）");
                }
                $customer->decrement('balance', $newPrice);
                $balanceAfter = bcsub($balanceBefore, $newPrice, 2);
            }

            $baseDate = $subscription->expires_at->isFuture()
                ? $subscription->expires_at
                : now();

            $newExpiresAt = \App\Support\DurationHelper::addToDate($baseDate, $duration, $unit);

            $wasTest = (bool) $subscription->is_test;

            $updateData = [
                'price' => $newPrice,
                'duration' => $duration,
                'unit' => $unit,
                'expires_at' => $newExpiresAt,
                'renewed_count' => $subscription->renewed_count + 1,
                'last_renewed_at' => now(),
            ];

            if ($subscription->initial_duration === null) {
                $updateData['initial_duration'] = $subscription->duration;
                $updateData['initial_unit'] = $subscription->unit;
            }

            if ($wasTest) {
                $updateData['is_test'] = false;
                $updateData['test_reclaim_at'] = null;
            }

            $proxyIp = $subscription->proxy_ip_id ? ProxyIp::withTrashed()->find($subscription->proxy_ip_id) : null;

            if ($reactivate) {
                $updateData['status'] = 'active';

                if ($proxyIp && $proxyIp->trashed()) {
                    $proxyIp->restore();
                }

                if ($proxyIp) {
                    $proxyIp->update([
                        'status' => 'assigned',
                        'assigned_customer_id' => $subscription->customer_id,
                    ]);
                }
            }

            // 上游续费（Spark / IPIPV）— 不论是普通续费还是重激活都需要调
            if ($proxyIp) {
                if (!$proxyIp->spark_instance_id && !$proxyIp->ipipv_instance_id) {
                    $this->tryRecoverSparkInstanceId($proxyIp);
                }

                try {
                    if ($proxyIp->spark_instance_id) {
                        $this->renewViaSpark($proxyIp);
                    } elseif ($proxyIp->ipipv_instance_id) {
                        $this->renewViaIpipv($proxyIp);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Upstream renewal failed', [
                        'proxy_ip_id' => $proxyIp->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $subscription->update($updateData);

            if ($subscription->proxy_ip_id) {
                ProxyIp::withTrashed()->where('id', $subscription->proxy_ip_id)
                    ->update(['upstream_expires_at' => $newExpiresAt]);
            }

            if (!$skipDeduct && $newPrice > 0) {
                try {
                    $refreshedCustomer = $customer->refresh();
                    $commissionType = $wasTest ? 'purchase' : 'renew';
                    $durationMonthsForComm = max(\App\Support\DurationHelper::toMonths($duration, $unit), 1);
                    $fwdRule = ForwardRule::where('subscription_id', $subscription->id)
                        ->orderByRaw("status = 'active' DESC")
                        ->first();
                    $isFixedPricing = $fwdRule?->forwardPlan?->isFixedPricing() ?? false;
                    $ipListPricePerMonth = $isFixedPricing ? 0 : $this->resolveIpListPrice($subscription);
                    $fwdListPricePerMonth = $fwdRule?->forwardPlan ? (float) $fwdRule->forwardPlan->base_price : 0;
                    $totalListPrice = round(($ipListPricePerMonth + $fwdListPricePerMonth) * $durationMonthsForComm, 2);
                    $productCtx = $proxyIp ? [
                        'country_code' => $proxyIp->country_code ?? null,
                        'city_code'    => $proxyIp->city ?? null,
                        'product_id'   => $this->resolveSparkProductId($proxyIp),
                        'module'       => $fwdRule?->forwardPlan?->module ?? 'static',
                    ] : [];
                    app(\App\Services\ReferralService::class)->processCommission(
                        $refreshedCustomer, $commissionType, $newPrice, $subscription->id, $totalListPrice ?: $newPrice, $productCtx
                    );
                } catch (\Throwable $e) {
                    Log::warning('Commission on renew failed: ' . $e->getMessage());
                }
            }

            $desc = $reactivate ? '重新激活' : ($isAutoRenew ? '自动续费' : '续费');
            $desc .= "订阅#{$subscription->id}";
            if ($skipDeduct) {
                $desc .= '（线下已付，不扣余额）';
            }

            Transaction::create([
                'customer_id' => $customer->id,
                'type' => Transaction::TYPE_RENEW,
                'amount' => $skipDeduct ? 0 : -$newPrice,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'related_type' => Subscription::class,
                'related_id' => $subscription->id,
                'description' => $desc,
                'operated_by' => $userId,
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * 在 Spark 上游续费 1 个月（与客户计费解耦，后续由 cron 按月滚动续费）
     * 无论实例是正常还是已释放，都先调 RenewProxy（已释放的实例也能续费恢复，IP/密码不变）。
     */
    private function renewViaSpark(ProxyIp $proxyIp): void
    {
        $sparkInstance = SparkInstance::where('proxy_ip_id', $proxyIp->id)->latest()->first();
        $instanceId = $sparkInstance?->instance_id ?: $proxyIp->spark_instance_id;

        if (!$instanceId) {
            Log::warning("Spark renewal skipped: no instance ID for ProxyIp#{$proxyIp->id}");
            return;
        }

        $sparkApi = app(\App\Services\SparkApiService::class);
        $reqOrderNo = SparkOrder::generateReqOrderNo();

        $sparkOrder = SparkOrder::create([
            'req_order_no' => $reqOrderNo,
            'method' => 'RenewProxy',
            'product_id' => '',
            'amount' => 1,
            'duration' => 1,
            'unit' => 3,
            'status' => 1,
            'request_data' => [
                'instanceId' => $instanceId,
                'duration' => 1,
                'unit' => 3,
                'trigger' => 'reactivation',
            ],
        ]);

        try {
            $response = $sparkApi->renewProxy($reqOrderNo, [[
                'instanceId' => $instanceId,
                'duration' => 1,
                'unit' => 3,
            ]]);

            $sparkOrder->update([
                'spark_order_no' => $response['orderNo'] ?? null,
                'status' => 2,
                'response_data' => $response,
            ]);

            $newExpireAt = now()->addDays(30);
            if ($sparkInstance) {
                $sparkInstance->update(['expire_at' => $newExpireAt, 'status' => 2]);
            }
        } catch (\Throwable $e) {
            $sparkOrder->update([
                'status' => 3,
                'response_data' => ['error' => $e->getMessage()],
            ]);
            throw new \Exception("Spark API续费失败: {$e->getMessage()}");
        }
    }

    private function tryRecoverSparkInstanceId(ProxyIp $proxyIp): void
    {
        $sparkOrder = SparkOrder::where('method', 'CreateProxy')
            ->where('status', 2)
            ->where('response_data', 'like', "%{$proxyIp->ip_address}%")
            ->latest('id')
            ->first();

        if (!$sparkOrder) {
            return;
        }

        $ipInfo = $sparkOrder->response_data['ipInfo'] ?? [];
        foreach ($ipInfo as $info) {
            if (($info['ip'] ?? '') === $proxyIp->ip_address && !empty($info['instanceId'])) {
                $proxyIp->update(['spark_instance_id' => $info['instanceId']]);
                Log::info("Recovered spark_instance_id for ProxyIp#{$proxyIp->id}", [
                    'instance_id' => $info['instanceId'],
                    'from_order' => $sparkOrder->req_order_no,
                ]);
                return;
            }
        }
    }

    /**
     * 在 IPIPV 上游续费 1 个周期（与客户计费解耦）
     */
    private function renewViaIpipv(ProxyIp $proxyIp): void
    {
        $ipipvInstance = IpipvInstance::where('proxy_ip_id', $proxyIp->id)->first();
        if (!$ipipvInstance || !$ipipvInstance->instance_no) {
            Log::warning("IPIPV renewal skipped: no IpipvInstance for ProxyIp#{$proxyIp->id}");
            return;
        }

        $ipipvApi = app(\App\Services\IpipvApiService::class);
        $appOrderNo = IpipvOrder::generateAppOrderNo();

        $order = IpipvOrder::create([
            'app_order_no' => $appOrderNo,
            'method' => 'renew',
            'product_no' => $ipipvInstance->product_no ?? '',
            'amount' => 1,
            'duration' => 1,
            'unit' => 3,
            'cycle_times' => 1,
            'status' => 1,
            'request_data' => [
                'instanceNo' => $ipipvInstance->instance_no,
                'cycleTimes' => 1,
                'trigger' => 'reactivation',
            ],
        ]);

        try {
            $response = $ipipvApi->renewProxy($appOrderNo, [[
                'instanceNo' => $ipipvInstance->instance_no,
                'cycleTimes' => 1,
            ]]);

            $order->update([
                'ipipv_order_no' => $response['orderNo'] ?? null,
                'status' => 2,
                'response_data' => $response,
            ]);

            $newExpireAt = now()->addDays(30);
            $ipipvInstance->update(['expire_at' => $newExpireAt]);
        } catch (\Throwable $e) {
            $order->update([
                'status' => 3,
                'response_data' => ['error' => $e->getMessage()],
            ]);
            throw new \Exception("IPIPV API续费失败: {$e->getMessage()}");
        }
    }
}
