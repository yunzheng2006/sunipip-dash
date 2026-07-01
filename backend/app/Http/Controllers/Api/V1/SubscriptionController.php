<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IpAssignmentLog;
use App\Models\PricingRule;
use App\Models\ProvisionOrder;
use App\Models\ProvisionOrderItem;
use App\Models\ProxyIp;
use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 将前端排序值（如 expires_asc）映射为 Spatie QueryBuilder 格式
        $sortMap = [
            'expires_asc' => 'expires_at',
            'expires_desc' => '-expires_at',
            'created_desc' => '-created_at',
            'created_asc' => 'created_at',
            'price_desc' => '-price',
            'price_asc' => 'price',
            'id_asc' => 'id',
            'id_desc' => '-id',
            'expires_at_asc' => 'expires_at',
            'expires_at_desc' => '-expires_at',
            'created_at_asc' => 'created_at',
            'created_at_desc' => '-created_at',
        ];
        if ($request->filled('sort') && isset($sortMap[$request->input('sort')])) {
            $request->merge(['sort' => $sortMap[$request->input('sort')]]);
        }

        $query = QueryBuilder::for(Subscription::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('customer_id'),
                AllowedFilter::exact('proxy_ip_id'),
                AllowedFilter::callback('customer_name', function ($q, $value) {
                    $q->whereHas('customer', fn($c) => $c->where('customer_name', 'like', "%{$value}%"));
                }),
                AllowedFilter::callback('country', function ($q, $value) {
                    $q->whereHas('proxyIp', fn($p) => $p->where('country_name', 'like', "%{$value}%"));
                }),
                AllowedFilter::callback('expiring_soon', function ($q, $value) {
                    if ($value) {
                        $q->where('status', 'active')
                          ->where('expires_at', '<=', now()->addDays((int) $value))
                          ->where('expires_at', '>', now());
                    }
                }),
                AllowedFilter::callback('keyword', function ($q, $value) {
                    $value = is_array($value) ? reset($value) : $value;
                    $q->where(function ($outer) use ($value) {
                        $outer->whereHas('proxyIp', fn($p) => $p->where('asset_name', 'like', "%{$value}%")
                            ->orWhere('ip_address', 'like', "%{$value}%"));
                    });
                }),
                AllowedFilter::callback('asset_name', function ($q, $value) {
                    $value = is_array($value) ? reset($value) : $value;
                    $q->whereHas('proxyIp', fn($p) => $p->where('asset_name', 'like', "%{$value}%"));
                }),
                AllowedFilter::callback('ids', function ($q, $value) {
                    $ids = is_array($value) ? $value : explode(',', (string) $value);
                    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                    if (!empty($ids)) $q->whereIn('id', $ids);
                }),
                AllowedFilter::callback('ip_in', function ($q, $value) {
                    $ips = is_array($value) ? $value : explode(',', (string) $value);
                    $ips = array_values(array_unique(array_filter(array_map('trim', $ips))));
                    if (empty($ips)) return;
                    $q->whereHas('proxyIp', fn($p) => $p->whereIn('ip_address', $ips));
                }),
                AllowedFilter::callback('source_name', function ($q, $value) {
                    $value = is_array($value) ? reset($value) : $value;
                    $q->whereHas('proxyIp', fn($p) => $p->where('source_name', $value));
                }),
                AllowedFilter::callback('created_from', fn($q, $v) => $q->where('created_at', '>=', $v)),
                AllowedFilter::callback('created_to', fn($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59')),
                AllowedFilter::callback('expires_from', fn($q, $v) => $q->where('expires_at', '>=', $v)),
                AllowedFilter::callback('expires_to', fn($q, $v) => $q->where('expires_at', '<=', $v . ' 23:59:59')),
                AllowedFilter::callback('has_remark', function ($q, $v) {
                    if ($v) {
                        $q->whereNotNull('remark')->where('remark', '!=', '');
                    } else {
                        $q->where(fn($q2) => $q2->whereNull('remark')->orWhere('remark', ''));
                    }
                }),
                AllowedFilter::callback('sales_person', function ($q, $value) {
                    if ($value === '__none__') {
                        $q->whereHas('customer', fn($c) => $c->whereNull('sales_person')->orWhere('sales_person', ''));
                    } else {
                        $q->whereHas('customer', fn($c) => $c->where('sales_person', $value));
                    }
                }),
                AllowedFilter::callback('product_type', function ($q, $value) {
                    if ($value === 'static') {
                        $q->where(fn($sq) => $sq->whereNull('purchased_module')->orWhere('purchased_module', 'static'));
                    } else {
                        $q->where('purchased_module', $value);
                    }
                }),
            ])
            ->with([
                'customer:id,customer_name,sales_person',
                'proxyIp:id,asset_name,ip_address,port,auth_username,auth_password,country_code,country_name,city,source_name,ip_group_id,asset_group_id,spark_instance_id,ipipv_instance_id',
                'proxyIp.ipGroup:id,name',
                'proxyIp.assetGroup:id,name',
                'forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
                'forwardRule.forwardPlan:id,name,cost_price,base_price,module,pricing_mode',
            ])
            ->allowedSorts(['id', 'expires_at', 'created_at', 'price']);

        // 默认排序：活跃在前，过期/取消/退订在后，同状态内按到期时间升序
        if (!$request->filled('sort')) {
            $query->orderByRaw("FIELD(status, 'active', 'expired', 'refunded', 'cancelled')")
                  ->orderBy('expires_at', 'asc');
        }

        // 数据隔离：无 customer.view_all 权限只看自己名下客户的订阅
        $user = $request->user();
        if ($user && !$user->can('customer.view_all')) {
            $customerIds = Customer::where('sales_person', $user->name)->pluck('id');
            $query->whereIn('customer_id', $customerIds);
        }

        $subscriptions = $query->paginate($request->input('per_page', 15));

        // 为每条活跃/过期订阅附加动态计算的续费价
        $svc = app(\App\Services\SubscriptionService::class);
        $subscriptions->getCollection()->transform(function ($sub) use ($svc) {
            if ($sub->customer && in_array($sub->status, ['active', 'expired'])) {
                $sub->renewal_monthly_price = $svc->calcRenewalMonthlyPrice($sub->customer, $sub);
            }
            return $sub;
        });

        return $this->paginated($subscriptions);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load([
            'customer:id,customer_name,username,phone,email,sales_person,balance',
            'proxyIp.assetGroup:id,name,source_name',
            'proxyIp.ipGroup:id,name,isp_type,net_type',
            'provisionOrder',
            'creator:id,name',
            'forwardRule.deviceGroup:id,name,original_connect_host,custom_connect_host',
            'forwardRule.panel:id,name',
        ]);

        $data = $subscription->toArray();

        if ($subscription->customer && in_array($subscription->status, ['active', 'expired'])) {
            $svc = app(\App\Services\SubscriptionService::class);
            $data['renewal_breakdown'] = $svc->calcRenewalPriceBreakdown(
                $subscription->customer, $subscription
            );
        }

        return $this->success($data);
    }

    public function updateRemark(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate(['remark' => 'nullable|string|max:500']);
        $subscription->update(['remark' => $data['remark']]);
        return $this->success(null, '备注已更新');
    }

    public function renew(Request $request, Subscription $subscription): JsonResponse
    {
        $isExpired = $subscription->status === 'expired';
        if ($subscription->status !== 'active' && !$isExpired) {
            return $this->error('只能续费活跃或近期过期的订阅', 422);
        }
        if ($isExpired && $subscription->expires_at->diffInDays(now()) > 3) {
            return $this->error('该订阅已过期超过3天，无法续费', 422);
        }

        $data = $request->validate([
            'duration'     => 'nullable|integer|min:1',
            'unit'         => 'nullable|integer|in:1,2,3,4',
            'price'        => 'nullable|numeric|min:0',
            'skip_deduct'  => 'nullable|boolean',
        ]);

        $svc = app(\App\Services\SubscriptionService::class);
        $renewDuration = $data['duration'] ?? 1;

        // 未指定价格时自动按当前折扣计算
        $price = $data['price'] ?? null;
        if ($price === null && $subscription->customer) {
            $monthlyPrice = $svc->calcRenewalMonthlyPrice($subscription->customer, $subscription);
            $price = round($monthlyPrice * $renewDuration, 2);
        }

        try {
            $result = $svc->renewOne($subscription, [
                'duration' => $renewDuration,
                'unit' => $data['unit'] ?? 3,
                'price' => $price,
                'skip_deduct' => $data['skip_deduct'] ?? false,
                'reactivate' => $isExpired,
            ], $request->user()?->id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($subscription->customer_id);

        return $this->success($result, $isExpired ? '续费并重新激活成功' : '续费成功');
    }

    /**
     * 批量续费
     * POST /subscriptions/bulk-renew
     * Body: { items: [{ id, price, duration?, unit? }], duration, unit }
     */
    public function bulkRenew(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:subscriptions,id',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.duration' => 'nullable|integer|min:1',
            'items.*.unit' => 'nullable|integer|in:1,2,3,4',
            'duration' => 'nullable|integer|min:1',
            'unit' => 'nullable|integer|in:1,2,3,4',
            'skip_deduct' => 'nullable|boolean',
        ]);

        $defaultDuration = $data['duration'] ?? 1;
        $defaultUnit = $data['unit'] ?? 3;
        $skipDeduct = (bool) ($data['skip_deduct'] ?? false);
        $userId = $request->user()?->id;
        $svc = app(\App\Services\SubscriptionService::class);

        $succeeded = [];
        $failed = [];
        $totalCharged = 0;

        foreach ($data['items'] as $item) {
            $sub = Subscription::with(['customer', 'proxyIp'])->find($item['id']);
            if (!$sub) {
                $failed[] = ['id' => $item['id'], 'reason' => '订阅不存在'];
                continue;
            }
            $isExpired = $sub->status === 'expired';
            if ($sub->status !== 'active' && !$isExpired) {
                $failed[] = ['id' => $item['id'], 'reason' => '订阅非活跃状态'];
                continue;
            }
            if ($isExpired && $sub->expires_at->diffInDays(now()) > 3) {
                $failed[] = ['id' => $item['id'], 'reason' => '已过期超过3天'];
                continue;
            }

            $itemDuration = $item['duration'] ?? $defaultDuration;

            // 未指定价格 → 按当前折扣动态计算
            $itemPrice = $item['price'] ?? null;
            if ($itemPrice === null && $sub->customer) {
                $monthlyPrice = $svc->calcRenewalMonthlyPrice($sub->customer, $sub);
                $itemPrice = round($monthlyPrice * $itemDuration, 2);
            }

            try {
                $renewed = $this->renewOne($sub, [
                    'duration' => $itemDuration,
                    'unit' => $item['unit'] ?? $defaultUnit,
                    'price' => $itemPrice,
                    'skip_deduct' => $skipDeduct,
                    'reactivate' => $isExpired,
                ], $userId);

                $succeeded[] = [
                    'id' => $sub->id,
                    'new_expires_at' => $renewed->expires_at,
                    'charged' => (float) $itemPrice,
                ];
                $totalCharged += (float) $itemPrice;
            } catch (\Exception $e) {
                $failed[] = [
                    'id' => $sub->id,
                    'customer' => $sub->customer?->customer_name,
                    'asset_name' => $sub->proxyIp?->asset_name,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'succeeded' => $succeeded,
            'failed' => $failed,
            'succeeded_count' => count($succeeded),
            'failed_count' => count($failed),
            'total_charged' => round($totalCharged, 2),
        ], sprintf('批量续费完成：成功 %d 条，失败 %d 条', count($succeeded), count($failed)));
    }

    /**
     * 单条续费的内部实现（委托给 SubscriptionService）
     */
    private function renewOne(Subscription $subscription, array $opts, ?int $userId): Subscription
    {
        return app(\App\Services\SubscriptionService::class)->renewOne($subscription, $opts, $userId);
    }

    /**
     * 批量为订阅创建 3x-ui vless+reality 中转（走队列）
     * POST /subscriptions/batch-attach-xui-forward
     *
     * Body: {
     *   subscription_ids: [1,2,3],
     *   xui_panel_id: 5,
     * }
     *
     * 和 NY 不同点：
     *   - 不带 speed / fee 参数（3x-ui 当前流程暂不收转发费）
     *   - 复用 XuiCreateForwardJob 走队列 + xray settings lock
     */
    public function batchAttachXuiForward(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_ids' => 'required|array|min:1|max:5000',
            'subscription_ids.*' => 'integer|exists:subscriptions,id',
            'xui_panel_id' => 'required|integer|exists:xui_panels,id',
        ]);

        $panel = \App\Models\XuiPanel::find($data['xui_panel_id']);
        if (!$panel || !$panel->is_active) {
            return $this->error('3x-ui 面板不可用或未启用', 422);
        }
        if ($panel->is_mirror) {
            return $this->error('不能向备机直接开单，请选主面板', 422);
        }

        $batchId = (string) \Illuminate\Support\Str::uuid();
        $queued = [];
        $skipped = [];

        foreach ($data['subscription_ids'] as $id) {
            $sub = Subscription::with('proxyIp')->find($id);

            if (!$sub) {
                $skipped[] = ['id' => $id, 'reason' => '订阅不存在'];
                continue;
            }
            if ($sub->status !== 'active') {
                $skipped[] = ['id' => $id, 'reason' => '订阅非活跃'];
                continue;
            }
            if (!$sub->proxyIp) {
                $skipped[] = ['id' => $id, 'reason' => '订阅未关联 IP'];
                continue;
            }

            // 跳过已有 active xui 中转的订阅（防重复）
            $hasXui = \App\Models\XuiInbound::where('xui_panel_id', $panel->id)
                ->where('subscription_id', $sub->id)
                ->where('status', 'active')
                ->exists();
            if ($hasXui) {
                $skipped[] = ['id' => $id, 'reason' => '该面板已有此订阅的 xui 中转'];
                continue;
            }

            $remark = $sub->proxyIp->asset_name
                ?: "{$sub->proxyIp->country_name}-{$sub->proxyIp->ip_address}";

            $record = \App\Models\XuiInbound::create([
                'xui_panel_id' => $panel->id,
                'proxy_ip_id' => $sub->proxyIp->id,
                'subscription_id' => $sub->id,
                'remark' => $remark,
                'protocol' => 'vless',
                'server_name' => 'www.intel.com',
                'status' => 'pending',
                'batch_id' => $batchId,
            ]);

            \App\Jobs\XuiCreateForwardJob::dispatch($record->id);
            $queued[] = $record->id;
        }

        return $this->success([
            'batch_id' => $batchId,
            'panel_id' => $panel->id,
            'total_selected' => count($data['subscription_ids']),
            'queued_count' => count($queued),
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
        ], sprintf(
            '已提交到 3x-ui 队列：%d 条（跳过 %d 条）',
            count($queued),
            count($skipped)
        ));
    }

    /**
     * 查询 3x-ui 批次进度
     * GET /subscriptions/batch-xui-forward-status/{batchId}
     */
    public function batchXuiForwardStatus(string $batchId): JsonResponse
    {
        $rows = \App\Models\XuiInbound::where('batch_id', $batchId)
            ->with([
                'subscription.customer:id,customer_name',
                'proxyIp:id,asset_name,ip_address,port',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return $this->error('批次不存在', 404);
        }

        $byStatus = $rows->groupBy('status')->map->count();
        $total = $rows->count();
        $pending = (int) $byStatus->get('pending', 0);
        $processing = (int) $byStatus->get('processing', 0);
        $active = (int) $byStatus->get('active', 0);
        $failed = (int) $byStatus->get('failed', 0);
        $finished = ($pending + $processing) === 0;

        $failedRows = [];
        if ($failed > 0) {
            $failedRows = $rows->where('status', 'failed')->map(fn($r) => [
                'id' => $r->id,
                'subscription_id' => $r->subscription_id,
                'customer' => $r->subscription?->customer?->customer_name,
                'asset_name' => $r->proxyIp?->asset_name,
                'error' => $r->error_message,
            ])->values();
        }

        return $this->success([
            'batch_id' => $batchId,
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'active' => $active,
            'failed' => $failed,
            'finished' => $finished,
            'progress_pct' => $total > 0 ? round(($active + $failed) * 100 / $total, 1) : 0,
            'failed_rules' => $failedRows,
        ]);
    }

    /**
     * 批量修改订阅到期时间
     * POST /subscriptions/batch-update-expiry
     *
     * Body: {
     *   subscription_ids: [1,2,3],
     *   expires_at: "2026-12-31"   // 直接设置到这一天
     * }
     *
     * 用途：管理员手动调整一批订阅的到期日（不扣费、不调用 Spark）
     */
    public function batchUpdateExpiry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_ids' => 'required|array|min:1|max:5000',
            'subscription_ids.*' => 'integer|exists:subscriptions,id',
            'expires_at' => 'required|date',
            'sync_proxy_ip' => 'nullable|boolean', // 是否同步更新 proxy_ips.upstream_expires_at
        ]);

        $expiresAt = \Carbon\Carbon::parse($data['expires_at'])->endOfDay();
        $syncIp = $data['sync_proxy_ip'] ?? true;
        $isFuture = $expiresAt->gt(now());

        $updated = 0;
        $ipsUpdated = 0;

        foreach ($data['subscription_ids'] as $id) {
            $sub = Subscription::find($id);
            if (!$sub) continue;

            $sub->update([
                'expires_at' => $expiresAt,
                'status' => $isFuture ? 'active' : 'expired',
            ]);
            $updated++;

            if ($syncIp && $sub->proxy_ip_id) {
                $ipUpdate = ['upstream_expires_at' => $expiresAt];
                if (!$isFuture) {
                    $ipUpdate['status'] = 'expired';
                }
                \App\Models\ProxyIp::where('id', $sub->proxy_ip_id)->update($ipUpdate);
                $ipsUpdated++;
            }
        }

        // 触发飞书同步（可能涉及多个客户，收集 unique customer_ids）
        $affectedCustomerIds = \App\Models\Subscription::whereIn('id', $data['subscription_ids'])
            ->distinct()->pluck('customer_id')->toArray();
        foreach ($affectedCustomerIds as $cid) {
            \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($cid);
        }

        return $this->success([
            'updated_count' => $updated,
            'ips_updated' => $ipsUpdated,
            'expires_at' => $expiresAt->toIso8601String(),
        ], "已更新 {$updated} 条订阅到期时间");
    }

    /**
     * 批量修改订阅价格（下次续费生效）
     */
    public function batchUpdatePrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_ids' => 'required|array|min:1|max:5000',
            'subscription_ids.*' => 'integer|exists:subscriptions,id',
            'new_price' => 'required|numeric|min:0',
        ]);

        $newPrice = round((float) $data['new_price'], 2);
        $results = [];

        foreach ($data['subscription_ids'] as $id) {
            $sub = Subscription::find($id);
            if (!$sub) continue;

            $durationMonths = \App\Support\DurationHelper::toMonths($sub->duration ?: 1, $sub->unit ?: 3);
            $totalPrice = round($newPrice * max($durationMonths, 1), 2);
            $oldPrice = (float) $sub->price;
            $diff = round($totalPrice - $oldPrice, 2);

            $sub->update([
                'price' => $totalPrice,
                'admin_set_price' => $newPrice,
            ]);

            $diffNote = $diff > 0 ? "+¥{$diff}" : ($diff < 0 ? "-¥" . abs($diff) : '无差价');
            $results[] = [
                'id' => $sub->id,
                'old_price' => $oldPrice,
                'new_price' => $totalPrice,
                'diff' => $diff,
                'diff_note' => $diffNote,
            ];
        }

        return $this->success([
            'updated_count' => count($results),
            'details' => $results,
        ], '已更新 ' . count($results) . ' 条订阅价格，下次续费生效');
    }

    /**
     * 批量为多条订阅创建端口转发
     * POST /subscriptions/batch-attach-forward
     *
     * Body: {
     *   subscription_ids: [1,2,3],
     *   device_group_id: 5,
     *   speed_limit_mbps: 15 | null,
     *   forward_fee: 70
     * }
     */
    public function batchAttachForward(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_ids' => 'required|array|min:1|max:5000',
            'subscription_ids.*' => 'integer|exists:subscriptions,id',
            'forward_plan_id' => 'nullable|integer|exists:forward_plans,id',
            'device_group_id' => 'required|integer|exists:ny_device_groups,id',
            'speed_limit_mbps' => 'nullable|integer|min:1|max:10000',
            'forward_fee' => 'required|numeric|min:0',
            'deduct_balance' => 'nullable|in:current,next',
        ]);

        $forwardPlan = isset($data['forward_plan_id'])
            ? \App\Models\ForwardPlan::find($data['forward_plan_id'])
            : null;

        // 未选中转套餐时，根据 device_group_id 自动推断
        if (!$forwardPlan) {
            $forwardPlan = \App\Models\ForwardPlan::where('device_group_id', $data['device_group_id'])
                ->where('type', 'ny')
                ->where('is_active', 1)
                ->first();
        }

        $deviceGroup = \App\Models\NyDeviceGroup::with('panel')->find($data['device_group_id']);
        if (!$deviceGroup || !$deviceGroup->is_enabled) {
            return $this->error('设备组不可用或未启用', 422);
        }
        if (!$deviceGroup->panel || !$deviceGroup->panel->is_active) {
            return $this->error('设备组所属 NY 面板未启用', 422);
        }

        $speed = $data['speed_limit_mbps'] ?? null;
        $fee = (float) $data['forward_fee'];
        $deductNow = ($data['deduct_balance'] ?? 'next') === 'current';
        $batchId = (string) \Illuminate\Support\Str::uuid();
        $userId = $request->user()?->id;

        $skipped = [];
        $queuedRuleIds = [];
        $totalDeducted = 0;

        foreach ($data['subscription_ids'] as $id) {
            $sub = Subscription::with(['proxyIp', 'customer'])->find($id);

            if (!$sub) {
                $skipped[] = ['id' => $id, 'reason' => '订阅不存在'];
                continue;
            }
            if ($sub->status !== 'active') {
                $skipped[] = ['id' => $id, 'reason' => '订阅非活跃'];
                continue;
            }
            if ($sub->has_forward) {
                $skipped[] = ['id' => $id, 'reason' => '已有转发规则'];
                continue;
            }
            if (!$sub->proxyIp) {
                $skipped[] = ['id' => $id, 'reason' => '订阅未关联 IP'];
                continue;
            }

            // 扣余额（本期费用）
            if ($deductNow && $fee > 0) {
                try {
                    DB::transaction(function () use ($sub, $fee, $userId) {
                        $customer = Customer::lockForUpdate()->findOrFail($sub->customer_id);
                        if ((float) $customer->balance < $fee) {
                            throw new \Exception("余额不足");
                        }
                        $balanceBefore = $customer->balance;
                        $customer->decrement('balance', $fee);
                        $balanceAfter = bcsub($balanceBefore, $fee, 2);

                        if (!$sub->balance_deducted) {
                            $sub->update(['balance_deducted' => true]);
                        }

                        Transaction::create([
                            'customer_id' => $customer->id,
                            'type' => Transaction::TYPE_DEDUCTION,
                            'amount' => -$fee,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $balanceAfter,
                            'related_type' => Subscription::class,
                            'related_id' => $sub->id,
                            'description' => "中转费用（本期）订阅#{$sub->id}",
                            'operated_by' => $userId,
                        ]);
                    });
                    $totalDeducted += $fee;
                } catch (\Exception $e) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => "扣费失败：{$e->getMessage()}（客户：{$sub->customer?->customer_name}）",
                    ];
                    continue;
                }
            }

            $rule = \App\Models\ForwardRule::create([
                'subscription_id' => $sub->id,
                'proxy_ip_id' => $sub->proxyIp->id,
                'ny_panel_id' => $deviceGroup->ny_panel_id,
                'ny_device_group_id' => $deviceGroup->id,
                'forward_plan_id' => $forwardPlan?->id,
                'name' => sprintf(
                    'SNP-S%d-%s:%d',
                    $sub->id,
                    $sub->proxyIp->ip_address,
                    $sub->proxyIp->port
                ),
                'dest_host' => $sub->proxyIp->ip_address,
                'dest_port' => (int) $sub->proxyIp->port,
                'speed_limit_mbps' => $speed,
                'forward_fee' => $fee,
                'status' => 'pending',
                'batch_id' => $batchId,
            ]);

            \App\Jobs\AttachForwardJob::dispatch($rule->id);
            $queuedRuleIds[] = $rule->id;

            if ($deductNow && $fee > 0 && $sub->customer) {
                try {
                    $fwdListPrice = $forwardPlan ? (float) $forwardPlan->base_price : 0;
                    $remainDays = max(0, now()->diffInDays($sub->expires_at, false));
                    $fwdTotalListPrice = $fwdListPrice > 0 ? round($fwdListPrice * $remainDays / 30, 2) : 0;
                    $module = $forwardPlan?->module ?? ($sub->purchased_module ?: 'static');
                    $productCtx = ['module' => $module];
                    $referralService = app(\App\Services\ReferralService::class);
                    $referralService->processCommission($sub->customer, 'forward', $fee, $sub->id, $fwdTotalListPrice ?: $fee, $productCtx);
                } catch (\Throwable $e) {
                    Log::warning('Forward commission failed', [
                        'subscription_id' => $sub->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $msg = sprintf('已提交到队列：%d 条（跳过 %d 条）。', count($queuedRuleIds), count($skipped));
        if ($deductNow && $totalDeducted > 0) {
            $msg .= sprintf(' 已扣费合计 ¥%.2f。', $totalDeducted);
        }

        return $this->success([
            'batch_id' => $batchId,
            'total_selected' => count($data['subscription_ids']),
            'queued_count' => count($queuedRuleIds),
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
            'total_deducted' => round($totalDeducted, 2),
        ], $msg);
    }

    /**
     * 查询批量转发进度
     * GET /subscriptions/batch-forward-status/{batchId}
     */
    public function batchForwardStatus(string $batchId): JsonResponse
    {
        $rules = \App\Models\ForwardRule::where('batch_id', $batchId)
            ->with(['subscription.customer:id,customer_name', 'proxyIp:id,asset_name,ip_address,port'])
            ->get();

        if ($rules->isEmpty()) {
            return $this->error('批次不存在', 404);
        }

        $byStatus = $rules->groupBy('status')->map->count();

        $total = $rules->count();
        $pending = (int) $byStatus->get('pending', 0);
        $processing = (int) $byStatus->get('processing', 0);
        $active = (int) $byStatus->get('active', 0);
        $failed = (int) $byStatus->get('failed', 0);
        $deleted = (int) $byStatus->get('deleted', 0);

        $finished = ($pending + $processing) === 0;

        $failedRules = [];
        if ($failed > 0) {
            $failedRules = $rules->where('status', 'failed')->map(fn($r) => [
                'id' => $r->id,
                'subscription_id' => $r->subscription_id,
                'customer' => $r->subscription?->customer?->customer_name,
                'asset_name' => $r->proxyIp?->asset_name,
                'error' => $r->error_message,
            ])->values();
        }

        return $this->success([
            'batch_id' => $batchId,
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'active' => $active,
            'failed' => $failed,
            'deleted' => $deleted,
            'finished' => $finished,
            'progress_pct' => $total > 0 ? round(($active + $failed) * 100 / $total, 1) : 0,
            'failed_rules' => $failedRules,
        ]);
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        if ($subscription->status !== 'active') {
            return $this->error('只能取消状态为激活的订阅', 422);
        }

        $data = $request->validate([
            'reverse_commission' => 'nullable|boolean',
        ]);
        $reverseCommission = $data['reverse_commission'] ?? true;

        $proxyIp = $subscription->proxyIp;
        $userId = $request->user()?->id;

        // ── 事务外：上游 API 释放（止损，避免续费继续扣币）──
        $sparkResult = null;
        $ipipvResult = null;

        if ($proxyIp && $proxyIp->spark_instance_id) {
            $sparkResult = \App\Services\SparkReleaseService::releaseInstance($proxyIp, 'admin_cancel');
        }

        if ($proxyIp && $proxyIp->ipipv_instance_id) {
            try {
                $ipipv = app(\App\Services\IpipvApiService::class);
                $orderNo = \App\Models\IpipvOrder::generateAppOrderNo();
                $ipipv->releaseProxy($orderNo, [$proxyIp->ipipv_instance_id]);
                \App\Models\IpipvOrder::create([
                    'app_order_no' => $orderNo,
                    'method' => 'release',
                    'status' => 1,
                    'request_data' => ['reason' => '管理后台取消订阅', 'proxy_ip_id' => $proxyIp->id],
                    'response_data' => [],
                ]);
                $ipipvResult = ['status' => 'confirmed'];
            } catch (\Throwable $e) {
                \Log::warning('cancel: IPIPV release failed', [
                    'proxy_ip_id' => $proxyIp->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($subscription, $proxyIp, $userId, $reverseCommission) {
            $subscription->update([
                'status' => 'cancelled',
                'keep_performance' => !$reverseCommission,
            ]);

            if ($proxyIp && $proxyIp->status === 'assigned') {
                $proxyIp->update([
                    'status'               => 'released',
                    'assigned_customer_id' => null,
                    'released_at'          => now(),
                    'release_reason'       => '管理后台取消订阅',
                    'released_by'          => $userId,
                ]);
            }

            IpAssignmentLog::create([
                'proxy_ip_id'    => $subscription->proxy_ip_id,
                'customer_id'    => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'action'         => 'unassign',
                'operated_by'    => $userId,
                'remark'         => '取消订阅释放IP',
                'created_at'     => now(),
            ]);

            if ($reverseCommission) {
                try {
                    app(\App\Services\ReferralService::class)
                        ->reverseCommissions($subscription->customer_id, $subscription->id);
                } catch (\Throwable $e) {
                    \Log::warning('cancel: commission reversal failed', [
                        'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        // 事务外删除 NY 转发
        if ($subscription->fresh()->has_forward) {
            try {
                app(\App\Services\Ny\NyForwardService::class)
                    ->deleteForSubscription($subscription->fresh());
            } catch (\Throwable $e) {
                \Log::warning('cancel: delete forward failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 事务外删除 3x-ui 中转（如果有）
        try {
            app(\App\Services\Xui\XuiForwardService::class)
                ->deleteForSubscription($subscription->fresh());
        } catch (\Throwable $e) {
            \Log::warning('cancel: delete xui forward failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($subscription->customer_id);

        $releaseMsg = '';
        if ($sparkResult) $releaseMsg .= '，Spark: ' . $sparkResult['status'];
        if ($ipipvResult) $releaseMsg .= '，IPIPV: 已释放';

        return $this->success([
            'spark_release' => $sparkResult,
            'ipipv_release' => $ipipvResult,
        ], '订阅已取消，IP已释放' . $releaseMsg);
    }

    /**
     * 退订 - 客户1天内可退订，释放IP+退款
     * POST /subscriptions/{id}/refund
     */
    public function refund(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            'refund_amount' => 'nullable|numeric|min:0',
            'release_upstream' => 'nullable|boolean',
            'reverse_commission' => 'nullable|boolean',
        ]);

        if (!in_array($subscription->status, ['active', 'expired'])) {
            return $this->error('只能退订状态为激活或已过期的订阅', 422);
        }

        $refundAmount = $data['refund_amount'] ?? $subscription->price;
        $proxyIp = $subscription->proxyIp;
        $userId = $request->user()->id;
        $releaseUpstream = $data['release_upstream'] ?? true;
        $reverseCommission = $data['reverse_commission'] ?? true;

        // ── 第1步：按选择决定是否调用上游释放 ──
        $sparkResult = null;
        $ipipvResult = null;

        if ($releaseUpstream) {
            if ($proxyIp && $proxyIp->spark_instance_id) {
                $sparkResult = \App\Services\SparkReleaseService::releaseInstance($proxyIp, 'admin_refund');
                if ($sparkResult['status'] === 'failed') {
                    return $this->error('Spark API 释放失败，退订中止: ' . $sparkResult['message'], 422);
                }
            }

            if ($proxyIp && $proxyIp->ipipv_instance_id) {
                try {
                    $ipipv = app(\App\Services\IpipvApiService::class);
                    $orderNo = \App\Models\IpipvOrder::generateAppOrderNo();
                    $ipipv->releaseProxy($orderNo, [$proxyIp->ipipv_instance_id]);
                    \App\Models\IpipvOrder::create([
                        'app_order_no' => $orderNo,
                        'method' => 'release',
                        'status' => 1,
                        'request_data' => ['reason' => '管理后台退订', 'proxy_ip_id' => $proxyIp->id],
                        'response_data' => [],
                    ]);
                    $ipipvResult = ['status' => 'confirmed'];
                } catch (\Throwable $e) {
                    return $this->error('IPIPV API 释放失败，退订中止: ' . $e->getMessage(), 422);
                }
            }
        }

        // ── 第2步：清理转发规则 ──
        $forwardDeleted = 0;
        if ($subscription->has_forward) {
            try {
                $forwardDeleted = app(\App\Services\Ny\NyForwardService::class)
                    ->deleteForSubscription($subscription);
            } catch (\Throwable $e) {
                \Log::warning('refund: delete forward failed', [
                    'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
                ]);
            }
        }
        try {
            app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($subscription);
        } catch (\Throwable $e) {
            \Log::warning('refund: delete xui forward failed', [
                'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
            ]);
        }

        // ── 第3步：API 释放成功，执行退订+退款事务 ──
        DB::transaction(function () use ($subscription, $data, $refundAmount, $userId, $proxyIp, $sparkResult, $releaseUpstream, $reverseCommission) {
            $subscription->update([
                'status' => 'refunded',
                'keep_performance' => !$reverseCommission,
                'refunded_at' => now(),
                'refund_reason' => $data['reason'] ?? '客户退订',
                'refund_amount' => $refundAmount,
                'refunded_by' => $userId,
            ]);

            if ($refundAmount > 0) {
                $customer = $subscription->customer()->lockForUpdate()->first();
                if ($customer) {
                    $balanceBefore = $customer->balance;
                    $customer->increment('balance', $refundAmount);
                    Transaction::create([
                        'customer_id' => $customer->id,
                        'type' => Transaction::TYPE_REFUND,
                        'amount' => $refundAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $customer->balance,
                        'related_type' => Subscription::class,
                        'related_id' => $subscription->id,
                        'description' => "退订 #{$subscription->id}: " . ($data['reason'] ?? '客户退订'),
                        'operated_by' => $userId,
                    ]);
                }
            }

            $ipStillAssigned = $proxyIp && (int) $proxyIp->assigned_customer_id === (int) $subscription->customer_id;
            if ($proxyIp && $ipStillAssigned) {
                if ($releaseUpstream) {
                    $proxyIp->update([
                        'assigned_customer_id' => null,
                        'status' => 'released',
                        'released_at' => now(),
                        'release_reason' => '客户退订: ' . ($data['reason'] ?? ''),
                        'released_by' => $userId,
                    ]);
                } else {
                    $proxyIp->update([
                        'assigned_customer_id' => null,
                        'status' => 'available',
                        'is_test_pool' => true,
                        'test_pool_added_at' => now(),
                        'test_pool_added_by' => $userId,
                        'test_pool_reason' => '退订未释放上游-回收至测试池',
                    ]);
                }

                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $subscription->customer_id,
                    'subscription_id' => $subscription->id,
                    'action' => 'unassign',
                    'operated_by' => $userId,
                    'remark' => $releaseUpstream ? '客户退订(已释放上游)' : '客户退订(未释放上游, IP入测试池)',
                    'created_at' => now(),
                ]);
            }

            if ($reverseCommission) {
                try {
                    app(\App\Services\ReferralService::class)
                        ->reverseCommissions($subscription->customer_id, $subscription->id);
                } catch (\Throwable $e) {
                    \Log::warning('refund: commission reversal failed', [
                        'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($subscription->customer_id);

        $releaseMsg = '';
        if (!$releaseUpstream) {
            $releaseMsg .= '，IP已回收至测试池（未释放上游）';
        } else {
            if ($sparkResult) $releaseMsg .= '，Spark: ' . $sparkResult['status'];
            if ($ipipvResult) $releaseMsg .= '，IPIPV: 已释放';
        }

        return $this->success([
            'refunded' => true,
            'forward_deleted' => $forwardDeleted,
            'spark_release' => $sparkResult,
            'ipipv_release' => $ipipvResult,
            'test_pool' => !$releaseUpstream,
        ], ($refundAmount > 0 ? '退订成功，已退款到客户余额' : '退订成功，未退款') . $releaseMsg);
    }

    /**
     * 部分退款（按剩余整月）
     * POST /subscriptions/{id}/partial-refund
     */
    public function partialRefund(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            'confirm' => 'nullable|boolean',
        ]);

        if ($subscription->status !== 'active') {
            return $this->error('只能对活跃订阅执行部分退款', 422);
        }

        $totalMonths = match ((int) $subscription->unit) {
            3 => $subscription->duration,
            4 => $subscription->duration * 12,
            default => null,
        };
        if (!$totalMonths || $totalMonths < 2) {
            return $this->error('部分退款仅支持 2 个月及以上的订阅', 422);
        }

        $expiresAt = Carbon::parse($subscription->expires_at);
        $cycleStart = $expiresAt->copy()->subMonths($totalMonths);
        $now = now();

        $monthsElapsed = (int) $cycleStart->diffInMonths($now);
        $currentMonth = min($monthsElapsed + 1, $totalMonths);
        $refundableMonths = $totalMonths - $currentMonth;

        if ($refundableMonths <= 0) {
            return $this->error('没有可退的剩余整月（当前已在最后一个月）', 422);
        }

        $monthlyPrice = round($subscription->price / $totalMonths, 2);
        $refundAmount = round($monthlyPrice * $refundableMonths, 2);
        $newExpiresAt = $cycleStart->copy()->addMonths($currentMonth);

        $preview = [
            'total_months' => $totalMonths,
            'current_month' => $currentMonth,
            'refundable_months' => $refundableMonths,
            'monthly_price' => $monthlyPrice,
            'refund_amount' => $refundAmount,
            'new_expires_at' => $newExpiresAt->toDateTimeString(),
            'original_expires_at' => $subscription->expires_at,
        ];

        if (!($data['confirm'] ?? false)) {
            return $this->success($preview, '部分退款预览');
        }

        $userId = $request->user()->id;
        $reason = $data['reason'] ?? "部分退款：退还{$refundableMonths}个月";

        DB::transaction(function () use ($subscription, $refundAmount, $refundableMonths, $newExpiresAt, $reason, $userId, $currentMonth, $totalMonths, $monthlyPrice) {
            $customer = Customer::lockForUpdate()->find($subscription->customer_id);

            // 1. 退款到客户余额
            $balanceBefore = (float) $customer->balance;
            $customer->increment('balance', $refundAmount);

            Transaction::create([
                'customer_id' => $customer->id,
                'type' => Transaction::TYPE_REFUND,
                'amount' => $refundAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore + $refundAmount,
                'related_type' => Subscription::class,
                'related_id' => $subscription->id,
                'description' => "部分退订 #{$subscription->id}：退还{$refundableMonths}个月 ¥{$refundAmount} ({$reason})",
                'operated_by' => $userId,
            ]);

            // 2. 缩短订阅到当月月底
            $subscription->update([
                'expires_at' => $newExpiresAt,
                'refund_amount' => $refundAmount,
                'refund_reason' => $reason,
                'refunded_at' => now(),
                'refunded_by' => $userId,
            ]);

            // 3. 同步 proxy_ip 上游到期时间
            if ($subscription->proxyIp) {
                $subscription->proxyIp->update(['upstream_expires_at' => $newExpiresAt]);
            }

            // 4. 创建负数佣金记录（退款当天扣除业绩，不修改原记录）
            $this->createNegativeCommissions($subscription, $refundAmount, $refundableMonths, $totalMonths, $reason, $userId);
        });

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($subscription->customer_id);

        return $this->success(array_merge($preview, ['refunded' => true]),
            "部分退款成功：退还{$refundableMonths}个月 ¥{$refundAmount}，订阅将于 {$newExpiresAt->toDateString()} 到期");
    }

    private function createNegativeCommissions(Subscription $subscription, float $refundAmount, int $refundableMonths, int $totalMonths, string $reason, int $userId): void
    {
        $proportion = $refundableMonths / $totalMonths;

        // 推荐返佣：按比例创建负记录
        $referralCommissions = ReferralCommission::where('referee_id', $subscription->customer_id)
            ->where('trigger_id', $subscription->id)
            ->whereIn('status', ['pending', 'credited'])
            ->get();

        foreach ($referralCommissions as $rc) {
            $deductAmount = round($rc->commission_amount * $proportion, 2);
            if ($deductAmount < 0.01) continue;

            ReferralCommission::create([
                'referrer_id' => $rc->referrer_id,
                'referee_id' => $rc->referee_id,
                'trigger_type' => 'partial_refund',
                'trigger_id' => $subscription->id,
                'trigger_amount' => $refundAmount,
                'commission_rate' => $rc->commission_rate,
                'commission_amount' => -$deductAmount,
                'status' => 'credited',
                'credited_at' => now(),
            ]);

            // 从推荐人的返佣余额中扣除
            $referrer = Customer::find($rc->referrer_id);
            if ($referrer) {
                $balBefore = (float) $referrer->commission_balance;
                $deduct = min($deductAmount, max(0, $balBefore));
                if ($deduct > 0) {
                    $referrer->decrement('commission_balance', $deduct);
                    Transaction::create([
                        'customer_id' => $referrer->id,
                        'type' => Transaction::TYPE_COMMISSION_REVERSAL,
                        'amount' => -$deduct,
                        'balance_before' => $balBefore,
                        'balance_after' => $balBefore - $deduct,
                        'description' => "部分退订返佣扣减 (订阅 #{$subscription->id} 退还{$refundableMonths}个月)",
                        'operated_by' => $userId,
                    ]);
                }
            }
        }

        // 销售提成：按比例创建负记录
        $salesCommissions = SalesCommission::where('customer_id', $subscription->customer_id)
            ->where('trigger_id', $subscription->id)
            ->whereIn('status', ['pending', 'credited'])
            ->get();

        foreach ($salesCommissions as $sc) {
            $deductAmount = round($sc->commission_amount * $proportion, 2);
            if ($deductAmount < 0.01) continue;

            SalesCommission::create([
                'user_id' => $sc->user_id,
                'customer_id' => $sc->customer_id,
                'level' => $sc->level,
                'trigger_type' => 'partial_refund',
                'trigger_id' => $subscription->id,
                'trigger_amount' => $refundAmount,
                'commission_rate' => $sc->commission_rate,
                'commission_amount' => -$deductAmount,
                'status' => 'credited',
                'credited_at' => now(),
            ]);

            // 从销售的提成余额中扣除
            $user = \App\Models\User::find($sc->user_id);
            if ($user) {
                $deduct = min($deductAmount, max(0, (float) ($user->commission_balance ?? 0)));
                if ($deduct > 0) {
                    $user->decrement('commission_balance', $deduct);
                }
            }
        }
    }

    /**
     * 测试订单转正
     * POST /subscriptions/{id}/convert-test
     */
    public function convertTest(Request $request, Subscription $subscription): JsonResponse
    {
        if (!$subscription->is_test) {
            return $this->error('该订阅不是测试订单', 422);
        }
        if (!in_array($subscription->status, ['active', 'expired'])) {
            return $this->error('只能转正状态为活跃或已过期的测试订阅', 422);
        }

        $data = $request->validate([
            'duration' => 'required|integer|min:1',
            'unit' => 'required|integer|in:3',
            'price' => 'required|numeric|min:0',
            'charge_customer' => 'required|boolean',
        ]);

        $duration = $data['duration'];
        $unit = $data['unit'];
        $price = (float) $data['price'];
        $chargeCustomer = $data['charge_customer'];
        $totalCharge = round($price * $duration, 2);

        $startedAt = $subscription->started_at ?? now();
        $expiresAt = \App\Support\DurationHelper::addToDate($startedAt, $duration, $unit);

        DB::transaction(function () use ($subscription, $data, $price, $chargeCustomer, $startedAt, $expiresAt, $duration, $unit, $request, $totalCharge) {
            $existingRemark = trim($subscription->remark ?? '');
            $convertNote = '[系统] 测试转正 ' . now()->format('m-d H:i');
            $newRemark = $existingRemark ? $existingRemark . "\n" . $convertNote : $convertNote;

            $updateData = [
                'is_test' => false,
                'test_reclaim_at' => null,
                'price' => $totalCharge,
                'admin_set_price' => $price,
                'duration' => $duration,
                'unit' => $unit,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'balance_deducted' => $chargeCustomer && $totalCharge > 0,
                'remark' => $newRemark,
            ];

            // 补全 sales_cost / list_price（测试订阅创建时可能未设置）
            $proxyIp = $subscription->proxyIp;
            if ($proxyIp) {
                $sparkInstance = \App\Models\SparkInstance::where('proxy_ip_id', $proxyIp->id)->first();
                $productId = $sparkInstance?->sparkOrder?->product_id;
                if ($productId) {
                    $products = \App\Services\SparkStockCacheService::products();
                    $sparkProduct = collect($products)->firstWhere('product_id', $productId);
                    if ($sparkProduct) {
                        if (!$subscription->sales_cost) {
                            $updateData['sales_cost'] = \App\Models\PricingMultiplier::calcSalesPrice($sparkProduct);
                        }
                        if (!$subscription->list_price) {
                            $updateData['list_price'] = \App\Models\PricingMultiplier::calcSalePrice($sparkProduct);
                        }
                        if (!$subscription->hard_cost && !empty($sparkProduct['cost_price'])) {
                            $updateData['hard_cost'] = (float) $sparkProduct['cost_price'];
                        }
                    }
                }
            }

            $subscription->update($updateData);

            if ($chargeCustomer && $totalCharge > 0) {
                $customer = $subscription->customer()->lockForUpdate()->first();
                if (!$customer) {
                    throw new \Exception('客户不存在');
                }
                if ($customer->balance < $totalCharge) {
                    throw new \Exception("客户余额不足（余额 ¥{$customer->balance}，需 ¥{$totalCharge}）");
                }
                $balanceBefore = $customer->balance;
                $customer->decrement('balance', $totalCharge);

                Transaction::create([
                    'customer_id' => $customer->id,
                    'type' => Transaction::TYPE_DEDUCTION,
                    'amount' => -$totalCharge,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $customer->balance,
                    'related_type' => Subscription::class,
                    'related_id' => $subscription->id,
                    'description' => "测试转正 #{$subscription->id}（{$duration}个月 × ¥{$price}/月）",
                    'operated_by' => $request->user()->id,
                ]);
            }

            // 同步 ProxyIp 到期时间
            if ($subscription->proxy_ip_id) {
                ProxyIp::withTrashed()->where('id', $subscription->proxy_ip_id)
                    ->update(['upstream_expires_at' => $expiresAt]);
            }
        });

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($subscription->customer_id);

        return $this->success($subscription->fresh()->load(['customer', 'proxyIp']),
            '测试订单已转正' . ($chargeCustomer && $totalCharge > 0 ? "，已扣款 ¥{$totalCharge}" : '，未扣款'));
    }

    /**
     * 降级：移除中转服务，退还剩余中转费差价到客户余额
     * POST /subscriptions/{subscription}/downgrade
     */
    public function downgrade(Request $request, Subscription $subscription): JsonResponse
    {
        if ($subscription->status !== 'active') {
            return $this->error('只能降级状态为活跃的订阅', 422);
        }

        if (!$subscription->has_forward) {
            return $this->error('该订阅没有中转服务，无法降级', 422);
        }

        $forwardRule = $subscription->forwardRule;
        if (!$forwardRule) {
            return $this->error('找不到中转规则', 422);
        }

        $data = $request->validate([
            'refund_to_balance' => 'required|boolean',
            'refund_amount' => 'nullable|numeric|min:0',
        ]);

        $forwardFee = (float) $forwardRule->forward_fee;
        $remainingDays = max(0, (int) ceil(now()->floatDiffInRealDays($subscription->expires_at)));
        $defaultRefund = round($forwardFee / 30 * $remainingDays, 2);
        $refundAmount = $data['refund_to_balance']
            ? round($data['refund_amount'] ?? $defaultRefund, 2)
            : 0;

        $userId = $request->user()->id;

        // ── 第1步：清理转发规则（事务外，调用远端 API）──
        $forwardDeleted = 0;
        try {
            $forwardDeleted = app(\App\Services\Ny\NyForwardService::class)
                ->deleteForSubscription($subscription);
        } catch (\Throwable $e) {
            \Log::warning('downgrade: delete ny forward failed', [
                'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
            ]);
        }
        try {
            app(\App\Services\Xui\XuiForwardService::class)->deleteForSubscription($subscription);
        } catch (\Throwable $e) {
            \Log::warning('downgrade: delete xui forward failed', [
                'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
            ]);
        }

        // ── 第2步：事务内更新订阅 + 退差价 ──
        DB::transaction(function () use ($subscription, $forwardRule, $forwardFee, $refundAmount, $remainingDays, $userId) {
            $subscription->update([
                'purchased_module' => 'static',
                'has_forward' => false,
            ]);

            $forwardRule->update(['status' => 'deleted']);

            if ($refundAmount > 0) {
                $customer = $subscription->customer()->lockForUpdate()->first();
                $balanceBefore = $customer->balance;
                $customer->increment('balance', $refundAmount);

                Transaction::create([
                    'customer_id' => $customer->id,
                    'type' => Transaction::TYPE_REFUND,
                    'amount' => $refundAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore + $refundAmount,
                    'related_type' => Subscription::class,
                    'related_id' => $subscription->id,
                    'description' => "降级退差价 #{$subscription->id}: 中转费 ¥{$forwardFee}/月, 剩余{$remainingDays}天",
                    'operated_by' => $userId,
                ]);
            }
        });

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($subscription->customer_id);

        $msg = '降级成功，已移除中转服务';
        if ($refundAmount > 0) {
            $msg .= "，已退 ¥{$refundAmount} 到客户余额";
        }

        return $this->success([
            'subscription' => $subscription->fresh()->load(['customer', 'proxyIp']),
            'forward_deleted' => $forwardDeleted,
            'refund_amount' => $refundAmount,
        ], $msg);
    }

    public function expiring(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 7);

        $subscriptions = Subscription::with(['customer', 'proxyIp'])
            ->expiringSoon($days)
            ->orderBy('expires_at')
            ->paginate($request->input('per_page', 15));

        return $this->paginated($subscriptions);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id'              => 'required|exists:customers,id',
            'items'                    => 'required|array|min:1',
            'items.*.ip_group_id'      => 'required|exists:ip_groups,id',
            'items.*.asset_group_id'   => 'nullable|exists:ip_asset_groups,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'nullable|numeric|min:0',
            'items.*.hard_cost'        => 'nullable|numeric|min:0',
            'items.*.duration'         => 'nullable|integer|min:1',
            'items.*.unit'             => 'nullable|integer|in:1,2,3,4',
            'remark'                   => 'nullable|string|max:500',
            'payment_method'           => 'nullable|in:offline,balance',
        ]);

        $customerId = $data['customer_id'];
        $paymentMethod = $data['payment_method'] ?? 'offline';
        $items = $data['items'];

        // Pre-check: resolve prices and check IP availability before entering the transaction
        $resolvedItems = [];
        foreach ($items as $index => $item) {
            $ipGroupId = $item['ip_group_id'];
            $assetGroupId = $item['asset_group_id'] ?? null;
            $quantity = $item['quantity'];
            $duration = $item['duration'] ?? 1;
            $unit = $item['unit'] ?? 3;

            // Resolve unit price
            $unitPrice = $item['unit_price'] ?? null;
            if ($unitPrice === null) {
                $pricingRule = PricingRule::where('ip_group_id', $ipGroupId)
                    ->where('is_active', 1)
                    ->first();

                if (!$pricingRule) {
                    return $this->error("第 " . ($index + 1) . " 项: IP组(id={$ipGroupId})未找到定价规则", 422);
                }
                $unitPrice = $pricingRule->price;
            }

            // Check available IPs
            $query = ProxyIp::where('ip_group_id', $ipGroupId)
                ->where('status', 'available');
            if ($assetGroupId) {
                $query->where('asset_group_id', $assetGroupId);
            }
            $availableCount = $query->count();

            if ($availableCount < $quantity) {
                return $this->error(
                    "第 " . ($index + 1) . " 项: 可用IP不足，需要 {$quantity} 个，当前可用 {$availableCount} 个",
                    422
                );
            }

            $resolvedItems[] = [
                'ip_group_id'    => $ipGroupId,
                'asset_group_id' => $assetGroupId,
                'quantity'       => $quantity,
                'unit_price'     => $unitPrice,
                'hard_cost'      => isset($item['hard_cost']) ? (float) $item['hard_cost'] : null,
                'duration'       => $duration,
                'unit'           => $unit,
            ];
        }

        $order = DB::transaction(function () use ($customerId, $resolvedItems, $data, $request, $paymentMethod) {
            $totalAmount = 0;
            foreach ($resolvedItems as $item) {
                $durationMonths = \App\Support\DurationHelper::toMonths($item['duration'], $item['unit']);
                $itemTotal = bcmul(bcmul($item['unit_price'], $item['quantity'], 2), max($durationMonths, 1), 2);
                $totalAmount = bcadd($totalAmount, $itemTotal, 2);
            }

            if ($paymentMethod === 'balance') {
                $customer = Customer::lockForUpdate()->findOrFail($customerId);
                if ((float) $customer->balance < (float) $totalAmount) {
                    throw new \Exception("客户余额不足（当前 ¥{$customer->balance}，需要 ¥{$totalAmount}）");
                }
                $balanceBefore = (float) $customer->balance;
                $customer->decrement('balance', $totalAmount);
                $balanceAfter = bcsub($balanceBefore, $totalAmount, 2);

                $purchaseTxn = Transaction::create([
                    'customer_id'    => $customerId,
                    'type'           => Transaction::TYPE_PURCHASE,
                    'amount'         => -$totalAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'description'    => "开通订单扣费 ¥{$totalAmount}",
                    'operated_by'    => $request->user()?->id,
                ]);
            }

            // Create ProvisionOrder
            $order = ProvisionOrder::create([
                'order_no'     => ProvisionOrder::generateOrderNo(),
                'customer_id'  => $customerId,
                'status'       => 'completed',
                'total_amount' => $totalAmount,
                'remark'       => $data['remark'] ?? null,
                'created_by'   => $request->user()?->id,
            ]);

            $allSubscriptions = [];

            foreach ($resolvedItems as $item) {
                $ipGroupId = $item['ip_group_id'];
                $assetGroupId = $item['asset_group_id'];
                $quantity = $item['quantity'];
                $unitPrice = $item['unit_price'];
                $itemHardCost = $item['hard_cost'];
                $duration = $item['duration'];
                $unit = $item['unit'];

                // Find available IPs with lock
                $ipQuery = ProxyIp::where('ip_group_id', $ipGroupId)
                    ->where('status', 'available')
                    ->lockForUpdate();
                if ($assetGroupId) {
                    $ipQuery->where('asset_group_id', $assetGroupId);
                }
                $ips = $ipQuery->limit($quantity)->get();

                if ($ips->count() < $quantity) {
                    throw new \Exception("IP组(id={$ipGroupId})可用IP不足，需要 {$quantity} 个，当前可用 {$ips->count()} 个");
                }

                // Create ProvisionOrderItem
                $durationMonths = \App\Support\DurationHelper::toMonths($duration, $unit);
                $subtotal = bcmul(bcmul($unitPrice, $quantity, 2), max($durationMonths, 1), 2);
                $firstIp = $ips->first();

                ProvisionOrderItem::create([
                    'order_id'     => $order->id,
                    'asset_group_id' => $assetGroupId ?? $firstIp->asset_group_id,
                    'country_code' => $firstIp->country_code ?? '',
                    'country_name' => $firstIp->country_name ?? '',
                    'city'         => $firstIp->city,
                    'quantity'     => $quantity,
                    'duration'     => $duration,
                    'unit'         => $unit,
                    'unit_price'   => $unitPrice,
                    'subtotal'     => $subtotal,
                    'status'       => 'completed',
                ]);

                $now = now();
                $defaultExpiresAt = \App\Support\DurationHelper::addToDate($now, $duration, $unit);

                foreach ($ips as $ip) {
                    // Update proxy IP status
                    $ip->update([
                        'status'               => 'assigned',
                        'assigned_customer_id' => $customerId,
                    ]);

                    // 外部导入IP有自身上游到期时间时，订阅到期同步到IP（逐条独立计算）
                    $ipExpiresAt = ($ip->upstream_expires_at && $ip->upstream_expires_at->isFuture())
                        ? $ip->upstream_expires_at
                        : $defaultExpiresAt;

                    $durationMonths = \App\Support\DurationHelper::toMonths($duration, $unit);
                    $totalPrice = round($unitPrice * max($durationMonths, 1), 2);

                    // 自动从上游产品目录解析成本
                    $hardCost = $itemHardCost;
                    $salesCost = null;
                    $listPrice = null;
                    if ($ip->source_name === 'Spark') {
                        $sparkInst = \App\Models\SparkInstance::where('proxy_ip_id', $ip->id)->first();
                        $productId = $sparkInst?->sparkOrder?->product_id;
                        if ($productId) {
                            $sparkProduct = collect(\App\Services\SparkStockCacheService::products())->firstWhere('product_id', $productId);
                            if ($sparkProduct) {
                                if ($hardCost === null) $hardCost = (float) ($sparkProduct['cost_price'] ?? 0) ?: null;
                                $salesCost = \App\Models\PricingMultiplier::calcSalesPrice($sparkProduct);
                                $listPrice = \App\Models\PricingMultiplier::calcSalePrice($sparkProduct);
                            }
                        }
                    } elseif ($ip->ipipv_instance_id) {
                        $ipipvInst = \App\Models\IpipvInstance::where('instance_no', $ip->ipipv_instance_id)->first();
                        $productNo = $ipipvInst?->product_no ?? $ipipvInst?->ipipvOrder?->product_no;
                        if ($productNo) {
                            $ipipvProduct = collect(\App\Services\IpipvStockCacheService::products())->firstWhere('productNo', $productNo);
                            if ($ipipvProduct) {
                                $costOverride = \App\Models\SystemConfig::get('cost.ipipv_hard_cost_override');
                                if ($hardCost === null) {
                                    $hardCost = ($costOverride !== null && (float) $costOverride > 0)
                                        ? (float) $costOverride
                                        : ((float) ($ipipvProduct['unitPrice'] ?? 0) ?: null);
                                }
                                $productArr = ['cost_price' => $hardCost, 'source' => 'ipipv'];
                                $salesCost = \App\Models\PricingMultiplier::calcSalesPrice($productArr);
                                $listPrice = \App\Models\PricingMultiplier::calcSalePrice($productArr);
                            }
                        }
                    }

                    $subscription = Subscription::create([
                        'customer_id'       => $customerId,
                        'proxy_ip_id'       => $ip->id,
                        'provision_order_id' => $order->id,
                        'price'             => $totalPrice,
                        'admin_set_price'   => $unitPrice,
                        'list_price'        => $listPrice,
                        'sales_cost'        => $salesCost,
                        'hard_cost'         => $hardCost,
                        'duration'          => $duration,
                        'unit'              => $unit,
                        'started_at'        => $now,
                        'expires_at'        => $ipExpiresAt,
                        'status'            => 'active',
                        'created_by'        => $request->user()?->id,
                        'balance_deducted'  => $paymentMethod === 'balance',
                    ]);

                    $allSubscriptions[] = $subscription;

                    // Create assignment log
                    IpAssignmentLog::create([
                        'proxy_ip_id'     => $ip->id,
                        'customer_id'     => $customerId,
                        'subscription_id' => $subscription->id,
                        'action'          => 'assign',
                        'operated_by'     => $request->user()?->id,
                        'remark'          => "开通订单#{$order->order_no}",
                        'created_at'      => $now,
                    ]);
                }
            }

            if (isset($purchaseTxn) && !empty($allSubscriptions)) {
                $purchaseTxn->update([
                    'related_type' => Subscription::class,
                    'related_id' => $allSubscriptions[0]->id,
                ]);
            }

            return $order;
        });

        $order->load(['items', 'subscriptions.proxyIp', 'customer']);

        // Process referral + sales commissions (only when customer actually paid via balance)
        if ($paymentMethod === 'balance' && $customerId && $totalAmount > 0) {
            try {
                $customer = Customer::find($customerId);
                if ($customer) {
                    $referralService = app(\App\Services\ReferralService::class);
                    foreach ($order->subscriptions as $sub) {
                        $dm = max(\App\Support\DurationHelper::toMonths($sub->duration, $sub->unit), 1);
                        $fwdRule = \App\Models\ForwardRule::where('subscription_id', $sub->id)->first();
                        $isFixedPricing = $fwdRule?->forwardPlan?->isFixedPricing() ?? false;
                        $ipLp = $isFixedPricing ? 0 : ($sub->list_price ?: ((float) $sub->price / $dm));
                        $fwdLp = $fwdRule?->forwardPlan ? (float) $fwdRule->forwardPlan->base_price : 0;
                        $subListPrice = round(($ipLp + $fwdLp) * $dm, 2);
                        $subAmount = (float) $sub->price;
                        $module = $fwdRule?->forwardPlan?->module ?? ($sub->purchased_module ?: 'static');
                        $productCtx = ['module' => $module];
                        $referralService->processCommission($customer, 'purchase', $subAmount, $sub->id, $subListPrice ?: $subAmount, $productCtx);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Commission processing failed (admin createOrder)', [
                    'customer_id' => $customerId,
                    'amount' => $totalAmount,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->success($order, '订单创建成功');
    }

    public function availableIps(Request $request): JsonResponse
    {
        $request->validate([
            'ip_group_id'    => 'required|integer|exists:ip_groups,id',
            'asset_group_id' => 'nullable|integer|exists:ip_asset_groups,id',
        ]);

        $query = ProxyIp::where('ip_group_id', $request->input('ip_group_id'))
            ->where('status', 'available');

        if ($request->filled('asset_group_id')) {
            $query->where('asset_group_id', $request->input('asset_group_id'));
        }

        $count = $query->count();

        return $this->success([
            'ip_group_id'    => (int) $request->input('ip_group_id'),
            'asset_group_id' => $request->input('asset_group_id') ? (int) $request->input('asset_group_id') : null,
            'available_count' => $count,
        ]);
    }

    /**
     * 订阅划转 — 将订阅从客户A转移到客户B
     * POST /subscriptions/{subscription}/transfer
     */
    public function transfer(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'target_customer_id' => 'required|exists:customers,id',
            'charge_target' => 'required|boolean',
            'charge_method' => 'nullable|in:balance,offline',
            'refund_source' => 'nullable|boolean',
            'remark' => 'nullable|string|max:500',
        ]);

        if ($subscription->status !== 'active') {
            return $this->error('只能划转状态为激活的订阅', 422);
        }

        $sourceCustomerId = $subscription->customer_id;
        $targetCustomerId = (int) $data['target_customer_id'];

        if ($sourceCustomerId === $targetCustomerId) {
            return $this->error('目标客户不能与当前客户相同', 422);
        }

        $targetCustomer = Customer::findOrFail($targetCustomerId);
        $chargeTarget = $data['charge_target'];
        $chargeMethod = $data['charge_method'] ?? 'balance';
        $refundSource = $data['refund_source'] ?? true;
        $price = (float) $subscription->price;
        $userId = $request->user()->id;
        $proxyIp = $subscription->proxyIp;

        if ($chargeTarget && $chargeMethod === 'balance' && $targetCustomer->balance < $price) {
            return $this->error("目标客户余额不足（需要 ¥{$price}，余额 ¥{$targetCustomer->balance}）", 422);
        }

        DB::transaction(function () use ($subscription, $sourceCustomerId, $targetCustomerId, $chargeTarget, $chargeMethod, $refundSource, $price, $userId, $proxyIp, $data) {
            // 1. 转移订阅
            $subscription->update([
                'customer_id' => $targetCustomerId,
                'balance_deducted' => $chargeTarget && $chargeMethod === 'balance',
            ]);

            // 2. 转移 IP 归属
            if ($proxyIp && $proxyIp->assigned_customer_id === $sourceCustomerId) {
                $proxyIp->update(['assigned_customer_id' => $targetCustomerId]);

                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $sourceCustomerId,
                    'subscription_id' => $subscription->id,
                    'action' => 'unassign',
                    'operated_by' => $userId,
                    'remark' => '订阅划转-从原客户移出',
                    'created_at' => now(),
                ]);
                IpAssignmentLog::create([
                    'proxy_ip_id' => $proxyIp->id,
                    'customer_id' => $targetCustomerId,
                    'subscription_id' => $subscription->id,
                    'action' => 'assign',
                    'operated_by' => $userId,
                    'remark' => '订阅划转-分配至新客户',
                    'created_at' => now(),
                ]);
            }

            // 3. 转发规则通过 subscription_id 关联，订阅已转移，无需额外更新

            // 4. 资金处理
            if ($chargeTarget && $chargeMethod === 'balance') {
                // B 扣余额
                $targetCust = Customer::lockForUpdate()->find($targetCustomerId);
                $balBefore = $targetCust->balance;
                $targetCust->decrement('balance', $price);
                Transaction::create([
                    'customer_id' => $targetCustomerId,
                    'type' => Transaction::TYPE_PURCHASE,
                    'amount' => -$price,
                    'balance_before' => $balBefore,
                    'balance_after' => $targetCust->balance,
                    'related_type' => Subscription::class,
                    'related_id' => $subscription->id,
                    'description' => "订阅划转接收 #{$subscription->id}: " . ($data['remark'] ?? ''),
                    'operated_by' => $userId,
                ]);

                // A 退余额（可选）
                if ($refundSource) {
                    $sourceCust = Customer::lockForUpdate()->find($sourceCustomerId);
                    $balBefore = $sourceCust->balance;
                    $sourceCust->increment('balance', $price);
                    Transaction::create([
                        'customer_id' => $sourceCustomerId,
                        'type' => Transaction::TYPE_REFUND,
                        'amount' => $price,
                        'balance_before' => $balBefore,
                        'balance_after' => $sourceCust->balance,
                        'related_type' => Subscription::class,
                        'related_id' => $subscription->id,
                        'description' => "订阅划转退还 #{$subscription->id}: " . ($data['remark'] ?? ''),
                        'operated_by' => $userId,
                    ]);
                }
            }

            // 5. 业绩处理：回收原客户返佣+销售佣金
            try {
                app(\App\Services\ReferralService::class)
                    ->reverseCommissions($sourceCustomerId, $subscription->id);
            } catch (\Throwable $e) {
                \Log::warning('transfer: commission reversal failed', [
                    'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
                ]);
            }

            // 如果B付费，为B重新生成推荐返佣和销售佣金
            if ($chargeTarget) {
                $targetCustObj = Customer::find($targetCustomerId);
                if ($targetCustObj) {
                    $refService = app(\App\Services\ReferralService::class);
                    try {
                        $transferListPrice = (float) ($subscription->list_price ?: $price);
                        $fwdRule = \App\Models\ForwardRule::where('subscription_id', $subscription->id)->first();
                        $module = $fwdRule?->forwardPlan?->module ?? ($subscription->purchased_module ?: 'static');
                        $productCtx = ['module' => $module];
                        $refService->processCommission($targetCustObj, 'subscription', $price, $subscription->id, $transferListPrice, $productCtx);
                    } catch (\Throwable $e) {
                        \Log::warning('transfer: commission process for target failed', [
                            'subscription_id' => $subscription->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });

        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($sourceCustomerId);
        \App\Services\Feishu\FeishuSyncTrigger::triggerForCustomer($targetCustomerId);

        $chargeMsg = '';
        if ($chargeTarget) {
            if ($chargeMethod === 'balance') {
                $chargeMsg = $refundSource
                    ? "，已从目标客户扣除 ¥{$price} 并退还原客户"
                    : "，已从目标客户扣除 ¥{$price}（不退还原客户）";
            } else {
                $chargeMsg = '，目标客户线下付款（未扣余额）';
            }
        }

        return $this->success([
            'transferred' => true,
            'source_customer_id' => $sourceCustomerId,
            'target_customer_id' => $targetCustomerId,
            'charged_target' => $chargeTarget,
        ], '订阅已划转至 ' . $targetCustomer->customer_name . $chargeMsg);
    }
}
