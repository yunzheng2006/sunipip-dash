#!/usr/bin/env bash
###############################################################################
# SuniPIP Soft Router — One-click Installation Script
# 软路由一键安装脚本
#
# Target: Debian 12 industrial PC (N2940, 4GB RAM, 32GB SSD, 4 NICs)
# Usage:
#   curl -fsSL https://api-all.sunip.cc/api/v1/router-install/{token} | bash
#
# The INSTALL_TOKEN is either set as an environment variable or extracted
# from the download URL by the platform (injected into the script).
###############################################################################
set -euo pipefail

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
AGENT_VERSION="1.0.10"
PLATFORM_URL="${PLATFORM_URL:-https://api-all.sunip.cc}"
LOG_FILE="/var/log/sunipip-install.log"
CLASH_VERSION="v1.19.25"
CLASH_URL="https://github.com/MetaCubeX/mihomo/releases/download/${CLASH_VERSION}/mihomo-linux-amd64-compatible-${CLASH_VERSION}.gz"
AGENT_URL="${PLATFORM_URL}/api/v1/router-agent/download"
FRONTEND_URL="${PLATFORM_URL}/api/v1/router-downloads/router-frontend-dist.tar.gz"

# Temporary proxy for downloading from GitHub etc. during installation
_INSTALL_PROXY="socks5://hr.sunipip.com:23139"

# ---------------------------------------------------------------------------
# Colors & output helpers
# ---------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

