package services

import (
	"context"
	"crypto/md5"
	"encoding/hex"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"strings"
	"time"

	"sunipip-router-agent/internal/api"
)

const (
	apConfigHashPath = "/etc/sunipip/ap-config-hash"
	apScriptPath     = "/etc/sunipip/ap-config.sh"
)

// APService manages AP configuration via SSH.
type APService struct {
	logger *slog.Logger
}

// NewAPService creates a new AP management service.
func NewAPService(logger *slog.Logger) *APService {
	return &APService{logger: logger}
}

// Apply checks if the AP config needs updating and pushes via SSH if so.
func (s *APService) Apply(ctx context.Context, cfg api.APConfig) error {
	if !cfg.Enabled || cfg.WifiVersion < 2 {
		s.logger.Debug("AP management disabled or not v2, skipping")
		return nil
	}

	script := s.buildScript(cfg)
	hash := s.hashScript(script)

	oldHash, _ := os.ReadFile(apConfigHashPath)
	if string(oldHash) == hash {
		s.logger.Debug("AP config unchanged, skipping push")
		return nil
	}

	if err := writeFileAtomic(apScriptPath, []byte(script), 0644); err != nil {
		return fmt.Errorf("write AP script: %w", err)
	}

	s.ensureSshpass(ctx)

	apIP := s.discoverAP(ctx, cfg)
	if apIP == "" {
		s.logger.Warn("AP not reachable, will retry on next config apply")
		return nil
	}

	s.logger.Info("Pushing AP config", "ap_ip", apIP, "hash", hash[:8])

	if err := s.pushScript(ctx, apIP, cfg.Username, cfg.Password, script); err != nil {
		return fmt.Errorf("push AP config to %s: %w", apIP, err)
	}

	os.WriteFile(apConfigHashPath, []byte(hash), 0644)
	s.logger.Info("AP config pushed successfully", "ap_ip", apIP)
	return nil
}

// discoverAP finds the AP's IP address.
func (s *APService) discoverAP(ctx context.Context, cfg api.APConfig) string {
	// Try known static IP first
	if cfg.StaticIP != "" {
		if s.pingHost(ctx, cfg.StaticIP) {
			return cfg.StaticIP
		}
	}
	// Try platform-configured AP IP
	if cfg.APIP != "" && cfg.APIP != cfg.StaticIP {
		if s.pingHost(ctx, cfg.APIP) {
			return cfg.APIP
		}
	}
	// ARP scan fallback
	return s.arpDiscover(ctx)
}

func (s *APService) pingHost(ctx context.Context, ip string) bool {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "ping", "-c", "1", "-W", "2", ip)
	return cmd.Run() == nil
}

func (s *APService) arpDiscover(ctx context.Context) string {
	// Find trunk interface
	out, err := exec.CommandContext(ctx, "bash", "-c",
		`ip -o addr show 2>/dev/null | grep "10\.20\.0\." | awk '{print $2}' | head -1`).Output()
	if err != nil || len(out) == 0 {
		return ""
	}
	trunkIF := strings.TrimSpace(string(out))
	if trunkIF == "" {
		return ""
	}

	// Ping sweep
	exec.CommandContext(ctx, "bash", "-c",
		fmt.Sprintf(`for i in $(seq 100 200); do ping -c1 -W1 10.20.0.$i >/dev/null 2>&1 & done; wait`)).Run()

	// Check ARP
	out, err = exec.CommandContext(ctx, "bash", "-c",
		fmt.Sprintf(`ip neigh show dev %s 2>/dev/null | grep -v FAILED | awk '{print $1}' | grep '^10\.20\.0\.' | head -1`, trunkIF)).Output()
	if err != nil || len(out) == 0 {
		return ""
	}
	return strings.TrimSpace(string(out))
}

func (s *APService) pushScript(ctx context.Context, apIP, user, password, script string) error {
	ctx, cancel := context.WithTimeout(ctx, 120*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "sshpass", "-p", password,
		"ssh", "-o", "StrictHostKeyChecking=no", "-o", "ConnectTimeout=10",
		fmt.Sprintf("%s@%s", user, apIP),
		"cat > /tmp/ap-v2.sh && sh /tmp/ap-v2.sh; rm -f /tmp/ap-v2.sh")
	cmd.Stdin = strings.NewReader(script)

	output, err := cmd.CombinedOutput()
	s.logger.Info("AP script output", "output", string(output))
	if err != nil {
		return fmt.Errorf("ssh exec: %w (output: %s)", err, string(output))
	}
	return nil
}

func (s *APService) ensureSshpass(ctx context.Context) {
	if _, err := exec.LookPath("sshpass"); err == nil {
		return
	}
	s.logger.Info("Installing sshpass")
	cmd := exec.CommandContext(ctx, "apt-get", "install", "-y", "sshpass")
	cmd.Run()
}

func (s *APService) hashScript(script string) string {
	h := md5.Sum([]byte(script))
	return hex.EncodeToString(h[:])
}

func (s *APService) buildScript(cfg api.APConfig) string {
	routerIP := cfg.RouterIP
	if routerIP == "" {
		routerIP = "10.20.0.1"
	}
	staticIP := cfg.StaticIP
	if staticIP == "" {
		staticIP = "10.20.0.120"
	}
	radiusSecret := cfg.RadiusSecret
	if radiusSecret == "" {
		radiusSecret = "sunipip_radius_secret"
	}

	return fmt.Sprintf(`#!/bin/sh
echo "[V2] Starting AP v2 config..."

# 1. NSS offload
[ -f /etc/modules.d/ath11k ] && echo 'ath11k nss_offload=1 frame_mode=2' > /etc/modules.d/ath11k
for f in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
    [ -f "/etc/modules.d/$f" ] && echo "$(echo "$f" | sed 's/^[0-9]*-//' | tr '-' '_')" > "/etc/modules.d/$f"
done
rm -f /etc/modprobe.d/sunipip-no-nss-vlan.conf /etc/modprobe.d/blacklist-ath11k-pci.conf

# 2. Network: br-trunk bridge with wan port, static IP
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
uci set network.wan.ipaddr='%s'
uci set network.wan.netmask='255.255.255.0'
uci set network.wan.gateway='%s'
uci set network.wan.dns='223.5.5.5 119.29.29.29'

# 3. Disable AP's own DHCP
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

# 5. Wireless: all radios, RADIUS auth, network=wan
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
    uci set "wireless.${IFACE}.auth_server=%s"
    uci set "wireless.${IFACE}.auth_port=1812"
    uci set "wireless.${IFACE}.auth_secret=%s"
    uci set "wireless.${IFACE}.dynamic_vlan=0"
    uci -q delete "wireless.${IFACE}.vlan_tagged_interface" 2>/dev/null
    uci -q delete "wireless.${IFACE}.vlan_naming" 2>/dev/null
    uci set "wireless.${IFACE}.ieee80211w=1"
    echo "[V2] ${radio}: band=${BAND} network=wan"
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
echo "wan.proto=$(uci -q get network.wan.proto)"
echo "wan.ipaddr=$(uci -q get network.wan.ipaddr)"
echo "dhcp.lan.ignore=$(uci -q get dhcp.lan.ignore)"

nohup sh -c 'sleep 5 && reboot' >/dev/null 2>&1 &
echo "[V2] Done. AP rebooting in 5s."
`, staticIP, routerIP, routerIP, radiusSecret)
}
