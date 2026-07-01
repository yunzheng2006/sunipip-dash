<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProvisionApproval;
use App\Models\User;
use App\Services\SparkProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ApprovalController extends Controller
{
    /**
     * GET /approvals
     * 销售看自己提交的，经理/管理员看需要审批的
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $customerFields = 'id,customer_name,balance,commission_balance,sales_person,phone,verified_name,withdraw_bank_name,withdraw_account_holder,forward_certified,total_spent';
        $query = QueryBuilder::for(ProvisionApproval::class)
            ->with(['submitter:id,name', "customer:{$customerFields}", 'reviewer:id,name'])
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('submitted_by'),
                AllowedFilter::partial('customer_name', 'customer.customer_name'),
            ])
            ->allowedSorts(['id', 'created_at', 'total_amount', 'status'])
            ->defaultSort('-id');

        if ($user->hasRole('sales') && !$user->hasAnyRole(['super_admin', 'tech_admin', 'ops_admin', 'manager'])) {
            $query->where('submitted_by', $user->id);
        } elseif ($user->hasRole('manager') && !$user->hasAnyRole(['super_admin', 'ops_admin'])) {
            $subordinateIds = User::where('supervisor_id', $user->id)->pluck('id')->toArray();
            $subordinateIds[] = $user->id;
            $query->whereIn('submitted_by', $subordinateIds);
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }

    /**
     * GET /approvals/{approval}
     */
    public function show(ProvisionApproval $approval): JsonResponse
    {
        $customerFields = [
            'id', 'customer_name', 'display_name', 'phone', 'email',
            'balance', 'commission_balance', 'total_spent', 'sales_person',
            'company_name', 'is_company', 'business_license',
            'verified_type', 'verified_name', 'verified_id_number',
            'verified_enterprise_name', 'verified_credit_code',
            'forward_certified', 'forward_certified_at',
            'withdraw_bank_name', 'withdraw_bank_account', 'withdraw_account_holder',
            'referral_code', 'referred_by_customer',
            'created_at',
        ];
        $approval->load(['submitter:id,name', 'customer:' . implode(',', $customerFields), 'reviewer:id,name']);

        $extra = $this->buildExtraContext($approval);

        $result = $approval->toArray();
        if ($extra) {
            $result['extra'] = $extra;
        }

        return $this->success($result);
    }

    private function buildExtraContext(ProvisionApproval $approval): array
    {
        $extra = [];
        $customerId = $approval->customer_id;

        $extra['customer_stats'] = [
            'active_subscriptions' => \App\Models\Subscription::where('customer_id', $customerId)->where('status', 'active')->count(),
            'total_subscriptions' => \App\Models\Subscription::where('customer_id', $customerId)->count(),
            'active_ips' => \App\Models\ProxyIp::where('assigned_customer_id', $customerId)->where('status', 'assigned')->count(),
        ];

        if (in_array($approval->type, ['withdraw', 'certification'])) {
            $referrer = null;
            $customer = $approval->customer;
            if ($customer?->referred_by_customer) {
                $referrer = \App\Models\Customer::select('id', 'customer_name')->find($customer->referred_by_customer);
            }
            $extra['referrer'] = $referrer ? ['id' => $referrer->id, 'name' => $referrer->customer_name] : null;
        }

        if ($approval->type === 'withdraw') {
            $extra['recent_withdrawals'] = \App\Models\ProvisionApproval::where('customer_id', $customerId)
                ->where('type', 'withdraw')
                ->where('id', '!=', $approval->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'total_amount', 'status', 'created_at'])
                ->toArray();

            $extra['total_withdrawn'] = (float) \App\Models\ProvisionApproval::where('customer_id', $customerId)
                ->where('type', 'withdraw')
                ->where('status', 'executed')
                ->sum('total_amount');

            $extra['commission_records'] = \App\Models\ReferralCommission::where('referrer_id', $customerId)
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'commission_amount', 'trigger_type', 'status', 'created_at'])
                ->toArray();
        }

        if ($approval->type === 'redeem') {
            $subId = $approval->order_data['subscription_id'] ?? null;
            if ($subId) {
                $sub = \App\Models\Subscription::with('proxyIp:id,ip_address,country_name,country_code,source_name,upstream_expires_at,port,status')->find($subId);
                if ($sub) {
                    $extra['subscription'] = [
                        'id' => $sub->id,
                        'price' => $sub->price,
                        'sales_cost' => $sub->sales_cost,
                        'status' => $sub->status,
                        'started_at' => $sub->started_at?->toDateTimeString(),
                        'expires_at' => $sub->expires_at?->toDateTimeString(),
                        'renewed_count' => $sub->renewed_count,
                        'auto_renew' => $sub->auto_renew,
                        'remark' => $sub->remark,
                        'proxy_ip' => $sub->proxyIp ? [
                            'ip_address' => $sub->proxyIp->ip_address,
                            'port' => $sub->proxyIp->port,
                            'country_name' => $sub->proxyIp->country_name,
                            'source_name' => $sub->proxyIp->source_name,
                            'status' => $sub->proxyIp->status,
                            'upstream_expires_at' => $sub->proxyIp->upstream_expires_at?->toDateTimeString(),
                        ] : null,
                    ];
                }
            }
        }

        if ($approval->type === 'provision') {
            $orderData = $approval->order_data;
            $productId = $orderData['product_id'] ?? null;
            if ($productId) {
                $sparkProduct = collect(\App\Services\SparkStockCacheService::allProducts())->firstWhere('product_id', $productId);
                if ($sparkProduct) {
                    $extra['product_detail'] = [
                        'product_name' => $sparkProduct['productName'] ?? $sparkProduct['product_name'] ?? null,
                        'country_name' => $sparkProduct['countryName'] ?? null,
                        'isp_type' => $sparkProduct['ispType'] ?? $sparkProduct['isp_type'] ?? null,
                        'net_type' => $sparkProduct['netType'] ?? $sparkProduct['net_type'] ?? null,
                        'cost_price' => $sparkProduct['cost_price'] ?? $sparkProduct['unitPrice'] ?? null,
                        'stock' => $sparkProduct['stock'] ?? $sparkProduct['availableNum'] ?? null,
                    ];
                    $listPrice = \App\Models\PricingMultiplier::calcSalePrice($sparkProduct);
                    if ($listPrice) {
                        $extra['product_detail']['list_price'] = $listPrice;
                    }
                }
            }
            if (!empty($orderData['forward']['device_group_id'])) {
                $dg = \App\Models\NyDeviceGroup::find($orderData['forward']['device_group_id']);
                if ($dg) {
                    $extra['forward_detail'] = [
                        'device_group_name' => $dg->name,
                        'connect_host' => $dg->custom_connect_host ?: $dg->original_connect_host,
                    ];
                }
            }
        }

        if ($approval->type === 'certification') {
            $customer = $approval->customer;
            $extra['existing_forwards'] = \App\Models\ForwardRule::whereHas('subscription', function ($q) use ($customerId) {
                $q->where('customer_id', $customerId)->where('status', 'active');
            })->count();
        }

        return $extra;
    }

    /**
     * POST /approvals
     * 销售提交审批订单（支持 certification / custom_price 类型）
     */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|in:certification,custom_price,provision',
            'customer_id' => 'required|integer|exists:customers,id',
            'order_data' => 'required|array',
            'order_data.company_name' => 'nullable|string|max:200',
            'order_data.business_license' => 'nullable|string|max:100',
            'order_data.remark' => 'nullable|string|max:500',
            // For custom_price type
            'order_data.country_code' => 'nullable|string',
            'order_data.special_price' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            // For provision type
            'order_data.product_id' => 'nullable|string',
            'order_data.product_name' => 'nullable|string',
            'order_data.quantity' => 'nullable|integer|min:1',
            'order_data.duration' => 'nullable|integer|min:1',
            'order_data.unit' => 'nullable|integer|in:1,2,3,4',
            'order_data.sale_price' => 'nullable|numeric|min:0',
            'order_data.asset_group_id' => 'nullable|integer',
            'order_data.auto_renew' => 'nullable|boolean',
            'order_data.forward' => 'nullable|array',
            'order_data.xui_forward' => 'nullable|array',
            'order_data.cidr_blocks' => 'nullable|array',
        ]);

        $user = $request->user();
        $shouldAutoApprove = ($user->auto_approve && $data['type'] === 'provision')
            || ($user->auto_approve_forward && $data['type'] === 'certification');

        $autoApproveLabel = $data['type'] === 'certification' ? '独立审批中转权限' : '独立开IP权限';

        $approval = ProvisionApproval::create([
            'order_no' => ProvisionApproval::generateOrderNo(),
            'type' => $data['type'],
            'submitted_by' => $user->id,
            'customer_id' => $data['customer_id'],
            'order_data' => $data['order_data'],
            'total_amount' => $data['total_amount'] ?? 0,
            'status' => $shouldAutoApprove ? 'approved' : 'pending',
            'reviewed_by' => $shouldAutoApprove ? $user->id : null,
            'reviewed_at' => $shouldAutoApprove ? now() : null,
            'review_comment' => $shouldAutoApprove ? "自动审批（{$autoApproveLabel}）" : null,
        ]);

        if ($shouldAutoApprove) {
            try {
                if ($data['type'] === 'certification') {
                    $this->executeCertification($approval, $user->id);
                } else {
                    $this->executeProvision($approval, $user->id);
                }
                $approval->load(['submitter:id,name', 'customer:id,customer_name']);
                $msg = $data['type'] === 'certification' ? '已自动审批中转认证' : '已自动审批并开通';
                return $this->success($approval, $msg);
            } catch (\Throwable $e) {
                Log::error('Auto-approve execution failed', [
                    'approval_id' => $approval->id,
                    'error' => $e->getMessage(),
                ]);
                $approval->update([
                    'status' => 'pending',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_comment' => null,
                    'execution_result' => ['auto_approve_error' => $e->getMessage()],
                ]);
                // Fall through to normal pending flow
            }
        }

        // Notify supervisor
        $supervisor = $user->supervisor;
        $typeLabels = ['certification' => '中转认证', 'custom_price' => '特批价格', 'provision' => '开通订单', 'withdraw' => '提现'];
        $typeLabel = $typeLabels[$data['type']] ?? $data['type'];
        if ($supervisor) {
            try {
                app(\App\Services\NotificationService::class)->dispatch('approval_submitted', [
                    'title' => "📋 {$typeLabel}审批",
                    'content' => sprintf(
                        "**类型**：%s\n**提交人**：%s\n**客户**：%s\n**审批单号**：`%s`",
                        $typeLabel,
                        $user->name,
                        $approval->customer?->customer_name ?? '?',
                        $approval->order_no
                    ),
                    'related_type' => 'ProvisionApproval',
                    'related_id' => $approval->id,
                    'dedup_key' => 'approval_' . $approval->order_no,
                ]);
            } catch (\Throwable) {}
        }

        return $this->success($approval->load(['submitter:id,name', 'customer:id,customer_name']), '审批已提交');
    }

    /**
     * POST /approvals/{approval}/approve
     * 经理/管理员批准 — 支持 certification / custom_price 类型
     */
    public function approve(Request $request, ProvisionApproval $approval): JsonResponse
    {
        if ($approval->status !== 'pending') {
            return $this->error('该审批单不在待审批状态', 422);
        }

        $data = $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $approval->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_comment' => $data['comment'] ?? null,
        ]);

        // Execute based on type
        try {
            if ($approval->type === 'certification') {
                $this->executeCertification($approval, $request->user()->id);
            } elseif ($approval->type === 'custom_price') {
                // Create special price record
                $orderData = $approval->order_data;
                \App\Models\CustomerSpecialPrice::create([
                    'customer_id' => $approval->customer_id,
                    'country_code' => $orderData['country_code'] ?? null,
                    'area_code' => $orderData['area_code'] ?? null,
                    'city_code' => $orderData['city_code'] ?? null,
                    'product_id' => $orderData['product_id'] ?? null,
                    'special_price' => $orderData['special_price'] ?? 0,
                    'approved_by' => $request->user()->id,
                    'remark' => $orderData['remark'] ?? '审批通过',
                    'is_active' => 1,
                ]);

                $approval->update([
                    'status' => 'executed',
                    'executed_at' => now(),
                    'execution_result' => ['special_price_created' => true],
                ]);

            } elseif ($approval->type === 'provision') {
                $this->executeProvision($approval, $request->user()->id);
            } elseif ($approval->type === 'redeem') {
                $this->executeRedeem($approval, $request->user()->id);
            } elseif ($approval->type === 'withdraw') {
                // 提现审批通过：从 commission_balance 扣款
                $amount = (float) ($approval->total_amount ?? 0);
                if ($amount <= 0) {
                    throw new \RuntimeException('提现金额异常');
                }

                $orderData = $approval->order_data ?? [];
                $fee = (float) ($orderData['fee'] ?? 0);
                $actualAmount = (float) ($orderData['actual_amount'] ?? $amount);

                DB::transaction(function () use ($approval, $amount, $fee, $actualAmount, $request) {
                    $customer = \App\Models\Customer::lockForUpdate()->find($approval->customer_id);
                    if (!$customer) {
                        throw new \RuntimeException('客户不存在');
                    }
                    if ((float) $customer->commission_balance < $amount) {
                        throw new \RuntimeException('返佣余额不足，无法执行提现');
                    }

                    $before = (float) $customer->commission_balance;
                    $customer->decrement('commission_balance', $amount);

                    $desc = sprintf('返佣余额提现 ¥%.2f (审批 #%s)', $amount, $approval->order_no);
                    if ($fee > 0) {
                        $desc .= sprintf('，手续费 ¥%.2f，实际到账 ¥%.2f', $fee, $actualAmount);
                    }

                    \App\Models\Transaction::create([
                        'customer_id' => $customer->id,
                        'type' => \App\Models\Transaction::TYPE_WITHDRAWAL,
                        'amount' => -1 * $amount,
                        'balance_before' => $before,
                        'balance_after' => $before - $amount,
                        'description' => $desc,
                        'operated_by' => $request->user()->id,
                    ]);
                });

                $approval->update([
                    'status' => 'executed',
                    'executed_at' => now(),
                    'execution_result' => [
                        'withdrawn_amount' => $amount,
                        'fee' => $fee,
                        'actual_amount' => $actualAmount,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Approval execution failed', [
                'approval_id' => $approval->id,
                'error' => $e->getMessage(),
            ]);
            $approval->update(['execution_result' => ['error' => $e->getMessage()]]);
            return $this->success($approval->fresh(), '已批准，但执行失败: ' . $e->getMessage());
        }

        return $this->success($approval->fresh()->load(['submitter:id,name', 'reviewer:id,name']), '已批准');
    }

    /**
     * POST /approvals/{approval}/reject
     */
    public function reject(Request $request, ProvisionApproval $approval): JsonResponse
    {
        if ($approval->status !== 'pending') {
            return $this->error('该审批单不在待审批状态', 422);
        }

        $data = $request->validate([
            'comment' => 'required|string|max:500',
        ]);

        $approval->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_comment' => $data['comment'],
        ]);

        return $this->success($approval->fresh(), '已驳回');
    }

    /**
     * POST /approvals/{approval}/cancel
     * 提交人自己取消
     */
    public function cancel(Request $request, ProvisionApproval $approval): JsonResponse
    {
        if ($approval->status !== 'pending') {
            return $this->error('只能取消待审批的订单', 422);
        }
        if ($approval->submitted_by !== $request->user()->id && !$request->user()->hasAnyRole(['super_admin', 'ops_admin'])) {
            return $this->error('只能取消自己提交的审批', 403);
        }

        $approval->update(['status' => 'cancelled']);
        return $this->success(null, '已取消');
    }

    /**
     * GET /approvals/stats
     * Dashboard stats for approval center
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $baseQuery = ProvisionApproval::query();

        // Scope same as index
        if ($user->hasRole('sales') && !$user->hasAnyRole(['super_admin', 'tech_admin', 'ops_admin', 'manager'])) {
            $baseQuery->where('submitted_by', $user->id);
        } elseif ($user->hasRole('manager') && !$user->hasAnyRole(['super_admin', 'ops_admin'])) {
            $subordinateIds = User::where('supervisor_id', $user->id)->pluck('id')->toArray();
            $subordinateIds[] = $user->id;
            $baseQuery->whereIn('submitted_by', $subordinateIds);
        }

        return $this->success([
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'approved' => (clone $baseQuery)->where('status', 'approved')->count(),
            'executed' => (clone $baseQuery)->where('status', 'executed')->count(),
            'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
            'total' => (clone $baseQuery)->count(),
        ]);
    }

    private function executeCertification(ProvisionApproval $approval, int $operatorId): void
    {
        $customer = \App\Models\Customer::find($approval->customer_id);
        if ($customer) {
            $customer->forward_certified = true;
            $customer->forward_certified_at = now();
            $customer->forward_certified_by = $operatorId;
            $customer->save();

            $orderData = $approval->order_data;
            if (!empty($orderData['company_name'])) {
                $customer->update(['company_name' => $orderData['company_name']]);
            }
            if (!empty($orderData['business_license'])) {
                $customer->update(['business_license' => $orderData['business_license']]);
            }
        }

        $approval->update([
            'status' => 'executed',
            'executed_at' => now(),
            'execution_result' => ['certified' => true],
        ]);
    }

    private function executeProvision(ProvisionApproval $approval, int $operatorId): void
    {
        $customer = \App\Models\Customer::findOrFail($approval->customer_id);
        $orderData = $approval->order_data;

        $provision = app(SparkProvisionService::class);
        $provisionParams = [
            'product_id' => $orderData['product_id'],
            'product_name' => $orderData['product_name'] ?? '',
            'country_code' => $orderData['country_code'] ?? '',
            'country_cn' => $orderData['country_cn'] ?? '',
            'sale_price' => (float) ($orderData['sale_price'] ?? 0),
            'quantity' => (int) ($orderData['quantity'] ?? 1),
            'duration' => (int) ($orderData['duration'] ?? 1),
            'unit' => (int) ($orderData['unit'] ?? 3),
            'asset_group_id' => $orderData['asset_group_id'],
            'customer_id' => $customer->id,
            'auto_renew' => $orderData['auto_renew'] ?? false,
            'source_remark' => "审批开通 #{$approval->order_no}",
            'created_by' => $operatorId,
        ];

        if (!empty($orderData['forward'])) {
            $provisionParams['forward'] = $orderData['forward'];
        }
        if (!empty($orderData['xui_forward'])) {
            $provisionParams['xui_forward'] = $orderData['xui_forward'];
        }
        if (!empty($orderData['cidr_blocks'])) {
            $provisionParams['cidr_blocks'] = $orderData['cidr_blocks'];
        }

        $result = $provision->createOrder($provisionParams);

        $approval->update([
            'status' => 'executed',
            'executed_at' => now(),
            'execution_result' => [
                'subscription_ids' => $result['subscription_ids'] ?? [],
                'proxy_ip_ids' => $result['proxy_ip_ids'] ?? [],
                'spark_order_id' => $result['spark_order']?->id,
            ],
        ]);
    }

    /**
     * 赎回过期 Spark IP：调 RenewProxy 续费1个月 + 恢复订阅
     */
    private function executeRedeem(ProvisionApproval $approval, int $operatorId): void
    {
        $orderData = $approval->order_data;
        $subId = $orderData['subscription_id'] ?? null;
        $sparkInstanceId = $orderData['spark_instance_id'] ?? null;

        if (!$subId || !$sparkInstanceId) {
            throw new \RuntimeException('赎回数据不完整：缺少 subscription_id 或 spark_instance_id');
        }

        $sub = \App\Models\Subscription::findOrFail($subId);
        $proxyIp = $sub->proxyIp;
        $instance = \App\Models\SparkInstance::where('instance_id', $sparkInstanceId)->firstOrFail();

        $sparkApi = app(\App\Services\SparkApiService::class);

        // 查询实例状态
        $info = $sparkApi->getInstance(['instanceId' => $sparkInstanceId]);
        $status = (int) ($info['status'] ?? 0);
        if ($status === 4) {
            throw new \RuntimeException("实例已被释放(status=4)，无法赎回");
        }

        // RenewProxy 赎回
        $reqOrderNo = \App\Models\SparkOrder::generateReqOrderNo();
        $sparkOrder = \App\Models\SparkOrder::create([
            'req_order_no' => $reqOrderNo,
            'method' => 'RenewProxy',
            'product_id' => '',
            'amount' => 1,
            'duration' => 1,
            'unit' => 3,
            'status' => 1,
            'request_data' => [
                'instanceId' => $sparkInstanceId,
                'duration' => 1,
                'unit' => 3,
                'trigger' => 'approval_redeem',
                'approval_id' => $approval->id,
            ],
        ]);

        $response = $sparkApi->renewProxy($reqOrderNo, [[
            'instanceId' => $sparkInstanceId,
            'duration' => 1,
            'unit' => 3,
        ]]);

        $sparkOrder->update([
            'spark_order_no' => $response['orderNo'] ?? null,
            'status' => 2,
            'response_data' => $response,
        ]);

        // 更新上游到期时间
        $newExpireAt = now()->addDays(30);
        $instance->update(['expire_at' => $newExpireAt, 'status' => 2]);
        $proxyIp?->update(['upstream_expires_at' => $newExpireAt, 'status' => 'assigned']);

        // 恢复订阅（续费1个月）
        $subExpiry = \App\Support\DurationHelper::addToDate(now(), 1, 3);
        $sub->update([
            'status' => 'active',
            'expires_at' => $subExpiry,
            'last_renewed_at' => now(),
            'renewed_count' => ($sub->renewed_count ?? 0) + 1,
            'remark' => trim(($sub->remark ?? '') . "\n[系统] 审批赎回 #{$approval->order_no}"),
        ]);

        $approval->update([
            'status' => 'executed',
            'executed_at' => now(),
            'execution_result' => [
                'subscription_id' => $sub->id,
                'spark_order_id' => $sparkOrder->id,
                'new_upstream_expires_at' => $newExpireAt->toIso8601String(),
                'new_sub_expires_at' => $subExpiry->toIso8601String(),
            ],
        ]);
    }
}
