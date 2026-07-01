<?php

namespace App\Services\Router;

use App\Models\RouterDevice;
use App\Models\RouterEventLog;
use App\Models\RouterWifiAccount;
use App\Models\Subscription;
use Illuminate\Support\Str;

class RouterWifiService
{
    public function __construct(
        private RouterConfigService $configService,
    ) {}

    public function createWifiAccount(RouterDevice $device, array $data): RouterWifiAccount
    {
        $vlanInfo = $this->allocateVlanAndPrefix($device);

        $account = RouterWifiAccount::create([
            'router_device_id' => $device->id,
            'username' => $data['username'] ?? 'wifi-' . Str::lower(Str::random(4)),
            'password' => $data['password'] ?? Str::random(12),
            'label' => $data['label'] ?? null,
            'vlan_id' => $vlanInfo['vlan_id'],
            'ip_prefix' => $vlanInfo['ip_prefix'],
            'gateway_ip' => $vlanInfo['gateway_ip'],
            'proxy_subscription_id' => $data['proxy_subscription_id'] ?? null,
            'proxy_mode' => $data['proxy_mode'] ?? 'proxy',
            'is_active' => 1,
            'max_devices' => $data['max_devices'] ?? 5,
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

        if (isset($data['max_devices'])) {
            $updates['max_devices'] = $data['max_devices'];
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

        $this->configService->pushConfig($device);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'wifi_change',
            'severity' => 'info',
            'message' => "删除 WiFi 账号: {$username}",
            'created_at' => now(),
        ]);
    }

    public function allocateVlanAndPrefix(RouterDevice $device): array
    {
        $usedVlans = $device->wifiAccounts()->pluck('vlan_id')->toArray();

        $vlanId = 10;
        while (in_array($vlanId, $usedVlans) && $vlanId <= 200) {
            $vlanId++;
        }

        if ($vlanId > 200) {
            throw new \RuntimeException('VLAN 已用尽（最大 200）');
        }

        $ipPrefix = "10.10.{$vlanId}.0/29";
        $gatewayIp = "10.10.{$vlanId}.1";

        return [
            'vlan_id' => $vlanId,
            'ip_prefix' => $ipPrefix,
            'gateway_ip' => $gatewayIp,
        ];
    }

    public function linkSubscription(RouterWifiAccount $account, Subscription $subscription): void
    {
        $account->update(['proxy_subscription_id' => $subscription->id]);
        $this->configService->pushConfig($account->device);
    }
}
