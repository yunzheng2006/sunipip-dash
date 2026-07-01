<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ReferralCommission;
use App\Models\SalesCommission;
use App\Models\SystemConfig;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralSettingsController extends Controller
{
    /**
     * GET /settings/referral
     */
    private const PRODUCT_MODULES = ['static', 'video', 'live_mobile', 'live_pc'];

    public function show(): JsonResponse
    {
        $data = [
            'referral.enabled' => SystemConfig::get('referral.enabled', false),
            'referral.auto_credit' => SystemConfig::get('referral.auto_credit', true),
            'referral.rate' => SystemConfig::get('referral.rate', 5),
            'referral.rate_purchase' => SystemConfig::get('referral.rate_purchase', null),
            'referral.rate_renew' => SystemConfig::get('referral.rate_renew', null),
            'referral.floor_rate' => SystemConfig::get('referral.floor_rate', SystemConfig::get('referral.rate_special', 5)),
            'referral.threshold_discount' => SystemConfig::get('referral.threshold_discount', 80),
            'referral.withdraw_fee_percent' => SystemConfig::get('referral.withdraw_fee_percent', 1),
        ];

        foreach (self::PRODUCT_MODULES as $mod) {
            $data["referral.{$mod}.threshold"] = SystemConfig::get("referral.{$mod}.threshold", null);
            $data["referral.{$mod}.floor_rate"] = SystemConfig::get("referral.{$mod}.floor_rate", null);
            $data["referral.{$mod}.floor_rate_purchase"] = SystemConfig::get("referral.{$mod}.floor_rate_purchase", null);
            $data["referral.{$mod}.floor_rate_renew"] = SystemConfig::get("referral.{$mod}.floor_rate_renew", null);
        }

        $data['cost.ipipv_hard_cost_override'] = SystemConfig::get('cost.ipipv_hard_cost_override', null);

        return $this->success($data);
    }

    /**
     * PUT /settings/referral
     */
    public function update(Request $request): JsonResponse
    {
        $body = $request->json()->all();

        $boolKeys = ['referral.enabled', 'referral.auto_credit'];
        $numKeys = ['referral.rate', 'referral.rate_purchase', 'referral.rate_renew',
            'referral.floor_rate', 'referral.threshold_discount', 'referral.withdraw_fee_percent'];

        foreach (self::PRODUCT_MODULES as $mod) {
            $numKeys[] = "referral.{$mod}.threshold";
            $numKeys[] = "referral.{$mod}.floor_rate";
            $numKeys[] = "referral.{$mod}.floor_rate_purchase";
            $numKeys[] = "referral.{$mod}.floor_rate_renew";
        }
        $numKeys[] = 'cost.ipipv_hard_cost_override';

        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $body)) {
                SystemConfig::set($key, $body[$key] ? '1' : '0', 'boolean', 'referral');
            }
        }
        $coreRateKeys = ['referral.rate', 'referral.rate_purchase', 'referral.rate_renew'];
        foreach ($numKeys as $key) {
            if (!array_key_exists($key, $body)) continue;
            $group = str_contains($key, 'cost.') ? 'cost' : 'referral';
            if ($body[$key] === null || $body[$key] === '') {
                if (in_array($key, $coreRateKeys)) continue;
                SystemConfig::where('key', $key)->delete();
                \Illuminate\Support\Facades\Cache::forget("sys_config:{$key}");
            } else {
                SystemConfig::set($key, (string) $body[$key], 'string', $group);
            }
        }

        return $this->success(null, '设置已保存');
    }

    /**
     * GET /referral-stats
     * Admin overview of referral program
     */
    public function stats(): JsonResponse
    {
        $totalReferrals = Customer::whereNotNull('referred_by_customer')->count();
        $totalCommission = ReferralCommission::where('status', 'credited')->sum('commission_amount');
        $pendingCommission = ReferralCommission::where('status', 'pending')->sum('commission_amount');

        // Top referrers
        $topReferrers = Customer::whereNotNull('referral_code')
            ->withCount(['referrals'])
            ->having('referrals_count', '>', 0)
            ->orderByDesc('referrals_count')
            ->limit(10)
            ->get(['id', 'customer_name', 'referral_code'])
            ->map(function ($c) {
                $earned = ReferralCommission::where('referrer_id', $c->id)
                    ->where('status', 'credited')->sum('commission_amount');
                return [
                    'id' => $c->id,
                    'customer_name' => $c->customer_name,
                    'referral_code' => $c->referral_code,
                    'referral_count' => $c->referrals_count,
                    'total_earned' => (float) $earned,
                ];
            });

        $staffStats = \App\Models\User::whereNotNull('invite_code')
            ->get(['id', 'name', 'invite_code'])
            ->map(function ($u) {
                $count = Customer::where('invited_by', $u->id)->count();
                $spent = Customer::where('invited_by', $u->id)->sum('total_spent');
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'invite_code' => $u->invite_code,
                    'customer_count' => $count,
                    'total_spent' => (float) $spent,
                ];
            })->filter(fn($s) => $s['customer_count'] > 0)->values();

        return $this->success([
            'total_referrals' => $totalReferrals,
            'total_commission_paid' => (float) $totalCommission,
            'pending_commission' => (float) $pendingCommission,
            'top_referrers' => $topReferrers,
            'staff_stats' => $staffStats,
        ]);
    }

    /**
     * POST /referral-commissions/{id}/credit
     * Manually credit a pending commission
     */
    public function creditCommission(ReferralCommission $referralCommission): JsonResponse
    {
        $service = app(ReferralService::class);
        $result = $service->creditCommission($referralCommission);
        return $result
            ? $this->success(null, '已发放佣金')
            : $this->error('发放失败（可能已发放或推荐人不存在）', 422);
    }

    /**
     * GET /referral-commissions
     * All commissions list
     */
    public function commissions(Request $request): JsonResponse
    {
        $query = ReferralCommission::with([
            'referrer:id,customer_name',
            'referee:id,customer_name',
        ])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('referrer_id')) {
            $query->where('referrer_id', $request->input('referrer_id'));
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }

    /**
     * GET /sales-commissions
     */
    public function salesCommissions(Request $request): JsonResponse
    {
        $query = SalesCommission::with([
            'user:id,name',
            'customer:id,customer_name',
        ])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        return $this->paginated($query->paginate($request->input('per_page', 20)));
    }

    /**
     * POST /sales-commissions/{salesCommission}/credit
     */
    public function creditSalesCommission(SalesCommission $salesCommission): JsonResponse
    {
        $service = app(ReferralService::class);
        $result = $service->creditSalesCommission($salesCommission);
        return $result
            ? $this->success(null, '已发放销售提成')
            : $this->error('发放失败（可能已发放或销售不存在）', 422);
    }
}
