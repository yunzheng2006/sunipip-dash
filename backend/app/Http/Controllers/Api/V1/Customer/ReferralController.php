<?php
namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProvisionApproval;
use App\Models\ReferralCommission;
use App\Services\ReferralService;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReferralController extends Controller
{
    public function __construct(protected ReferralService $referral) {}

    /**
     * GET /customer/referral
     * Get own referral info + stats
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        // Auto-generate code if not exists
        $this->referral->generateCode($customer);

        $portalUrl = rtrim(config('proxy.platform.customer_portal_url', 'https://user.sunipip.com'), '/');
        $stats = $this->referral->getStats($customer);
        $rate = $this->referral->getCommissionRate('purchase');
        $rateRenew = $this->referral->getCommissionRate('renew');

        $referrals = Customer::where('referred_by_customer', $customer->id)
            ->select('id', 'customer_name', 'created_at')
            ->withCount('subscriptions')
            ->orderByDesc('id')
            ->get();

        $refereeIds = $referrals->pluck('id');
        $commissionsByReferee = $refereeIds->isNotEmpty()
            ? ReferralCommission::where('referrer_id', $customer->id)
                ->whereIn('referee_id', $refereeIds)
                ->groupBy('referee_id')
                ->selectRaw('referee_id, SUM(commission_amount) as total')
                ->pluck('total', 'referee_id')
            : collect();

        $referrals->each(function ($r) use ($commissionsByReferee) {
            $r->total_commission = (float) ($commissionsByReferee[$r->id] ?? 0);
        });

        // 已提现总额（历史累计：approved/executed/pending 的提现申请）
        $totalWithdrawn = ProvisionApproval::where('customer_id', $customer->id)
            ->where('type', 'withdraw')
            ->whereIn('status', ['approved', 'pending', 'executed'])
            ->sum('total_amount');

        // 可提现金额 = 当前返佣余额（余额已拆分后直接使用字段）
        $commissionBalance = (float) $customer->commission_balance;
        $availableWithdraw = $commissionBalance;

        $withdrawFeePercent = (float) \App\Models\SystemConfig::get('referral.withdraw_fee_percent', 1);

        return $this->success([
            'enabled' => $this->referral->isEnabled(),
            'referral_code' => $stats['referral_code'],
            'referral_link' => "{$portalUrl}/register?ref={$customer->referral_code}",
            'commission_rate' => $rate,
            'commission_rate_renew' => $rateRenew,
            'referral_count' => $stats['referral_count'],
            'total_commission' => $stats['total_commission'],
            'pending_commission' => $stats['pending_commission'],
            'commission_balance' => $commissionBalance,
            'balance' => (float) $customer->balance,
            'available_withdraw' => $availableWithdraw,
            'total_withdrawn' => (float) $totalWithdrawn,
            'withdraw_fee_percent' => $withdrawFeePercent,
            'withdraw_info' => [
                'bank_name' => $customer->withdraw_bank_name,
                'bank_account' => $customer->withdraw_bank_account,
                'account_holder' => $customer->withdraw_account_holder,
            ],
            'recent_referrals' => $referrals,
        ]);
    }

    /**
     * GET /customer/referral/commissions
     * Commission history
     */
    public function commissions(Request $request): JsonResponse
    {
        $customer = $request->user();
        $specialRate = $this->referral->getSpecialCommissionRate();

        $commissions = ReferralCommission::with([
                'referee:id,customer_name',
                'subscription:id,proxy_ip_id,purchased_module,has_forward',
                'subscription.proxyIp:id,ip_address,country_name,access_type',
            ])
            ->where('referrer_id', $customer->id)
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 20), 50));

        $moduleLabels = [
            'video' => 'IPLC视频专线',
            'live_mobile' => 'IPLC直播专线(手机)',
            'live_pc' => 'IPLC直播专线(电脑)',
        ];

        $commissions->getCollection()->transform(function ($c) use ($specialRate, $moduleLabels) {
            $sub = $c->subscription;
            $ip = $sub?->proxyIp;

            $parts = array_filter([
                $ip?->country_name,
                $moduleLabels[$sub?->purchased_module] ?? null,
            ]);
            $c->product_desc = $parts ? implode(' ', $parts) : null;
            $c->ip_address = $ip?->ip_address;
            $c->is_special_rate = abs((float) $c->commission_rate - $specialRate) < 0.01;

            unset($c->subscription);
            return $c;
        });

        return $this->paginated($commissions);
    }

    /**
     * PUT /customer/referral/withdraw-info
     * 保存提现银行卡信息
     */
    public function updateWithdrawInfo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => 'required|string|max:100',
            'bank_account' => 'required|string|max:50',
            'account_holder' => 'required|string|max:50',
        ], [
            'bank_name.required' => '请输入银行名称',
            'bank_account.required' => '请输入银行卡号',
            'account_holder.required' => '请输入持卡人姓名',
        ]);

        $customer = $request->user();
        $customer->update([
            'withdraw_bank_name' => $data['bank_name'],
            'withdraw_bank_account' => $data['bank_account'],
            'withdraw_account_holder' => $data['account_holder'],
        ]);

        return $this->success(null, '提现信息已保存');
    }

    /**
     * POST /customer/referral/withdraw
     * 提交提现申请
     */
    public function requestWithdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:10',
        ], [
            'amount.required' => '请输入提现金额',
            'amount.min' => '最低提现金额为 ¥10',
        ]);

        $customer = $request->user();
        $amount = (float) $data['amount'];

        // 检查银行卡信息
        if (!$customer->withdraw_bank_name || !$customer->withdraw_bank_account) {
            return $this->error('请先绑定提现银行卡信息', 422);
        }

        // 检查有无待审核的提现
        $pendingExists = ProvisionApproval::where('customer_id', $customer->id)
            ->where('type', 'withdraw')
            ->where('status', 'pending')
            ->exists();
        if ($pendingExists) {
            return $this->error('您有待审核的提现申请，请等待处理后再提交', 422);
        }

        // 校验返佣余额（从 commission_balance 扣，审批通过时由 ApprovalController 执行）
        $commissionBalance = (float) $customer->commission_balance;
        if ($amount > $commissionBalance) {
            return $this->error("返佣余额不足，当前返佣余额 ¥" . number_format($commissionBalance, 2), 422);
        }

        $feePercent = (float) \App\Models\SystemConfig::get('referral.withdraw_fee_percent', 1);
        $fee = round($amount * $feePercent / 100, 2);
        $actualAmount = round($amount - $fee, 2);

        $approval = ProvisionApproval::create([
            'order_no' => ProvisionApproval::generateOrderNo(),
            'type' => 'withdraw',
            'submitted_by' => 1, // 客户发起
            'customer_id' => $customer->id,
            'order_data' => [
                'amount' => $amount,
                'fee_percent' => $feePercent,
                'fee' => $fee,
                'actual_amount' => $actualAmount,
                'bank_name' => $customer->withdraw_bank_name,
                'bank_account' => $customer->withdraw_bank_account,
                'account_holder' => $customer->withdraw_account_holder,
                'commission_balance_snapshot' => $commissionBalance,
            ],
            'total_amount' => $amount,
            'status' => 'pending',
        ]);

        try {
            app(\App\Services\NotificationService::class)->dispatch('withdrawal_request', [
                'title' => '💰 提现申请',
                'content' => sprintf(
                    "**客户**：%s\n**提现金额**：¥%s\n**手续费**：¥%s（%s%%）\n**实际到账**：¥%s\n**银行**：%s %s\n**审批单号**：`%s`",
                    $customer->customer_name,
                    number_format($amount, 2),
                    number_format($fee, 2),
                    $feePercent,
                    number_format($actualAmount, 2),
                    $customer->withdraw_bank_name,
                    substr($customer->withdraw_bank_account, -4),
                    $approval->order_no
                ),
                'related_type' => 'App\\Models\\ProvisionApproval',
                'related_id' => $approval->id,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Withdrawal webhook failed', ['error' => $e->getMessage()]);
        }

        return $this->success([
            'fee_percent' => $feePercent,
            'fee' => $fee,
            'actual_amount' => $actualAmount,
        ], '提现申请已提交，请等待审核');
    }

    /**
     * POST /customer/referral/transfer-to-balance
     * 返佣余额转入常规余额（不可逆）
     */
    public function transferToBalance(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ], [
            'amount.required' => '请输入转入金额',
            'amount.min' => '最低转入金额为 ¥0.01',
        ]);
        $amount = (float) $data['amount'];

        return DB::transaction(function () use ($customer, $amount) {
            // 锁行读取最新余额，避免并发
            $fresh = \App\Models\Customer::lockForUpdate()->find($customer->id);
            if (!$fresh) {
                return $this->error('账户异常', 422);
            }
            // 考虑已存在的 pending 提现金额，避免审批执行时 commission_balance 不足
            $pendingWithdraw = (float) ProvisionApproval::where('customer_id', $fresh->id)
                ->where('type', 'withdraw')
                ->where('status', 'pending')
                ->sum('total_amount');
            $availableForTransfer = (float) $fresh->commission_balance - $pendingWithdraw;
            if ($availableForTransfer < $amount) {
                $msg = $pendingWithdraw > 0
                    ? sprintf('可转入金额不足（返佣余额 ¥%.2f，已锁定待审批提现 ¥%.2f）', (float) $fresh->commission_balance, $pendingWithdraw)
                    : '返佣余额不足';
                return $this->error($msg, 422);
            }

            $balanceBefore = (float) $fresh->balance;
            $fresh->decrement('commission_balance', $amount);
            $fresh->increment('balance', $amount);

            // 写入一条 Transaction：常规余额视角（balance_before/after 对应 balance）
            Transaction::create([
                'customer_id' => $fresh->id,
                'type' => Transaction::TYPE_COMMISSION_TRANSFER,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore + $amount,
                'description' => sprintf('返佣余额转入常规余额 ¥%.2f', $amount),
                'operated_by' => null,
            ]);

            $fresh->refresh();
            return $this->success([
                'balance' => (float) $fresh->balance,
                'commission_balance' => (float) $fresh->commission_balance,
            ], '转入成功');
        });
    }
}
