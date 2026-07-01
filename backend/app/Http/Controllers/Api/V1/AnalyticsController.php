<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\ForwardRule;
use App\Models\PageView;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\VipTier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function marketing(Request $request): JsonResponse
    {
        $mode = $request->query('mode', 'all'); // today | all | N (days)
        $today = Carbon::today();

        if ($mode === 'today') {
            $since = $today;
        } elseif ($mode === 'all') {
            $since = null;
        } else {
            $since = Carbon::now()->subDays((int) $mode)->startOfDay();
        }

        // === 实时区 ===
        $todayViews = PageView::whereDate('created_at', $today)->count();

        $todayLoggedIn = PageView::whereDate('created_at', $today)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        $fiveMinAgo = Carbon::now()->subMinutes(5);
        $onlineCustomers = PageView::where('created_at', '>=', $fiveMinAgo)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');
        $onlineGuests = PageView::where('created_at', '>=', $fiveMinAgo)
            ->whereNull('customer_id')
            ->distinct('ip_address')
            ->count('ip_address');

        // === 在线时间曲线图（今日按小时分布）===
        $hourlyOnline = PageView::whereDate('created_at', $today)
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(DISTINCT COALESCE(customer_id, ip_address)) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour')
            ->toArray();
        $hourlyChart = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyChart[] = ['hour' => $h, 'label' => sprintf('%02d:00', $h), 'count' => $hourlyOnline[$h] ?? 0];
        }

        // === 存量指标 ===

        // 官网访问总量
        $viewsQuery = PageView::query();
        if ($since) $viewsQuery->where('created_at', '>=', $since);
        $viewsTotal = $viewsQuery->count();

        // 官网注册总量
        $registeredQuery = Customer::withTrashed();
        if ($since) $registeredQuery->where('created_at', '>=', $since);
        $registeredTotal = $registeredQuery->count();
        $registeredCustomers = $this->compactCustomers(
            $registeredQuery->clone()->get(['id', 'customer_name', 'display_name', 'phone'])
        );

        // 实名认证总量
        $verifiedQuery = Customer::withTrashed()->whereNotNull('verified_at');
        if ($since) $verifiedQuery->where('verified_at', '>=', $since);
        $verifiedTotal = $verifiedQuery->count();
        $verifiedCustomers = $this->compactCustomers(
            $verifiedQuery->clone()->get(['id', 'customer_name', 'display_name', 'phone'])
        );

        // 已购买用户总量
        $purchasedSubQuery = Subscription::query();
        if ($since) $purchasedSubQuery->where('started_at', '>=', $since);
        $purchasedIds = $purchasedSubQuery->distinct('customer_id')->pluck('customer_id');
        $purchasedTotal = $purchasedIds->count();
        $purchasedCustomers = $this->compactCustomers(
            Customer::whereIn('id', $purchasedIds)->get(['id', 'customer_name', 'display_name', 'phone'])
        );

        // 访问未注册用户总量
        $unregQuery = PageView::whereNull('customer_id');
        if ($since) $unregQuery->where('created_at', '>=', $since);
        $unregisteredIps = $unregQuery->clone()->distinct('ip_address')->count('ip_address');
        $unregisteredIpList = $unregQuery->clone()
            ->select('ip_address', DB::raw('MAX(created_at) as last_visit'))
            ->groupBy('ip_address')
            ->orderByDesc('last_visit')
            ->limit(200)
            ->get()
            ->map(fn ($r) => ['ip' => $r->ip_address, 'last_visit' => $r->last_visit]);

        // 注册未购买用户总量
        $noPurchaseQuery = Customer::whereDoesntHave('subscriptions');
        if ($since) $noPurchaseQuery->where('created_at', '>=', $since);
        $noPurchaseTotal = $noPurchaseQuery->count();
        $noPurchaseCustomers = $this->compactCustomers(
            $noPurchaseQuery->clone()->get(['id', 'customer_name', 'display_name', 'phone'])
        );

        // 购买后连续三月未复购用户总量
        $ninetyDaysAgo = Carbon::now()->subDays(90);
        $churnedIds = Customer::whereHas('subscriptions')
            ->whereDoesntHave('subscriptions', function ($q) use ($ninetyDaysAgo) {
                $q->where(function ($sq) use ($ninetyDaysAgo) {
                    $sq->where('started_at', '>=', $ninetyDaysAgo)
                        ->orWhere('last_renewed_at', '>=', $ninetyDaysAgo);
                });
            })
            ->pluck('id');
        $churnedTotal = $churnedIds->count();
        $churnedCustomers = $this->compactCustomers(
            Customer::whereIn('id', $churnedIds)->get(['id', 'customer_name', 'display_name', 'phone'])
        );

        return response()->json([
            'success' => true,
            'data' => [
                'mode' => $mode,
                'realtime' => [
                    'today_views' => $todayViews,
                    'today_logged_in' => $todayLoggedIn,
                    'online_count' => $onlineCustomers + $onlineGuests,
                    'online_customers' => $onlineCustomers,
                    'online_guests' => $onlineGuests,
                ],
                'hourly_chart' => $hourlyChart,
                'metrics' => [
                    [
                        'key' => 'views_total',
                        'label' => '官网访问总量',
                        'value' => $viewsTotal,
                        'total_customers' => $viewsTotal,
                    ],
                    [
                        'key' => 'registered_total',
                        'label' => '官网注册总量',
                        'value' => $registeredTotal,
                        'customers' => $registeredCustomers,
                        'total_customers' => $registeredTotal,
                    ],
                    [
                        'key' => 'verified_total',
                        'label' => '实名认证总量',
                        'value' => $verifiedTotal,
                        'customers' => $verifiedCustomers,
                        'total_customers' => $verifiedTotal,
                    ],
                    [
                        'key' => 'purchased_total',
                        'label' => '已购买用户总量',
                        'value' => $purchasedTotal,
                        'customers' => $purchasedCustomers,
                        'total_customers' => $purchasedTotal,
                    ],
                    [
                        'key' => 'unregistered_visitors',
                        'label' => '访问未注册用户总量',
                        'value' => $unregisteredIps,
                        'ip_list' => $unregisteredIpList,
                        'total_customers' => $unregisteredIps,
                    ],
                    [
                        'key' => 'registered_no_purchase',
                        'label' => '注册未购买用户总量',
                        'value' => $noPurchaseTotal,
                        'customers' => $noPurchaseCustomers,
                        'total_customers' => $noPurchaseTotal,
                    ],
                    [
                        'key' => 'churned_users',
                        'label' => '购买后连续三月未复购用户总量',
                        'value' => $churnedTotal,
                        'customers' => $churnedCustomers,
                        'total_customers' => $churnedTotal,
                    ],
                ],
            ],
        ]);
    }

    public function pricing(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $since = Carbon::now()->subDays($days)->startOfDay();
        $minSpent = $request->query('min_spent');

        // 该时段内有消费(订阅)记录的客户
        $activeCustomerIds = Subscription::where('started_at', '>=', $since)
            ->distinct('customer_id')
            ->pluck('customer_id');

        // 加载客户 + VIP + 特批价
        $customers = Customer::whereIn('id', $activeCustomerIds)
            ->with('vipTier')
            ->get(['id', 'customer_name', 'display_name', 'phone', 'vip_tier_id', 'total_spent']);

        if ($minSpent) {
            $customers = $customers->filter(fn ($c) => (float) $c->total_spent >= (float) $minSpent);
        }

        // 预加载特批折扣
        $specialPrices = CustomerSpecialPrice::whereIn('customer_id', $customers->pluck('id'))
            ->where('is_active', 1)
            ->whereNotNull('discount_percent_static')
            ->get()
            ->groupBy('customer_id');

        $groups = [
            'full_price' => ['label' => '原价用户', 'customers' => collect()],
            'agent_price' => ['label' => '代理价用户', 'customers' => collect()],
            'discount_70' => ['label' => '7折用户', 'customers' => collect()],
            'discount_60' => ['label' => '6折用户', 'customers' => collect()],
            'discount_50' => ['label' => '5折用户', 'customers' => collect()],
            'discount_below_50' => ['label' => '5折内用户', 'customers' => collect()],
        ];

        foreach ($customers as $customer) {
            $vipDiscount = $customer->vipTier ? (int) $customer->vipTier->discount_percent : 100;

            $specialDiscount = 100;
            $sp = $specialPrices->get($customer->id);
            if ($sp && $sp->count() > 0) {
                $minSpecial = $sp->min('discount_percent_static');
                if ($minSpecial !== null) {
                    $specialDiscount = (int) $minSpecial;
                }
            }

            $bestDiscount = min($vipDiscount, $specialDiscount);
            $compact = $this->compactCustomer($customer);
            $compact['discount'] = $bestDiscount;
            $compact['total_spent'] = (float) $customer->total_spent;

            if ($bestDiscount >= 100) {
                $groups['full_price']['customers']->push($compact);
            } else {
                $groups['agent_price']['customers']->push($compact);
                if ($bestDiscount >= 70) {
                    $groups['discount_70']['customers']->push($compact);
                } elseif ($bestDiscount >= 60) {
                    $groups['discount_60']['customers']->push($compact);
                } elseif ($bestDiscount >= 50) {
                    $groups['discount_50']['customers']->push($compact);
                } else {
                    $groups['discount_below_50']['customers']->push($compact);
                }
            }
        }

        $metrics = [];
        foreach ($groups as $key => $g) {
            $metrics[] = [
                'key' => $key,
                'label' => $g['label'],
                'value' => $g['customers']->count(),
                'customers' => $g['customers']->values(),
                'total_customers' => $g['customers']->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => ['metrics' => $metrics],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $since = Carbon::now()->subDays($days)->startOfDay();

        // === 实时快照 ===
        $totalIps = ProxyIp::count();
        $assignedIps = ProxyIp::where('status', 'assigned')->count();

        // 单IP = assigned 且无 forward_rule
        $forwardedSubIds = ForwardRule::where('status', '!=', 'deleted')
            ->pluck('subscription_id')
            ->filter();
        $forwardedIpIds = $forwardedSubIds->isNotEmpty()
            ? Subscription::whereIn('id', $forwardedSubIds)->pluck('proxy_ip_id')
            : collect();

        $singleIpTotal = ProxyIp::where('status', 'assigned')
            ->when($forwardedIpIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $forwardedIpIds))
            ->count();

        // 视频专线 / 直播专线
        $forwardRulesWithPlan = ForwardRule::where('status', '!=', 'deleted')
            ->whereNotNull('forward_plan_id')
            ->with('forwardPlan:id,module')
            ->get();

        $videoSubIds = collect();
        $liveSubIds = collect();
        foreach ($forwardRulesWithPlan as $rule) {
            if (!$rule->forwardPlan) continue;
            $module = $rule->forwardPlan->module;
            if ($module === 'video') {
                $videoSubIds->push($rule->subscription_id);
            } elseif (in_array($module, ['live_mobile', 'live_pc'])) {
                $liveSubIds->push($rule->subscription_id);
            }
        }

        $videoIpCount = $videoSubIds->isNotEmpty()
            ? ProxyIp::where('status', 'assigned')
                ->whereIn('id', Subscription::whereIn('id', $videoSubIds)->pluck('proxy_ip_id'))
                ->count()
            : 0;

        $liveIpCount = $liveSubIds->isNotEmpty()
            ? ProxyIp::where('status', 'assigned')
                ->whereIn('id', Subscription::whereIn('id', $liveSubIds)->pluck('proxy_ip_id'))
                ->count()
            : 0;

        // 各地区在线IP
        $regionOnline = ProxyIp::where('status', 'assigned')
            ->select('country_name', DB::raw('COUNT(*) as count'))
            ->groupBy('country_name')
            ->orderByDesc('count')
            ->get();

        // === 存量指标 ===

        // 连续续费三月在线IP（当前活跃且续费3次以上，不受时间筛选）
        $renewedIpTotal = Subscription::where('status', 'active')
            ->where('renewed_count', '>=', 3)
            ->count();

        // 过期IP：通过订阅 expires_at 判断（upstream_expires_at 可能为 NULL）
        $expiredIpIds = Subscription::where('status', 'expired')
            ->where('expires_at', '>=', $since)
            ->distinct()
            ->pluck('proxy_ip_id')
            ->filter();

        // 若订阅维度无数据，回退到 proxy_ips.updated_at（含软删除）
        if ($expiredIpIds->isEmpty()) {
            $expiredIpIds = ProxyIp::withTrashed()->where('status', 'expired')
                ->where('updated_at', '>=', $since)
                ->pluck('id');
        }
        $expiredTotal = $expiredIpIds->count();

        // 各地区过期IP
        $regionExpired = $expiredIpIds->isNotEmpty()
            ? ProxyIp::withTrashed()->whereIn('id', $expiredIpIds)
                ->select('country_name', DB::raw('COUNT(*) as count'))
                ->groupBy('country_name')
                ->orderByDesc('count')
                ->get()
            : collect();

        // 退款IP（订阅状态为 refunded）
        $refundedIpIds = Subscription::where('status', 'refunded')
            ->where('updated_at', '>=', $since)
            ->distinct()
            ->pluck('proxy_ip_id')
            ->filter();
        $refundedTotal = $refundedIpIds->count();

        // 各地区退款IP
        $regionRefunded = $refundedIpIds->isNotEmpty()
            ? ProxyIp::withTrashed()->whereIn('id', $refundedIpIds)
                ->select('country_name', DB::raw('COUNT(*) as count'))
                ->groupBy('country_name')
                ->orderByDesc('count')
                ->get()
            : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'realtime' => [
                    'total_ips' => $totalIps,
                    'assigned_ips' => $assignedIps,
                    'single_ip_total' => $singleIpTotal,
                    'video_line_total' => $videoIpCount,
                    'live_line_total' => $liveIpCount,
                    'region_online' => $regionOnline,
                ],
                'metrics' => [
                    [
                        'key' => 'renewed_3m_active',
                        'label' => '连续续费三月在线IP',
                        'value' => $renewedIpTotal,
                    ],
                    [
                        'key' => 'expired_total',
                        'label' => '过期IP总量',
                        'value' => $expiredTotal,
                    ],
                    [
                        'key' => 'region_expired',
                        'label' => '各地区过期IP',
                        'value' => $regionExpired->sum('count'),
                        'regions' => $regionExpired,
                    ],
                    [
                        'key' => 'refunded_total',
                        'label' => '退款IP总量',
                        'value' => $refundedTotal,
                    ],
                    [
                        'key' => 'region_refunded',
                        'label' => '各地区退款IP总量',
                        'value' => $regionRefunded->sum('count'),
                        'regions' => $regionRefunded,
                    ],
                ],
            ],
        ]);
    }

    public function customerDetail(int $id): JsonResponse
    {
        $customer = Customer::with(['vipTier', 'subscriptions' => function ($q) {
            $q->with(['proxyIp:id,ip_address,country_name,status', 'forwardRule.forwardPlan:id,name,module'])
                ->orderByDesc('id')
                ->limit(20);
        }])->findOrFail($id);

        $recentTransactions = $customer->transactions()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'type', 'amount', 'balance_after', 'description', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'display_name' => $customer->display_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'company_name' => $customer->company_name,
                'created_at' => $customer->created_at,
                'verified_at' => $customer->verified_at,
                'vip_tier' => $customer->vipTier ? [
                    'name' => $customer->vipTier->name,
                    'discount_percent' => $customer->vipTier->discount_percent,
                    'badge_color' => $customer->vipTier->badge_color,
                ] : null,
                'balance' => (float) $customer->balance,
                'total_spent' => (float) $customer->total_spent,
                'subscriptions' => $customer->subscriptions->map(fn ($s) => [
                    'id' => $s->id,
                    'ip' => $s->proxyIp?->ip_address,
                    'country' => $s->proxyIp?->country_name,
                    'status' => $s->status,
                    'price' => (float) $s->price,
                    'expires_at' => $s->expires_at,
                    'has_forward' => $s->forwardRule ? true : false,
                    'forward_plan' => $s->forwardRule?->forwardPlan?->name,
                ]),
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }

    public function trackVisit(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string|max:500',
        ]);

        $ip = $request->ip();
        $path = $request->input('path');
        $customerId = null;

        if ($request->bearerToken()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
            if ($token && $token->tokenable_type === Customer::class) {
                $customerId = $token->tokenable_id;
            }
        }

        // 限流：同IP同path 1分钟内最多1次
        $recentExists = PageView::where('ip_address', $ip)
            ->where('path', $path)
            ->where('created_at', '>=', Carbon::now()->subMinute())
            ->exists();

        if (!$recentExists) {
            PageView::create([
                'customer_id' => $customerId,
                'ip_address' => $ip,
                'path' => $path,
                'created_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function compactCustomers($customers): array
    {
        return $customers->map(fn ($c) => $this->compactCustomer($c))->values()->toArray();
    }

    private function compactCustomer($customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->display_name ?: $customer->customer_name,
            'phone' => $customer->phone,
        ];
    }
}
