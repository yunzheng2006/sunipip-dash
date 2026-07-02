<?php

namespace App\Console\Commands;

use App\Models\RouterDevice;
use App\Models\RouterRemoteCommand;
use App\Models\RouterWifiAccount;
use App\Services\Router\RouterConfigService;
use App\Services\Router\RouterWifiService;
use Illuminate\Console\Command;

class MigrateWifiV2 extends Command
{
    protected $signature = 'wifi:migrate-v2
        {--device= : Target specific device ID}
        {--dry-run : Preview changes without applying}
        {--skip-ap : Skip AP config push, only push platform config}
        {--skip-agent-update : Skip setting target_agent_version}
        {--check : Check migration readiness (agent versions, AP IPs)}
        {--ap-password= : Override AP SSH password}';

    protected $description = 'Migrate devices from WiFi v1 (dynamic VLAN) to v2 (flat IP, NSS enabled)';

    public function handle(): int
    {
        $query = RouterDevice::whereNotNull('customer_id')
            ->whereHas('wifiAccounts');

        if ($deviceId = $this->option('device')) {
            $query = RouterDevice::where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->warn('No devices with WiFi accounts found.');
            return 1;
        }

        $this->info("Found {$devices->count()} device(s) with WiFi accounts.");
        $this->showStatus($devices);

        if ($this->option('check')) {
            return $this->checkReadiness($devices);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — changes that would be made:');
            foreach ($devices as $device) {
                $this->line("  Device #{$device->id} ({$device->hostname}):");
                if (!$this->option('skip-ap')) {
                    $this->line("    → Push ap-config-v2.sh to AP {$device->ap_ip}");
                }
                $this->line("    → Bump config_version (triggers v2 config pull)");
            }
            return 0;
        }

        $action = $this->option('skip-ap') ? 'push v2 platform config' : 'push AP v2 script + v2 platform config';
        if (!$this->confirm("Migrate {$devices->count()} device(s)? This will {$action}.")) {
            return 0;
        }

        $configService = app(RouterConfigService::class);
        $wifiService = app(RouterWifiService::class);
        $success = 0;

        foreach ($devices as $device) {
            $this->newLine();
            $this->info("=== Device #{$device->id} ({$device->hostname}) ===");

            // Step 1: Set target_agent_version to trigger OTA update for this device
            if (!$this->option('skip-agent-update')) {
                $latestVersion = $this->getLatestAgentVersion();
                if ($latestVersion && $latestVersion !== $device->agent_version) {
                    $device->update(['target_agent_version' => $latestVersion]);
                    $this->info("  Target agent version set to {$latestVersion} (current: {$device->agent_version})");
                } else {
                    $this->info("  Agent already at latest version ({$device->agent_version})");
                }
            }

            // Step 2: Allocate IP ranges for existing accounts that have ip_start_index=0
            $migrated = $this->migrateAccountIps($device, $wifiService);
            if ($migrated > 0) {
                $this->info("  Allocated IP ranges for {$migrated} existing account(s)");
            }

            // Step 3: Push AP v2 config via remote command (ARP discovery + sshpass)
            if (!$this->option('skip-ap')) {
                $this->pushApV2Config($device);
            }

            // Step 4: Mark device as v2 + push config
            $device->update(['wifi_version' => 2]);
            $configService->pushConfig($device);
            $this->info("  wifi_version=2, config version bumped to {$device->fresh()->config_version}");

            $success++;
        }

        $this->newLine();
        $this->info("Migration triggered for {$success}/{$devices->count()} devices.");
        $this->info("Devices will pull v2 config on next heartbeat (5s interval).");
        $this->newLine();
        $this->info("Monitor progress:");
        $this->line("  SELECT id, hostname, config_version, applied_config_version, agent_version FROM router_devices WHERE customer_id IS NOT NULL;");

        return 0;
    }

