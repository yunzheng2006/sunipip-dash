<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IpAssignmentLog;
use App\Models\ProvisionApproval;
use App\Models\ProxyIp;
use App\Models\SparkInstance;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\IpipvApiService;
use App\Services\SparkApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSales = !$user->can('customer.view_all');
        $isManager = $user->hasRole('manager') && $user->can('customer.view_all');

        // 销售：只看自己名下客户的数据
        if ($isSales) {
            $myCustomerIds = Customer::where('sales_person', $user->name)->pluck('id');
            $stats = [
                'my_customers'         => $myCustomerIds->count(),
                'my_active_subs'       => Subscription::whereIn('customer_id', $myCustomerIds)->where('status', 'active')->count(),
                'my_expiring_soon'     => Subscription::whereIn('customer_id', $myCustomerIds)->expiringSoon(7)->count(),
                'my_pending_approvals' => ProvisionApproval::where('submitted_by', $user->id)->where('status', 'pending')->count(),
                'role' => 'sales',
            ];
            return $this->success($stats);
        }

        // 经理：自己名下 + 下属的数据 + 系统概览
        $subordinateIds = [];
        if ($isManager) {
            $subordinateIds = \App\Models\User::where('supervisor_id', $user->id)->pluck('id')->toArray();
        }

        $stats = [
            'total_customers'        => Customer::count(),
            'total_ips'              => ProxyIp::count(),
            'available_ips'          => ProxyIp::where('status', 'available')->count(),
            'assigned_ips'           => ProxyIp::where('status', 'assigned')->count(),
            'active_subscriptions'   => Subscription::where('status', 'active')->count(),
            'expiring_soon'          => Subscription::expiringSoon(7)->count(),
            'pending_approvals'      => 0,
            'total_revenue'          => (float) Transaction::where('type', Transaction::TYPE_TOPUP)->sum('amount'),
            'spark_balance'          => null,
            'spark_active_instances' => SparkInstance::where('status', 2)->count(),
            'role' => $isManager ? 'manager' : 'admin',
        ];

        // 审批数：经理看下属的，管理员看全部
        if ($isManager) {
            $subIds = $subordinateIds;
            $subIds[] = $user->id;
            $stats['pending_approvals'] = ProvisionApproval::whereIn('submitted_by', $subIds)->where('status', 'pending')->count();
            // 经理也显示自己名下客户数
            $stats['my_customers'] = Customer::where('sales_person', $user->name)->count();
        } else {
            $stats['pending_approvals'] = ProvisionApproval::where('status', 'pending')->count();
        }

        // Spark balance（仅有 spark.view 权限的角色才拉取）
        if ($user->can('spark.view')) {
            try {
                $spark = app(SparkApiService::class);
                $balanceData = $spark->getBalance();
                $stats['spark_balance'] = $balanceData['amount'] ?? null;
                $stats['spark_balance_ext'] = $balanceData['ext'] ?? null;
            } catch (\Throwable $e) {
                Log::debug('Dashboard: Spark balance fetch failed: ' . $e->getMessage());
            }

            try {
                $ipipv = app(IpipvApiService::class);
                if ($ipipv->isConfigured()) {
                    $ipipvInfo = $ipipv->getAppInfo();
                    $stats['ipipv_balance'] = $ipipvInfo['coin'] ?? $ipipvInfo['balance'] ?? $ipipvInfo['amount'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::debug('Dashboard: IPIPV balance fetch failed: ' . $e->getMessage());
            }
        }

        return $this->success($stats);
    }

    public function expiring(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Subscription::with(['customer', 'proxyIp'])->expiringSoon(7)->orderBy('expires_at');

        if (!$user->can('customer.view_all')) {
            $myCustomerIds = Customer::where('sales_person', $user->name)->pluck('id');
            $query->whereIn('customer_id', $myCustomerIds);
        }

        return $this->success($query->get());
    }

    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = IpAssignmentLog::with(['proxyIp', 'customer', 'operator'])->latest('created_at')->limit(20);

        if (!$user->can('customer.view_all')) {
            $myCustomerIds = Customer::where('sales_person', $user->name)->pluck('id');
            $query->whereIn('customer_id', $myCustomerIds);
        }

        return $this->success($query->get());
    }
}
