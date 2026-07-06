<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QueryBuilder::for(Customer::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('customer_name'),
                AllowedFilter::callback('sales_person', function ($query, $value) {
                    if ($value === '__none__') {
                        $query->where(function ($q) {
                            $q->whereNull('sales_person')->orWhere('sales_person', '');
                        });
                    } else {
                        $query->where('sales_person', 'like', "%{$value}%");
                    }
                }),
                AllowedFilter::callback('keyword', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('customer_name', 'like', "%{$value}%")
                          ->orWhere('username', 'like', "%{$value}%")
                          ->orWhere('phone', 'like', "%{$value}%")
                          ->orWhere('email', 'like', "%{$value}%")
                          ->orWhere('company_name', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('invite_code_used'),
            ])
            ->with(['referrer:id,customer_name,sales_person,invited_by'])
            ->withCount(['proxyIps', 'activeSubscriptions'])
            ->allowedSorts(['id', 'customer_name', 'balance', 'created_at'])
            ->defaultSort('-id');

        // 数据隔离：无 customer.view_all 权限的用户只看自己名下
        $user = $request->user();
        if ($user && !$user->can('customer.view_all')) {
            $query->where('sales_person', $user->name);
        }

        $customers = $query->paginate($request->input('per_page', 15));

        return $this->paginated($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:100',
            'username'      => 'nullable|string|max:50|unique:customers,username',
            'password'      => 'nullable|string|min:6',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'company_name'  => 'nullable|string|max:200',
            'company_id'    => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:500',
            'balance'       => 'nullable|numeric|min:0',
            'sales_person'  => 'nullable|string|max:50',
            'status'        => 'nullable|integer|in:0,1',
            'remark'        => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        // 无 customer.view_all 权限的用户创建：强制写成自己名下
        if ($user && !$user->can('customer.view_all')) {
            $data['sales_person'] = $user->name;
        }

        // 任何角色：如果前端没填业务归属，默认写成当前登录用户的 name
        // 超级管理员 / admin 可以在前端主动填别人的名字来覆盖
        if (empty($data['sales_person']) && $user) {
            $data['sales_person'] = $user->name;
        }

        // 清理同名/同用户名的软删除记录，释放唯一索引
        Customer::onlyTrashed()->where('customer_name', $data['customer_name'])->forceDelete();
        if (!empty($data['username'])) {
            Customer::onlyTrashed()->where('username', $data['username'])->forceDelete();
        }
        if (!empty($data['phone'])) {
            Customer::onlyTrashed()->where('username', $data['phone'])->forceDelete();
        }

        // 重复客户名校验：customer_name 在数据库内必须唯一
        $exists = Customer::where('customer_name', $data['customer_name'])->exists();
        if ($exists) {
            return $this->error("客户名「{$data['customer_name']}」已存在，不能重复添加", 422);
        }

        $generatedUsername = null;
        $generatedPassword = null;

        if (empty($data['username'])) {
            $generatedUsername = 'snp_' . Str::random(8);
            $data['username'] = $generatedUsername;
        }

        if (empty($data['password'])) {
            $generatedPassword = Str::random(12);
            $data['password'] = $generatedPassword;
        }

        $rawPassword = $data['password'];
        $balance = $data['balance'] ?? 0;
        $status = $data['status'] ?? 1;
        unset($data['balance'], $data['status']);
        $customer = new Customer($data);
        $customer->balance = $balance;
        $customer->status = $status;
        $customer->save();

        return $this->success([
            'customer'   => $customer,
            'credentials' => [
                'username' => $generatedUsername ?? $customer->username,
                'password' => $rawPassword,
            ],
        ], '客户创建成功');
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->loadCount(['activeSubscriptions', 'proxyIps', 'subscriptions']);
        $customer->load([
            'proxyIps:id,assigned_customer_id,asset_name,ip_address,port,country_name,source_name,status,upstream_expires_at',
            'subscriptions' => fn($q) => $q->with([
                'proxyIp:id,asset_name,ip_address,country_name,source_name',
            ])->latest()->limit(50),
            'transactions' => fn($q) => $q->latest()->limit(20),
        ]);

        $data = $customer->toArray();

        // 推荐链路
        $chain = [];
        if ($customer->referred_by_customer) {
            $referrer = Customer::find($customer->referred_by_customer);
            $data['referrer_name'] = $referrer?->customer_name;
            if ($referrer) {
                $chain[] = [
                    'id' => $referrer->id,
                    'name' => $referrer->customer_name,
                    'role' => 'referrer',
                    'sales_person' => $referrer->sales_person,
                    'invited_by' => $referrer->invited_by,
                ];
            }
        }
        if ($customer->invited_by) {
            $inviter = \App\Models\User::find($customer->invited_by);
            if ($inviter) {
                $chain[] = [
                    'id' => $inviter->id,
                    'name' => $inviter->name,
                    'role' => 'sales_direct',
                ];
            }
        } elseif ($customer->referred_by_customer) {
            $referrer = Customer::find($customer->referred_by_customer);
            if ($referrer?->invited_by) {
                $inviter = \App\Models\User::find($referrer->invited_by);
                if ($inviter) {
                    $chain[] = [
                        'id' => $inviter->id,
                        'name' => $inviter->name,
                        'role' => 'sales_indirect',
                    ];
                }
            }
        }
        if (!collect($chain)->whereIn('role', ['sales_direct', 'sales_indirect'])->count() && $customer->sales_person) {
            $salesUser = \App\Models\User::where('name', $customer->sales_person)->first();
            $chain[] = [
                'id' => $salesUser?->id,
                'name' => $customer->sales_person,
                'role' => 'sales_direct',
            ];
        }
        $data['referral_chain'] = $chain;

        $referrals = Customer::where('referred_by_customer', $customer->id)
            ->select('id', 'customer_name', 'sales_person', 'created_at')
            ->get();
        $data['referral_count'] = $referrals->count();
        $data['referrals'] = $referrals;

        return $this->success($data);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:100',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'company_name'  => 'nullable|string|max:200',
            'company_id'    => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:500',
            'sales_person'  => 'nullable|string|max:50',
            'status'        => 'nullable|integer|in:0,1',
            'remark'        => 'nullable|string|max:1000',
            'password'      => 'nullable|string|min:6|max:100',
            'forward_certified' => 'nullable|boolean',
        ]);

        // 空字符串不视为修改密码
        if (empty($data['password'])) {
            unset($data['password']);
        }

        // 改 customer_name 时检查重复（排除自身）
        if (isset($data['customer_name']) && $data['customer_name'] !== $customer->customer_name) {
            $duplicate = Customer::where('customer_name', $data['customer_name'])
                ->where('id', '!=', $customer->id)
                ->exists();
            if ($duplicate) {
                return $this->error("客户名「{$data['customer_name']}」已存在", 422);
            }
        }

        // 无 view_all 权限只能编辑自己名下的客户，且不能改 sales_person
        $user = $request->user();
        if ($user && !$user->can('customer.view_all')) {
            if ($customer->sales_person !== $user->name) {
                return $this->error('无权编辑他人客户', 403);
            }
            unset($data['sales_person']);
        }

        // 修改业务归属人需要单独权限
        if (isset($data['sales_person']) && $user && !$user->can('customer.change_sales')) {
            unset($data['sales_person']);
        }

        if (isset($data['status'])) {
            $customer->status = $data['status'];
            unset($data['status']);
        }
        // 手动开关中转认证
        if (isset($data['forward_certified'])) {
            $customer->forward_certified = $data['forward_certified'];
            if ($data['forward_certified'] && !$customer->forward_certified_at) {
                $customer->forward_certified_at = now();
                $customer->forward_certified_by = $user?->id;
            }
            unset($data['forward_certified']);
        }
        unset($data['balance']);

        // 检查 sales_person 是否发生变更
        $salesPersonChanged = isset($data['sales_person']) && $data['sales_person'] !== $customer->sales_person;
        $newSalesPerson = $data['sales_person'] ?? null;

        $customer->fill($data);
        $customer->save();

        // 如果业务归属人变更，同步 invited_by 并级联更新下游客户
        if ($salesPersonChanged && $newSalesPerson) {
            $salesUser = \App\Models\User::where('name', $newSalesPerson)->first();
            $invitedBy = $salesUser?->id;

            // 更新当前客户的 invited_by
            $customer->invited_by = $invitedBy;
            $customer->save();

            // 递归收集所有下游客户并更新
            $downstream = collect();
            $this->collectDownstream($customer, $downstream);
            if ($downstream->isNotEmpty()) {
                Customer::whereIn('id', $downstream->pluck('id'))
                    ->update([
                        'sales_person' => $newSalesPerson,
                        'invited_by'   => $invitedBy,
                    ]);
            }
        } elseif ($salesPersonChanged && empty($newSalesPerson)) {
            // 清空业务归属人时，也清空 invited_by
            $customer->invited_by = null;
            $customer->save();
        }

        return $this->success($customer->fresh(), '客户更新成功');
    }

    /**
     * 合并客户（仅 super_admin）
     * POST /customers/merge
     *
     * Body: {
     *   source_id: 1,    // 被合并掉的（小号）
     *   target_id: 2,    // 保留的（主号）
     * }
     *
     * 操作（事务内）：
     *   - source 的 balance → target.balance
     *   - source 的所有 proxy_ips / subscriptions / transactions /
     *     ip_assignment_logs / payment_orders / provision_orders 转移到 target
     *   - 写一条特殊的 transaction 记录"合并入账"
     *   - 软删除 source 客户
     */
    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => 'required|integer|exists:customers,id|different:target_id',
            'target_id' => 'required|integer|exists:customers,id',
            'remark'    => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            return $this->error('仅超级管理员可执行合并操作', 403);
        }

        $source = Customer::find($data['source_id']);
        $target = Customer::find($data['target_id']);
        if (!$source || !$target) {
            return $this->error('客户不存在');
        }

        try {
            $result = app(\App\Services\Admin\CustomerMergeService::class)
                ->merge($target, $source, $user->id, $data['remark'] ?? null);
        } catch (\Throwable $e) {
            return $this->error('合并失败：' . $e->getMessage(), 500);
        }

        return $this->success($result, sprintf(
            '已合并「%s」(#%d) → 「%s」(#%d)',
            $source->customer_name, $source->id,
            $target->customer_name, $target->id
        ));
    }

    /**
     * POST /customers/merge-preview
     * 预览合并影响，不改数据。前端合并确认前调这个展示摘要。
     */
    public function mergePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => 'required|integer|exists:customers,id|different:target_id',
            'target_id' => 'required|integer|exists:customers,id',
        ]);

        $source = Customer::find($data['source_id']);
        $target = Customer::find($data['target_id']);

        $counts = [
            'proxy_ips'               => \App\Models\ProxyIp::where('assigned_customer_id', $source->id)->count(),
            'subscriptions'           => \App\Models\Subscription::where('customer_id', $source->id)->count(),
            'transactions'            => Transaction::where('customer_id', $source->id)->count(),
            'provision_orders'        => \App\Models\ProvisionOrder::where('customer_id', $source->id)->count(),
            'payment_orders'          => \App\Models\PaymentOrder::where('customer_id', $source->id)->count(),
            'ip_assignment_logs'      => \App\Models\IpAssignmentLog::where('customer_id', $source->id)->count(),
            'provision_approvals'     => \App\Models\ProvisionApproval::where('customer_id', $source->id)->count(),
            'feishu_sync_configs'     => \App\Models\FeishuSyncConfig::where('customer_id', $source->id)->count(),
            'customer_special_prices' => \App\Models\CustomerSpecialPrice::where('customer_id', $source->id)->count(),
            'referral_commissions'    => \App\Models\ReferralCommission::where('referrer_id', $source->id)
                                                ->orWhere('referee_id', $source->id)->count(),
            'invited_customers'       => Customer::where('invited_by', $source->id)->count(),
            'referred_customers'      => Customer::where('referred_by_customer', $source->id)->count(),
        ];

        // 检测特批价冲突
        $sourceSpecials = \App\Models\CustomerSpecialPrice::where('customer_id', $source->id)->where('is_active', 1)->get();
        $targetSpecials = \App\Models\CustomerSpecialPrice::where('customer_id', $target->id)->where('is_active', 1)->get();
        $conflicts = [];
        foreach ($sourceSpecials as $sp) {
            $key = implode('|', [$sp->country_code, $sp->area_code, $sp->city_code, $sp->product_id]);
            $match = $targetSpecials->first(fn ($t) =>
                $t->country_code === $sp->country_code
                && $t->area_code === $sp->area_code
                && $t->city_code === $sp->city_code
                && $t->product_id === $sp->product_id
            );
            if ($match) {
                $conflicts[] = [
                    'country_code' => $sp->country_code,
                    'area_code'    => $sp->area_code,
                    'city_code'    => $sp->city_code,
                    'product_id'   => $sp->product_id,
                    'source_price' => (float) $sp->special_price,
                    'target_price' => (float) $match->special_price,
                ];
            }
        }

        return $this->success([
            'target'  => [
                'id' => $target->id,
                'customer_name' => $target->customer_name,
                'username' => $target->username,
                'phone' => $target->phone,
                'balance' => (float) $target->balance,
                'commission_balance' => (float) $target->commission_balance,
                'total_spent' => (float) $target->total_spent,
                'max_single_topup' => (float) $target->max_single_topup,
            ],
            'source'  => [
                'id' => $source->id,
                'customer_name' => $source->customer_name,
                'username' => $source->username,
                'phone' => $source->phone,
                'balance' => (float) $source->balance,
                'commission_balance' => (float) $source->commission_balance,
                'total_spent' => (float) $source->total_spent,
                'max_single_topup' => (float) $source->max_single_topup,
            ],
            'counts'  => $counts,
            'special_price_conflicts' => $conflicts,
            'preview' => [
                'new_balance'            => (float) $target->balance + (float) $source->balance,
                'new_commission_balance' => (float) $target->commission_balance + (float) $source->commission_balance,
                'new_total_spent'        => (float) $target->total_spent + (float) $source->total_spent,
                'new_max_topup'          => max((float) $target->max_single_topup, (float) $source->max_single_topup),
            ],
        ]);
    }

    /**
     * 管理员重置客户密码
     * POST /customers/{customer}/reset-password
     */
    public function resetPassword(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'password' => 'nullable|string|min:6|max:100',
        ]);

        // 留空则自动生成
        $newPassword = $data['password'] ?? \Illuminate\Support\Str::random(10);

        $customer->update(['password' => $newPassword]);

        // 撤销该客户所有已登录的 token（强制重新登录）
        $customer->tokens()->delete();

        return $this->success([
            'password' => $newPassword, // 返回明文给 admin 抄录给客户
        ], '密码已重置，请告知客户');
    }

    /**
     * 模拟登录：为管理员生成一个 customer token，可直接跳到客户端面板查看
     * POST /customers/{customer}/impersonate
     *
     * 安全控制：
     *   - 业务员只能模拟自己名下的客户
     *   - token 有效期 2 小时
     *   - token 带 'customer' ability（和客户自己登录一致）
     *   - 额外标记 impersonated_by 在 token name 里（审计）
     */
    public function impersonate(Request $request, Customer $customer): JsonResponse
    {
        $user = $request->user();

        // 数据隔离
        if ($user && !$user->can('customer.view_all')) {
            if ($customer->sales_person !== $user->name) {
                return $this->error('无权模拟登录他人客户', 403);
            }
        }

        if ((int) $customer->status !== 1) {
            return $this->error('该客户账号已被禁用', 422);
        }

        // 生成 2h 有效的 customer token
        $token = $customer->createToken(
            "impersonate-by-{$user->id}-{$user->name}",
            ['customer'],
            now()->addHours(2)
        )->plainTextToken;

        return $this->success([
            'token' => $token,
            'customer_name' => $customer->customer_name,
            'username' => $customer->username,
            'expires_in' => 7200,
        ], '模拟登录 token 已生成');
    }

    /**
     * POST /customers/{customer}/set-referrer
     * 管理员为客户绑定推荐人（referral_code）
     *
     * Body: { referral_code: string, confirm: bool }
     *   - confirm=false（默认）：仅预览，返回将产生的追溯返佣金额
     *   - confirm=true：执行绑定 + 追溯返佣
     */
    public function setReferrer(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'referral_code' => 'required|string|max:50',
            'confirm' => 'nullable|boolean',
        ]);

        $code = strtoupper(trim($data['referral_code']));
        $confirm = (bool) ($data['confirm'] ?? false);

        $referrer = Customer::where('referral_code', $code)->first();
        if (!$referrer) {
            return $this->error("邀请码「{$code}」不存在，请检查", 422);
        }
        if ($referrer->id === $customer->id) {
            return $this->error('不能绑定自己的邀请码', 422);
        }
        if ($customer->referred_by_customer) {
            $existing = Customer::find($customer->referred_by_customer);
            return $this->error(sprintf(
                '该客户已有推荐人：%s (#%d)，如需变更请先清除',
                $existing?->customer_name ?? '-', $customer->referred_by_customer
            ), 422);
        }

        $referralService = app(\App\Services\ReferralService::class);
        $rate = $referralService->getCommissionRate('purchase');

        // 计算追溯返佣：该客户所有非测试订阅的 price 总和
        $purchaseTotal = (float) \App\Models\Subscription::where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'expired', 'cancelled'])
            ->where('is_test', 0)
            ->sum('price');
        $retroCommission = round($purchaseTotal * $rate / 100, 2);

        if (!$confirm) {
            return $this->success([
                'referrer' => [
                    'id' => $referrer->id,
                    'customer_name' => $referrer->customer_name,
                    'referral_code' => $referrer->referral_code,
                    'commission_balance' => (float) $referrer->commission_balance,
                ],
                'customer' => [
                    'id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                ],
                'purchase_total' => $purchaseTotal,
                'commission_rate' => $rate,
                'retro_commission' => $retroCommission,
            ], '预览：确认后将绑定推荐关系并追溯返佣');
        }

        // 执行绑定
        $customer->referred_by_customer = $referrer->id;
        $customer->save();

        // 追溯返佣
        if ($retroCommission > 0) {
            $record = \App\Models\ReferralCommission::create([
                'referrer_id' => $referrer->id,
                'referee_id' => $customer->id,
                'trigger_type' => 'purchase',
                'trigger_id' => null,
                'trigger_amount' => $purchaseTotal,
                'commission_rate' => $rate,
                'commission_amount' => $retroCommission,
                'status' => 'pending',
            ]);

            if ($referralService->isAutoCredit()) {
                $referralService->creditCommission($record);
            }
        }

        return $this->success([
            'referred_by_customer' => $referrer->id,
            'referrer_name' => $referrer->customer_name,
            'retro_commission' => $retroCommission,
        ], sprintf('已绑定推荐人「%s」，追溯返佣 ¥%.2f', $referrer->customer_name, $retroCommission));
    }

    /**
     * POST /customers/{customer}/transfer-referrer
     * 划转推荐人：将客户的推荐人从 A 变更为 C，同时转移所有返佣记录和已发放佣金
     *
     * Body: { new_referrer_id: int, confirm: bool }
     *   - confirm=false（默认）：预览模式，返回将要转移的记录汇总
     *   - confirm=true：执行划转
     */
    public function transferReferrer(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'new_referrer_id' => 'required|integer',
            'confirm' => 'nullable|boolean',
        ]);

        $confirm = (bool) ($data['confirm'] ?? false);
        $newReferrerId = $data['new_referrer_id'];

        // 校验：客户必须有现有推荐人
        if (!$customer->referred_by_customer) {
            return $this->error('该客户没有推荐人，无法划转', 422);
        }

        // 校验：新推荐人不能是客户自己
        if ($newReferrerId === $customer->id) {
            return $this->error('新推荐人不能是客户自己', 422);
        }

        // 校验：新推荐人不能和当前推荐人相同
        if ($newReferrerId === $customer->referred_by_customer) {
            return $this->error('新推荐人与当前推荐人相同，无需划转', 422);
        }

        // 校验：新推荐人必须存在
        $newReferrer = Customer::find($newReferrerId);
        if (!$newReferrer) {
            return $this->error('新推荐人不存在', 422);
        }

        $oldReferrerId = $customer->referred_by_customer;
        $oldReferrer = Customer::find($oldReferrerId);

        // 查询要转移的返佣记录
        $commissions = \App\Models\ReferralCommission::where('referrer_id', $oldReferrerId)
            ->where('referee_id', $customer->id)
            ->get();

        $totalRecords = $commissions->count();
        $creditedCommissions = $commissions->where('status', 'credited');
        $pendingCommissions = $commissions->where('status', 'pending');
        $creditedAmount = (float) $creditedCommissions->sum('commission_amount');
        $pendingAmount = (float) $pendingCommissions->sum('commission_amount');

        if (!$confirm) {
            // 预览模式
            return $this->success([
                'old_referrer' => [
                    'id' => $oldReferrer?->id,
                    'customer_name' => $oldReferrer?->customer_name ?? '-',
                    'commission_balance' => (float) ($oldReferrer?->commission_balance ?? 0),
                ],
                'new_referrer' => [
                    'id' => $newReferrer->id,
                    'customer_name' => $newReferrer->customer_name,
                    'commission_balance' => (float) $newReferrer->commission_balance,
                ],
                'customer' => [
                    'id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                ],
                'total_records' => $totalRecords,
                'credited_count' => $creditedCommissions->count(),
                'credited_amount' => $creditedAmount,
                'pending_count' => $pendingCommissions->count(),
                'pending_amount' => $pendingAmount,
            ], '预览：确认后将划转推荐人及相关返佣记录');
        }

        // 执行划转
        DB::transaction(function () use ($customer, $oldReferrerId, $newReferrerId, $commissions, $creditedAmount) {
            // 1. 更新客户的推荐人
            $customer->referred_by_customer = $newReferrerId;
            $customer->save();

            // 2. 转移所有返佣记录
            \App\Models\ReferralCommission::where('referrer_id', $oldReferrerId)
                ->where('referee_id', $customer->id)
                ->update(['referrer_id' => $newReferrerId]);

            // 3. 对于已发放的佣金，调整双方的 commission_balance
            if ($creditedAmount > 0) {
                $oldReferrer = Customer::lockForUpdate()->find($oldReferrerId);
                $newReferrer = Customer::lockForUpdate()->find($newReferrerId);

                if ($oldReferrer) {
                    $oldBefore = (float) $oldReferrer->commission_balance;
                    $oldReferrer->decrement('commission_balance', $creditedAmount);
                    \App\Models\Transaction::create([
                        'customer_id' => $oldReferrer->id,
                        'type' => \App\Models\Transaction::TYPE_COMMISSION,
                        'amount' => -$creditedAmount,
                        'balance_before' => $oldBefore,
                        'balance_after' => $oldBefore - $creditedAmount,
                        'description' => sprintf('推荐人划转：客户#%d 的已发放返佣 ¥%.2f 转出至客户#%d', $customer->id, $creditedAmount, $newReferrerId),
                        'operated_by' => auth()->id(),
                    ]);
                }
                if ($newReferrer) {
                    $newBefore = (float) $newReferrer->commission_balance;
                    $newReferrer->increment('commission_balance', $creditedAmount);
                    \App\Models\Transaction::create([
                        'customer_id' => $newReferrer->id,
                        'type' => \App\Models\Transaction::TYPE_COMMISSION,
                        'amount' => $creditedAmount,
                        'balance_before' => $newBefore,
                        'balance_after' => $newBefore + $creditedAmount,
                        'description' => sprintf('推荐人划转：客户#%d 的已发放返佣 ¥%.2f 转入（原推荐人#%d）', $customer->id, $creditedAmount, $oldReferrerId),
                        'operated_by' => auth()->id(),
                    ]);
                }
            }
        });

        return $this->success(null, sprintf(
            '已将「%s」的推荐人从「%s」划转到「%s」，转移 %d 条返佣记录，已发放佣金 ¥%.2f 已同步转移',
            $customer->customer_name,
            $oldReferrer?->customer_name ?? '-',
            $newReferrer->customer_name,
            $totalRecords,
            $creditedAmount
        ));
    }

    /**
     * POST /customers/{customer}/clear-referrer
     * 清除客户的推荐人绑定（不回收已发放的返佣）
     */
    public function clearReferrer(Request $request, Customer $customer): JsonResponse
    {
        if (!$customer->referred_by_customer) {
            return $this->error('该客户没有推荐人', 422);
        }

        $oldReferrer = Customer::find($customer->referred_by_customer);
        $customer->referred_by_customer = null;
        $customer->save();

        return $this->success(null, sprintf('已清除推荐人「%s」', $oldReferrer?->customer_name ?? '-'));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return $this->success(null, '客户已删除');
    }

    public function topup(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:500',
        ]);

        $transaction = DB::transaction(function () use ($customer, $data, $request) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);

            $balanceBefore = $customer->balance;
            $customer->increment('balance', $data['amount']);
            $balanceAfter = bcadd($balanceBefore, $data['amount'], 2);

            return Transaction::create([
                'customer_id'    => $customer->id,
                'type'           => Transaction::TYPE_TOPUP,
                'amount'         => $data['amount'],
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $data['description'] ?? '余额充值',
                'operated_by'    => $request->user()?->id,
            ]);
        });

        $customer->refresh();

        return $this->success([
            'transaction' => $transaction,
            'balance'     => $customer->balance,
        ], '充值成功');
    }

    /**
     * 手动调整余额（增加或扣除）
     * POST /customers/{customer}/adjust-balance
     */
    public function adjustBalance(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'action'  => 'required|in:increase,decrease',
            'amount'  => 'required|numeric|gt:0',
            'reason'  => 'required|string|max:100',
            'remark'  => 'nullable|string|max:500',
        ]);

        $action = $data['action'];
        $amount = (float) $data['amount'];
        $reason = $data['reason'];
        $remark = $data['remark'] ?? '';

        $transaction = DB::transaction(function () use ($customer, $action, $amount, $reason, $remark, $request) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);

            if ($action === 'decrease' && (float) $customer->balance < $amount) {
                throw new \Exception("余额不足，当前余额 ¥{$customer->balance}");
            }

            $balanceBefore = $customer->balance;

            if ($action === 'increase') {
                $customer->increment('balance', $amount);
                $balanceAfter = bcadd($balanceBefore, $amount, 2);
                $txAmount = $amount;
                $type = Transaction::TYPE_ADJUSTMENT_IN;
            } else {
                $customer->decrement('balance', $amount);
                $balanceAfter = bcsub($balanceBefore, $amount, 2);
                $txAmount = -$amount;
                $type = $reason === '私下退款' ? Transaction::TYPE_WITHDRAWAL : Transaction::TYPE_ADJUSTMENT_OUT;
            }

            $description = $reason . ($remark ? "（{$remark}）" : '');

            return Transaction::create([
                'customer_id'    => $customer->id,
                'type'           => $type,
                'amount'         => $txAmount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'operated_by'    => $request->user()?->id,
            ]);
        });

        $customer->refresh();
        $label = $action === 'increase' ? '增加' : '扣除';

        return $this->success([
            'transaction' => $transaction,
            'balance'     => $customer->balance,
        ], "已{$label} ¥" . number_format($amount, 2));
    }

    /**
     * 变更业务归属 + 同步下游 + 可选追溯提成
     * POST /customers/{customer}/change-sales
     *
     * preview=true  → 仅返回受影响客户列表及金额预估
     * preview=false → 执行变更 + 可选 backfill_commission
     */
    public function changeSales(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'sales_person'       => 'required|string|max:50',
            'preview'            => 'nullable|boolean',
            'backfill_commission' => 'nullable|boolean',
        ]);

        $salesName = $data['sales_person'];
        $isPreview = $data['preview'] ?? false;

        // 查找对应的管理员用户
        $salesUser = \App\Models\User::where('name', $salesName)->first();

        // 递归收集所有下游客户
        $allAffected = collect();
        $this->collectDownstream($customer, $allAffected);

        // 包含当前客户本身
        $affectedIds = $allAffected->pluck('id')->prepend($customer->id)->unique();

        // 收集每个客户的消费数据
        $affectedData = Customer::whereIn('id', $affectedIds)
            ->withCount('subscriptions')
            ->get()
            ->map(function ($c) use ($salesUser, $customer) {
                $totalSpent = (float) Transaction::where('customer_id', $c->id)
                    ->whereIn('type', Transaction::REVENUE_TYPES)
                    ->where('amount', '<', 0)
                    ->sum(DB::raw('ABS(amount)'));

                // 该客户已有的 sales_commission 总额（给任何销售的）
                $existingCommission = (float) \App\Models\SalesCommission::where('customer_id', $c->id)
                    ->sum('commission_amount');

                // 如果指定了销售用户，计算该用户已有的提成
                $existingForUser = $salesUser
                    ? (float) \App\Models\SalesCommission::where('customer_id', $c->id)
                        ->where('user_id', $salesUser->id)
                        ->sum('commission_amount')
                    : 0;

                return [
                    'id'                    => $c->id,
                    'customer_name'         => $c->customer_name,
                    'current_sales_person'  => $c->sales_person,
                    'subscription_count'    => $c->subscriptions_count,
                    'total_spent'           => $totalSpent,
                    'existing_commission'   => $existingCommission,
                    'existing_for_user'     => $existingForUser,
                    'is_self'               => $c->id === $customer->id,
                ];
            });

        // 计算可追溯提成预估
        $referralService = app(\App\Services\ReferralService::class);
        $l1Rate = $referralService->getSalesCommissionRate('purchase', 1);
        $l2Rate = $referralService->getSalesCommissionRate('purchase', 2);

        $totalPotential = 0;
        $commissionBreakdown = [];

        if ($salesUser) {
            foreach ($affectedData as $item) {
                $spendable = $item['total_spent'] - $item['existing_for_user'];
                if ($spendable <= 0) continue;
                $potential = round($spendable * $l1Rate / 100, 2);
                $totalPotential += $potential;
                $commissionBreakdown[] = [
                    'customer_id'   => $item['id'],
                    'customer_name' => $item['customer_name'],
                    'spend_amount'  => $spendable,
                    'rate'          => $l1Rate,
                    'commission'    => $potential,
                ];
            }
        }

        if ($isPreview) {
            return $this->success([
                'customer'             => ['id' => $customer->id, 'customer_name' => $customer->customer_name],
                'sales_user'           => $salesUser ? ['id' => $salesUser->id, 'name' => $salesUser->name] : null,
                'affected_customers'   => $affectedData,
                'commission_rate'      => $l1Rate,
                'total_potential_commission' => $totalPotential,
                'commission_breakdown' => $commissionBreakdown,
            ]);
        }

        // ── 执行变更 ──
        $backfill = $data['backfill_commission'] ?? false;
        $invitedBy = $salesUser?->id;

        DB::transaction(function () use ($affectedIds, $salesName, $invitedBy, $backfill, $salesUser, $referralService) {
            Customer::whereIn('id', $affectedIds)->update([
                'sales_person' => $salesName,
                'invited_by'   => $invitedBy,
            ]);

            if ($backfill && $salesUser) {
                $this->backfillSalesCommission($affectedIds, $salesUser, $referralService);
            }
        });

        return $this->success(null, sprintf(
            '已将 %d 个客户的业务归属更新为「%s」%s',
            $affectedIds->count(),
            $salesName,
            $backfill ? '，并已追溯历史提成' : ''
        ));
    }

    private function collectDownstream(Customer $customer, &$collection): void
    {
        $children = Customer::where('referred_by_customer', $customer->id)->get();
        foreach ($children as $child) {
            $collection->push($child);
            $this->collectDownstream($child, $collection);
        }
    }

    private function backfillSalesCommission($affectedIds, \App\Models\User $salesUser, \App\Services\ReferralService $svc): void
    {
        $rate = $svc->getSalesCommissionRate('purchase', 1);
        if ($rate <= 0) return;

        foreach ($affectedIds as $customerId) {
            // 查找所有消费交易
            $transactions = Transaction::where('customer_id', $customerId)
                ->whereIn('type', Transaction::REVENUE_TYPES)
                ->where('amount', '<', 0)
                ->get();

            foreach ($transactions as $tx) {
                $triggerAmount = abs((float) $tx->amount);
                if ($triggerAmount < 0.01) continue;

                // 检查是否已有此用户对此交易的提成记录
                $exists = \App\Models\SalesCommission::where('user_id', $salesUser->id)
                    ->where('customer_id', $customerId)
                    ->where('trigger_type', $tx->type ?: 'purchase')
                    ->where('trigger_id', $tx->id)
                    ->exists();
                if ($exists) continue;

                $commission = round($triggerAmount * $rate / 100, 2);
                if ($commission < 0.01) continue;

                $record = \App\Models\SalesCommission::create([
                    'user_id'           => $salesUser->id,
                    'customer_id'       => $customerId,
                    'level'             => 1,
                    'trigger_type'      => $tx->type ?: 'purchase',
                    'trigger_id'        => $tx->id,
                    'trigger_amount'    => $triggerAmount,
                    'commission_rate'   => $rate,
                    'commission_amount' => $commission,
                    'status'            => 'pending',
                ]);

                if ($svc->isSalesAutoCredit()) {
                    $svc->creditSalesCommission($record);
                }
            }
        }
    }

    public function verificationInfo(Customer $customer): JsonResponse
    {
        return $this->success([
            'verified_type' => $customer->verified_type,
            'verified_at' => $customer->verified_at,
            'verified_name' => $customer->verified_name,
            'verified_id_number' => $customer->verified_id_number,
            'verified_enterprise_name' => $customer->verified_enterprise_name,
            'verified_credit_code' => $customer->verified_credit_code,
        ]);
    }

    public function manualVerify(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'verified_type' => 'required|in:personal,enterprise',
            'verified_name' => 'required|string|max:50',
            'verified_id_number' => 'required|string|max:30',
            'verified_enterprise_name' => 'nullable|string|max:200',
            'verified_credit_code' => 'nullable|string|max:30',
        ]);

        $update = [
            'verified_type' => $data['verified_type'],
            'verified_at' => now(),
            'verified_name' => $data['verified_name'],
            'verified_id_number' => $data['verified_id_number'],
            'pending_biz_token' => null,
            'pending_verify_name' => null,
            'pending_verify_id' => null,
            'pending_verify_at' => null,
        ];

        if ($data['verified_type'] === 'enterprise') {
            $update['verified_enterprise_name'] = $data['verified_enterprise_name'] ?? null;
            $update['verified_credit_code'] = $data['verified_credit_code'] ?? null;
            $update['company_name'] = $data['verified_enterprise_name'] ?? $customer->company_name;
        }

        $customer->update($update);

        return $this->success(null, '已手动完成实名认证');
    }

    public function resetVerification(Customer $customer): JsonResponse
    {
        $customer->update([
            'verified_type' => null,
            'verified_at' => null,
            'verified_name' => null,
            'verified_id_number' => null,
            'verified_enterprise_name' => null,
            'verified_credit_code' => null,
        ]);

        return $this->success(null, '实名认证已重置，客户需重新认证');
    }
}
