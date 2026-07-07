<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\RouterDevice;
use App\Models\RouterEventLog;
use App\Models\RouterRemoteCommand;
use App\Models\RouterWifiAccount;
use App\Models\Subscription;
use App\Services\Router\RouterProvisionService;
use App\Services\Router\RouterWifiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouterController extends Controller
{
    public function __construct(
        private RouterProvisionService $provisionService,
        private RouterWifiService $wifiService,
    ) {}

    public function myDevices(Request $request): JsonResponse
    {
        $customer = $request->user();

        $devices = RouterDevice::where('customer_id', $customer->id)
            ->withCount('wifiAccounts')
            ->orderByDesc('id')
            ->get()
            ->map(function ($device) {
                $device->is_online = $device->isOnline();
                $device->config_synced = $device->isConfigSynced();
                return $device;
            });

        return $this->success($devices);
    }

    public function showDevice(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $device = RouterDevice::where('id', $id)
            ->where('customer_id', $customer->id)
            ->with([
                'wifiAccounts.subscription:id,proxy_ip_id,status,price,expires_at',
                'wifiAccounts.subscription.proxyIp:id,ip_address,country_name',
                'wifiAccounts.subscription.forwardRule.forwardPlan:id,name,display_host,module',
            ])
            ->firstOrFail();

        $device->is_online = $device->isOnline();
        $device->config_synced = $device->isConfigSynced();

        return $this->success($device);
    }

    public function activate(Request $request): JsonResponse
    {
        $customer = $request->user();

        $data = $request->validate([
            'serial_number' => 'required|string|max:100',
            'module' => 'required|string|in:video,live_mobile,live_pc',
        ]);

        $device = RouterDevice::where('serial_number', $data['serial_number'])
            ->whereIn('status', ['provisioned', 'online'])
            ->first();

        if (!$device) {
            return $this->error('设备不存在或尚未完成初始化，请联系客服', 404);
        }

        if ($device->customer_id && $device->customer_id !== $customer->id) {
            return $this->error('该设备已绑定到其他客户', 422);
        }

        if ($device->customer_id === $customer->id) {
            return $this->success($device, '设备已绑定到您的账户');
        }

        try {
            $this->provisionService->bindToCustomer($device, $customer, $data['module']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $device->fresh()->load('wifiAccounts'),
            '设备激活成功'
        );
    }

    public function wifiAccounts(Request $request, int $deviceId): JsonResponse
    {
        $customer = $request->user();

        $device = RouterDevice::where('id', $deviceId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $accounts = $device->wifiAccounts()
            ->with([
                'subscription:id,proxy_ip_id,status,price,expires_at',
                'subscription.proxyIp:id,ip_address,country_name',
                'subscription.forwardRule.forwardPlan:id,name,display_host,module',
            ])
            ->orderBy('vlan_id')
            ->get();

        return $this->success($accounts);
    }

    public function createWifiAccount(Request $request, int $deviceId): JsonResponse
    {
        $customer = $request->user();

        $device = RouterDevice::where('id', $deviceId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $deviceMax = $device->wifi_max_devices_per_account ?? 10;

        $data = $request->validate([
            'username' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9._-]+$/',
            'password' => 'nullable|string|max:128',
            'label' => 'nullable|string|max:100',
            'proxy_subscription_id' => 'nullable|integer',
            'proxy_mode' => 'nullable|string|in:proxy,direct',
            'max_devices' => "nullable|integer|min:1|max:{$deviceMax}",
        ]);

        if (!empty($data['proxy_subscription_id'])) {
            $sub = Subscription::where('id', $data['proxy_subscription_id'])
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->first();

            if (!$sub) {
                return $this->error('无效的订阅或订阅已过期', 422);
            }
        }

        try {
            $account = $this->wifiService->createWifiAccount($device, $data);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $account->load(['subscription:id,proxy_ip_id,status,price,expires_at', 'subscription.proxyIp:id,ip_address,country_name', 'subscription.forwardRule.forwardPlan:id,name,display_host,module']),
            'WiFi 账号已创建'
        );
    }

    public function updateWifiAccount(Request $request, int $accountId): JsonResponse
    {
        $customer = $request->user();

        $account = RouterWifiAccount::whereHas('device', function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
        })->findOrFail($accountId);

        $device = $account->device;
        $deviceMax = $device ? ($device->wifi_max_devices_per_account ?? 10) : 10;

        $data = $request->validate([
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:128',
            'label' => 'nullable|string|max:100',
            'proxy_subscription_id' => 'nullable|integer',
            'proxy_mode' => 'nullable|string|in:proxy,direct',
            'is_active' => 'nullable|boolean',
            'max_devices' => "nullable|integer|min:1|max:{$deviceMax}",
        ]);

        if (!empty($data['proxy_subscription_id'])) {
            $sub = Subscription::where('id', $data['proxy_subscription_id'])
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->first();

            if (!$sub) {
                return $this->error('无效的订阅或订阅已过期', 422);
            }
        }

        $account = $this->wifiService->updateWifiAccount($account, $data);

        return $this->success(
            $account->load(['subscription:id,proxy_ip_id,status,price,expires_at', 'subscription.proxyIp:id,ip_address,country_name', 'subscription.forwardRule.forwardPlan:id,name,display_host,module']),
            'WiFi 账号已更新'
        );
    }

    public function deleteWifiAccount(Request $request, int $accountId): JsonResponse
    {
        $customer = $request->user();

        $account = RouterWifiAccount::whereHas('device', function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
        })->findOrFail($accountId);

        $this->wifiService->deleteWifiAccount($account);

        return $this->success(null, 'WiFi 账号已删除');
    }

    public function availableSubscriptions(Request $request, int $deviceId): JsonResponse
    {
        $customer = $request->user();

        $device = RouterDevice::where('id', $deviceId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $usedSubIds = $device->wifiAccounts()
            ->whereNotNull('proxy_subscription_id')
            ->pluck('proxy_subscription_id')
            ->toArray();

        $query = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->whereNotIn('id', $usedSubIds)
            ->with(['proxyIp:id,ip_address,country_name', 'forwardRule.forwardPlan:id,name,display_host,module']);

        if ($device->bound_module) {
            $query->whereHas('forwardRule.forwardPlan', function ($q) use ($device) {
                $q->where('module', $device->bound_module);
            });
        }

        $subscriptions = $query->orderByDesc('id')
            ->get()
            ->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'module' => $sub->forwardRule?->forwardPlan?->module,
                    'expires_at' => $sub->expires_at,
                    'price' => $sub->price,
                    'proxy_ip' => $sub->proxyIp ? [
                        'ip_address' => $sub->proxyIp->ip_address,
                        'country_name' => $sub->proxyIp->country_name,
                    ] : null,
                    'forward_plan' => $sub->forwardRule?->forwardPlan ? [
                        'name' => $sub->forwardRule->forwardPlan->name,
                        'display_host' => $sub->forwardRule->forwardPlan->display_host,
                    ] : null,
                ];
            });

        return $this->success($subscriptions);
    }

    public function deviceStatus(Request $request, int $deviceId): JsonResponse
    {
        $customer = $request->user();

        $device = RouterDevice::where('id', $deviceId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        return $this->success([
            'is_online' => $device->isOnline(),
            'config_synced' => $device->isConfigSynced(),
            'config_version' => $device->config_version,
            'applied_config_version' => $device->applied_config_version,
            'last_heartbeat_at' => $device->last_heartbeat_at,
            'system_info' => $device->system_info,
            'agent_version' => $device->agent_version,
            'wan_ip' => $device->wan_ip,
            'wired_ip' => '100.64.1.1',
        ]);
    }

    public function wifiProfile(Request $request, int $accountId)
    {
        $customer = $request->user();
        $account = RouterWifiAccount::whereHas('device', fn($q) => $q->where('customer_id', $customer->id))
            ->findOrFail($accountId);

        $device = $account->device;
        $ssid = 'SuniPIP-Proxy';
        $uuid = \Illuminate\Support\Str::uuid()->toString();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0"><dict>' . "\n"
            . '<key>PayloadContent</key><array><dict>' . "\n"
            . '<key>AutoJoin</key><true/>' . "\n"
            . '<key>EAPClientConfiguration</key><dict>' . "\n"
            . '<key>AcceptEAPTypes</key><array><integer>21</integer></array>' . "\n"
            . '<key>TTLSInnerAuthentication</key><string>PAP</string>' . "\n"
            . '<key>UserName</key><string>' . htmlspecialchars($account->username) . '</string>' . "\n"
            . '<key>UserPassword</key><string>' . htmlspecialchars($account->password) . '</string>' . "\n"
            . '</dict>' . "\n"
            . '<key>EncryptionType</key><string>WPA2</string>' . "\n"
            . '<key>HIDDEN_NETWORK</key><false/>' . "\n"
            . '<key>PayloadDescription</key><string>SuniPIP WiFi - ' . htmlspecialchars($account->label ?: $account->username) . '</string>' . "\n"
            . '<key>PayloadDisplayName</key><string>' . htmlspecialchars($ssid) . '</string>' . "\n"
            . '<key>PayloadIdentifier</key><string>com.sunipip.wifi.' . $account->id . '</string>' . "\n"
            . '<key>PayloadType</key><string>com.apple.wifi.managed</string>' . "\n"
            . '<key>PayloadUUID</key><string>' . $uuid . '</string>' . "\n"
            . '<key>PayloadVersion</key><integer>1</integer>' . "\n"
            . '<key>ProxyType</key><string>None</string>' . "\n"
            . '<key>SSID_STR</key><string>' . htmlspecialchars($ssid) . '</string>' . "\n"
            . '</dict></array>' . "\n"
            . '<key>PayloadDisplayName</key><string>SuniPIP WiFi 配置</string>' . "\n"
            . '<key>PayloadIdentifier</key><string>com.sunipip.router.' . $device->id . '.wifi.' . $account->id . '</string>' . "\n"
            . '<key>PayloadOrganization</key><string>SuniPIP</string>' . "\n"
            . '<key>PayloadRemovalDisallowed</key><false/>' . "\n"
            . '<key>PayloadType</key><string>Configuration</string>' . "\n"
            . '<key>PayloadUUID</key><string>' . \Illuminate\Support\Str::uuid()->toString() . '</string>' . "\n"
            . '<key>PayloadVersion</key><integer>1</integer>' . "\n"
            . '</dict></plist>';

        $filename = 'SuniPIP-WiFi-' . ($account->label ?: $account->username) . '.mobileconfig';

        return response($xml, 200)
            ->header('Content-Type', 'application/x-apple-aspen-config')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function cleanStaleConnections(Request $request, $id): JsonResponse
    {
        $customer = $request->user();
        $device = RouterDevice::where('id', $id)->where('customer_id', $customer->id)->first();

        if (!$device) {
            return $this->error('设备不存在', 404);
        }

        if ($device->wifi_version < 2) {
            return $this->error('此功能仅支持 WiFi v2 设备', 422);
        }

        $adminController = app(\App\Http\Controllers\Api\V1\RouterDeviceController::class);
        return $adminController->cleanStaleConnections($request, $device);
    }

}
