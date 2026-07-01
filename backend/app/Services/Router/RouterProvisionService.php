<?php

namespace App\Services\Router;

use App\Models\Customer;
use App\Models\RouterDevice;
use App\Models\RouterDeviceWgPeer;
use App\Models\RouterEventLog;
use App\Models\WgServer;
use Illuminate\Support\Str;

class RouterProvisionService
{
    public function __construct(
        private WgServerService $wgService,
        private RouterConfigService $configService,
    ) {}

    public function createDevice(array $data): RouterDevice
    {
        return RouterDevice::create([
            'device_no' => RouterDevice::generateDeviceNo(),
            'serial_number' => Str::upper(Str::uuid()->toString()),
            'hostname' => $data['hostname'] ?? null,
            'router_model_id' => $data['router_model_id'] ?? null,
            'ap_model_id' => $data['ap_model_id'] ?? null,
            'bundle_id' => $data['bundle_id'] ?? null,
            'remark' => $data['remark'] ?? null,
            'status' => 'inventory',
        ]);
    }

    public function generateInstallToken(RouterDevice $device): string
    {
        $token = bin2hex(random_bytes(64));
        $device->update([
            'install_token' => $token,
            'install_token_expires_at' => now()->addHours(72),
        ]);
        return $token;
    }

    public function registerDevice(
        string $installToken,
        string $serialNumber,
        string $wgPubKey1,
        string $wgPubKey2,
        ?string $hostname,
        ?string $agentVersion,
    ): array {
        $device = RouterDevice::where('install_token', $installToken)
            ->where('install_token_expires_at', '>', now())
            ->whereIn('status', ['inventory', 'provisioned'])
            ->first();

        if (!$device) {
            throw new \InvalidArgumentException('安装令牌无效或已过期');
        }


        $agentKey = 'rtr_' . bin2hex(random_bytes(64));

        $servers = WgServer::where('is_active', 1)->orderBy('id')->limit(2)->get();

        $wgConfigs = [];
        foreach ($servers as $i => $server) {
            $pubKey = $i === 0 ? $wgPubKey1 : $wgPubKey2;
            $peer = $this->wgService->generateWgPeer($device, $server);
            $peer->update(['peer_public_key' => $pubKey]);

            // Deploy peer config to the actual WG server via SSH
            $this->wgService->deployPeerToServer($server, $peer);

            $wgConfigs[] = [
                'interface' => 'wg' . $i,
                'assigned_ip' => $peer->assigned_ip,
                'server_public_key' => $server->public_key,
                'server_endpoint' => $server->endpoint,
                'mtu' => $server->mtu,
                'persistent_keepalive' => $peer->persistent_keepalive,
                'dns' => $server->dns,
                'table' => 'off',        // link-only management tunnel, no routing
                'allowed_ips' => $server->server_cidr,
            ];
        }

        $device->update([
            'hostname' => $hostname ?? $device->hostname,
            'system_info' => array_merge($device->system_info ?? [], ['board_serial' => $serialNumber]),
            'agent_key' => $agentKey,
            'agent_version' => $agentVersion,
            'status' => 'provisioned',
            'install_token' => null,
            'install_token_expires_at' => null,
            'wg_ip_1' => $wgConfigs[0]['assigned_ip'] ?? null,
            'wg_ip_2' => $wgConfigs[1]['assigned_ip'] ?? null,
            'wg_server_1_id' => $servers[0]->id ?? null,
            'wg_server_2_id' => $servers[1]->id ?? null,
        ]);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'register',
            'severity' => 'info',
            'message' => "设备注册成功: {$serialNumber}",
            'metadata' => ['hostname' => $hostname, 'agent_version' => $agentVersion],
            'created_at' => now(),
        ]);

        return [
            'device_id' => $device->id,
            'agent_key' => $agentKey,
            'wg_configs' => $wgConfigs,
        ];
    }

    public function bindToCustomer(RouterDevice $device, Customer $customer, string $module): void
    {
        if ($device->customer_id && $device->customer_id !== $customer->id) {
            throw new \InvalidArgumentException('设备已绑定到其他客户');
        }

        $device->update([
            'customer_id' => $customer->id,
            'bound_module' => $module,
            'bound_at' => now(),
        ]);

        $this->configService->pushConfig($device);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'bind',
            'severity' => 'info',
            'message' => "绑定客户: {$customer->customer_name}, 模块: {$module}",
            'metadata' => ['customer_id' => $customer->id, 'module' => $module],
            'created_at' => now(),
        ]);
    }

    public function unbindFromCustomer(RouterDevice $device): void
    {
        $oldCustomerId = $device->customer_id;

        $device->wifiAccounts()->delete();

        $device->update([
            'customer_id' => null,
            'bound_module' => null,
            'bound_at' => null,
        ]);

        $this->configService->pushConfig($device);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'unbind',
            'severity' => 'info',
            'message' => "解绑客户: {$oldCustomerId}",
            'metadata' => ['old_customer_id' => $oldCustomerId],
            'created_at' => now(),
        ]);
    }

    public function decommission(RouterDevice $device): void
    {
        if ($device->customer_id) {
            $this->unbindFromCustomer($device);
        }

        $device->update(['status' => 'decommissioned']);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'decommission',
            'severity' => 'warning',
            'message' => '设备已停用',
            'created_at' => now(),
        ]);
    }
}
