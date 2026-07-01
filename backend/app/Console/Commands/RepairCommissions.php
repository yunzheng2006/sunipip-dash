<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ReferralCommission;
use App\Models\SystemConfig;
use App\Models\Transaction;
use App\Support\DurationHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairCommissions extends Command
{
    protected $signature = 'commissions:repair {--dry-run : Preview without making changes} {--id= : Repair a specific commission ID}';
    protected $description = 'Repair historical referral commissions to use the new formula with per-type rate caps';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — 不会修改任何数据 ===');
        }

        $thresholdPercent = (float) SystemConfig::get('referral.threshold_discount', 80);
        $floorRate = (float) SystemConfig::get('referral.floor_rate', 5);
        $purchaseRate = (float) SystemConfig::get('referral.rate_purchase', SystemConfig::get('referral.rate', 5));
        $renewRate = (float) SystemConfig::get('referral.rate_renew', SystemConfig::get('referral.rate', 5));

        $this->info("当前配置: 阈值={$thresholdPercent}% 兜底={$floorRate}% 新购标准={$purchaseRate}% 续费标准={$renewRate}%");

        $query = ReferralCommission::whereIn('status', ['credited', 'pending']);
        if ($this->option('id')) {
            $query->where('id', $this->option('id'));
        }
        $commissions = $query->orderBy('id')->get();

        $this->info("共 {$commissions->count()} 条待检查记录");

        $allCustomerIds = $commissions->pluck('referee_id')
            ->merge($commissions->pluck('referrer_id'))
            ->unique()->all();
        $customerNames = DB::table('customers')
            ->whereIn('id', $allCustomerIds)
            ->pluck('customer_name', 'id');

        $fixed = 0;
        $skipped = 0;
        $unchanged = 0;
        $totalDelta = 0;
        $balanceAdjustments = [];

        $reversed = 0;
        foreach ($commissions as $rc) {
            if ($rc->trigger_id && $this->isSubscriptionRefunded($rc->trigger_id)) {
                $refereeName = $customerNames[$rc->referee_id] ?? "#{$rc->referee_id}";
                $referrerName = $customerNames[$rc->referrer_id] ?? "#{$rc->referrer_id}";
                $this->line(sprintf(
                    "  #%d [%s] %s→%s | 佣金=%.2f | 订阅已退订→应撤销",
                    $rc->id, $rc->trigger_type, $refereeName, $referrerName, $rc->commission_amount
                ));
                if (!$dryRun) {
                    $this->reverseCommission($rc);
                }
                $reversed++;
                continue;
            }

            $result = $this->recalculate($rc, $thresholdPercent, $floorRate, $purchaseRate, $renewRate);

            if ($result === null) {
                $skipped++;
                continue;
            }

            $oldAmount = (float) $rc->commission_amount;
            $newAmount = $result['new_commission'];
            $newRate = $result['new_rate'];
            $totalListPrice = $result['total_list_price'];
            $delta = round($newAmount - $oldAmount, 2);

            if (abs($delta) < 0.01) {
                $unchanged++;
                continue;
            }

            $refereeName = $customerNames[$rc->referee_id] ?? "#{$rc->referee_id}";
            $referrerName = $customerNames[$rc->referrer_id] ?? "#{$rc->referrer_id}";
            $capNote = $result['capped'] ? ' [已封顶]' : '';
            $this->line(sprintf(
                "  #%d [%s] %s→%s | 消费=%.2f 原价=%.2f | 旧佣金=%.2f(%.1f%%) → 新佣金=%.2f(%.1f%%) | 差额=%+.2f%s",
                $rc->id, $rc->trigger_type, $refereeName, $referrerName,
                $rc->trigger_amount, $totalListPrice,
                $oldAmount, $rc->commission_rate, $newAmount, $newRate, $delta, $capNote
            ));

            $totalDelta += $delta;
            $balanceAdjustments[$rc->referrer_id] = ($balanceAdjustments[$rc->referrer_id] ?? 0) + $delta;

            if (!$dryRun) {
                $rc->update([
                    'commission_amount' => $newAmount,
                    'commission_rate' => $newRate,
                ]);

                if ($rc->status === 'credited' && abs($delta) >= 0.01) {
                    $referrer = Customer::find($rc->referrer_id);
                    if ($referrer) {
                        $balanceBefore = (float) $referrer->commission_balance;
                        if ($delta > 0) {
                            $referrer->increment('commission_balance', $delta);
                        } else {
                            $deduct = min(abs($delta), $balanceBefore);
                            if ($deduct > 0) {
                                $referrer->decrement('commission_balance', $deduct);
                            }
                        }
                        Transaction::create([
                            'customer_id' => $referrer->id,
                            'type' => $delta > 0 ? Transaction::TYPE_COMMISSION : Transaction::TYPE_COMMISSION_REVERSAL,
                            'amount' => $delta,
                            'balance_before' => $balanceBefore,
                            'balance_after' => (float) $referrer->fresh()->commission_balance,
                            'description' => sprintf('返佣修正 (佣金#%d %s: %.2f→%.2f)', $rc->id, $rc->trigger_type, $oldAmount, $newAmount),
                            'operated_by' => null,
                        ]);
                    }
                }
                $fixed++;
            } else {
                $fixed++;
            }
        }

        $this->newLine();
        $this->info("=== 汇总 ===");
        $this->info("  需修正: {$fixed}");
        $this->info("  已退订需撤销: {$reversed}");
        $this->info("  无变化: {$unchanged}");
        $this->info("  跳过(无法确定原价): {$skipped}");
        $this->info(sprintf("  总差额: %+.2f", $totalDelta));

        if (!empty($balanceAdjustments)) {
            $this->newLine();
            $this->info("=== 代理余额调整 ===");
            foreach ($balanceAdjustments as $customerId => $adj) {
                $customer = Customer::find($customerId);
                $name = $customer ? $customer->customer_name : "#{$customerId}";
                $this->line(sprintf("  %s (ID:%d): %+.2f", $name, $customerId, $adj));
            }
        }

        if ($dryRun && $fixed > 0) {
            $this->newLine();
            $this->warn("以上为预览，执行修复请去掉 --dry-run 参数");
        }

        return 0;
    }

    private function recalculate(
        ReferralCommission $rc,
        float $thresholdPercent,
        float $floorRate,
        float $purchaseRate,
        float $renewRate
    ): ?array {
        $triggerAmount = (float) $rc->trigger_amount;
        if ($triggerAmount <= 0) return null;

        $totalListPrice = $this->resolveListPrice($rc);
        if ($totalListPrice === null) return null;

        $standardRate = match ($rc->trigger_type) {
            'renew' => $renewRate,
            default => $purchaseRate,
        };

        $module = $this->resolveModule($rc);
        $moduleFloorRate = $this->getModuleFloorRate($module, $rc->trigger_type, $floorRate);
        $moduleThreshold = $this->getModuleThreshold($module, $thresholdPercent);

        if ($triggerAmount >= $totalListPrice * $moduleThreshold / 100) {
            $newCommission = round($triggerAmount * $standardRate / 100, 2);
            $newRate = $standardRate;
            $base = $triggerAmount;
        } else {
            $newCommission = round($totalListPrice * $moduleFloorRate / 100, 2);
            $newRate = $moduleFloorRate;
            $base = $totalListPrice;
        }

        return [
            'new_commission' => $newCommission,
            'new_rate' => $newRate,
            'total_list_price' => $totalListPrice,
            'capped' => false,
        ];
    }

    private function resolveModule(ReferralCommission $rc): string
    {
        $sub = DB::table('subscriptions')->find($rc->trigger_id);
        if (!$sub) return 'static';

        $fwdRule = DB::table('forward_rules')
            ->where('subscription_id', $sub->id)
            ->whereNotNull('forward_plan_id')
            ->first();

        if ($fwdRule && $fwdRule->forward_plan_id) {
            $plan = DB::table('forward_plans')->find($fwdRule->forward_plan_id);
            if ($plan && $plan->module) return $plan->module;
        }

        return $sub->purchased_module ?? 'static';
    }

    private function getModuleFloorRate(string $module, string $triggerType, float $defaultFloor): float
    {
        $specific = \App\Models\SystemConfig::get("referral.{$module}.floor_rate_{$triggerType}");
        if ($specific !== null) return (float) $specific;

        $moduleDefault = \App\Models\SystemConfig::get("referral.{$module}.floor_rate");
        if ($moduleDefault !== null) return (float) $moduleDefault;

        return $defaultFloor;
    }

    private function getModuleThreshold(string $module, float $defaultThreshold): float
    {
        $val = \App\Models\SystemConfig::get("referral.{$module}.threshold");
        if ($val !== null) return (float) $val;

        return $defaultThreshold;
    }

    private function resolveListPrice(ReferralCommission $rc): ?float
    {
        if ($rc->trigger_type === 'forward') {
            return $this->resolveForwardListPrice($rc);
        }

        $subs = $this->findSubscriptions($rc);
        if ($subs->isEmpty()) return null;

        $totalListPrice = 0;
        foreach ($subs as $sub) {
            $dm = max(DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3), 1);

            $fwdLp = 0;
            $isFixedPricing = false;
            if ($rc->trigger_type === 'purchase') {
                $fwdRule = DB::table('forward_rules')
                    ->where('subscription_id', $sub->id)
                    ->whereNotNull('forward_plan_id')
                    ->where('created_at', '<=', Carbon::parse($rc->created_at)->addSeconds(60))
                    ->first();
            } else {
                $fwdRule = DB::table('forward_rules')
                    ->where('subscription_id', $sub->id)
                    ->whereNotNull('forward_plan_id')
                    ->first();
            }
            if ($fwdRule && $fwdRule->forward_plan_id) {
                $plan = DB::table('forward_plans')->find($fwdRule->forward_plan_id);
                if ($plan) {
                    $fwdLp = (float) $plan->base_price;
                    $isFixedPricing = ($plan->pricing_mode === 'fixed')
                        || in_array($plan->module, ['live_mobile', 'live_pc']);
                }
            }

            if ($isFixedPricing) {
                $ipLp = 0;
            } else {
                $ipLp = $sub->list_price;
                if (!$ipLp) {
                    $ipLp = $this->lookupProductListPrice($sub);
                }
                if (!$ipLp) {
                    $ipLp = (float) $sub->price / $dm;
                }
            }

            $totalListPrice += round(($ipLp + $fwdLp) * $dm, 2);
        }

        return $totalListPrice > 0 ? $totalListPrice : null;
    }

    private function resolveForwardListPrice(ReferralCommission $rc): ?float
    {
        if (!$rc->trigger_id) return null;

        $sub = DB::table('subscriptions')->find($rc->trigger_id);
        if (!$sub) return null;

        $fwdRule = DB::table('forward_rules')
            ->where('subscription_id', $sub->id)
            ->whereNotNull('forward_plan_id')
            ->first();

        if (!$fwdRule || !$fwdRule->forward_plan_id) return null;

        $plan = DB::table('forward_plans')->find($fwdRule->forward_plan_id);
        if (!$plan) return null;

        $fwdBasePrice = (float) $plan->base_price;
        if ($fwdBasePrice <= 0) return null;

        $remainDays = Carbon::parse($rc->created_at)
            ->diffInDays(Carbon::parse($sub->expires_at), false);
        $remainMonths = max($remainDays / 30, 1);

        return round($fwdBasePrice * $remainMonths, 2);
    }

    private function findSubscriptions(ReferralCommission $rc)
    {
        if ($rc->trigger_id && in_array($rc->trigger_type, ['renew', 'forward', 'subscription'])) {
            $sub = DB::table('subscriptions')->find($rc->trigger_id);
            return $sub ? collect([$sub]) : collect();
        }

        return DB::table('subscriptions')
            ->where('customer_id', $rc->referee_id)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($rc->created_at) - 120))
            ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($rc->created_at) + 120))
            ->get();
    }


    private function isSubscriptionRefunded(int $subscriptionId): bool
    {
        $sub = DB::table('subscriptions')
            ->where('id', $subscriptionId)
            ->first();

        if (!$sub) return true;

        if (!in_array($sub->status, ['cancelled', 'refunded'])) return false;

        return !($sub->keep_performance ?? false);
    }

    private function reverseCommission(ReferralCommission $rc): void
    {
        if ($rc->status === 'credited') {
            $referrer = Customer::find($rc->referrer_id);
            if ($referrer) {
                $amount = (float) $rc->commission_amount;
                $balanceBefore = (float) $referrer->commission_balance;
                $deduct = min($amount, $balanceBefore);
                if ($deduct > 0) {
                    $referrer->decrement('commission_balance', $deduct);
                    Transaction::create([
                        'customer_id' => $referrer->id,
                        'type' => Transaction::TYPE_COMMISSION_REVERSAL,
                        'amount' => -$deduct,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceBefore - $deduct,
                        'description' => sprintf('返佣撤销-订阅已退订 (佣金#%d %s)', $rc->id, $rc->trigger_type),
                        'operated_by' => null,
                    ]);
                }
            }
        }
        $rc->update(['status' => 'reversed']);
    }

    private function lookupProductListPrice($sub): ?float
    {
        if (!$sub->proxy_ip_id) return null;

        $sparkInst = DB::table('spark_instances')->where('proxy_ip_id', $sub->proxy_ip_id)->first();
        if ($sparkInst && $sparkInst->spark_order_id) {
            $order = DB::table('spark_orders')->find($sparkInst->spark_order_id);
            if ($order && $order->product_id) {
                try {
                    $product = collect(\App\Services\SparkStockCacheService::allProducts())
                        ->firstWhere('product_id', $order->product_id);
                    if ($product) {
                        return \App\Models\PricingMultiplier::calcSalePrice($product);
                    }
                } catch (\Throwable $e) {}
            }
        }

        $ip = DB::table('proxy_ips')->find($sub->proxy_ip_id);
        if ($ip && $ip->ipipv_instance_id) {
            $ipipvInst = DB::table('ipipv_instances')->where('instance_no', $ip->ipipv_instance_id)->first();
            if ($ipipvInst) {
                $productNo = $ipipvInst->product_no;
                if (!$productNo && $ipipvInst->ipipv_order_id) {
                    $ipipvOrder = DB::table('ipipv_orders')->find($ipipvInst->ipipv_order_id);
                    $productNo = $ipipvOrder->product_no ?? null;
                }
                if ($productNo) {
                    try {
                        $product = collect(\App\Services\IpipvStockCacheService::products())
                            ->first(fn($p) => ($p['product_no'] ?? $p['productNo'] ?? null) === $productNo);
                        if ($product) {
                            $cost = (float) ($product['cost_price'] ?? $product['unitPrice'] ?? 0);
                            $costOverride = SystemConfig::get('cost.ipipv_hard_cost_override');
                            if ($costOverride !== null && (float) $costOverride > 0) {
                                $cost = (float) $costOverride;
                            }
                            if ($cost > 0) {
                                return \App\Models\PricingMultiplier::calcSalePrice(['cost_price' => $cost, 'source' => 'ipipv']);
                            }
                        }
                    } catch (\Throwable $e) {}
                }
            }
        }

        return null;
    }
}
