<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\ForwardPlan;
use App\Models\IpAssetGroup;
use App\Models\ProductPricing;
use App\Models\SparkPricingRule;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\IpipvProvisionService;
use App\Services\IpipvStockCacheService;
use App\Services\SparkProvisionService;
use App\Services\SparkStockCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 客户自助下单流程。
 *
 * 步骤：
 *   1. 查 SparkPricingRule 拿对客售价；无则拒绝
 *   2. 从缓存产品列表中按 country_code 筛选有库存的 Spark 产品
 *   3. 计算总价 = 单价 × 数量 × 时长倍数
 *   4. 事务内：锁客户余额 → 校验 → 扣款 → 写 Transaction → 调 SparkProvisionService
 *   5. 返回：订阅 ID、扣款金额、新余额
 *
 * 说明：
 *   - 目前仅支持 unit=3(月)，避免时长换算歧义
 *   - Spark API 失败会整个事务回滚（余额不动）
 */
class CheckoutService
{
    public function __construct(protected SparkProvisionService $provision) {}

    /**
     * @param Customer $customer
     * @param string $countryCode  alpha-3
     * @param int $quantity
     * @param int $duration  时长数值
     * @param int $unit      仅支持 3 (月)
     * @param bool $autoRenew
     *
     * @return array{
     *   spark_order_id: int,
     *   subscription_ids: int[],
     *   proxy_ip_ids: int[],
     *   charged: float,
     *   new_balance: float,
     *   status: int,
     *   message: string,
     * }
     *
     * @throws ValidationException
     */
    public function purchase(
        Customer $customer,
        string $countryCode,
        int $quantity,
        int $duration,
        int $unit = 3,
        bool $autoRenew = false,
    ): array {
        $countryCode = strtoupper($countryCode);

        if ($quantity < 1 || $quantity > 10) {
            throw ValidationException::withMessages(['quantity' => '数量需在 1-10 之间']);
        }
        if ($duration < 1 || $duration > 12) {
            throw ValidationException::withMessages(['duration' => '时长需在 1-12 之间']);
        }
        if ($unit !== 3) {
            throw ValidationException::withMessages(['unit' => '当前仅支持按月购买']);
        }

        // 1. 查定价规则
        $rule = SparkPricingRule::findByCountry($countryCode);
        if (!$rule) {
            throw ValidationException::withMessages(['country_code' => '该国家暂未配置定价，请联系客服']);
        }
        $unitPrice = (float) $rule->monthly_price;

        // 2. 库存校验
        $byCountry = SparkStockCacheService::stockByCountry();
        $stockInfo = $byCountry[$countryCode] ?? null;
        if (!$stockInfo || $stockInfo['stock'] < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => sprintf('库存不足，当前可用 %d 条', $stockInfo['stock'] ?? 0),
            ]);
        }

        // 3. 选一个可用 Spark 产品
        $products = SparkStockCacheService::products();
        $candidates = array_values(array_filter(
            $products,
            fn($p) => strtoupper($p['country_code'] ?? '') === $countryCode
                  && ($p['inventory'] ?? 0) >= $quantity
        ));
        if (empty($candidates)) {
            throw ValidationException::withMessages([
                'country_code' => '该国家暂无足够的产品库存（单产品需 ≥ 数量）',
            ]);
        }
        // 选库存最多的那个，失败率最低
        usort($candidates, fn($a, $b) => ($b['inventory'] ?? 0) <=> ($a['inventory'] ?? 0));
        $product = $candidates[0];

        // 4. Spark 资产组（去取一个 spark_api 类型的组作为容器）
        $assetGroup = IpAssetGroup::where('source_type', 'spark_api')
            ->where('status', 1)
            ->first();
        if (!$assetGroup) {
            throw ValidationException::withMessages([
                'country_code' => '平台暂未配置 Spark 资产组，请联系客服',
            ]);
        }

        // 5. 总价计算：月单价 × 数量 × 月数
        $total = round($unitPrice * $quantity * $duration, 2);

        // 6. 事务：锁余额 → 扣款 → 调 Spark
        try {
            $result = DB::transaction(function () use (
                $customer, $total, $quantity, $duration, $unit, $autoRenew,
                $countryCode, $product, $rule, $assetGroup, $unitPrice
            ) {
                $fresh = Customer::lockForUpdate()->findOrFail($customer->id);
                if ((float) $fresh->balance < $total) {
                    throw ValidationException::withMessages([
                        'balance' => sprintf('余额不足：当前 ¥%.2f，需要 ¥%.2f', $fresh->balance, $total),
                    ]);
                }

                $balanceBefore = $fresh->balance;
                $fresh->decrement('balance', $total);
                $balanceAfter = bcsub($balanceBefore, $total, 2);

                // country_cn 用于 asset_name 拼装
                $countryCn = \App\Models\SparkCountry::getNameByCode($countryCode) ?: $countryCode;

                $txn = Transaction::create([
                    'customer_id' => $fresh->id,
                    'type' => Transaction::TYPE_PURCHASE,
                    'amount' => -$total,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => "自助购买:{$countryCn} x {$quantity}条 x {$duration}月",
                    'operated_by' => null,
                ]);

                // 调 Spark 下单（同步或挂起）
                $orderParams = [
                    'product_id' => $product['product_id'],
                    'product_name' => $product['product_name'] ?? '',
                    'country_code' => $countryCode,
                    'country_cn' => $countryCn,
                    'sale_price' => round($unitPrice * $duration, 2),
                    'quantity' => $quantity,
                    'duration' => $duration,
                    'unit' => $unit,
                    'asset_group_id' => $assetGroup->id,
                    'customer_id' => $fresh->id,
                    'auto_renew' => $autoRenew,
                    'source_remark' => '客户自助下单',
                    'created_by' => 1,
                ];
                $blockedByProduct = \App\Models\SparkProductBlock::blockedCidrsByProduct();
                if (!empty($blockedByProduct[$product['product_id']]) && !empty($product['cidr_blocks'])) {
                    $orderParams['cidr_blocks'] = self::pickCidrForOrder($product['cidr_blocks'], $quantity);
                }
                $provisionResult = $this->provision->createOrder($orderParams);

                if (!empty($provisionResult['subscription_ids'])) {
                    Subscription::whereIn('id', $provisionResult['subscription_ids'])
                        ->update(['balance_deducted' => true]);
                    $txn->update([
                        'related_type' => Subscription::class,
                        'related_id' => $provisionResult['subscription_ids'][0],
                    ]);
                }

                return [
                    'spark_order' => $provisionResult['spark_order'],
                    'subscription_ids' => $provisionResult['subscription_ids'],
                    'proxy_ip_ids' => $provisionResult['proxy_ip_ids'],
                    'charged' => $total,
                    'new_balance' => (float) $balanceAfter,
                    'status' => $provisionResult['status'],
                    'message' => $provisionResult['message'],
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 其他异常：事务已回滚，余额未变
            throw ValidationException::withMessages([
                'spark' => '开通失败：' . $e->getMessage(),
            ]);
        }

        return [
            'spark_order_id' => $result['spark_order']->id,
            'subscription_ids' => $result['subscription_ids'],
            'proxy_ip_ids' => $result['proxy_ip_ids'],
            'charged' => $result['charged'],
            'new_balance' => $result['new_balance'],
            'status' => $result['status'],
            'message' => $result['message'],
        ];
    }

    /**
     * 多产品下单（新版客户商店）
     *
     * @param Customer $customer
     * @param array $items [{pricing_id, quantity}, ...]
     * @param int $duration 统一时长（月）
     * @param bool $autoRenew
     * @return array
     */
    public function purchaseMulti(
        Customer $customer,
        array $items,
        int $duration,
        bool $autoRenew = false,
    ): array {
        if ($duration < 1 || $duration > 12) {
            throw ValidationException::withMessages(['duration' => '时长需在 1-12 之间']);
        }

        // 1. Resolve all pricing rules + calculate total
        $resolvedItems = [];
        $total = 0;

        foreach ($items as $item) {
            $pricing = ProductPricing::where('id', $item['pricing_id'])
                ->where('is_active', 1)
                ->first();
            if (!$pricing) {
                throw ValidationException::withMessages(['items' => "定价 #{$item['pricing_id']} 不存在或已下架"]);
            }

            $qty = (int) $item['quantity'];
            if ($qty < 1 || $qty > 10) {
                throw ValidationException::withMessages(['items' => '每项数量需在 1-10 之间']);
            }

            $lineTotal = round((float) $pricing->monthly_price * $qty * $duration, 2);
            $total += $lineTotal;

            $resolvedItems[] = [
                'pricing' => $pricing,
                'quantity' => $qty,
                'line_total' => $lineTotal,
                'cidr' => $item['cidr'] ?? null,
            ];
        }

        // 2. Transaction: lock balance → deduct → provision each item
        try {
            $results = DB::transaction(function () use (
                $customer, $total, $resolvedItems, $duration, $autoRenew
            ) {
                $fresh = Customer::lockForUpdate()->findOrFail($customer->id);
                if ((float) $fresh->balance < $total) {
                    throw ValidationException::withMessages([
                        'balance' => sprintf('余额不足：当前 ¥%.2f，需要 ¥%.2f', $fresh->balance, $total),
                    ]);
                }

                $balanceBefore = $fresh->balance;
                $fresh->decrement('balance', $total);
                $balanceAfter = bcsub($balanceBefore, $total, 2);

                $desc = collect($resolvedItems)->map(function ($ri) {
                    $p = $ri['pricing'];
                    return ($p->country_name ?: $p->country_code) . ' x ' . $ri['quantity'] . '条';
                })->join(', ');

                $txn = Transaction::create([
                    'customer_id' => $fresh->id,
                    'type' => Transaction::TYPE_PURCHASE,
                    'amount' => -$total,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => "自助购买:{$desc} x {$duration}月",
                    'operated_by' => null,
                ]);

                // Provision each item
                $allSubIds = [];
                $allIpIds = [];
                $allOrders = [];
                $blockedByProduct = null;

                foreach ($resolvedItems as $ri) {
                    $pricing = $ri['pricing'];
                    $countryCode = strtoupper($pricing->country_code);
                    $countryCn = \App\Models\SparkCountry::getNameByCode($countryCode) ?: $countryCode;

                    // Find best Spark product for this country + ip_group
                    $products = SparkStockCacheService::products();
                    $ipGroup = $pricing->ip_group_id ? \App\Models\IpGroup::find($pricing->ip_group_id) : null;

                    $candidates = collect($products)->filter(function ($p) use ($countryCode, $ipGroup, $pricing) {
                        if (strtoupper($p['country_code'] ?? '') !== $countryCode) return false;
                        if (($p['cost_price'] ?? 999) >= (float) $pricing->monthly_price) return false;
                        if (!$ipGroup) return true;
                        if ($ipGroup->spark_isp_type && ($p['isp_type'] ?? null) != $ipGroup->spark_isp_type) return false;
                        if ($ipGroup->spark_net_type !== null && ($p['net_type'] ?? null) != $ipGroup->spark_net_type) return false;
                        return true;
                    })->sortByDesc('inventory')->values();

                    if ($candidates->isEmpty()) {
                        throw ValidationException::withMessages([
                            'items' => "{$countryCn} 库存不足或无匹配产品",
                        ]);
                    }

                    $product = $candidates->first();
                    $assetGroup = IpAssetGroup::where('source_type', 'spark_api')->where('status', 1)->first();
                    if (!$assetGroup) {
                        throw ValidationException::withMessages(['items' => '平台暂未配置 Spark 资产组']);
                    }

                    $unitPrice = (float) $pricing->monthly_price;

                    $orderParams = [
                        'product_id' => $product['product_id'],
                        'product_name' => $product['product_name'] ?? '',
                        'country_code' => $countryCode,
                        'country_cn' => $countryCn,
                        'sale_price' => round($unitPrice * $duration, 2),
                        'quantity' => $ri['quantity'],
                        'duration' => $duration,
                        'unit' => 3,
                        'asset_group_id' => $assetGroup->id,
                        'ip_group_id' => $ipGroup?->id,
                        'customer_id' => $fresh->id,
                        'auto_renew' => $autoRenew,
                        'source_remark' => '客户自助下单',
                        'created_by' => 1,
                    ];
                    $blockedByProduct = $blockedByProduct ?? \App\Models\SparkProductBlock::blockedCidrsByProduct();
                    if (!empty($ri['cidr'])) {
                        $orderParams['cidr_blocks'] = [['cidr' => $ri['cidr'], 'count' => $ri['quantity']]];
                    } elseif (!empty($blockedByProduct[$product['product_id']]) && !empty($product['cidr_blocks'])) {
                        $orderParams['cidr_blocks'] = self::pickCidrForOrder($product['cidr_blocks'], $ri['quantity']);
                    }
                    $provisionResult = $this->provision->createOrder($orderParams);

                    $allSubIds = array_merge($allSubIds, $provisionResult['subscription_ids']);
                    $allIpIds = array_merge($allIpIds, $provisionResult['proxy_ip_ids']);
                    $allOrders[] = $provisionResult['spark_order']->id;
                }

                if (!empty($allSubIds)) {
                    $txn->update([
                        'related_type' => Subscription::class,
                        'related_id' => $allSubIds[0],
                    ]);
                }

                return [
                    'subscription_ids' => $allSubIds,
                    'proxy_ip_ids' => $allIpIds,
                    'spark_order_ids' => $allOrders,
                    'charged' => $total,
                    'new_balance' => (float) $balanceAfter,
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'spark' => '开通失败：' . $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * v3 下单：按 Spark product_id 直接购买，售价由倍率计算
     */
    public function purchaseByProducts(
        Customer $customer,
        array $items,
        int $duration,
        bool $autoRenew = false,
        ?int $forwardPlanId = null,
    ): array {
        if ($duration < 1 || $duration > 12) {
            throw ValidationException::withMessages(['duration' => '时长需在 1-12 之间']);
        }

        // 1. 合并 Spark + IPIPV 产品缓存，计算售价
        $sparkProducts = SparkStockCacheService::products();
        foreach ($sparkProducts as &$sp) { $sp['source'] = $sp['source'] ?? 'spark'; }
        unset($sp);

        $ipipvProducts = IpipvStockCacheService::products();
        $ipipvCostOverride = \App\Models\SystemConfig::get('cost.ipipv_hard_cost_override');
        if ($ipipvCostOverride !== null && (float) $ipipvCostOverride > 0) {
            foreach ($ipipvProducts as &$ip) {
                $ip['cost_price'] = (float) $ipipvCostOverride;
            }
            unset($ip);
        }
        $allProducts = array_merge($sparkProducts, $ipipvProducts);
        $productMap = collect($allProducts)->keyBy('product_id');
        $resolvedItems = [];
        $total = 0;
        $listTotal = 0;

        // 直连套餐费用
        $forwardPlan = $forwardPlanId ? ForwardPlan::find($forwardPlanId) : null;
        $forwardFeePerIp = $forwardPlan ? (float) $forwardPlan->base_price : 0;
        $isFixedPricing = $forwardPlan?->isFixedPricing() ?? false;

        foreach ($items as $item) {
            $product = $productMap[$item['product_id']] ?? null;
            if (!$product) {
                throw ValidationException::withMessages(['items' => "产品 {$item['product_id']} 不存在或已下架"]);
            }

            $salePrice = \App\Models\PricingMultiplier::calcSalePrice($product);
            if ($salePrice === null) {
                $name = $product['product_name'] ?? $item['product_id'];
                throw ValidationException::withMessages(['items' => "产品「{$name}」未配置定价"]);
            }

            $originalPrice = $salePrice;

            // Apply customer special price if exists
            $forwardModule = $forwardPlan?->module ?? 'static';
            $specialTrace = \App\Models\CustomerSpecialPrice::findPriceTrace($customer->id, $product, $forwardModule);
            $hasSpecialPricing = false;
            if ($specialTrace['price'] !== null) {
                $salePrice = (float) $specialTrace['price'];
                $hasSpecialPricing = true;
            } elseif ($specialTrace['discount_percent'] !== null) {
                $salePrice = round($salePrice * (float) $specialTrace['discount_percent'] / 100, 2);
                $hasSpecialPricing = true;
            }

            // Apply VIP discount only when no special pricing is active
            if (!$hasSpecialPricing) {
                $salePrice = \App\Services\VipService::applyDiscount($customer, $salePrice);
            }

            $qty = (int) $item['quantity'];
            if ($qty < 1 || $qty > 10) {
                throw ValidationException::withMessages(['items' => '每项数量需在 1-10 之间']);
            }
            if (($product['inventory'] ?? 0) < $qty) {
                throw ValidationException::withMessages(['items' => "产品「{$product['product_name']}」库存不足"]);
            }

            // 每行单独算中转费：若该客户对此产品有特批中转价，用它覆盖默认
            $hasSpecialForward = $forwardPlan && $specialTrace['forward_price'] !== null;
            $lineForwardFee = $hasSpecialForward
                ? (float) $specialTrace['forward_price']
                : $forwardFeePerIp;

            // 无特批中转价但有折扣时，将折扣应用到中转费
            if ($forwardPlan && !$hasSpecialForward && $specialTrace['discount_percent'] !== null) {
                $lineForwardFee = round($lineForwardFee * (float) $specialTrace['discount_percent'] / 100, 2);
            }

            if ($isFixedPricing) {
                $ipLineTotal = 0;
                $fwdLineTotal = round($lineForwardFee * $qty * $duration, 2);
                $lineListTotal = round($forwardFeePerIp * $qty * $duration, 2);
            } else {
                // 叠加模式：IP 费 + 中转费
                $ipLineTotal = round($salePrice * $qty * $duration, 2);
                $fwdLineTotal = round($lineForwardFee * $qty * $duration, 2);
                $lineListTotal = round(($originalPrice + $forwardFeePerIp) * $qty * $duration, 2);
            }
            $lineTotal = $ipLineTotal + $fwdLineTotal;
            $total += $lineTotal;
            $listTotal += $lineListTotal;

            $resolvedItems[] = [
                'product' => $product,
                'sale_price' => $salePrice,
                'forward_fee' => $lineForwardFee,
                'quantity' => $qty,
                'line_total' => $lineTotal,
                'cidr' => $item['cidr'] ?? null,
            ];
        }

        // 2. 事务：扣款 + 开通
        try {
            $results = DB::transaction(function () use (
                $customer, $total, $resolvedItems, $duration, $autoRenew, $forwardPlan, $listTotal, $isFixedPricing
            ) {
                $fresh = Customer::lockForUpdate()->findOrFail($customer->id);
                if ((float) $fresh->balance < $total) {
                    throw ValidationException::withMessages([
                        'balance' => sprintf('余额不足：当前 ¥%.2f，需要 ¥%.2f', $fresh->balance, $total),
                    ]);
                }

                $balanceBefore = $fresh->balance;
                $fresh->decrement('balance', $total);
                $balanceAfter = bcsub($balanceBefore, $total, 2);

                $moduleLabel = '';
                if ($forwardPlan) {
                    $moduleLabel = match($forwardPlan->module) {
                        'video' => ' IPLC视频专线',
                        'live_mobile' => ' IPLC直播专线(手机)',
                        'live_pc' => ' IPLC直播专线(电脑)',
                        default => '',
                    };
                }

                $desc = collect($resolvedItems)->map(function ($ri) use ($moduleLabel) {
                    $p = $ri['product'];
                    $cc = strtoupper($p['country_code'] ?? '');
                    $location = \App\Models\SparkCountry::getNameByCode($cc)
                        ?: ($p['country_name'] ?? $cc);
                    if (!empty($p['city_name'])) $location .= $p['city_name'];
                    return $location . $moduleLabel . ' x ' . $ri['quantity'] . '条';
                })->join(', ');

                $txn = Transaction::create([
                    'customer_id' => $fresh->id,
                    'type' => Transaction::TYPE_PURCHASE,
                    'amount' => -$total,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => "自助购买:{$desc} x {$duration}月",
                    'operated_by' => null,
                ]);

                $allSubIds = [];
                $allIpIds = [];
                $allOrders = [];
                $blockedByProduct = null;

                foreach ($resolvedItems as $ri) {
                    $product = $ri['product'];
                    $source = $product['source'] ?? 'spark';
                    $countryCode = strtoupper($product['country_code'] ?? '');
                    $countryCn = \App\Models\SparkCountry::getNameByCode($countryCode)
                        ?: ($product['country_name'] ?? \App\Services\CountryMapper::toCn($countryCode) ?? $countryCode);

                    $fwdFeePerIp = $ri['forward_fee'] ?? 0;
                    $subscriptionPrice = $isFixedPricing
                        ? round($fwdFeePerIp * $duration, 2)
                        : round(($ri['sale_price'] + $fwdFeePerIp) * $duration, 2);

                    if ($source === 'ipipv') {
                        $ipipvAssetGroup = IpAssetGroup::where('source_type', 'ipipv_api')->where('status', 1)->first()
                            ?: IpAssetGroup::where('source_name', 'IPIPV')->where('status', 1)->first();
                        if (!$ipipvAssetGroup) {
                            $ipipvAssetGroup = IpAssetGroup::create([
                                'name' => 'IPIPV-API',
                                'source_type' => 'ipipv_api',
                                'source_name' => 'IPIPV',
                                'status' => 1,
                                'created_by' => 1,
                            ]);
                        }
                        $ipipvDuration = $duration;
                        $ipipvUnit = 3;
                        $ipipvCycleTimes = $duration;
                        if (($product['unit'] ?? 3) === 1) {
                            $ipipvUnit = 1;
                            $ipipvDuration = $product['duration'] ?? 30;
                            $ipipvCycleTimes = $duration;
                        }

                        $ipipvService = app(IpipvProvisionService::class);
                        $ipipvOrderParams = [
                            'product_no' => $product['product_no'] ?? $product['product_id'],
                            'product_name' => $product['product_name'] ?? '',
                            'country_code' => $countryCode,
                            'country_cn' => $countryCn,
                            'sale_price' => $subscriptionPrice,
                            'quantity' => $ri['quantity'],
                            'duration' => $ipipvDuration,
                            'unit' => $ipipvUnit,
                            'cycle_times' => $ipipvCycleTimes,
                            'asset_group_id' => $ipipvAssetGroup->id,
                            'customer_id' => $fresh->id,
                            'auto_renew' => $autoRenew,
                            'source_remark' => '客户自助下单(IPIPV)',
                            'created_by' => 1,
                        ];
                        if (!empty($ri['cidr'])) {
                            $ipipvOrderParams['cidr_blocks'] = [['cidr' => $ri['cidr'], 'count' => $ri['quantity']]];
                        }
                        $provisionResult = $ipipvService->createOrder($ipipvOrderParams);

                        $allSubIds = array_merge($allSubIds, $provisionResult['subscription_ids']);
                        $allIpIds = array_merge($allIpIds, $provisionResult['proxy_ip_ids']);
                        $allOrders[] = $provisionResult['ipipv_order']->id ?? null;
                    } else {
                        $assetGroup = IpAssetGroup::where('source_type', 'spark_api')->where('status', 1)->first();
                        if (!$assetGroup) {
                            throw ValidationException::withMessages(['items' => '平台暂未配置 Spark 资产组']);
                        }

                        $orderParams = [
                            'product_id' => $product['product_id'],
                            'product_name' => $product['product_name'] ?? '',
                            'country_code' => $countryCode,
                            'country_cn' => $countryCn,
                            'sale_price' => $subscriptionPrice,
                            'quantity' => $ri['quantity'],
                            'duration' => $duration,
                            'unit' => 3,
                            'asset_group_id' => $assetGroup->id,
                            'customer_id' => $fresh->id,
                            'auto_renew' => $autoRenew,
                            'source_remark' => '客户自助下单(v3)',
                            'created_by' => 1,
                            'forward_plan_id' => $forwardPlan?->id,
                            'purchased_module' => $forwardPlan?->module ?? 'static',
                        ];

                        $blockedByProduct = $blockedByProduct ?? \App\Models\SparkProductBlock::blockedCidrsByProduct();
                        if (!empty($ri['cidr'])) {
                            $orderParams['cidr_blocks'] = [['cidr' => $ri['cidr'], 'count' => $ri['quantity']]];
                        } elseif (!empty($blockedByProduct[$product['product_id']]) && !empty($product['cidr_blocks'])) {
                            $orderParams['cidr_blocks'] = self::pickCidrForOrder($product['cidr_blocks'], $ri['quantity']);
                        }

                        $provisionResult = $this->provision->createOrder($orderParams);

                        $allSubIds = array_merge($allSubIds, $provisionResult['subscription_ids']);
                        $allIpIds = array_merge($allIpIds, $provisionResult['proxy_ip_ids']);
                        $allOrders[] = $provisionResult['spark_order']->id;
                    }
                }

                // Set purchased_module + balance_deducted on all new subscriptions
                if (!empty($allSubIds)) {
                    $module = $forwardPlan?->module ?? 'static';
                    Subscription::whereIn('id', $allSubIds)->update(['purchased_module' => $module, 'balance_deducted' => true]);
                    $txn->update([
                        'related_type' => Subscription::class,
                        'related_id' => $allSubIds[0],
                    ]);
                }

                return [
                    'subscription_ids' => $allSubIds,
                    'proxy_ip_ids' => $allIpIds,
                    'spark_order_ids' => $allOrders,
                    'charged' => $total,
                    'list_total' => $listTotal,
                    'new_balance' => (float) $balanceAfter,
                    'forward_plan' => $forwardPlan?->name,
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'spark' => '开通失败：' . $e->getMessage(),
            ]);
        }

        // Recalculate VIP after purchase
        try { app(\App\Services\VipService::class)->recalculate(Customer::find($customer->id)); } catch (\Throwable) {}

        // Process referral + sales commission
        try {
            $purchaseCustomer = Customer::find($customer->id);
            $firstProduct = $resolvedItems[0]['product'] ?? [];
            $firstProduct['module'] = $forwardPlan?->module ?? 'static';
            $subId = $results['subscription_ids'][0] ?? null;
            app(\App\Services\ReferralService::class)->processCommission(
                $purchaseCustomer, 'purchase', $results['charged'], $subId, $results['list_total'] ?? 0, $firstProduct
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Commission processing failed', [
                'customer_id' => $customer->id,
                'amount' => $total,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * 从允许的 CIDR 列表中选一个库存最多的，将订单数量全部分配给它。
     * Spark 要求 cidrBlocks 的 count 总和 == 订单数量。
     */
    private static function pickCidrForOrder(array $cidrBlocks, int $quantity): array
    {
        usort($cidrBlocks, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));

        $best = $cidrBlocks[0] ?? null;
        if (!$best) {
            return [];
        }

        return [['cidr' => $best['cidr'], 'count' => $quantity]];
    }
}
