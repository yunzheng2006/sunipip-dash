<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RouterDevice;
use App\Models\RouterEventLog;
use App\Models\RouterRemoteCommand;
use App\Services\Router\RouterConfigService;
use App\Services\Router\RouterProvisionService;
use App\Services\Router\RouterWifiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RouterDeviceController extends Controller
{
    public function __construct(
        private RouterProvisionService $provisionService,
        private RouterConfigService $configService,
        private RouterWifiService $wifiService,
    ) {}

    public function stats(): JsonResponse
    {
        $total = RouterDevice::count();
        $online = RouterDevice::online()->count();
        $offline = RouterDevice::where('status', 'provisioned')
            ->where(function ($q) {
                $q->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subMinutes(5));
            })->count();
        $inventory = RouterDevice::where('status', 'inventory')->count();
        $unbound = RouterDevice::unbound()->whereIn('status', ['provisioned'])->count();
        $decommissioned = RouterDevice::where('status', 'decommissioned')->count();

        return $this->success([
            'total' => $total,
            'online' => $online,
            'offline' => $offline,
            'inventory' => $inventory,
            'unbound' => $unbound,
            'decommissioned' => $decommissioned,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $devices = QueryBuilder::for(RouterDevice::class)
            ->with([
                'customer:id,customer_name,company_name',
                'routerModel:id,name',
                'apModel:id,name',
                'bundle:id,name',
            ])
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('customer_id'),
                AllowedFilter::exact('bound_module'),
                AllowedFilter::exact('router_model_id'),
                AllowedFilter::exact('bundle_id'),
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('device_no', 'like', "%{$value}%")
                            ->orWhere('serial_number', 'like', "%{$value}%")
                            ->orWhere('hostname', 'like', "%{$value}%")
                            ->orWhere('remark', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::callback('online', function ($query, $value) {
                    if ($value) {
                        $query->online();
                    } else {
                        $query->offline();
                    }
                }),
            ])
            ->allowedSorts(['id', 'serial_number', 'status', 'last_heartbeat_at', 'created_at'])
            ->defaultSort('-id')
            ->paginate($request->input('per_page', 15));

        return $this->paginated($devices);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hostname' => 'nullable|string|max:100',
            'router_model_id' => 'nullable|integer|exists:router_models,id',
            'ap_model_id' => 'nullable|integer|exists:ap_models,id',
            'bundle_id' => 'nullable|integer|exists:router_bundles,id',
            'remark' => 'nullable|string|max:500',
        ]);

        $device = $this->provisionService->createDevice($data);

        return $this->success($device->load(['routerModel:id,name', 'apModel:id,name', 'bundle:id,name']), '设备已添加到库存');
    }

    public function show(RouterDevice $routerDevice): JsonResponse
    {
        $routerDevice->load([
            'customer:id,customer_name,company_name',
            'routerModel:id,name,cpu,ram_mb,storage_gb,ports',
            'apModel:id,name,band',
            'bundle:id,name,bundle_price',
            'wifiAccounts.subscription:id,proxy_ip_id,status,price,expires_at',
            'wifiAccounts.subscription.proxyIp:id,ip_address,country_name',
            'wgPeers.server:id,name,endpoint',
        ]);

        $routerDevice->config_synced = $routerDevice->isConfigSynced();

        return $this->success($routerDevice);
    }

    public function update(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => 'nullable|string|max:100|unique:router_devices,serial_number,' . $routerDevice->id,
            'remark' => 'nullable|string|max:500',
            'router_model_id' => 'nullable|integer|exists:router_models,id',
            'ap_model_id' => 'nullable|integer|exists:ap_models,id',
            'bundle_id' => 'nullable|integer|exists:router_bundles,id',
            'ap_management_enabled' => 'nullable|boolean',
            'ap_ip' => 'nullable|string|max:45',
            'target_agent_version' => 'nullable|string|max:30',
        ]);

        $routerDevice->update($data);

        return $this->success(
            $routerDevice->fresh()->load(['routerModel:id,name', 'apModel:id,name', 'bundle:id,name']),
            '设备已更新'
        );
    }

    public function destroy(RouterDevice $routerDevice): JsonResponse
    {
        $this->provisionService->decommission($routerDevice);

        return $this->success(null, '设备已停用');
    }

    public function reauthorize(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'agent_key' => 'required|string|min:10',
        ]);

        $routerDevice->update([
            'agent_key' => $data['agent_key'],
            'status' => 'online',
        ]);

        return $this->success(null, '设备已重新授权');
    }

    public function generateInstallToken(RouterDevice $routerDevice): JsonResponse
    {
        if ($routerDevice->status === 'decommissioned') {
            return $this->error('已停用的设备无法生成安装令牌', 422);
        }

        $reinstall = !in_array($routerDevice->status, ['inventory', 'provisioned']);
        if ($reinstall) {
            $routerDevice->update([
                'status' => 'provisioned',
            ]);
        }

        $token = $this->provisionService->generateInstallToken($routerDevice);

        $installUrl = rtrim(config('app.url'), '/') . "/api/v1/router-install/{$token}";

        return $this->success([
            'install_token' => $token,
            'install_url' => $installUrl,
            'expires_at' => $routerDevice->fresh()->install_token_expires_at,
        ], $reinstall ? '重装令牌已生成，设备状态已重置为待配置' : '安装令牌已生成，有效期72小时');
    }

    public function bind(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'module' => 'required|string|in:video,live_mobile,live_pc',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        try {
            $this->provisionService->bindToCustomer($routerDevice, $customer, $data['module']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($routerDevice->fresh()->load('customer:id,customer_name,company_name'), '设备已绑定客户');
    }

    public function unbind(RouterDevice $routerDevice): JsonResponse
    {
        if (!$routerDevice->customer_id) {
            return $this->error('设备未绑定客户', 422);
        }

        $this->provisionService->unbindFromCustomer($routerDevice);

        return $this->success(null, '设备已解绑');
    }

    public function pushConfig(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        if ($routerDevice->status === 'decommissioned') {
            return $this->error('已停用设备无法推送配置', 422);
        }

        $this->configService->pushConfig($routerDevice, $request->user()?->id);

        return $this->success([
            'config_version' => $routerDevice->fresh()->config_version,
        ], '配置已推送');
    }

    public function events(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $events = $routerDevice->eventLogs()
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return $this->paginated($events);
    }

    public function wifiAccounts(RouterDevice $routerDevice): JsonResponse
    {
        $accounts = $routerDevice->wifiAccounts()
            ->with(['subscription:id,proxy_ip_id,status,price,expires_at', 'subscription.proxyIp:id,ip_address,country_name'])
            ->orderBy('vlan_id')
            ->get();

        return $this->success($accounts);
    }

    public function createWifiAccount(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        if (!$routerDevice->customer_id) {
            return $this->error('设备未绑定客户，无法创建 WiFi 账号', 422);
        }

        $data = $request->validate([
            'username' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_-]+$/',
            'password' => 'nullable|string|max:128',
            'label' => 'nullable|string|max:100',
            'proxy_subscription_id' => 'nullable|integer|exists:subscriptions,id',
            'proxy_mode' => 'nullable|string|in:proxy,direct',
            'max_devices' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $account = $this->wifiService->createWifiAccount($routerDevice, $data);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($account->load('subscription:id,proxy_ip_id,status,price,expires_at'), 'WiFi 账号已创建');
    }

    public function updateWifiAccount(Request $request, int $accountId): JsonResponse
    {
        $account = \App\Models\RouterWifiAccount::findOrFail($accountId);

        $data = $request->validate([
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:128',
            'label' => 'nullable|string|max:100',
            'proxy_subscription_id' => 'nullable|integer|exists:subscriptions,id',
            'proxy_mode' => 'nullable|string|in:proxy,direct',
            'is_active' => 'nullable|boolean',
            'max_devices' => 'nullable|integer|min:1|max:5',
        ]);

        $account = $this->wifiService->updateWifiAccount($account, $data);

        return $this->success($account->load('subscription:id,proxy_ip_id,status,price,expires_at'), 'WiFi 账号已更新');
    }

    public function deleteWifiAccount(int $accountId): JsonResponse
    {
        $account = \App\Models\RouterWifiAccount::findOrFail($accountId);

        $this->wifiService->deleteWifiAccount($account);

        return $this->success(null, 'WiFi 账号已删除');
    }

    public function availableSubscriptions(RouterDevice $routerDevice): JsonResponse
    {
        if (!$routerDevice->customer_id) {
            return $this->success([]);
        }

        $usedSubIds = $routerDevice->wifiAccounts()
            ->whereNotNull('proxy_subscription_id')
            ->pluck('proxy_subscription_id')
            ->toArray();

        $query = \App\Models\Subscription::where('customer_id', $routerDevice->customer_id)
            ->where('status', 'active')
            ->whereNotIn('id', $usedSubIds)
            ->with(['proxyIp:id,ip_address,country_name', 'forwardRule.forwardPlan:id,name,display_host,module']);

        if ($routerDevice->bound_module) {
            $query->whereHas('forwardRule.forwardPlan', function ($q) use ($routerDevice) {
                $q->where('module', $routerDevice->bound_module);
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

    public function rebootDevice(RouterDevice $routerDevice): JsonResponse
    {
        if ($routerDevice->status === 'decommissioned') {
            return $this->error('设备已停用', 422);
        }

        $result = $this->executeRemoteCommand($routerDevice, 'reboot');
        if ($result === null) {
            return $this->error('无法连接设备：WG 隧道信息不完整', 422);
        }

        RouterEventLog::create([
            'router_device_id' => $routerDevice->id,
            'event_type' => 'remote_reboot',
            'severity' => 'warning',
            'message' => '管理员远程重启设备',
            'metadata' => ['operator' => request()->user()?->name],
            'created_at' => now(),
        ]);

        return $this->success(null, '重启命令已发送');
    }

    public function toggleTrunkDhcp(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        if ($routerDevice->status === 'decommissioned') {
            return $this->error('设备已停用', 422);
        }

        $enabled = $data['enabled'];

        if ($enabled) {
            $cmd = 'cat /etc/dnsmasq.d/sunipip-trunk.conf | grep -q "^dhcp-range=set:mgmt" && echo "already enabled" && exit 0; '
                . 'NETMASK=$(grep "dhcp-range=set:wifi" /etc/dnsmasq.d/sunipip-trunk.conf | head -1 | sed "s/.*,static,//;s/,.*//"); '
                . '[ -z "$NETMASK" ] && NETMASK="255.255.255.0"; '
                . 'sed -i "/^interface=/a dhcp-range=set:mgmt,10.20.0.100,10.20.0.200,255.255.255.0,5m\ndhcp-option=tag:mgmt,3,10.20.0.1\ndhcp-option=tag:mgmt,6,10.20.0.1" /etc/dnsmasq.d/sunipip-trunk.conf; '
                . 'systemctl restart dnsmasq; '
                . 'echo "trunk DHCP enabled"';
        } else {
            $cmd = 'sed -i "/^dhcp-range=set:mgmt/d;/^dhcp-option=tag:mgmt/d" /etc/dnsmasq.d/sunipip-trunk.conf; '
                . 'systemctl restart dnsmasq; '
                . 'echo "trunk DHCP disabled"';
        }

        RouterRemoteCommand::create([
            'router_device_id' => $routerDevice->id,
            'command' => $cmd,
            'timeout' => 30,
            'status' => 'pending',
        ]);

        $action = $enabled ? '开启' : '关闭';
        RouterEventLog::create([
            'router_device_id' => $routerDevice->id,
            'event_type' => 'trunk_dhcp_toggle',
            'severity' => 'info',
            'message' => "管理员{$action}管理段 DHCP",
            'metadata' => ['enabled' => $enabled, 'operator' => request()->user()?->name],
            'created_at' => now(),
        ]);

        return $this->success(null, "管理段 DHCP {$action}命令已下发，等待设备执行");
    }

    public function restartService(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'service' => 'required|string|in:clash,freeradius,dnsmasq,sunipip-router-agent',
        ]);

        if ($routerDevice->status === 'decommissioned') {
            return $this->error('设备已停用', 422);
        }

        $result = $this->executeRemoteCommand($routerDevice, "systemctl restart {$data['service']}");
        if ($result === null) {
            return $this->error('无法连接设备：WG 隧道信息不完整', 422);
        }

        RouterEventLog::create([
            'router_device_id' => $routerDevice->id,
            'event_type' => 'remote_restart_service',
            'severity' => 'info',
            'message' => "管理员远程重启服务: {$data['service']}",
            'metadata' => ['service' => $data['service'], 'operator' => request()->user()?->name],
            'created_at' => now(),
        ]);

        return $this->success(null, "服务 {$data['service']} 重启命令已发送");
    }

    public function getApDiscovery(RouterDevice $routerDevice): JsonResponse
    {
        $lastError = null;
        if ($routerDevice->ap_discover_requested) {
            $lastError = RouterEventLog::where('router_device_id', $routerDevice->id)
                ->where('event_type', 'ap_discovery_failed')
                ->orderByDesc('created_at')
                ->first(['message', 'created_at']);
        }

        return $this->success([
            'ap_management_enabled' => $routerDevice->ap_management_enabled,
            'ap_ip' => $routerDevice->ap_ip,
            'ap_discover_requested' => $routerDevice->ap_discover_requested,
            'ap_discovery' => $routerDevice->ap_discovery,
            'ap_config' => $routerDevice->ap_config,
            'last_scan_error' => $lastError ? [
                'message' => $lastError->message,
                'at' => $lastError->created_at->toDateTimeString(),
            ] : null,
        ]);
    }

    public function triggerApDiscovery(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        if (!$routerDevice->ap_management_enabled) {
            return $this->error('请先开启 AP 管理', 422);
        }

        $data = $request->validate([
            'ap_username' => 'required|string|max:64',
            'ap_password' => 'required|string|max:128',
            'ap_protocol' => 'nullable|string|in:ssh,http',
            'ap_ip' => 'nullable|string|max:45',
        ]);

        $apConfig = $routerDevice->ap_config ?? [];
        $apConfig['ap_username'] = $data['ap_username'];
        $apConfig['ap_password'] = $data['ap_password'];
        $apConfig['ap_protocol'] = $data['ap_protocol'] ?? 'ssh';

        $updates = [
            'ap_config' => $apConfig,
            'ap_discover_requested' => true,
        ];
        if (!empty($data['ap_ip'])) {
            $updates['ap_ip'] = $data['ap_ip'];
        }

        $routerDevice->update($updates);
        $this->configService->pushConfig($routerDevice, $request->user()?->id);

        return $this->success(null, 'AP 扫描请求已发送，等待 Agent 执行扫描...');
    }

    public function getApConfig(RouterDevice $routerDevice): JsonResponse
    {
        return $this->success([
            'ap_management_enabled' => $routerDevice->ap_management_enabled,
            'ap_ip' => $routerDevice->ap_ip,
            'ap_config' => $routerDevice->ap_config ?? [
                'ssid_2g' => '',
                'ssid_5g' => '',
                'country_code' => 'US',
                'channel_2g' => 'auto',
                'channel_5g' => 'auto',
                'htmode_2g' => 'HT40',
                'htmode_5g' => 'VHT80',
                'tx_power_2g' => 'auto',
                'tx_power_5g' => 'auto',
                'hidden' => false,
                'radius_secret' => 'sunipip_radius_secret',
                'ap_username' => 'root',
                'ap_password' => '',
                'ap_protocol' => 'ssh',
            ],
        ]);
    }

    public function updateApConfig(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'ap_management_enabled' => 'nullable|boolean',
            'ap_ip' => 'nullable|string|max:45',
            'ap_config' => 'nullable|array',
            'ap_config.ssid_2g' => 'nullable|string|max:32',
            'ap_config.ssid_5g' => 'nullable|string|max:32',
            'ap_config.country_code' => 'nullable|string|size:2',
            'ap_config.channel_2g' => 'nullable|string|max:10',
            'ap_config.channel_5g' => 'nullable|string|max:10',
            'ap_config.htmode_2g' => 'nullable|string|in:HT20,HT40',
            'ap_config.htmode_5g' => 'nullable|string|in:VHT20,VHT40,VHT80,VHT160,HE80,HE160',
            'ap_config.tx_power_2g' => 'nullable|string|max:10',
            'ap_config.tx_power_5g' => 'nullable|string|max:10',
            'ap_config.hidden' => 'nullable|boolean',
            'ap_config.radius_secret' => 'nullable|string|max:128',
            'ap_config.ap_username' => 'nullable|string|max:64',
            'ap_config.ap_password' => 'nullable|string|max:128',
            'ap_config.ap_protocol' => 'nullable|string|in:ssh,http',
        ]);

        $updates = [];
        if (array_key_exists('ap_management_enabled', $data)) {
            $updates['ap_management_enabled'] = $data['ap_management_enabled'];
        }
        if (array_key_exists('ap_ip', $data)) {
            $updates['ap_ip'] = $data['ap_ip'];
        }
        if (isset($data['ap_config'])) {
            $existing = $routerDevice->ap_config ?? [];
            $updates['ap_config'] = array_merge($existing, $data['ap_config']);
        }

        $routerDevice->update($updates);

        if (isset($data['ap_config'])) {
            $this->configService->pushConfig($routerDevice, $request->user()?->id);
        }

        return $this->success($routerDevice->fresh(['routerModel:id,name', 'apModel:id,name']), 'AP 配置已更新');
    }

    public function getAgentVersion(): JsonResponse
    {
        $versionFile = '/www/uploads/sunipip/router-agent/version.txt';
        if (!file_exists($versionFile)) {
            $versionFile = storage_path('app/router-agent/version.txt');
        }

        $version = null;
        $uploadedAt = null;
        $fileSize = null;

        if (file_exists($versionFile)) {
            $version = trim(file_get_contents($versionFile));
            $uploadedAt = date('Y-m-d H:i:s', filemtime($versionFile));
        }

        $binaryPath = '/www/uploads/sunipip/router-agent/sunipip-router-agent';
        if (!file_exists($binaryPath)) {
            $binaryPath = storage_path('app/router-agent/sunipip-router-agent');
        }
        if (file_exists($binaryPath)) {
            $fileSize = filesize($binaryPath);
        }

        $onlineCount = RouterDevice::online()->count();
        $totalProvisioned = RouterDevice::whereIn('status', ['online', 'offline', 'provisioned'])->count();

        $versionStats = RouterDevice::whereIn('status', ['online', 'offline', 'provisioned'])
            ->whereNotNull('agent_version')
            ->selectRaw('agent_version, COUNT(*) as count')
            ->groupBy('agent_version')
            ->orderByDesc('count')
            ->get();

        return $this->success([
            'current_version' => $version,
            'uploaded_at' => $uploadedAt,
            'binary_size' => $fileSize,
            'online_devices' => $onlineCount,
            'total_provisioned' => $totalProvisioned,
            'version_distribution' => $versionStats,
        ]);
    }

    public function uploadAgentBinary(Request $request): JsonResponse
    {
        $request->validate([
            'version' => 'required|string|max:20|regex:/^\d+\.\d+\.\d+$/',
            'binary' => 'required|file|max:51200',
        ]);

        $version = $request->input('version');

        $uploadDir = '/www/uploads/sunipip/router-agent';
        if (!is_dir($uploadDir)) {
            $uploadDir = storage_path('app/router-agent');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
        }

        $binary = $request->file('binary');
        $fileSize = $binary->getSize();
        $binary->move($uploadDir, 'sunipip-router-agent');
        chmod($uploadDir . '/sunipip-router-agent', 0755);

        file_put_contents($uploadDir . '/version.txt', $version);

        \Log::info("Agent binary uploaded: v{$version}", [
            'size' => $fileSize,
            'operator' => $request->user()?->name,
        ]);

        return $this->success([
            'version' => $version,
            'size' => $fileSize,
        ], "Agent v{$version} 已上传，在线设备将在下次心跳时自动更新");
    }

    public function sendCommand(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $data = $request->validate([
            'command' => 'required|string|max:4000',
            'timeout' => 'nullable|integer|min:5|max:300',
        ]);

        $cmd = \App\Models\RouterRemoteCommand::create([
            'router_device_id' => $routerDevice->id,
            'command' => $data['command'],
            'timeout' => $data['timeout'] ?? 30,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return $this->success($cmd, '命令已下发，等待设备执行');
    }

    public function commandHistory(Request $request, RouterDevice $routerDevice): JsonResponse
    {
        $commands = \App\Models\RouterRemoteCommand::where('router_device_id', $routerDevice->id)
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->limit($request->input('limit', 20))
            ->get();

        return $this->success($commands);
    }

    private function executeRemoteCommand(RouterDevice $device, string $command): ?bool
    {
        $wgIp = $device->wg_ip_1;
        if (!$wgIp) return null;

        // Strip /32 CIDR suffix if present
        $ip = explode('/', $wgIp)[0];

        $server = $device->wgServer1;
        if (!$server || !$server->ssh_private_key) return null;

        $keyFile = tempnam(sys_get_temp_dir(), 'wg_ssh_');
        file_put_contents($keyFile, $server->ssh_private_key);
        chmod($keyFile, 0600);

        try {
            // SSH via WG server as jump host to reach the device
            $sshCmd = sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes -i %s root@%s %s',
                escapeshellarg($keyFile),
                escapeshellarg($ip),
                escapeshellarg($command)
            );

            exec($sshCmd . ' 2>&1', $output, $exitCode);

            \Log::info("Remote command to device #{$device->id}", [
                'command' => $command,
                'exit_code' => $exitCode,
                'output' => implode("\n", $output),
            ]);

            return $exitCode === 0;
        } finally {
            @unlink($keyFile);
        }
    }
}