    private function showStatus($devices): void
    {
        $this->table(
            ['ID', 'Hostname', 'Agent Ver', 'AP IP', 'Config/Applied', 'Status', 'WiFi Accounts'],
            $devices->map(fn ($d) => [
                $d->id,
                $d->hostname ?: '—',
                $d->agent_version ?: '—',
                $d->ap_ip ?: '(none)',
                "{$d->config_version}/{$d->applied_config_version}",
                $d->isOnline() ? 'online' : 'offline',
                $d->wifiAccounts()->count(),
            ])
        );
    }

    private function checkReadiness($devices): int
    {
        $this->newLine();
        $this->info('Migration readiness check:');

        $issues = 0;

        foreach ($devices as $device) {
            $problems = [];

            if (!$device->isOnline()) {
                $problems[] = 'OFFLINE (last heartbeat: ' . ($device->last_heartbeat_at?->diffForHumans() ?? 'never') . ')';
            }

            if (!$device->isConfigSynced()) {
                $problems[] = "config not synced (v{$device->config_version} vs applied v{$device->applied_config_version})";
            }

            if (empty($device->ap_ip)) {
                $problems[] = 'no AP IP set';
            }

            if (empty($problems)) {
                $this->info("  #{$device->id} ({$device->hostname}): READY");
            } else {
                $issues += count($problems);
                $this->warn("  #{$device->id} ({$device->hostname}):");
                foreach ($problems as $p) {
                    $this->line("    ✗ {$p}");
                }
            }
        }

        $this->newLine();
        if ($issues === 0) {
            $this->info('All devices ready for migration.');
        } else {
            $this->warn("{$issues} issue(s) found. Fix before migrating, or use --skip-ap for devices without AP IP.");
        }

        return $issues > 0 ? 1 : 0;
    }

    private function getLatestAgentVersion(): ?string
    {
        $versionFile = '/www/uploads/sunipip/router-agent/version.txt';
        if (!file_exists($versionFile)) {
            $versionFile = storage_path('app/router-agent/version.txt');
        }
        if (!file_exists($versionFile)) {
            return null;
        }
        return trim(file_get_contents($versionFile));
    }

    private function migrateAccountIps(RouterDevice $device, RouterWifiService $wifiService): int
    {
        $accounts = $device->wifiAccounts()->where('ip_start_index', 0)->get();
        $migrated = 0;

        foreach ($accounts as $account) {
            $ipInfo = $wifiService->allocateIpRange($device->fresh(), $account->max_devices);
            $account->update([
                'ip_start_index' => $ipInfo['ip_start_index'],
                'vlan_id' => $ipInfo['vlan_id'],
                'ip_prefix' => $ipInfo['ip_prefix'],
                'gateway_ip' => $ipInfo['gateway_ip'],
            ]);
            $migrated++;
        }

        return $migrated;
    }

    private function pushApV2Config(RouterDevice $device): void
    {
        $apConfig = $device->ap_config ?? [];
        $apUser = $apConfig['ap_username'] ?? 'root';
        $apPass = $this->option('ap-password') ?? ($apConfig['ap_password'] ?? 'as204921.net');
        $apStaticIp = '10.20.0.120';
        $device->update(['ap_ip' => $apStaticIp]);

        $apScript = $this->buildApV2Script($apStaticIp);
        $b64 = base64_encode($apScript);

        $command = sprintf(
            'TRUNK_IF=$(ip -o addr show 2>/dev/null | grep "10\\.20\\.0\\." | awk \'{print $2}\' | head -1); '
            . '[ -z "$TRUNK_IF" ] && echo "ERROR: No interface with 10.20.0.x found" && exit 1; '
            . 'echo "Trunk interface: $TRUNK_IF"; '
            . 'AP_IP=$(ip neigh show dev $TRUNK_IF 2>/dev/null | grep -v FAILED | awk \'{print $1}\' | grep \'^10\\.20\\.0\\.\' | head -1); '
            . 'if [ -z "$AP_IP" ]; then for i in $(seq 100 200); do ping -c1 -W1 10.20.0.$i >/dev/null 2>&1 & done; wait; '
            . 'AP_IP=$(ip neigh show dev $TRUNK_IF 2>/dev/null | grep -v FAILED | awk \'{print $1}\' | grep \'^10\\.20\\.0\\.\' | head -1); fi; '
            . '[ -z "$AP_IP" ] && echo "ERROR: AP not found on $TRUNK_IF" && exit 1; '
            . 'echo "Discovered AP at $AP_IP"; '
            . 'echo %s | base64 -d | sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@$AP_IP '
            . '"cat > /tmp/ap-v2.sh && sh /tmp/ap-v2.sh; rm -f /tmp/ap-v2.sh"',
            escapeshellarg($b64),
            escapeshellarg($apPass),
            escapeshellarg($apUser)
        );

        RouterRemoteCommand::create([
            'router_device_id' => $device->id,
            'command' => $command,
            'timeout' => 180,
            'status' => 'pending',
        ]);

        $this->info("  AP v2 config command queued (auto-detect trunk interface)");
    }

