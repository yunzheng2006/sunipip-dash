<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ForwardRule;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixMissingConvertCommissions extends Command
{
    protected $signature = 'fix:missing-convert-commissions {--dry-run}';
    protected $description = '补发管理员测试转正时漏发的推荐返佣';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '【DRY RUN 模式】' : '【执行模式】');

        $convertTxns = Transaction::where('type', Transaction::TYPE_DEDUCTION)
            ->where('description', 'like', '测试转正%')
            ->where('amount', '<', 0)
            ->orderBy('created_at')
            ->get();

        $this->line("管理员转正扣款交易总数: {$convertTxns->count()}");
        $this->newLine();

        $fixCount = 0;

        foreach ($convertTxns as $txn) {
            $customer = Customer::find($txn->customer_id);
            if (!$customer || !$customer->referred_by_customer) {
                continue;
            }

            $subId = $txn->related_id;

            $hasComm = DB::table('referral_commissions')
                ->where('referee_id', $customer->id)
                ->where('trigger_id', $subId)
                ->exists();

            if ($hasComm) {
                continue;
            }

            $referrer = Customer::find($customer->referred_by_customer);
            if (!$referrer || (int) $referrer->status !== 1) {
                continue;
            }

            $sub = Subscription::find($subId);
            if (!$sub) {
                continue;
            }

            $amount = abs((float) $txn->amount);
            $fixCount++;

            $duration = $sub->duration ?: 1;
            $unit = $sub->unit ?: 3;
            $durationMonths = max(\App\Support\DurationHelper::toMonths($duration, $unit), 1);
            $fwdRule = ForwardRule::where('subscription_id', $sub->id)
                ->orderByRaw("status = 'active' DESC")
                ->first();
            $isFixedPricing = $fwdRule?->forwardPlan?->isFixedPricing() ?? false;
            $ipListPrice = $isFixedPricing ? 0 : (float) ($sub->list_price ?: ($sub->price / max($durationMonths, 1)));
            $fwdListPrice = $fwdRule?->forwardPlan ? (float) $fwdRule->forwardPlan->base_price : 0;
            $totalListPrice = round(($ipListPrice + $fwdListPrice) * $durationMonths, 2);

            $proxyIp = $sub->proxyIp;
            $productCtx = [];
            if ($proxyIp) {
                $sparkInstance = \App\Models\SparkInstance::where('proxy_ip_id', $proxyIp->id)->first();
                $productCtx = [
                    'country_code' => $proxyIp->country_code ?? null,
                    'city_code'    => $proxyIp->city ?? null,
                    'product_id'   => $sparkInstance?->sparkOrder?->product_id,
                    'module'       => $fwdRule?->forwardPlan?->module ?? 'static',
                ];
            }

            $this->line(str_repeat('─', 60));
            $this->info("#{$fixCount} Txn#{$txn->id} Sub#{$subId} {$txn->created_at}");
            $this->line("  客户: {$customer->customer_name} #{$customer->id}");
            $this->line("  推荐人: {$referrer->customer_name} #{$referrer->id}");
            $this->line("  扣款金额: ¥{$amount}  官网价: ¥{$totalListPrice}");
            $this->line("  订阅状态: {$sub->status}  price={$sub->price}");

            if (!$dryRun) {
                $commission = app(ReferralService::class)->processCommission(
                    $customer, 'purchase', $amount, $subId, $totalListPrice ?: $amount, $productCtx
                );
                if ($commission > 0) {
                    $this->info("  ✓ 已补发返佣 ¥{$commission}");
                } else {
                    $this->warn("  ⚠ processCommission 返回 0（可能返佣未启用或费率为0）");
                }
            } else {
                $rate = (float) \App\Models\SystemConfig::get('referral.rate_purchase', 20);
                $estimated = round($amount * $rate / 100, 2);
                $this->line("  预估返佣: ¥{$estimated} (purchase费率{$rate}%)");
            }

            $this->newLine();
        }

        $this->line(str_repeat('─', 60));
        if ($fixCount === 0) {
            $this->info('没有需要补发的返佣。');
        } elseif ($dryRun) {
            $this->warn("共 {$fixCount} 笔需补发。去掉 --dry-run 执行实际补发。");
        } else {
            $this->info("补发完成，共 {$fixCount} 笔。");
        }

        return 0;
    }
}
