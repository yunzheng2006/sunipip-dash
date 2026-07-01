<?php

namespace App\Services\Router;

use App\Models\RouterDevice;
use App\Models\RouterEventLog;
use App\Models\RouterWifiAccount;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RouterWifiService
{
    public function __construct(
        private RouterConfigService $configService,
    ) {}

    public function createWifiAccount(RouterDevice $device, array $data): RouterWifiAccount
    {
        $maxDevices = $data['max_devices'] ?? 5;
        $ipInfo = $this->allocateIpRange($device, $maxDevices);

        $account = RouterWifiAccount::create([
            'router_device_id' => $device->id,
            'username' => $data['username'] ?? 'wifi-' . Str::lower(Str::random(4)),
            'password' => $data['password'] ?? Str::random(12),
            'label' => $data['label'] ?? null,
            'vlan_id' => $ipInfo['vlan_id'],
            'ip_prefix' => $ipInfo['ip_prefix'],
            'gateway_ip' => $ipInfo['gateway_ip'],
            'ip_start_index' => $ipInfo['ip_start_index'],
            'proxy_subscription_id' => $data['proxy_subscription_id'] ?? null,
            'proxy_mode' => $data['proxy_mode'] ?? 'proxy',
            'is_active' => 1,
            'max_devices' => $maxDevices,
        ]);

        $this->configService->pushConfig($device);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'wifi_change',
            'severity' => 'info',
            'message' => "创建 WiFi 账号: {$account->username}",
            'metadata' => ['account_id' => $account->id, 'vlan_id' => $account->vlan_id],
            'created_at' => now(),
        ]);

        return $account;
    }

    public function updateWifiAccount(RouterWifiAccount $account, array $data): RouterWifiAccount
    {
        $updates = [];

        foreach (['username', 'password', 'proxy_mode'] as $field) {
            if (!empty($data[$field])) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('label', $data)) {
            $updates['label'] = $data['label'];
        }

        // Allow explicit null to unbind subscription
        if (array_key_exists('proxy_subscription_id', $data)) {
            $updates['proxy_subscription_id'] = $data['proxy_subscription_id'];
            if ($data['proxy_subscription_id'] === null) {
                $updates['proxy_mode'] = 'direct';
            }
        }

        if (array_key_exists('is_active', $data)) {
            $updates['is_active'] = $data['is_active'];
        }

        if (isset($data['max_devices']) && $data['max_devices'] != $account->max_devices) {
            throw new \RuntimeException('设备数上限创建后不可修改，请删除账号重新创建');
        }

        if (!empty($updates)) {
            $account->update($updates);
        }

        $this->configService->pushConfig($account->device);

        return $account->fresh();
    }

    public function deleteWifiAccount(RouterWifiAccount $account): void
    {
        $device = $account->device;
        $username = $account->username;

        $account->delete();

        // Reclaim IP pool when all accounts are deleted
        if ($device->wifiAccounts()->count() === 0) {
            $device->update(['wifi_ip_next_index' => 2]);
        }

        $this->configService->pushConfig($device);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'wifi_change',
            'severity' => 'info',
            'message' => "删除 WiFi 账号: {$username}",
            'created_at' => now(),
        ]);
    }

    public function allocateIpRange(RouterDevice $device, int $maxDevices): array
    {
        return DB::transaction(function () use ($device, $maxDevices) {
            $locked = RouterDevice::where('id', $device->id)->lockForUpdate()->first();
            $startIndex = $locked->wifi_ip_next_index ?? 2;

            if ($startIndex + $maxDevices > 65534) {
                throw new \RuntimeException('IP 地址池已用尽');
            }

            $locked->update(['wifi_ip_next_index' => $startIndex + $maxDevices]);

            $firstIp = long2ip(ip2long('10.10.0.0') + $startIndex);

            return [
                'vlan_id' => 10,
                'ip_prefix' => "{$firstIp}/32",
                'gateway_ip' => '10.10.0.1',
                'ip_start_index' => $startIndex,
            ];
        });
    }

    public function linkSubscription(RouterWifiAccount $account, Subscription $subscription): void
    {
        $account->update(['proxy_subscription_id' => $subscription->id]);
        $this->configService->pushConfig($account->device);
    }
}
