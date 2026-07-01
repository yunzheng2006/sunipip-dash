<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\ForwardRule;
use App\Models\PageView;
use App\Models\ProxyIp;
use App\Models\Subscription;
use App\Models\SystemConfig;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VipTier;
use App\Services\GeoIpService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BigDataController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        // --- Auth: Sanctum bearer OR ?key= matching SystemConfig ---
        if (!$this->authenticateBigData($request)) {
            return $this->error('Unauthorized', 401);
        }

        $data = Cache::remember('bigdata:dashboard:v1', 15, function () {
            $now = Carbon::now();
            $today = Carbon::today();
            $fiveMinAgo = $now->copy()->subMinutes(5);
            $ninetyDaysAgo = $now->copy()->subDays(90);

            return [
                'marketing'  => $this->buildMarketing($today, $fiveMinAgo, $ninetyDaysAgo),
                'pricing'    => $this->buildPricing(),
                'products'   => $this->buildProducts($today),
                'map'        => $this->buildMap($fiveMinAgo),
                'sales'      => $this->buildSales($today),
                'finance'    => $this->buildFinance($today, $now),
                'crm'        => $this->buildCrm(),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        });

        return $this->success($data);
    }

    // ================================================================
    //  Auth
    // ================================================================

    private function authenticateBigData(Request $request): bool
    {
        // 1. Try Sanctum bearer token (admin only)
        if ($request->bearerToken()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
            if ($token && $token->tokenable_type === User::class) {
                return true;
            }
        }

        // 2. Try query param ?key=
        $key = $request->query('key');
        if ($key && $key === SystemConfig::get('bigdata.api_key')) {
            return true;
        }

        return false;
    }

    // ================================================================
    //  Marketing
    // ================================================================

    private function buildMarketing(Carbon $today, Carbon $fiveMinAgo, Carbon $ninetyDaysAgo): array
    {
        // --- Today ---
        $todayVisits = PageView::whereDate('created_at', $today)->count();
        $todayRegistrations = Customer::withTrashed()->whereDate('created_at', $today)->count();
        $todayVerified = Customer::withTrashed()->whereNotNull('verified_at')->whereDate('verified_at', $today)->count();

        $todayPurchasedIds = Subscription::whereDate('started_at', $today)
            ->distinct('customer_id')->pluck('customer_id');
        $todayPurchased = $todayPurchasedIds->count();

        // Online (last 5 min)
        $onlineCustomers = PageView::where('created_at', '>=', $fiveMinAgo)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');
        $onlineGuests = PageView::where('created_at', '>=', $fiveMinAgo)
            ->whereNull('customer_id')
            ->distinct('ip_address')
            ->count('ip_address');
        $onlineCount = $onlineCustomers + $onlineGuests;

        // --- Total ---
        $totalVisits = PageView::count();
        $totalRegistrations = Customer::withTrashed()->count();
        $totalVerified = Customer::withTrashed()->whereNotNull('verified_at')->count();

        $totalPurchasedIds = Subscription::distinct('customer_id')->pluck('customer_id');
        $totalPurchased = $totalPurchasedIds->count();

        $totalNotPurchased = Customer::whereDoesntHave('subscriptions')->count();

        $totalUnregisteredVisitors = PageView::whereNull('customer_id')
            ->distinct('ip_address')
            ->count('ip_address');

        // Churned = has subscriptions but none in last 90 days
        $churned = Customer::whereHas('subscriptions')
            ->whereDoesntHave('subscriptions', function ($q) use ($ninetyDaysAgo) {
                $q->where(function ($sq) use ($ninetyDaysAgo) {
                    $sq->where('started_at', '>=', $ninetyDaysAgo)
                        ->orWhere('last_renewed_at', '>=', $ninetyDaysAgo);
                });
            })
            ->count();

        // --- Hourly (today) ---
        $hourlyRaw = PageView::whereDate('created_at', $today)
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->pluck('count', 'hour')
            ->toArray();

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = [
                'hour'  => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $hourlyRaw[$h] ?? 0,
            ];
        }

        return [
            'today' => [
                'visits'           => $todayVisits,
                'registrations'    => $todayRegistrations,
                'verified'         => $todayVerified,
                'purchased'        => $todayPurchased,
                'online_count'     => $onlineCount,
                'online_customers' => $onlineCustomers,
                'online_guests'    => $onlineGuests,
            ],
            'total' => [
                'visits'                  => $totalVisits,
                'registrations'           => $totalRegistrations,
                'verified'                => $totalVerified,
                'purchased'               => $totalPurchased,
                'not_purchased'           => $totalNotPurchased,
                'unregistered_visitors'   => $totalUnregisteredVisitors,
                'churned'                 => $churned,
            ],
            'hourly' => $hourly,
        ];
    }

    // ================================================================
    //  Pricing
    // ================================================================

    private function buildPricing(): array
    {
        // Load all customers with VIP tier
        $customers = Customer::with('vipTier')->get(['id', 'vip_tier_id']);

        // Load special prices
        $specialPrices = CustomerSpecialPrice::where('is_active', 1)
            ->whereNotNull('discount_percent_static')
            ->get()
            ->groupBy('customer_id');

        $groups = [
            'full_price'       => ['label' => '原价用户', 'count' => 0],
            'agent_price'      => ['label' => '代理价用户', 'count' => 0],
            'discount_70'      => ['label' => '7折用户', 'count' => 0],
            'discount_60'      => ['label' => '6折用户', 'count' => 0],
            'discount_50'      => ['label' => '5折用户', 'count' => 0],
            'discount_below_50' => ['label' => '5折内用户', 'count' => 0],
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

            $best = min($vipDiscount, $specialDiscount);

            if ($best >= 100) {
                $groups['full_price']['count']++;
            } else {
                $groups['agent_price']['count']++;
                if ($best >= 70) {
                    $groups['discount_70']['count']++;
                } elseif ($best >= 60) {
                    $groups['discount_60']['count']++;
                } elseif ($best >= 50) {
                    $groups['discount_50']['count']++;
                } else {
                    $groups['discount_below_50']['count']++;
                }
            }
        }

        $result = [];
        foreach ($groups as $key => $g) {
            $result[] = [
                'key'   => $key,
                'label' => $g['label'],
                'value' => $g['count'],
            ];
        }

        return $result;
    }

    // ================================================================
    //  Products
    // ================================================================

    private function buildProducts(Carbon $today): array
    {
        $totalIps = ProxyIp::count();
        $assignedIps = ProxyIp::where('status', 'assigned')->count();

        // Single IP = assigned without active forward rules
        $forwardedSubIds = ForwardRule::where('status', '!=', 'deleted')
            ->pluck('subscription_id')
            ->filter();
        $forwardedIpIds = $forwardedSubIds->isNotEmpty()
            ? Subscription::whereIn('id', $forwardedSubIds)->pluck('proxy_ip_id')
            : collect();

        $singleIps = ProxyIp::where('status', 'assigned')
            ->when($forwardedIpIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $forwardedIpIds))
            ->count();

        // Video / Live lines
        $rulesWithPlan = ForwardRule::where('status', '!=', 'deleted')
            ->whereNotNull('forward_plan_id')
            ->with('forwardPlan:id,module')
            ->get();

        $videoSubIds = collect();
        $liveSubIds = collect();
        foreach ($rulesWithPlan as $rule) {
            if (!$rule->forwardPlan) continue;
            $module = $rule->forwardPlan->module;
            if ($module === 'video') {
                $videoSubIds->push($rule->subscription_id);
            } elseif (in_array($module, ['live_mobile', 'live_pc'])) {
                $liveSubIds->push($rule->subscription_id);
            }
        }

        $videoLines = $videoSubIds->isNotEmpty()
            ? ProxyIp::where('status', 'assigned')
                ->whereIn('id', Subscription::whereIn('id', $videoSubIds)->pluck('proxy_ip_id'))
                ->count()
            : 0;

        $liveLines = $liveSubIds->isNotEmpty()
            ? ProxyIp::where('status', 'assigned')
                ->whereIn('id', Subscription::whereIn('id', $liveSubIds)->pluck('proxy_ip_id'))
                ->count()
            : 0;

        // Expired / Refunded / Renewed 3m
        $expiredTotal = Subscription::where('status', 'expired')->count();
        $refundedTotal = Subscription::where('status', 'refunded')->count();
        $renewed3m = Subscription::where('status', 'active')
            ->where('renewed_count', '>=', 3)
            ->count();

        // Region breakdowns
        $regionOnline = ProxyIp::where('status', 'assigned')
            ->select('country_name as name', DB::raw('COUNT(*) as count'))
            ->groupBy('country_name')
            ->orderByDesc('count')
            ->get();

        $expiredIpIds = Subscription::where('status', 'expired')
            ->distinct()->pluck('proxy_ip_id')->filter();
        $regionExpired = $expiredIpIds->isNotEmpty()
            ? ProxyIp::withTrashed()->whereIn('id', $expiredIpIds)
                ->select('country_name as name', DB::raw('COUNT(*) as count'))
                ->groupBy('country_name')
                ->orderByDesc('count')
                ->get()
            : collect();

        $refundedIpIds = Subscription::where('status', 'refunded')
            ->distinct()->pluck('proxy_ip_id')->filter();
        $regionRefunded = $refundedIpIds->isNotEmpty()
            ? ProxyIp::withTrashed()->whereIn('id', $refundedIpIds)
                ->select('country_name as name', DB::raw('COUNT(*) as count'))
                ->groupBy('country_name')
                ->orderByDesc('count')
                ->get()
            : collect();

        $todayNewAssigned = Subscription::whereDate('started_at', $today)
            ->where('status', 'active')->count();
        $todayExpired = Subscription::where('status', 'expired')
            ->whereDate('updated_at', $today)->count();
        $todayRefunded = Subscription::where('status', 'refunded')
            ->whereDate('updated_at', $today)->count();

        return [
            'total_ips'       => $totalIps,
            'assigned_ips'    => $assignedIps,
            'single_ips'      => $singleIps,
            'video_lines'     => $videoLines,
            'live_lines'      => $liveLines,
            'expired_total'   => $expiredTotal,
            'refunded_total'  => $refundedTotal,
            'renewed_3m'      => $renewed3m,
            'region_online'   => $regionOnline,
            'region_expired'  => $regionExpired,
            'region_refunded' => $regionRefunded,
            'today' => [
                'new_assigned' => $todayNewAssigned,
                'expired'      => $todayExpired,
                'refunded'     => $todayRefunded,
            ],
        ];
    }

    // ================================================================
    //  Map (GeoIP)
    // ================================================================

    private function buildMap(Carbon $fiveMinAgo): array
    {
        $geoIp = app(GeoIpService::class);

        // Visitors: distinct IPs from page_views last 15 minutes
        $fifteenMinAgo = Carbon::now()->subMinutes(15);
        $visitorIps = PageView::where('created_at', '>=', $fifteenMinAgo)
            ->distinct()
            ->limit(500)
            ->pluck('ip_address')
            ->toArray();

        $visitorProvinces = $geoIp->batchResolve($visitorIps);
        $visitorMap = $this->aggregateProvinces($visitorProvinces);

        // Customers: distinct last_login_ip from all customers
        $customerIps = Customer::whereNotNull('last_login_ip')
            ->where('last_login_ip', '!=', '')
            ->distinct()
            ->limit(1000)
            ->pluck('last_login_ip')
            ->toArray();

        $customerProvinces = $geoIp->batchResolve($customerIps);
        $customerMap = $this->aggregateProvinces($customerProvinces);

        return [
            'visitors'  => $visitorMap,
            'customers' => $customerMap,
        ];
    }

    /**
     * Aggregate province resolution results into [{name, value}, ...].
     */
    private function aggregateProvinces(array $ipProvinceMap): array
    {
        $counts = [];
        foreach ($ipProvinceMap as $province) {
            if ($province === null || $province === '未知') continue;
            $counts[$province] = ($counts[$province] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];
        foreach ($counts as $name => $value) {
            $result[] = ['name' => $name, 'value' => $value];
        }

        return $result;
    }

    // ================================================================
    //  Sales
    // ================================================================

    private function buildSales(Carbon $today): array
    {
        $revenueTypes = Transaction::REVENUE_TYPES;
        // Group customers by sales_person
        $salesPersons = Customer::whereNotNull('sales_person')
            ->where('sales_person', '!=', '')
            ->select('sales_person')
            ->distinct()
            ->pluck('sales_person');

        $result = [];

        foreach ($salesPersons as $sp) {
            $customerIds = Customer::where('sales_person', $sp)->pluck('id');

            $customerCount = $customerIds->count();
            $purchasedCount = Subscription::whereIn('customer_id', $customerIds)
                ->distinct('customer_id')
                ->count('customer_id');

            $activeIpCount = ProxyIp::where('status', 'assigned')
                ->whereIn('assigned_customer_id', $customerIds)
                ->count();

            $revenue = abs((float) Transaction::whereIn('customer_id', $customerIds)
                ->whereIn('type', $revenueTypes)
                ->sum('amount'));

            $todayRevenue = abs((float) Transaction::whereIn('customer_id', $customerIds)
                ->whereIn('type', $revenueTypes)
                ->whereDate('created_at', $today)
                ->sum('amount'));

            $todayCustomers = Customer::where('sales_person', $sp)
                ->whereDate('created_at', $today)->count();

            $result[] = [
                'name'            => $sp,
                'customers'       => $customerCount,
                'purchased'       => $purchasedCount,
                'ips'             => $activeIpCount,
                'revenue'         => round($revenue, 2),
                'today_revenue'   => round($todayRevenue, 2),
                'today_customers' => $todayCustomers,
            ];
        }

        // Sort by revenue descending
        usort($result, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $result;
    }

    // ================================================================
    //  Finance
    // ================================================================

    private function buildFinance(Carbon $today, Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();

        $revenueTypes = Transaction::REVENUE_TYPES;

        $todayRevenue = abs((float) Transaction::whereIn('type', $revenueTypes)
            ->whereDate('created_at', $today)
            ->sum('amount'));

        $monthRevenue = abs((float) Transaction::whereIn('type', $revenueTypes)
            ->where('created_at', '>=', $monthStart)
            ->sum('amount'));

        $totalRevenue = abs((float) Transaction::whereIn('type', $revenueTypes)
            ->sum('amount'));

        // Topup
        $todayTopup = (float) Transaction::where('type', Transaction::TYPE_TOPUP)
            ->whereDate('created_at', $today)
            ->sum('amount');

        // Refund
        $todayRefund = abs((float) Transaction::where('type', Transaction::TYPE_REFUND)
            ->whereDate('created_at', $today)
            ->sum('amount'));

        // Trend: last 7 days
        $sevenDaysAgo = $now->copy()->subDays(6)->startOfDay();
        $dailyData = Transaction::where('created_at', '>=', $sevenDaysAgo)
            ->select(
                DB::raw('DATE(created_at) as date'),
                'type',
                DB::raw('SUM(amount) as total')
            )
            ->groupByRaw('DATE(created_at), type')
            ->get()
            ->groupBy('date');

        $trend = [];
        $cursor = $sevenDaysAgo->copy();
        while ($cursor <= $now) {
            $d = $cursor->format('Y-m-d');
            $dayData = $dailyData->get($d, collect());

            $trend[] = [
                'date'    => $d,
                'revenue' => round(abs((float) $dayData->whereIn('type', $revenueTypes)->sum('total')), 2),
                'topup'   => round((float) $dayData->where('type', Transaction::TYPE_TOPUP)->sum('total'), 2),
            ];
            $cursor->addDay();
        }

        return [
            'today_revenue' => round($todayRevenue, 2),
            'month_revenue' => round($monthRevenue, 2),
            'total_revenue' => round($totalRevenue, 2),
            'today_topup'   => round($todayTopup, 2),
            'today_refund'  => round($todayRefund, 2),
            'trend'         => $trend,
        ];
    }

    // ================================================================
    //  CRM
    // ================================================================

    private function buildCrm(): array
    {
        return [
            'spending_10w'  => Customer::where('total_spent', '>', 100000)->count(),
            'spending_50w'  => Customer::where('total_spent', '>', 500000)->count(),
            'spending_100w' => Customer::where('total_spent', '>', 1000000)->count(),
        ];
    }
}
