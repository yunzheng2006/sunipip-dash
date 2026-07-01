<?php

namespace App\Console\Commands;

use App\Models\RouterDevice;
use App\Models\RouterRemoteCommand;
use Illuminate\Console\Command;

class OtaUpdateApConfig extends Command
{
    protected $signature = 'ap:ota-update
        {--device= : Target specific device ID}
        {--dry-run : Preview commands without sending}
        {--skip-reboot : Apply config without rebooting AP}
        {--ap-password= : Override AP SSH password for all devices}';

    protected $description = 'OTA push AP config fix: disable PCIe radio (TX queue leak), fix SoC 5GHz to CH149/HE80, disable NSS offload';

    public function handle(): int
    {
        $query = RouterDevice::where('id', '!=', 1);

        if ($deviceId = $this->option('device')) {
            $query->where('id', $deviceId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->warn('No devices found.');

            return 1;
        }

        $this->info('Current device status:');
        $this->showDeviceTable($devices);

        // Auto-discover AP IPs for online devices without ap_ip
        $needDiscovery = $devices->filter(fn ($d) => empty($d->ap_ip) && $d->status === 'online');
        if ($needDiscovery->isNotEmpty()) {
            $this->newLine();
            $this->info("Discovering AP IPs for {$needDiscovery->count()} online device(s)...");
            $discovered = $this->discoverApIps($needDiscovery);

            if ($discovered > 0) {
                $devices = $query->get();
                $this->newLine();
                $this->info('Updated device status:');
                $this->showDeviceTable($devices);
            }
        }

        $targetDevices = $devices->filter(fn ($d) => ! empty($d->ap_ip));
        $skipped = $devices->count() - $targetDevices->count();

        if ($skipped > 0) {
            $this->warn("Skipping {$skipped} device(s) without AP IP.");
        }

        if ($targetDevices->isEmpty()) {
            $this->error('No devices with AP IP to update.');

            return 1;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — commands that would be sent:');
            foreach ($targetDevices as $device) {
                $this->line("  Device #{$device->id} → SSH to AP {$device->ap_ip}");
            }

            return 0;
        }

        if (! $this->confirm("Send OTA update to {$targetDevices->count()} AP(s)?")) {
            return 0;
        }

        $sent = 0;
        foreach ($targetDevices as $device) {
            if ($this->sendUpdate($device)) {
                $sent++;
            }
        }

        $this->newLine();
        $this->info("Queued OTA commands for {$sent}/{$targetDevices->count()} devices.");
        $this->info('Agent polls every 5s. Check results:');
        $this->line('  SELECT id, router_device_id, status, exit_code, LEFT(output,200) FROM router_remote_commands ORDER BY id DESC LIMIT 10;');

        return 0;
    }

    private function showDeviceTable($devices): void
    {
        $this->table(
            ['ID', 'Hostname', 'Status', 'AP IP', 'Last Heartbeat'],
            $devices->map(fn ($d) => [
                $d->id,
                $d->hostname ?: '—',
                $d->status,
                $d->ap_ip ?: '(none)',
                $d->last_heartbeat_at?->diffForHumans() ?? 'Never',
            ])
        );
    }

    private function discoverApIps($devices): int
    {
        // Send discovery commands to each router
        $commandIds = [];
        foreach ($devices as $device) {
            $cmd = RouterRemoteCommand::create([
                'router_device_id' => $device->id,
                'command' => $this->buildDiscoveryCommand(),
                'timeout' => 15,
                'status' => 'pending',
            ]);
            $commandIds[$device->id] = $cmd->id;
            $this->line("  #{$device->id}: discovery command queued");
        }

        // Poll for results (agent polls every 5s, give up to 30s)
        $this->output->write('  Waiting for agents');
        $deadline = time() + 30;
        while (time() < $deadline) {
            sleep(3);
            $this->output->write('.');
            $pending = RouterRemoteCommand::whereIn('id', array_values($commandIds))
                ->whereIn('status', ['pending', 'sent'])
                ->count();
            if ($pending === 0) {
                break;
            }
        }
        $this->newLine();

        // Parse results and update ap_ip
        $discovered = 0;
        foreach ($commandIds as $deviceId => $cmdId) {
            $result = RouterRemoteCommand::find($cmdId);

            if ($result->status !== 'completed') {
                $this->warn("  #{$deviceId}: discovery {$result->status}" . ($result->status === 'failed' ? " (exit {$result->exit_code})" : ''));

                continue;
            }

            $apIp = $this->parseApIp($result->output, $deviceId);
            if ($apIp) {
                RouterDevice::where('id', $deviceId)->update(['ap_ip' => $apIp]);
                $this->info("  #{$deviceId}: discovered AP at {$apIp}");
                $discovered++;
            } else {
                $this->warn("  #{$deviceId}: no AP found on trunk subnet");
                if ($result->output) {
                    $this->line('    Raw output: ' . substr($result->output, 0, 200));
                }
            }
        }

        return $discovered;
    }

    private function buildDiscoveryCommand(): string
    {
        return <<<'CMD'
AP_IF=$(python3 -c "import json;print(json.load(open('/etc/sunipip/agent.json'))['interface_map']['ap'])" 2>/dev/null || echo enp3s0)
echo "AP_IF=$AP_IF"
SELF_IP=$(ip addr show dev "$AP_IF" 2>/dev/null | grep -oP 'inet \K[0-9.]+' | head -1)
echo "SELF_IP=$SELF_IP"
echo "---LEASES---"
cat /var/lib/misc/dnsmasq.leases 2>/dev/null || echo "(no leases file)"
echo "---ARP---"
ip neigh show dev "$AP_IF" 2>/dev/null | grep -v FAILED || echo "(no arp entries)"
CMD;
    }

    private function parseApIp(string $output, int $deviceId): ?string
    {
        $routerIp = null;
        $candidates = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            // Extract router's own IP on AP interface
            if (preg_match('/^SELF_IP=(.+)/', $line, $m)) {
                $routerIp = trim($m[1]);

                continue;
            }

            // Parse DHCP leases: "timestamp mac ip hostname clientid"
            if (preg_match('/^\d+\s+[\da-f:]+\s+([\d.]+)\s+/', $line, $m)) {
                $ip = $m[1];
                if ($ip !== $routerIp && $ip !== '0.0.0.0') {
                    $candidates[$ip] = ($candidates[$ip] ?? 0) + 2; // DHCP lease = higher confidence
                }

                continue;
            }

            // Parse ARP: "ip dev ... lladdr mac REACHABLE/STALE/..."
            if (preg_match('/^([\d.]+)\s+/', $line, $m) && ! str_starts_with($line, 'AP_IF') && ! str_starts_with($line, 'SELF_IP')) {
                $ip = $m[1];
                if ($ip !== $routerIp && $ip !== '0.0.0.0') {
                    $score = str_contains($line, 'REACHABLE') ? 3 : 1;
                    $candidates[$ip] = ($candidates[$ip] ?? 0) + $score;
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Pick highest-scoring candidate
        arsort($candidates);

        return array_key_first($candidates);
    }

    private function sendUpdate(RouterDevice $device): bool
    {
        $apConfig = $device->ap_config ?? [];
        $apUser = $apConfig['ap_username'] ?? 'root';
        $apPass = $this->option('ap-password') ?? ($apConfig['ap_password'] ?? '');
        $apIp = $device->ap_ip;

        if (! empty($apPass)) {
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

        $apScript = $this->buildApScript(! $this->option('skip-reboot'));
        $b64 = base64_encode($apScript);

        $command = sprintf(
            'echo %s | base64 -d | %s "cat > /tmp/ap-ota.sh && sh /tmp/ap-ota.sh; rm -f /tmp/ap-ota.sh"',
            escapeshellarg($b64),
            $sshPrefix
        );

        if (strlen($command) > 4000) {
            $this->error("Device #{$device->id}: command too long (" . strlen($command) . ' chars)');

            return false;
        }

        RouterRemoteCommand::create([
            'router_device_id' => $device->id,
            'command' => $command,
            'timeout' => 120,
            'status' => 'pending',
        ]);

        $this->info("  #{$device->id} ({$device->hostname}) → AP {$apIp}: OTA queued");

        return true;
    }

    private function buildApScript(bool $reboot): string
    {
        $script = <<<'SH'
#!/bin/sh
echo "[OTA] AP config update starting..."

# 1. ath11k: disable NSS offload + native WiFi frame mode
if [ -f /etc/modules.d/ath11k ]; then
    echo 'ath11k nss_offload=0 frame_mode=0' > /etc/modules.d/ath11k
    echo "[OTA] ath11k nss_offload=0 frame_mode=0"
fi

# 2. Disable NSS VLAN/bridge modules
for f in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
    [ -f "/etc/modules.d/$f" ] && echo "# disabled by sunipip" > "/etc/modules.d/$f"
done

# 3. Modprobe blacklist
mkdir -p /etc/modprobe.d
printf 'blacklist qca_nss_vlan\nblacklist qca_nss_bridge_mgr\n' > /etc/modprobe.d/sunipip-no-nss-vlan.conf

# 4. Fix radios: disable PCIe 5GHz (TX leak), fix SoC 5GHz to CH149/HE80
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${radio}" >/dev/null 2>&1 || continue
    BAND=$(uci -q get "wireless.${radio}.band" 2>/dev/null)
    RPATH=$(uci -q get "wireless.${radio}.path" 2>/dev/null)
    IS_PCIE=0
    echo "$RPATH" | grep -q "pci" && IS_PCIE=1

    if [ "$BAND" = "5g" ] && [ "$IS_PCIE" = "1" ]; then
        uci set "wireless.${radio}.disabled=1"
        echo "[OTA] ${radio}: PCIe 5GHz DISABLED (ath11k_pci TX queue leak)"
    elif [ "$BAND" = "5g" ]; then
        uci set "wireless.${radio}.disabled=0"
        uci set "wireless.${radio}.channel=149"
        uci set "wireless.${radio}.htmode=HE80"
        echo "[OTA] ${radio}: SoC 5GHz → CH149 HE80"
    elif [ "$BAND" = "6g" ] && [ "$IS_PCIE" = "1" ]; then
        uci set "wireless.${radio}.disabled=1"
        echo "[OTA] ${radio}: PCIe 6GHz DISABLED"
    fi
done

# 5. Commit + restart WiFi
uci commit wireless
wifi down; sleep 2; wifi up; sleep 3

# Verify
echo "--- Verification ---"
cat /etc/modules.d/ath11k 2>/dev/null
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${radio}" >/dev/null 2>&1 || continue
    echo "${radio}: disabled=$(uci -q get wireless.${radio}.disabled) band=$(uci -q get wireless.${radio}.band) ch=$(uci -q get wireless.${radio}.channel) ht=$(uci -q get wireless.${radio}.htmode)"
done
SH;

        if ($reboot) {
            $script .= <<<'SH'

# 6. Schedule reboot (NSS module param change needs reboot to take effect)
echo "[OTA] Scheduling reboot in 5 seconds..."
nohup sh -c 'sleep 5 && reboot' >/dev/null 2>&1 &
echo "[OTA] Done. AP will reboot shortly."
SH;
        } else {
            $script .= "\necho \"[OTA] Done. Reboot skipped — run 'reboot' manually for NSS changes.\"";
        }

        return $script;
    }
}