    private function buildApV2Script(string $apStaticIp = '10.20.0.120'): string
    {
        $script = <<<'SH'
#!/bin/sh
echo "[V2] Starting AP v2 migration..."

# 1. NSS offload
[ -f /etc/modules.d/ath11k ] && echo 'ath11k nss_offload=1 frame_mode=2' > /etc/modules.d/ath11k
for f in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
    [ -f "/etc/modules.d/$f" ] && echo "$(echo "$f" | sed 's/^[0-9]*-//' | tr '-' '_')" > "/etc/modules.d/$f"
done
rm -f /etc/modprobe.d/sunipip-no-nss-vlan.conf /etc/modprobe.d/blacklist-ath11k-pci.conf

# 2. Network: create br-trunk bridge with wan port, WiFi joins wan network
uci delete network.wan6 2>/dev/null || true
uci delete network.globals.ula_prefix 2>/dev/null || true

TRUNK_EXISTS=0; IDX=0
while uci -q get "network.@device[${IDX}]" >/dev/null 2>&1; do
    NAME=$(uci -q get "network.@device[${IDX}].name")
    [ "$NAME" = "br-trunk" ] && TRUNK_EXISTS=1 && break
    IDX=$((IDX + 1))
done
if [ "$TRUNK_EXISTS" = "0" ]; then
    uci add network device
    uci set "network.@device[-1].name=br-trunk"
    uci set "network.@device[-1].type=bridge"
    uci add_list "network.@device[-1].ports=wan"
    echo "[V2] Created br-trunk with wan port"
fi
uci set network.wan.device='br-trunk'
uci set network.wan.proto='static'
uci set network.wan.ipaddr='__AP_STATIC_IP__'
uci set network.wan.netmask='255.255.255.0'
uci set network.wan.gateway='10.20.0.1'
uci set network.wan.dns='223.5.5.5 119.29.29.29'

# 3. Disable AP's own DHCP on LAN (WiFi clients should get DHCP from router)
uci set dhcp.lan.ignore='1'
echo "[V2] Disabled DHCP on LAN"

# 4. Firewall: wan zone open
ZONE_IDX=0
while uci -q get "firewall.@zone[${ZONE_IDX}]" >/dev/null 2>&1; do
    ZNAME=$(uci -q get "firewall.@zone[${ZONE_IDX}].name")
    if [ "$ZNAME" = "wan" ]; then
        uci set "firewall.@zone[${ZONE_IDX}].masq=0"
        uci set "firewall.@zone[${ZONE_IDX}].input=ACCEPT"
        uci set "firewall.@zone[${ZONE_IDX}].forward=ACCEPT"
        break
    fi
    ZONE_IDX=$((ZONE_IDX + 1))
done
if ! uci show firewall 2>/dev/null | grep -q "Allow-SSH-WAN"; then
    uci add firewall rule >/dev/null
    uci set firewall.@rule[-1].name='Allow-SSH-WAN'
    uci set firewall.@rule[-1].src='wan'
    uci set firewall.@rule[-1].dest_port='22'
    uci set firewall.@rule[-1].proto='tcp'
    uci set firewall.@rule[-1].target='ACCEPT'
fi

# 5. Wireless: all radios enabled, dynamic_vlan=0, network=wan (br-trunk)
ROUTER_IP=$(ip route 2>/dev/null | awk '/default/{print $3}' | head -1)
[ -z "$ROUTER_IP" ] && ROUTER_IP="10.20.0.1"
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${radio}" >/dev/null 2>&1 || continue
    IFACE="default_${radio}"
    uci set "wireless.${radio}.disabled=0"
    uci set "wireless.${radio}.country=HK"
    BAND=$(uci -q get "wireless.${radio}.band" 2>/dev/null)
    RPATH=$(uci -q get "wireless.${radio}.path" 2>/dev/null)
    case "$BAND" in
        2g) uci set "wireless.${radio}.channel=6"; uci set "wireless.${radio}.htmode=HE40"; uci set "wireless.${radio}.noscan=1" ;;
        5g)
            if echo "$RPATH" | grep -q "pci"; then
                uci set "wireless.${radio}.channel=36"; uci set "wireless.${radio}.htmode=HE160"
            else
                uci set "wireless.${radio}.channel=149"; uci set "wireless.${radio}.htmode=HE80"
            fi ;;
        6g) uci set "wireless.${radio}.channel=1"; uci set "wireless.${radio}.htmode=HE160" ;;
    esac
    if ! uci -q get "wireless.${IFACE}" >/dev/null 2>&1; then
        uci set "wireless.${IFACE}=wifi-iface"
        uci set "wireless.${IFACE}.device=${radio}"
        uci set "wireless.${IFACE}.mode=ap"
    fi
    uci set "wireless.${IFACE}.ssid=SunIPIP.com Streaming LAN"
    uci set "wireless.${IFACE}.encryption=wpa2+ccmp"
    uci set "wireless.${IFACE}.network=wan"
    uci set "wireless.${IFACE}.auth_server=${ROUTER_IP}"
    uci set "wireless.${IFACE}.auth_port=1812"
    uci set "wireless.${IFACE}.auth_secret=sunipip_radius_secret"
    uci set "wireless.${IFACE}.dynamic_vlan=0"
    uci -q delete "wireless.${IFACE}.vlan_tagged_interface" 2>/dev/null
    uci -q delete "wireless.${IFACE}.vlan_naming" 2>/dev/null
    uci set "wireless.${IFACE}.ieee80211w=1"
    echo "[V2] ${radio}: band=${BAND} network=wan dynamic_vlan=0"
done

# 6. Remove v1 watchdog
rm -f /usr/bin/radius_watchdog.sh
crontab -l 2>/dev/null | grep -v "radius_watchdog" | crontab - 2>/dev/null

# 7. Commit all
uci commit network
uci commit wireless
uci commit dhcp
uci commit firewall

echo "--- Verify ---"
cat /etc/modules.d/ath11k 2>/dev/null
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${radio}" >/dev/null 2>&1 || continue
    echo "${radio}: network=$(uci -q get wireless.default_${radio}.network) dvlan=$(uci -q get wireless.default_${radio}.dynamic_vlan)"
done
echo "wan.device=$(uci -q get network.wan.device)"
echo "dhcp.lan.ignore=$(uci -q get dhcp.lan.ignore)"

echo "wan.proto=$(uci -q get network.wan.proto)"
echo "wan.ipaddr=$(uci -q get network.wan.ipaddr)"

nohup sh -c 'sleep 5 && reboot' >/dev/null 2>&1 &
echo "[V2] Done. AP rebooting in 5s."
SH;

        return str_replace('__AP_STATIC_IP__', $apStaticIp, $script);
    }
}