log()  { echo -e "${BLUE}[INFO]${NC}  $*" | tee -a "$LOG_FILE"; }
ok()   { echo -e "${GREEN}[  OK]${NC}  $*" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*" | tee -a "$LOG_FILE"; }
err()  { echo -e "${RED}[FAIL]${NC}  $*" | tee -a "$LOG_FILE"; }
step() { echo -e "\n${CYAN}${BOLD}>>> $*${NC}" | tee -a "$LOG_FILE"; }

die() {
    err "$*"
    err "安装失败 / Installation failed. See ${LOG_FILE} for details."
    exit 1
}

# Redirect all stdout/stderr to log file as well
exec > >(tee -a "$LOG_FILE") 2>&1

echo ""
echo -e "${BOLD}============================================================${NC}"
echo -e "${BOLD}  SuniPIP Soft Router Installer v${AGENT_VERSION}${NC}"
echo -e "${BOLD}  软路由安装程序${NC}"
echo -e "${BOLD}============================================================${NC}"
echo ""

# ---------------------------------------------------------------------------
# Step 1: Pre-checks / 前置检查
# ---------------------------------------------------------------------------
step "Step 1/17: Pre-checks / 前置检查"

# Must run as root
if [[ $EUID -ne 0 ]]; then
    die "This script must be run as root / 请以 root 用户执行此脚本"
fi
ok "Running as root / 以 root 身份运行"

# Check Debian 12
if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    if [[ "${ID:-}" != "debian" ]] || [[ ! "${VERSION_ID:-}" =~ ^12 ]]; then
        die "Requires Debian 12 (bookworm). Detected: ${PRETTY_NAME:-unknown} / 需要 Debian 12 系统"
    fi
    ok "OS: ${PRETTY_NAME}"
else
    die "/etc/os-release not found / 无法检测操作系统版本"
fi

# Check at least 4 network interfaces (physical, exclude lo/docker/veth/wg/br)
NIC_COUNT=$(ip -o link show | awk -F': ' '{print $2}' | grep -cE '^eth[0-9]+$' || true)
if [[ $NIC_COUNT -lt 4 ]]; then
    # Also try enp* naming scheme
    NIC_COUNT_ENP=$(ip -o link show | awk -F': ' '{print $2}' | grep -cE '^enp[0-9]+' || true)
    TOTAL_NICS=$((NIC_COUNT + NIC_COUNT_ENP))
    if [[ $TOTAL_NICS -lt 4 ]]; then
        warn "Found only ${TOTAL_NICS} Ethernet interfaces (need 4). Listing all interfaces:"
        ip -o link show | awk -F': ' '{print "  " $2}' | tee -a "$LOG_FILE"
        die "At least 4 network interfaces required / 需要至少 4 个网卡"
    fi
fi
ok "Network interfaces: ${NIC_COUNT} eth* found / 网卡检测通过"

# Read board serial number
SERIAL=""
if command -v dmidecode &>/dev/null; then
    SERIAL=$(dmidecode -s baseboard-serial-number 2>/dev/null || true)
fi
if [[ -z "$SERIAL" ]] && [[ -f /sys/class/dmi/id/board_serial ]]; then
    SERIAL=$(cat /sys/class/dmi/id/board_serial 2>/dev/null || true)
fi
if [[ -z "$SERIAL" || "$SERIAL" == "Not Specified" || "$SERIAL" == "Default string" ]]; then
    # Generate a fallback serial from MAC address of eth0
    SERIAL="SRP-$(cat /sys/class/net/eth0/address 2>/dev/null | tr -d ':' | tail -c 12)"
    warn "No board serial found; using MAC-based ID: ${SERIAL}"
else
    ok "Board serial: ${SERIAL} / 主板序列号"
fi

# Check install token
if [[ -z "${INSTALL_TOKEN:-}" ]]; then
    die "INSTALL_TOKEN not set. Please use the installation URL from the platform. / 未设置安装令牌"
fi
ok "Install token present / 安装令牌已设置"

# ---------------------------------------------------------------------------
# Step 2: Install dependencies / 安装依赖
# ---------------------------------------------------------------------------
step "Step 2/17: Installing dependencies / 安装依赖包"

export DEBIAN_FRONTEND=noninteractive

log "Updating package lists / 更新软件源..."
apt-get update -qq || die "apt-get update failed / 软件源更新失败"

PACKAGES=(
    freeradius
    freeradius-utils
    wireguard
    wireguard-tools
    dnsmasq
    nftables
    nginx
    curl
    jq
    dmidecode
    bridge-utils
    vlan
)

log "Installing packages / 安装软件包: ${PACKAGES[*]}"
apt-get install -y -qq "${PACKAGES[@]}" || die "Package installation failed / 软件包安装失败"
ok "All dependencies installed / 所有依赖已安装"

# Enable 802.1Q VLAN kernel module
modprobe 8021q 2>/dev/null || true
echo "8021q" >> /etc/modules 2>/dev/null || true

# ---------------------------------------------------------------------------
# Step 3: Generate WireGuard keys / 生成 WireGuard 密钥
# ---------------------------------------------------------------------------
step "Step 3/17: Generating WireGuard keys / 生成 WireGuard 密钥对"

mkdir -p /etc/wireguard
chmod 700 /etc/wireguard

wg genkey | tee /etc/wireguard/wg0_private.key | wg pubkey > /etc/wireguard/wg0_public.key
wg genkey | tee /etc/wireguard/wg1_private.key | wg pubkey > /etc/wireguard/wg1_public.key

chmod 600 /etc/wireguard/*_private.key
chmod 644 /etc/wireguard/*_public.key

WG0_PUBKEY=$(cat /etc/wireguard/wg0_public.key)
WG1_PUBKEY=$(cat /etc/wireguard/wg1_public.key)

ok "WireGuard key pairs generated / WireGuard 密钥对已生成"
log "  wg0 public key: ${WG0_PUBKEY}"
log "  wg1 public key: ${WG1_PUBKEY}"

# ---------------------------------------------------------------------------
# Step 4: Register with platform / 向平台注册
# ---------------------------------------------------------------------------
step "Step 4/17: Registering with platform / 向平台注册设备"

REGISTER_PAYLOAD=$(jq -n \
    --arg install_token "$INSTALL_TOKEN" \
    --arg serial_number "$SERIAL" \
    --arg wg_public_key_1 "$WG0_PUBKEY" \
    --arg wg_public_key_2 "$WG1_PUBKEY" \
    --arg hostname "$(hostname)" \
    --arg agent_version "$AGENT_VERSION" \
    '{
        install_token: $install_token,
        serial_number: $serial_number,
        wg_public_key_1: $wg_public_key_1,
        wg_public_key_2: $wg_public_key_2,
        hostname: $hostname,
        agent_version: $agent_version
    }')

REGISTER_RESPONSE=$(curl -fsSL -X POST \
    "${PLATFORM_URL}/api/v1/router-agent/register" \
    -H "Content-Type: application/json" \
    -d "$REGISTER_PAYLOAD" \
    --connect-timeout 30 \
    --max-time 60) || die "Platform registration failed / 平台注册失败。请检查网络连接和安装令牌。"

# Validate response — API wraps data in {"success": true, "data": {...}}
if ! echo "$REGISTER_RESPONSE" | jq -e '.success' &>/dev/null; then
    err "Registration response: $REGISTER_RESPONSE"
    die "Invalid registration response / 注册响应无效"
fi

REG_SUCCESS=$(echo "$REGISTER_RESPONSE" | jq -r '.success')
if [[ "$REG_SUCCESS" != "true" ]]; then
    REG_MSG=$(echo "$REGISTER_RESPONSE" | jq -r '.message // "unknown error"')
    die "Registration failed: ${REG_MSG} / 注册失败"
fi

DEVICE_ID=$(echo "$REGISTER_RESPONSE" | jq -r '.data.device_id')
AGENT_KEY=$(echo "$REGISTER_RESPONSE" | jq -r '.data.agent_key')

ok "Registered as device ${DEVICE_ID} / 设备注册成功"

# ---------------------------------------------------------------------------
# Step 5: Write agent config / 写入 Agent 配置文件
# ---------------------------------------------------------------------------
step "Step 5/17: Writing agent config / 写入 Agent 配置"

mkdir -p /etc/sunipip
cat > /etc/sunipip/agent.json <<AGENT_EOF
{
    "device_id": ${DEVICE_ID},
    "agent_key": "${AGENT_KEY}",
    "platform_url": "${PLATFORM_URL}",
    "heartbeat_interval_seconds": 60,
    "config_poll_interval_seconds": 30,
    "local_api_listen": "0.0.0.0:8080",
    "serial_number": "${SERIAL}"
}
AGENT_EOF

chmod 600 /etc/sunipip/agent.json
ok "Agent config written to /etc/sunipip/agent.json / Agent 配置已写入"

# ---------------------------------------------------------------------------
# Step 6: Configure WireGuard / 配置 WireGuard 隧道
# ---------------------------------------------------------------------------
step "Step 6/17: Configuring WireGuard tunnels / 配置 WireGuard 隧道"

# Parse WG configs from registration response (data is inside .data wrapper)
WG_CONFIGS=$(echo "$REGISTER_RESPONSE" | jq -r '.data.wg_configs')

for i in 0 1; do
    WG_IF="wg${i}"
    PRIVATE_KEY_FILE="/etc/wireguard/${WG_IF}_private.key"
    PRIVATE_KEY=$(cat "$PRIVATE_KEY_FILE")

    ASSIGNED_IP=$(echo "$WG_CONFIGS" | jq -r ".[${i}].assigned_ip // empty")
    MTU=$(echo "$WG_CONFIGS" | jq -r ".[${i}].mtu // \"1420\"")
    SERVER_PUBKEY=$(echo "$WG_CONFIGS" | jq -r ".[${i}].server_public_key // empty")
    SERVER_ENDPOINT=$(echo "$WG_CONFIGS" | jq -r ".[${i}].server_endpoint // empty")
    ALLOWED_IPS=$(echo "$WG_CONFIGS" | jq -r ".[${i}].allowed_ips // \"10.10.0.0/16\"")
    KEEPALIVE=$(echo "$WG_CONFIGS" | jq -r ".[${i}].persistent_keepalive // \"25\"")
    LISTEN_PORT=$(echo "$WG_CONFIGS" | jq -r ".[${i}].listen_port // empty")

    TABLE=$(echo "$WG_CONFIGS" | jq -r ".[${i}].table // \"off\"")

    if [[ -z "$ASSIGNED_IP" || -z "$SERVER_PUBKEY" || -z "$SERVER_ENDPOINT" ]]; then
        warn "WireGuard config for ${WG_IF} incomplete in response; skipping / ${WG_IF} 配置不完整，跳过"
        continue
    fi

    LISTEN_LINE=""
    if [[ -n "$LISTEN_PORT" && "$LISTEN_PORT" != "null" ]]; then
        LISTEN_LINE="ListenPort = ${LISTEN_PORT}"
    fi

    TABLE_LINE=""
    if [[ -n "$TABLE" && "$TABLE" != "null" ]]; then
        TABLE_LINE="Table = ${TABLE}"
    fi

    cat > "/etc/wireguard/${WG_IF}.conf" <<WG_EOF
[Interface]
PrivateKey = ${PRIVATE_KEY}
Address = ${ASSIGNED_IP}
MTU = ${MTU}
${TABLE_LINE}
${LISTEN_LINE}

[Peer]
PublicKey = ${SERVER_PUBKEY}
Endpoint = ${SERVER_ENDPOINT}
AllowedIPs = ${ALLOWED_IPS}
PersistentKeepalive = ${KEEPALIVE}
WG_EOF

    chmod 600 "/etc/wireguard/${WG_IF}.conf"
    ok "${WG_IF}.conf written (endpoint: ${SERVER_ENDPOINT}) / ${WG_IF} 配置已写入"
done

# ---------------------------------------------------------------------------
# Step 7: Configure network interfaces / 配置网络接口
# ---------------------------------------------------------------------------
step "Step 7/17: Configuring network interfaces / 配置网络接口"

mkdir -p /etc/network/interfaces.d

# eth0 — WAN (DHCP)
cat > /etc/network/interfaces.d/eth0 <<'NET_EOF'
# eth0 — WAN uplink (DHCP)
auto eth0
iface eth0 inet dhcp
NET_EOF

# eth1 — Management network (static)
cat > /etc/network/interfaces.d/eth1 <<'NET_EOF'
# eth1 — Management network
auto eth1
iface eth1 inet static
    address 172.10.0.1
    netmask 255.255.255.0
NET_EOF

# eth2 — Trunk port to AP (no IP, up only)
cat > /etc/network/interfaces.d/eth2 <<'NET_EOF'
# eth2 — Trunk port to AP (802.1Q tagged traffic)
auto eth2
iface eth2 inet manual
    up ip link set $IFACE up
    down ip link set $IFACE down
NET_EOF

# eth3 — Wired LAN (100.64.x CGNAT 段，避免与上游 192.168.x 同网段冲突)
cat > /etc/network/interfaces.d/eth3 <<'NET_EOF'
# eth3 — Wired LAN
auto eth3
iface eth3 inet static
    address 100.64.1.1
    netmask 255.255.255.0
NET_EOF

ok "Network interfaces configured / 网络接口已配置"
log "  eth0: WAN (DHCP)"
log "  eth1: Management 172.10.0.1/24"
log "  eth2: Trunk (no IP)"
log "  eth3: Wired LAN 100.64.1.1/24"

# ---------------------------------------------------------------------------
# Step 8: Configure FreeRADIUS / 配置 FreeRADIUS
# ---------------------------------------------------------------------------
step "Step 8/17: Configuring FreeRADIUS / 配置 FreeRADIUS"

# Extract RADIUS secret from response, or use default
RADIUS_SECRET=$(echo "$REGISTER_RESPONSE" | jq -r '.data.radius_secret // "sunipip_radius_secret"')

# clients.conf — allow AP connections from eth2 subnet
cat > /etc/freeradius/3.0/clients.conf <<RADIUS_CLIENTS_EOF
# SuniPIP RADIUS clients

# AP connected via eth2 trunk
client ap_network {
    ipaddr = 0.0.0.0/0
    secret = ${RADIUS_SECRET}
    nastype = other
    shortname = sunipip-ap
}
RADIUS_CLIENTS_EOF

# Ensure files module is enabled (Debian 12 FreeRADIUS 3.0 default)
if [[ ! -L /etc/freeradius/3.0/mods-enabled/files ]]; then
    ln -sf /etc/freeradius/3.0/mods-available/files /etc/freeradius/3.0/mods-enabled/files
fi

# Create initial empty authorize file (agent will populate dynamically)
mkdir -p /etc/freeradius/3.0/mods-config/files
cat > /etc/freeradius/3.0/mods-config/files/authorize <<'RADIUS_AUTH_EOF'
# SuniPIP dynamic user entries
# This file is managed by the SuniPIP Router Agent.
# Do not edit manually.
#
# Format:
# username Cleartext-Password := "password"
#     Tunnel-Type = VLAN,
#     Tunnel-Medium-Type = IEEE-802,
#     Tunnel-Private-Group-Id = "<vlan_id>"
RADIUS_AUTH_EOF

# Enable PEAP/TTLS tunneled reply so VLAN attributes reach the AP
sed -i 's/use_tunneled_reply = no/use_tunneled_reply = yes/g' /etc/freeradius/3.0/mods-enabled/eap 2>/dev/null || true
sed -i 's/use_tunneled_reply = no/use_tunneled_reply = yes/g' /etc/freeradius/3.0/mods-available/eap 2>/dev/null || true

chown -R freerad:freerad /etc/freeradius/3.0/
ok "FreeRADIUS configured / FreeRADIUS 已配置"

# ---------------------------------------------------------------------------
# Step 9: Configure dnsmasq / 配置 dnsmasq
# ---------------------------------------------------------------------------
step "Step 9/17: Configuring dnsmasq / 配置 dnsmasq"

# Stop default dnsmasq to avoid conflicts
systemctl stop dnsmasq 2>/dev/null || true

# Remove default config if present
[[ -f /etc/dnsmasq.conf ]] && mv /etc/dnsmasq.conf /etc/dnsmasq.conf.bak

cat > /etc/dnsmasq.conf <<'DNSMASQ_MAIN_EOF'
# SuniPIP dnsmasq main configuration
# Per-interface configs in /etc/dnsmasq.d/

# Only bind to specific interfaces
bind-interfaces

# Do not read /etc/resolv.conf
no-resolv

# Upstream DNS servers
server=8.8.8.8
server=1.1.1.1

# Include drop-in configs
conf-dir=/etc/dnsmasq.d/,*.conf
DNSMASQ_MAIN_EOF

mkdir -p /etc/dnsmasq.d

# Management network DHCP (eth1)
cat > /etc/dnsmasq.d/01-management.conf <<'DNSMASQ_MGMT_EOF'
# Management network — eth1 (172.10.0.0/24)
interface=eth1
dhcp-range=interface:eth1,172.10.0.100,172.10.0.200,255.255.255.0,12h
dhcp-option=interface:eth1,3,172.10.0.1
dhcp-option=interface:eth1,6,172.10.0.1
DNSMASQ_MGMT_EOF

# Wired LAN DHCP (eth3)
cat > /etc/dnsmasq.d/02-wired-lan.conf <<'DNSMASQ_LAN_EOF'
# Wired LAN — eth3 (100.64.1.0/24)
interface=eth3
dhcp-range=interface:eth3,100.64.1.100,100.64.1.200,255.255.255.0,12h
dhcp-option=interface:eth3,3,100.64.1.1
dhcp-option=interface:eth3,6,100.64.1.1
DNSMASQ_LAN_EOF

# Ensure no DHCP on eth0, eth2, wg interfaces
cat > /etc/dnsmasq.d/00-global.conf <<'DNSMASQ_GLOBAL_EOF'
# Exclude non-LAN interfaces from DHCP
no-dhcp-interface=eth0
no-dhcp-interface=eth2
no-dhcp-interface=wg0
no-dhcp-interface=wg1
DNSMASQ_GLOBAL_EOF

ok "dnsmasq configured / dnsmasq 已配置"
log "  eth1: DHCP 172.10.0.100-200"
log "  eth3: DHCP 100.64.1.100-200"

# ---------------------------------------------------------------------------
# Step 10: Configure nftables / 配置防火墙
# ---------------------------------------------------------------------------
step "Step 10/17: Configuring nftables firewall / 配置 nftables 防火墙"

cat > /etc/nftables.conf <<'NFT_EOF'
#!/usr/sbin/nft -f
# SuniPIP Soft Router — nftables ruleset
# 软路由防火墙规则

flush ruleset

table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;

        # Allow established/related connections
        ct state established,related accept

        # Loopback
        iif "lo" accept

        # Management network (eth1) — physically secured, allow all
        iifname "eth1" accept

        # WAN (eth0) — WireGuard endpoints only
        iif "eth0" udp dport { 51820, 51821 } accept

        # WireGuard tunnels — SSH for remote management
        iif "wg0" tcp dport 22 accept
        iif "wg1" tcp dport 22 accept

        # Trunk port (eth2) — RADIUS from AP
        iif "eth2" udp dport { 1812, 1813 } accept

        # VLAN bridges — DHCP + DNS for customer VLANs
        iif "br-vlan*" udp dport { 67, 53 } accept
        iif "br-vlan*" tcp dport 53 accept

        # Wired LAN (eth3) — DHCP + DNS + web UI
        iif "eth3" udp dport { 67, 53 } accept
        iif "eth3" tcp dport { 53, 80 } accept
    }

    chain forward {
        type filter hook forward priority 0; policy drop;

        # Allow established/related
        ct state established,related accept

        # Wired LAN -> WAN
        iif "eth3" oif "eth0" accept

        # Customer VLANs -> WAN
        iif "br-vlan*" oif "eth0" accept
    }
}

table inet nat {
    chain postrouting {
        type nat hook postrouting priority 100;

        # Masquerade all traffic leaving via WAN
        oif "eth0" masquerade
    }
}
NFT_EOF

chmod 755 /etc/nftables.conf
ok "nftables firewall rules written / 防火墙规则已写入"

# ---------------------------------------------------------------------------
# Step 11: Install Clash / 安装 Clash 代理
# ---------------------------------------------------------------------------
step "Step 11/17: Installing Clash proxy / 安装 Clash 代理"

mkdir -p /etc/clash

# Download Clash Meta (mihomo) — uses temporary proxy for GitHub access
CLASH_TMP="/tmp/mihomo.gz"
log "Downloading Clash from ${CLASH_URL} (via proxy) ..."
if curl -fsSL -o "$CLASH_TMP" "$CLASH_URL" --proxy "$_INSTALL_PROXY" --connect-timeout 30 --max-time 300; then
    gunzip -f "$CLASH_TMP"
    mv /tmp/mihomo /usr/local/bin/clash
    chmod +x /usr/local/bin/clash
    /usr/local/bin/clash -v 2>&1 | tee -a "$LOG_FILE" || warn "Clash binary version check failed"
    ok "Clash binary installed to /usr/local/bin/clash"
else
    warn "Failed to download Clash via proxy; trying direct..."
    if curl -fsSL -o "$CLASH_TMP" "$CLASH_URL" --connect-timeout 30 --max-time 300; then
        gunzip -f "$CLASH_TMP"
        mv /tmp/mihomo /usr/local/bin/clash
        chmod +x /usr/local/bin/clash
        ok "Clash binary installed (direct download)"
    else
        warn "Failed to download Clash; creating placeholder. Agent will download on first sync."
        echo '#!/bin/bash' > /usr/local/bin/clash
        echo 'echo "Clash binary not installed."' >> /usr/local/bin/clash
        chmod +x /usr/local/bin/clash
    fi
fi

# Minimal Clash config (agent will overwrite with full config)
cat > /etc/clash/config.yaml <<'CLASH_CFG_EOF'
# SuniPIP Clash configuration
# This file is managed by the SuniPIP Router Agent.
mixed-port: 7890
allow-lan: true
bind-address: "*"
mode: rule
log-level: info
external-controller: "172.10.0.1:9090"

dns:
  enable: true
  listen: "0.0.0.0:1053"
  nameserver:
    - 8.8.8.8
    - 1.1.1.1

proxies: []

rules:
  - MATCH,DIRECT
CLASH_CFG_EOF

# Systemd service
cat > /etc/systemd/system/clash.service <<'CLASH_SVC_EOF'
[Unit]
Description=Clash Proxy
Documentation=https://github.com/MetaCubeX/mihomo
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/clash -d /etc/clash
Restart=on-failure
RestartSec=5
LimitNOFILE=1048576
CapabilityBoundingSet=CAP_NET_ADMIN CAP_NET_RAW CAP_NET_BIND_SERVICE
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_RAW CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
CLASH_SVC_EOF

ok "Clash service configured / Clash 服务已配置"

# ---------------------------------------------------------------------------
# Step 12: Install Go Agent / 安装 SuniPIP Agent
# ---------------------------------------------------------------------------
step "Step 12/17: Installing SuniPIP Router Agent / 安装软路由 Agent"

log "Downloading agent from ${AGENT_URL} ..."
if curl -fsSL -o /usr/local/bin/sunipip-router-agent "$AGENT_URL" --connect-timeout 30 --max-time 120; then
    chmod +x /usr/local/bin/sunipip-router-agent
    ok "Agent binary installed to /usr/local/bin/sunipip-router-agent"
else
    warn "Failed to download agent binary; placeholder created. Deploy manually later."
    cat > /usr/local/bin/sunipip-router-agent <<'AGENT_PLACEHOLDER'
#!/bin/bash
echo "SuniPIP Router Agent binary not installed."
echo "Please download from the platform and place at /usr/local/bin/sunipip-router-agent"
sleep infinity
AGENT_PLACEHOLDER
    chmod +x /usr/local/bin/sunipip-router-agent
fi

# Systemd service
cat > /etc/systemd/system/sunipip-router-agent.service <<'AGENT_SVC_EOF'
[Unit]
Description=SuniPIP Router Agent
Documentation=https://api-all.sunip.cc
After=network.target wg-quick@wg0.service

[Service]
Type=simple
ExecStart=/usr/local/bin/sunipip-router-agent
Restart=always
RestartSec=10
WorkingDirectory=/etc/sunipip
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
AGENT_SVC_EOF

ok "Agent service configured / Agent 服务已配置"

# ---------------------------------------------------------------------------
# Step 13: Deploy frontend SPA / 部署前端页面
# ---------------------------------------------------------------------------
step "Step 13/17: Deploying frontend SPA / 部署前端页面"

mkdir -p /var/www/router-frontend

FRONTEND_TMP="/tmp/router-frontend-dist.tar.gz"
log "Downloading frontend from ${FRONTEND_URL} ..."
if curl -fsSL -o "$FRONTEND_TMP" "$FRONTEND_URL" --connect-timeout 30 --max-time 60; then
    tar -xzf "$FRONTEND_TMP" -C /var/www/router-frontend --strip-components=1 2>/dev/null \
        || tar -xzf "$FRONTEND_TMP" -C /var/www/router-frontend
    rm -f "$FRONTEND_TMP"
    ok "Frontend deployed to /var/www/router-frontend"
else
    warn "Failed to download frontend; creating placeholder page."
    cat > /var/www/router-frontend/index.html <<'HTML_EOF'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuniPIP Router</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               display: flex; justify-content: center; align-items: center;
               height: 100vh; margin: 0; background: #f5f5f5; color: #333; }
        .card { background: white; border-radius: 12px; padding: 48px;
                box-shadow: 0 2px 12px rgba(0,0,0,0.1); text-align: center; }
        h1 { color: #1a73e8; margin-bottom: 8px; }
        p { color: #666; }
    </style>
</head>
<body>
    <div class="card">
        <h1>SuniPIP Router</h1>
        <p>Frontend is being deployed by the agent...</p>
        <p>前端页面正在由 Agent 部署中...</p>
    </div>
</body>
</html>
HTML_EOF
fi

chown -R www-data:www-data /var/www/router-frontend
ok "Frontend ready / 前端就绪"

# ---------------------------------------------------------------------------
# Step 14: Configure Nginx / 配置 Nginx
# ---------------------------------------------------------------------------
step "Step 14/17: Configuring Nginx / 配置 Nginx"

cat > /etc/nginx/sites-available/router <<'NGINX_EOF'
# SuniPIP Router — Nginx configuration

server {
    listen 80 default_server;
    server_name _;
    charset utf-8;
    root /var/www/router-frontend;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8080/api/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
NGINX_EOF

# Enable site, disable default
ln -sf /etc/nginx/sites-available/router /etc/nginx/sites-enabled/router
rm -f /etc/nginx/sites-enabled/default

# Test config
nginx -t 2>&1 | tee -a "$LOG_FILE" || warn "Nginx config test reported issues; will attempt to start anyway"
ok "Nginx configured / Nginx 已配置"

# ---------------------------------------------------------------------------
# Step 15: Enable IP forwarding / 启用 IP 转发
# ---------------------------------------------------------------------------
step "Step 15/17: Enabling IP forwarding / 启用 IP 转发"

cat > /etc/sysctl.d/99-sunipip.conf <<'SYSCTL_EOF'
# SuniPIP — enable IPv4 forwarding
net.ipv4.ip_forward=1
# Allow binding to non-local addresses (required for TProxy)
net.ipv4.ip_nonlocal_bind=1
SYSCTL_EOF

sysctl -p /etc/sysctl.d/99-sunipip.conf
ok "IP forwarding enabled / IP 转发已启用"

# Set up TProxy routing rules (Clash transparent proxy needs these)
log "Setting up TProxy routing / 配置 TProxy 路由..."
ip rule add fwmark 1 table 100 2>/dev/null || true
ip route replace local default dev lo table 100
ok "TProxy routing configured / TProxy 路由已配置"

# ---------------------------------------------------------------------------
# Step 16: Enable and start all services / 启动所有服务
# ---------------------------------------------------------------------------
step "Step 16/17: Enabling and starting services / 启动服务"

systemctl daemon-reload

SERVICES=(
    nftables
    freeradius
    dnsmasq
    clash
    sunipip-router-agent
    nginx
    wg-quick@wg0
    wg-quick@wg1
)

for svc in "${SERVICES[@]}"; do
    log "Enabling ${svc} ..."
    systemctl enable "$svc" 2>/dev/null || warn "Failed to enable ${svc}"
done

for svc in "${SERVICES[@]}"; do
    log "Starting ${svc} ..."
    if systemctl start "$svc" 2>/dev/null; then
        ok "${svc} started"
    else
        warn "${svc} failed to start — check: journalctl -u ${svc}"
    fi
done

# ---------------------------------------------------------------------------
# Step 17: Success / 安装完成
# ---------------------------------------------------------------------------
step "Step 17/17: Installation complete / 安装完成"

echo ""
echo -e "${GREEN}${BOLD}============================================================${NC}"
echo -e "${GREEN}${BOLD}  SuniPIP Soft Router — Installation Complete!${NC}"
echo -e "${GREEN}${BOLD}  软路由安装完成!${NC}"
echo -e "${GREEN}${BOLD}============================================================${NC}"
echo ""
echo -e "  ${BOLD}Device ID:${NC}       ${DEVICE_ID}"
echo -e "  ${BOLD}Serial Number:${NC}   ${SERIAL}"
echo -e "  ${BOLD}Agent Version:${NC}   ${AGENT_VERSION}"
echo -e "  ${BOLD}Hostname:${NC}        $(hostname)"
echo ""
echo -e "  ${BOLD}Network:${NC}"
echo -e "    eth0 (WAN):        DHCP"
echo -e "    eth1 (Management): 172.10.0.1/24"
echo -e "    eth2 (Trunk):      No IP (802.1Q)"
echo -e "    eth3 (Wired LAN):  100.64.1.1/24"
echo ""
echo -e "  ${BOLD}Services:${NC}"
for svc in "${SERVICES[@]}"; do
    STATUS=$(systemctl is-active "$svc" 2>/dev/null || echo "unknown")
    if [[ "$STATUS" == "active" ]]; then
        echo -e "    ${GREEN}[active]${NC} ${svc}"
    else
        echo -e "    ${RED}[${STATUS}]${NC} ${svc}"
    fi
done
echo ""
echo -e "  ${BOLD}Web UI:${NC}          http://172.10.0.1/"
echo -e "  ${BOLD}Log file:${NC}        ${LOG_FILE}"
echo ""
echo -e "  ${CYAN}Connect to eth1 or eth3 to access the management interface.${NC}"
echo -e "  ${CYAN}连接 eth1 或 eth3 网口，访问管理界面。${NC}"
echo ""
