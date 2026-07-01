<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customer = Customer::with(['referrer:id,customer_name,sales_person,invited_by'])
            ->withCount(['proxyIps', 'activeSubscriptions'])
            ->find($request->input('customer_id'));

        // 基本信息
        $data = [
            'customer' => [
                'id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'username' => $customer->username,
                'phone' => $customer->phone,
                'balance' => $customer->balance,
                'commission_balance' => $customer->commission_balance,
                'sales_person' => $customer->sales_person,
                'referral_code' => $customer->referral_code,
                'status' => $customer->status,
                'created_at' => $customer->created_at,
                'proxy_ips_count' => $customer->proxy_ips_count,
                'active_subscriptions_count' => $customer->active_subscriptions_count,
            ],
        ];

        // 推荐链路
        $data['referrer'] = null;
        if ($customer->referred_by_customer) {
            $referrer = Customer::select('id', 'customer_name', 'sales_person', 'invited_by')->find($customer->referred_by_customer);
            $data['referrer'] = $referrer;
        }

        $data['invited_by_user'] = null;
        if ($customer->invited_by) {
            $data['invited_by_user'] = \App\Models\User::select('id', 'name')->find($customer->invited_by);
        }

        // 推荐了谁
        $data['referrals'] = Customer::where('referred_by_customer', $customer->id)
            ->select('id', 'customer_name', 'sales_person', 'balance', 'created_at')
            ->get();

        // 订阅明细
        $data['subscriptions'] = Subscription::where('customer_id', $customer->id)
            ->with('proxyIp:id,asset_name,ip_address,port,country_name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'proxy_ip' => $s->proxyIp ? [
                    'asset_name' => $s->proxyIp->asset_name,
                    'ip_address' => $s->proxyIp->ip_address,
                    'country_name' => $s->proxyIp->country_name,
                ] : null,
                'price' => $s->price,
                'duration' => $s->duration,
                'unit' => $s->unit,
                'started_at' => $s->started_at,
                'expires_at' => $s->expires_at,
                'status' => $s->status,
                'renewed_count' => $s->renewed_count,
            ]);

        // 交易流水
        $data['transactions'] = Transaction::where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => $t->amount,
                'balance_after' => $t->balance_after,
                'description' => $t->description,
                'created_at' => $t->created_at,
            ]);

        // 该客户的消费产生的佣金（被推荐人角色）
        $data['commissions_as_referee'] = ReferralCommission::where('referee_id', $customer->id)
            ->with('referrer:id,customer_name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'referrer_name' => $c->referrer?->customer_name,
                'referrer_id' => $c->referrer_id,
                'trigger_type' => $c->trigger_type,
                'trigger_amount' => $c->trigger_amount,
                'commission_rate' => $c->commission_rate,
                'commission_amount' => $c->commission_amount,
                'status' => $c->status,
                'created_at' => $c->created_at,
            ]);

        // 该客户作为推荐人收到的佣金
        $data['commissions_as_referrer'] = ReferralCommission::where('referrer_id', $customer->id)
            ->with('referee:id,customer_name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'referee_name' => $c->referee?->customer_name,
                'referee_id' => $c->referee_id,
                'trigger_type' => $c->trigger_type,
                'trigger_amount' => $c->trigger_amount,
                'commission_rate' => $c->commission_rate,
                'commission_amount' => $c->commission_amount,
                'status' => $c->status,
                'created_at' => $c->created_at,
            ]);

        // 该客户消费产生的销售提成
        $data['sales_commissions'] = SalesCommission::where('customer_id', $customer->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'user_name' => $c->user?->name,
                'user_id' => $c->user_id,
                'level' => $c->level,
                'trigger_type' => $c->trigger_type,
                'trigger_amount' => $c->trigger_amount,
                'commission_rate' => $c->commission_rate,
                'commission_amount' => $c->commission_amount,
                'status' => $c->status,
                'created_at' => $c->created_at,
            ]);

        // 汇总
        $data['summary'] = [
            'total_spent' => (float) Transaction::where('customer_id', $customer->id)
                ->where('amount', '<', 0)
                ->whereNotIn('type', Transaction::SPENDING_EXCLUDE_TYPES)
                ->sum('amount') * -1,
            'total_topup' => (float) Transaction::where('customer_id', $customer->id)
                ->whereIn('type', Transaction::TOPUP_TYPES)
                ->sum('amount'),
            'total_referral_commission' => (float) ReferralCommission::where('referee_id', $customer->id)->sum('commission_amount'),
            'total_sales_commission' => (float) SalesCommission::where('customer_id', $customer->id)->sum('commission_amount'),
            'referral_earned' => (float) ReferralCommission::where('referrer_id', $customer->id)->sum('commission_amount'),
        ];

        return $this->success($data);
    }
}
