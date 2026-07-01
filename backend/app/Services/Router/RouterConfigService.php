<?php

namespace App\Services\Router;

use App\Models\RouterConfigSnapshot;
use App\Models\RouterDevice;

class RouterConfigService
{
    public function generateFullConfig(RouterDevice $device): array
    {
        return [
            'config_version' => $device->config_version,
            'generated_at' => now()->toIso8601String(),
            'device' => [
                'id' => $device->id,
                'serial_number' => $device->serial_number,
                'hostname' => $device->hostname,
                'bound_module' => $device->bound_module,
                'ap_management_enabled' => $device->ap_management_enabled,
            ],
            'network' => $this->buildNetworkConfig($device),
            'freeradius' => $this->buildFreeRadiusConfig($device),
            'clash' => $this->buildClashConfig($device),
            'wireguard' => $this->buildWireGuardConfig($device),
            'local_page' => $this->buildLocalPageHtml(),
            'ap_config' => [],
        ];
    }

    public function buildNetworkConfig(RouterDevice $device): array
    {
        $vlans = [];
        foreach ($device->wifiAccounts()->where('is_active', 1)->get() as $account) {
            $prefix = $account->ip_prefix;
            $gw = $account->gateway_ip;
            $vlanId = $account->vlan_id;

            $baseIp = explode('/', $prefix)[0];
            $parts = explode('.', $baseIp);
            $dhcpStart = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.2';
            $dhcpEnd = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.6';

            $vlans[] = [
                'vlan_id' => $vlanId,
                'interface' => "eth2.{$vlanId}",
                'bridge' => "br-vlan{$vlanId}",
                'ip' => str_replace('.0/', ".1/", $prefix),
                'dhcp' => [
                    'range_start' => $dhcpStart,
                    'range_end' => $dhcpEnd,
                    'lease' => '1m',
                    'gateway' => $gw,
                    'dns' => $gw,
                ],
            ];
        }

        return [
            'wan' => ['interface' => 'eth0', 'mode' => 'dhcp'],
            'management' => [
                'interface' => 'eth1',
                'ip' => '172.10.0.1/24',
                'dhcp' => [
                    'range_start' => '172.10.0.100',
                    'range_end' => '172.10.0.200',
                    'lease' => '1m',
                    'gateway' => '172.10.0.1',
                    'dns' => '172.10.0.1',
                ],
            ],
            'wired' => [
                'interface' => 'eth3',
                'ip' => '100.64.1.1/24',
                'dhcp' => [
                    'range_start' => '100.64.1.100',
                    'range_end' => '100.64.1.200',
                    'lease' => '1m',
                    'gateway' => '100.64.1.1',
                    'dns' => '100.64.1.1',
                ],
            ],
            'trunk' => [
                'interface' => 'eth2',
                'ip' => '10.20.0.1/24',
                'dhcp' => [
                    'range_start' => '10.20.0.100',
                    'range_end' => '10.20.0.200',
                    'lease' => '1m',
                    'gateway' => '10.20.0.1',
                    'dns' => '10.20.0.1',
                ],
            ],
            'vlans' => $vlans,
        ];
    }

    public function buildFreeRadiusConfig(RouterDevice $device): array
    {
        $users = [];
        foreach ($device->wifiAccounts()->where('is_active', 1)->get() as $account) {
            $users[] = [
                'username' => $account->username,
                'password' => $account->password,
                'vlan_id' => $account->vlan_id,
                'label' => $account->label,
                'max_devices' => $account->max_devices,
            ];
        }

        return [
            'clients' => [
                ['name' => 'ap', 'ip' => '0.0.0.0/0', 'secret' => 'sunipip_radius_secret'],
            ],
            'users' => $users,
        ];
    }

    public function buildClashConfig(RouterDevice $device): array
    {
        $proxies = [];
        $proxyNames = [];
        $rules = [];

        $accounts = $device->wifiAccounts()
            ->where('is_active', 1)
            ->with(['subscription.proxyIp', 'subscription.forwardRule'])
            ->get();

        foreach ($accounts as $account) {
            $socks5Parts = $account->getSocks5Parts();
            if (!$socks5Parts || $account->proxy_mode === 'direct') {
                continue;
            }

            $proxyName = "proxy-vlan-{$account->vlan_id}";
            $proxies[] = [
                'name' => $proxyName,
                'type' => 'socks5',
                'server' => $socks5Parts['server'],
                'port' => $socks5Parts['port'],
                'username' => $socks5Parts['username'],
                'password' => $socks5Parts['password'],
            ];
            $proxyNames[] = $proxyName;

            $rules[] = [
                'type' => 'SRC-IP-CIDR',
                'value' => $account->ip_prefix,
                'proxy' => $proxyName,
            ];
        }

        $proxyGroups = [];
        $defaultProxy = 'DIRECT';

        if (!empty($proxyNames)) {
            $defaultProxy = 'GLOBAL';
            $proxyGroups[] = [
                'name' => 'GLOBAL',
                'type' => 'select',
                'proxies' => array_merge($proxyNames, ['DIRECT']),
            ];
        }

        $rules[] = ['type' => 'MATCH', 'value' => '', 'proxy' => $defaultProxy];

        return [
            'proxies' => $proxies,
            'proxy_groups' => $proxyGroups,
            'rules' => $rules,
        ];
    }

    public function buildWireGuardConfig(RouterDevice $device): array
    {
        $peers = [];
        $wgPeers = $device->wgPeers()->where('is_active', 1)->with('server')->get();

        foreach ($wgPeers as $i => $peer) {
            $server = $peer->server;
            if (!$server) continue;

            $peers[] = [
                'interface' => 'wg' . $i,
                'private_key' => $peer->peer_private_key,
                'address' => $peer->assigned_ip,
                'mtu' => $server->mtu,
                'table' => 'off',   // link-only management tunnel, no routing
                'peer' => [
                    'public_key' => $server->public_key,
                    'endpoint' => $server->endpoint,
                    'allowed_ips' => $server->server_cidr,
                    'persistent_keepalive' => $peer->persistent_keepalive,
                ],
            ];
        }

        return ['peers' => $peers];
    }

    public function buildLocalPageHtml(): string
    {
        $path = resource_path('router/local-page.html');
        if (file_exists($path)) {
            return file_get_contents($path);
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>SuniPIP Router</title></head><body><h1>SuniPIP Router</h1><p>Local page not found.</p></body></html>';
    }

    public function pushConfig(RouterDevice $device, ?int $userId = null): void
    {
        $version = $device->bumpConfigVersion();
        $config = $this->generateFullConfig($device);

        RouterConfigSnapshot::create([
            'router_device_id' => $device->id,
            'config_version' => $version,
            'config_type' => 'full',
            'payload' => $config,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
