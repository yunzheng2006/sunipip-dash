<?php

namespace App\Services\Admin;

use App\Models\Customer;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\ProvisionOrder;
use App\Models\PaymentOrder;
use App\Models\IpAssignmentLog;
use App\Models\ProvisionApproval;
use App\Models\FeishuSyncConfig;
use App\Models\CustomerSpecialPrice;
use App\Models\ReferralCommission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 客户合并：把 source 客户的全部业务数据迁移到 target 客户，然后软删 source。
 *
 * 合并规则：
 * - 关系表（订阅/IP/交易/订单/审批/特批价/返佣等）全部 UPDATE customer_id = target
 * - 金额字段 target += source（balance / commission_balance / total_spent 累加，
 *   max_single_topup 取 max）
 * - 认证/VIP/自动续费等布尔/枚举字段：target 空则继承 source 非空值
 * - 自引用字段（invited_by / referred_by_customer / referral_commissions.*_id）
 *   也一并迁移，避免孤儿引用
 * - 最后软删 source，记录 merge 事件到日志
 *
 * 参数校验由 Controller 做（两个客户都存在、不同、都未软删）
 */
class CustomerMergeService
{
    /**
     * @return array 合并摘要
     * @throws \Exception
     */
    public function merge(Customer $target, Customer $source, ?int $operatedBy = null, ?string $remark = null): array
    {
        if ($target->id === $source->id) {
            throw new \InvalidArgumentException('不能合并到自己');
        }

        return DB::transaction(function () use ($target, $source, $operatedBy, $remark) {
            // 锁两条记录（按 id 升序锁，避免死锁）
            [$firstId, $secondId] = $target->id < $source->id
                ? [$target->id, $source->id]
                : [$source->id, $target->id];

            Customer::where('id', $firstId)->lockForUpdate()->first();
            Customer::where('id', $secondId)->lockForUpdate()->first();

            // 重新读取（锁定后的最新状态）
            $target = Customer::findOrFail($target->id);
            $source = Customer::findOrFail($source->id);

            $counts = [];

            // === 关系表：customer_id 字段迁移 ===
            $counts['proxy_ips']             = ProxyIp::where('assigned_customer_id', $source->id)
                                                    ->update(['assigned_customer_id' => $target->id]);
            $counts['subscriptions']         = Subscription::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            $counts['transactions']          = Transaction::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            $counts['provision_orders']      = ProvisionOrder::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            $counts['payment_orders']        = PaymentOrder::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            $counts['ip_assignment_logs']    = IpAssignmentLog::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            $counts['provision_approvals']   = ProvisionApproval::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            $counts['feishu_sync_configs']   = FeishuSyncConfig::where('customer_id', $source->id)
                                                    ->update(['customer_id' => $target->id]);
            // 特批价：检测冲突，冲突时保留更低价（对客户更优），删除源端重复行
            $sourceSpecials = CustomerSpecialPrice::where('customer_id', $source->id)->get();
            $targetSpecials = CustomerSpecialPrice::where('customer_id', $target->id)->get();
            $conflictCount = 0;
            $movedCount = 0;
            foreach ($sourceSpecials as $sp) {
                $match = $targetSpecials->first(fn ($t) =>
                    $t->country_code === $sp->country_code
                    && $t->area_code === $sp->area_code
                    && $t->city_code === $sp->city_code
                    && $t->product_id === $sp->product_id
                );
                if ($match) {
                    if ((float) $sp->special_price < (float) $match->special_price) {
                        $match->update(['special_price' => $sp->special_price, 'remark' => trim(($match->remark ?? '') . ' [合并自#' . $source->id . ']')]);
                    }
                    $sp->delete();
                    $conflictCount++;
                } else {
                    $sp->update(['customer_id' => $target->id]);
                    $movedCount++;
                }
            }
            $counts['customer_special_prices'] = $movedCount;
            $counts['special_price_conflicts'] = $conflictCount;

            // 返佣系统双向引用
            $counts['referral_commissions_referrer'] = ReferralCommission::where('referrer_id', $source->id)
                                                    ->update(['referrer_id' => $target->id]);
            $counts['referral_commissions_referee']  = ReferralCommission::where('referee_id', $source->id)
                                                    ->update(['referee_id' => $target->id]);

            // 自引用：别的客户 invited_by / referred_by_customer 指向了 source
            $counts['customers_invited_by']          = Customer::where('invited_by', $source->id)
                                                    ->update(['invited_by' => $target->id]);
            $counts['customers_referred_by']         = Customer::where('referred_by_customer', $source->id)
                                                    ->update(['referred_by_customer' => $target->id]);

            // === 可选关系表（按表是否存在判断，避免 schema 不同报错） ===
            if (\Schema::hasTable('forward_rules') && \Schema::hasColumn('forward_rules', 'customer_id')) {
                $counts['forward_rules'] = DB::table('forward_rules')
                    ->where('customer_id', $source->id)
                    ->update(['customer_id' => $target->id]);
            }
            if (\Schema::hasTable('verification_logs') && \Schema::hasColumn('verification_logs', 'customer_id')) {
                $counts['verification_logs'] = DB::table('verification_logs')
                    ->where('customer_id', $source->id)
                    ->update(['customer_id' => $target->id]);
            }

            // === target 数值字段累加 ===
            $target->balance            = (float) $target->balance + (float) $source->balance;
            $target->commission_balance = (float) $target->commission_balance + (float) $source->commission_balance;
            $target->total_spent        = (float) $target->total_spent + (float) $source->total_spent;
            $target->max_single_topup   = max((float) $target->max_single_topup, (float) $source->max_single_topup);

            // 继承非空字段：target 空则用 source 的
            foreach (['email', 'company_name', 'company_id', 'address', 'business_license', 'sales_person'] as $f) {
                if (empty($target->$f) && !empty($source->$f)) {
                    $target->$f = $source->$f;
                }
            }
            if (!$target->forward_certified && $source->forward_certified) {
                $target->forward_certified     = true;
                $target->forward_certified_at  = $source->forward_certified_at ?: now();
                $target->forward_certified_by  = $source->forward_certified_by;
            }
            // VIP 等级：按新的 total_spent / max_single_topup 重算
            $newTier = \App\Models\VipTier::resolveForCustomer($target);
            $target->vip_tier_id = $newTier?->id;

            // last_login 取更晚的
            if ($source->last_login_at && (!$target->last_login_at || $source->last_login_at > $target->last_login_at)) {
                $target->last_login_at = $source->last_login_at;
                $target->last_login_ip = $source->last_login_ip;
            }

            // 记录合并摘要到 target.remark
            $mergeNote = sprintf(
                '[合并] 吸收客户 #%d(%s) 于 %s%s',
                $source->id, $source->customer_name, now()->format('Y-m-d H:i'),
                $remark ? " — {$remark}" : ''
            );
            $target->remark = trim(($target->remark ? $target->remark . "\n" : '') . $mergeNote);
            $target->save();

            // === source 收尾 ===
            // 撤销所有 Sanctum token，确保前端立即失效
            $source->tokens()->delete();

            // 清掉 source 的金额（已迁移），并打上合并标记
            $source->balance = 0;
            $source->commission_balance = 0;
            $source->total_spent = 0;
            $source->max_single_topup = 0;
            $source->status = 0;
            $source->remark = sprintf(
                '[已合并] 于 %s 合并到 #%d(%s) — 已软删%s',
                now()->format('Y-m-d H:i'), $target->id, $target->customer_name,
                $remark ? " — {$remark}" : ''
            );
            $source->save();
            $source->delete(); // soft delete

            Log::info('CustomerMerge', [
                'target_id'    => $target->id,
                'source_id'    => $source->id,
                'operated_by'  => $operatedBy,
                'counts'       => $counts,
                'remark'       => $remark,
            ]);

            return [
                'target_id'   => $target->id,
                'source_id'   => $source->id,
                'counts'      => $counts,
                'new_balance' => (float) $target->balance,
                'new_total_spent' => (float) $target->total_spent,
                'new_vip_tier' => $newTier?->name,
            ];
        });
    }
}
