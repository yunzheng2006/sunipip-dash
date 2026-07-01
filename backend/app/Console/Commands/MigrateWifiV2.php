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

            // Step 3: Push AP v2 config via remote command
            if (!$this->option('skip-ap')) {
                if (empty($device->ap_ip)) {
                    $this->warn("  No AP IP — skipping AP config push. Use --skip-ap or set ap_ip first.");
                    continue;
                }
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
        $apPass = $this->option('ap-password') ?? ($apConfig['ap_password'] ?? '');
        $apIp = $device->ap_ip;

        if (!empty($apPass)) {
            $sshPrefix = sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s',
                escapeshellarg($apPass),
                escapeshellarg($apUser),
                escapeshellarg($apIp)
            );
        } else {
            $sshPrefix = sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s',
                escapeshellarg($apUser),
                escapeshellarg($apIp)
            );
        }

        $apScript = $this->buildApV2Script();
        $b64 = base64_encode($apScript);

        $command = sprintf(
            'echo %s | base64 -d | %s "cat > /tmp/ap-v2.sh && sh /tmp/ap-v2.sh; rm -f /tmp/ap-v2.sh"',
            escapeshellarg($b64),
            $sshPrefix
        );

        RouterRemoteCommand::create([
            'router_device_id' => $device->id,
            'command' => $command,
            'timeout' => 120,
            'status' => 'pending',
        ]);

        $this->info("  AP v2 config command queued → AP {$apIp}");
    }

    private function buildApV2Script(): string
    {
        return <<<'SH'
#!/bin/sh
echo "[V2] AP v2 migration starting..."

# 1. Enable NSS offload (was disabled in v1)
if [ -f /etc/modules.d/ath11k ]; then
    echo 'ath11k nss_offload=1 frame_mode=2' > /etc/modules.d/ath11k
    echo "[V2] ath11k nss_offload=1 frame_mode=2"
fi

# 2. Re-enable NSS VLAN/bridge modules (v1 disabled them)
for f in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
    if [ -f "/etc/modules.d/$f" ]; then
        if grep -q "disabled" "/etc/modules.d/$f" 2>/dev/null; then
            MOD=$(echo "$f" | sed 's/^[0-9]*-//' | tr '-' '_')
            echo "$MOD" > "/etc/modules.d/$f"
            echo "[V2] Re-enabled $f"
        fi
    fi
done

# 3. Remove v1 blacklists
rm -f /etc/modprobe.d/sunipip-no-nss-vlan.conf
rm -f /etc/modprobe.d/blacklist-ath11k-pci.conf
echo "[V2] Removed v1 modprobe blacklists"

# 4. Enable ALL radios + set dynamic_vlan=0
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${radio}" >/dev/null 2>&1 || continue
    IFACE="default_${radio}"
    BAND=$(uci -q get "wireless.${radio}.band" 2>/dev/null)

    # Enable radio
    uci set "wireless.${radio}.disabled=0"

    # Configure band
    case "$BAND" in
        2g)
            uci set "wireless.${radio}.channel=6"
            uci set "wireless.${radio}.htmode=HE40"
            ;;
        5g)
            RPATH=$(uci -q get "wireless.${radio}.path" 2>/dev/null)
            if echo "$RPATH" | grep -q "pci"; then
                uci set "wireless.${radio}.channel=36"
                uci set "wireless.${radio}.htmode=HE160"
                echo "[V2] ${radio}: PCIe 5GHz ENABLED (HE160 CH36)"
            else
                uci set "wireless.${radio}.channel=149"
                uci set "wireless.${radio}.htmode=HE80"
                echo "[V2] ${radio}: SoC 5GHz (HE80 CH149)"
            fi
            ;;
        6g)
            uci set "wireless.${radio}.channel=1"
            uci set "wireless.${radio}.htmode=HE160"
            echo "[V2] ${radio}: 6GHz (HE160)"
            ;;
    esac

    # Disable dynamic VLAN (v2: flat IP mode)
    if uci -q get "wireless.${IFACE}" >/dev/null 2>&1; then
        uci set "wireless.${IFACE}.dynamic_vlan=0"
        uci -q delete "wireless.${IFACE}.vlan_tagged_interface" 2>/dev/null
        uci -q delete "wireless.${IFACE}.vlan_naming" 2>/dev/null
    fi
done
echo "[V2] dynamic_vlan=0, VLAN tagging removed"

# 5. Commit + reboot (NSS param change requires reboot)
uci commit wireless

echo "--- Verify ---"
cat /etc/modules.d/ath11k 2>/dev/null
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${radio}" >/dev/null 2>&1 || continue
    IFACE="default_${radio}"
    echo "${radio}: disabled=$(uci -q get wireless.${radio}.disabled) band=$(uci -q get wireless.${radio}.band) dvlan=$(uci -q get wireless.${IFACE}.dynamic_vlan)"
done

echo "[V2] Scheduling reboot in 5 seconds..."
nohup sh -c 'sleep 5 && reboot' >/dev/null 2>&1 &
echo "[V2] Done. AP will reboot shortly."
SH;
    }
}
