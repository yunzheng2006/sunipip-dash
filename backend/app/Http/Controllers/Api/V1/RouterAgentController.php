<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RouterConfigSnapshot;
use App\Models\RouterDevice;
use App\Models\RouterEventLog;
use App\Models\RouterRemoteCommand;
use App\Services\Router\RouterConfigService;
use App\Services\Router\RouterProvisionService;
use App\Services\Router\RouterWifiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouterAgentController extends Controller
{
    public function __construct(
        private RouterProvisionService $provisionService,
    ) {}

    private function authenticate(Request $request): ?RouterDevice
    {
        $key = $request->header('X-Agent-Key');
        if (!$key) {
            return null;
        }

        return RouterDevice::where('agent_key', $key)
            ->whereNotIn('status', ['decommissioned'])
            ->first();
    }

    public function installScript(string $token)
    {
        $device = RouterDevice::where('install_token', $token)
            ->where('install_token_expires_at', '>', now())
            ->whereIn('status', ['inventory', 'provisioned'])
            ->first();

        if (!$device) {
            abort(404, '安装令牌无效或已过期');
        }

        $platformUrl = rtrim(config('app.url'), '/');

        $script = $this->generateInstallScript($device, $token, $platformUrl);

        return response($script, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'inline; filename="install.sh"');
    }

    private function generateInstallScript(RouterDevice $device, string $token, string $platformUrl): string
    {
        $script = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
export PATH="/usr/sbin:/sbin:/usr/local/sbin:$PATH"

# ============================================================
#  SuniPIP 软路由 Agent 安装脚本
#  自动生成 — 令牌有效期 72 小时，注册后自动失效
# ============================================================

INSTALL_TOKEN="__TOKEN__"
PLATFORM_URL="__PLATFORM_URL__"
DEVICE_ID="__DEVICE_ID__"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

[[ $EUID -ne 0 ]] && error "请使用 root 权限运行此脚本"

# ---- 1. 硬件检查 & 网卡自动检测 ----
info "检查硬件环境..."

# 自动检测物理网卡，按 PCI 总线地址排序（enp1s0, enp2s0... 或 eth0, eth1...）
mapfile -t NICS < <(ls -1 /sys/class/net | grep -vE '^lo$|^wg|^docker|^br-|^veth|^virbr' | sort)
NIC_COUNT=${#NICS[@]}

[[ $NIC_COUNT -lt 4 ]] && warn "检测到 $NIC_COUNT 个网卡，建议 4 个（当前: ${NICS[*]}）"
[[ $NIC_COUNT -lt 2 ]] && error "至少需要 2 个物理网卡"

# 网口角色分配（按 PCI 总线顺序）
NIC_WAN="${NICS[0]}"          # 第1口: WAN 上网 (DHCP/PPPoE)
NIC_MGMT="${NICS[1]}"         # 第2口: 管理口 172.10.0.1
NIC_AP="${NICS[2]:-}"         # 第3口: AP trunk (802.1Q)
NIC_LAN="${NICS[3]:-}"        # 第4口: 有线 LAN 100.64.1.1 (CGNAT 段，避免与上游 192.168.x 冲突，与 Agent 运行时配置一致)

info "网卡检测完成:"
info "  WAN  (上网):   $NIC_WAN"
info "  MGMT (管理口): $NIC_MGMT"
[[ -n "$NIC_AP" ]]  && info "  AP   (trunk):   $NIC_AP"   || warn "  AP   (trunk):   未检测到第3网口"
[[ -n "$NIC_LAN" ]] && info "  LAN  (有线):    $NIC_LAN"  || warn "  LAN  (有线):    未检测到第4网口"

SERIAL=$(cat /sys/class/dmi/id/board_serial 2>/dev/null || hostname)
[[ "$SERIAL" == "Default string" || -z "$SERIAL" ]] && SERIAL=$(hostname)
info "主板序列号: $SERIAL"

TOTAL_MEM=$(free -m | awk '/Mem:/{print $2}')
info "总内存: ${TOTAL_MEM}MB"

# 保存网卡映射到配置文件
mkdir -p /etc/sunipip
cat > /etc/sunipip/interfaces.json <<EOF
{
    "wan": "$NIC_WAN",
    "mgmt": "$NIC_MGMT",
    "ap": "${NIC_AP}",
    "lan": "${NIC_LAN}"
}
EOF

# ---- 2. 安装依赖 ----
info "安装依赖包..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq curl jq wireguard freeradius dnsmasq nftables nginx \
    iproute2 net-tools vlan chrony procps sshpass >/dev/null 2>&1
# 加载 802.1Q 模块（AP trunk 需要）
modprobe 8021q 2>/dev/null || true
echo "8021q" >> /etc/modules-load.d/sunipip.conf 2>/dev/null || true

# ---- 2b. 同步时区为 UTC+8 (Asia/Shanghai) ----
info "设置时区为 Asia/Shanghai (UTC+8)..."
timedatectl set-timezone Asia/Shanghai 2>/dev/null || ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime
# 配置 chrony NTP 同步
cat > /etc/chrony/chrony.conf <<'CHRONYCFG'
server ntp.aliyun.com iburst
server cn.ntp.org.cn iburst
server ntp.tencent.com iburst
driftfile /var/lib/chrony/chrony.drift
makestep 1.0 3
rtcsync
CHRONYCFG
systemctl enable chrony >/dev/null 2>&1
systemctl restart chrony >/dev/null 2>&1
info "时区和时间同步配置完成"

info "依赖安装完成"

# ---- 3. 生成 WireGuard 密钥 ----
info "生成 WireGuard 密钥对..."
WG_PRIV_1=$(wg genkey)
WG_PUB_1=$(echo "$WG_PRIV_1" | wg pubkey)
WG_PRIV_2=$(wg genkey)
WG_PUB_2=$(echo "$WG_PRIV_2" | wg pubkey)
echo "$WG_PRIV_1" > /etc/sunipip/wg0.key && chmod 600 /etc/sunipip/wg0.key
echo "$WG_PRIV_2" > /etc/sunipip/wg1.key && chmod 600 /etc/sunipip/wg1.key
info "WG 公钥1: $WG_PUB_1"
info "WG 公钥2: $WG_PUB_2"

# ---- 4. 向平台注册 ----
info "向平台注册设备..."
AGENT_VERSION="0.1.0"
REG_RESPONSE=$(curl -sS -X POST "${PLATFORM_URL}/api/v1/router-agent/register" \
    -H "Content-Type: application/json" \
    -d "{
        \"install_token\": \"${INSTALL_TOKEN}\",
        \"serial_number\": \"${SERIAL}\",
        \"wg_public_key_1\": \"${WG_PUB_1}\",
        \"wg_public_key_2\": \"${WG_PUB_2}\",
        \"hostname\": \"$(hostname)\",
        \"agent_version\": \"${AGENT_VERSION}\"
    }")

REG_SUCCESS=$(echo "$REG_RESPONSE" | jq -r '.success // false')
if [[ "$REG_SUCCESS" != "true" ]]; then
    REG_MSG=$(echo "$REG_RESPONSE" | jq -r '.message // "未知错误"')
    error "注册失败: $REG_MSG"
fi

AGENT_KEY=$(echo "$REG_RESPONSE" | jq -r '.data.agent_key')
REG_DEVICE_ID=$(echo "$REG_RESPONSE" | jq -r '.data.device_id')
info "注册成功! device_id=$REG_DEVICE_ID"

# ---- 5. 写入 Agent 配置 ----
cat > /etc/sunipip/agent.json <<EOF
{
    "device_id": $REG_DEVICE_ID,
    "agent_key": "$AGENT_KEY",
    "platform_url": "$PLATFORM_URL",
    "heartbeat_interval_seconds": 5,
    "config_poll_interval_seconds": 5,
    "local_api_listen": "172.10.0.1:8080",
    "serial_number": "$SERIAL"
}
EOF
chmod 600 /etc/sunipip/agent.json
info "Agent 配置已写入 /etc/sunipip/agent.json"

# ---- 6. 配置 WireGuard ----
WG1_CONFIG=$(echo "$REG_RESPONSE" | jq -r '.data.wg_config_1 // empty')
WG2_CONFIG=$(echo "$REG_RESPONSE" | jq -r '.data.wg_config_2 // empty')

if [[ -n "$WG1_CONFIG" ]]; then
    WG1_ENDPOINT=$(echo "$WG1_CONFIG" | jq -r '.endpoint')
    WG1_SERVER_PUB=$(echo "$WG1_CONFIG" | jq -r '.server_public_key')
    WG1_IP=$(echo "$WG1_CONFIG" | jq -r '.assigned_ip')
    WG1_PSK=$(echo "$WG1_CONFIG" | jq -r '.preshared_key // empty')

    cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
PrivateKey = $WG_PRIV_1
Address = $WG1_IP

[Peer]
PublicKey = $WG1_SERVER_PUB
$([ -n "$WG1_PSK" ] && echo "PresharedKey = $WG1_PSK")
Endpoint = $WG1_ENDPOINT
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF
    chmod 600 /etc/wireguard/wg0.conf
    info "WireGuard wg0 已配置: $WG1_IP → $WG1_ENDPOINT"
fi

if [[ -n "$WG2_CONFIG" ]]; then
    WG2_ENDPOINT=$(echo "$WG2_CONFIG" | jq -r '.endpoint')
    WG2_SERVER_PUB=$(echo "$WG2_CONFIG" | jq -r '.server_public_key')
    WG2_IP=$(echo "$WG2_CONFIG" | jq -r '.assigned_ip')
    WG2_PSK=$(echo "$WG2_CONFIG" | jq -r '.preshared_key // empty')

    cat > /etc/wireguard/wg1.conf <<EOF
[Interface]
PrivateKey = $WG_PRIV_2
Address = $WG2_IP

[Peer]
PublicKey = $WG2_SERVER_PUB
$([ -n "$WG2_PSK" ] && echo "PresharedKey = $WG2_PSK")
Endpoint = $WG2_ENDPOINT
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF
    chmod 600 /etc/wireguard/wg1.conf
    info "WireGuard wg1 已配置: $WG2_IP → $WG2_ENDPOINT"
fi

# ---- 7. 基础网络配置 ----
info "配置基础网络..."

# 管理口
ip addr flush dev "$NIC_MGMT" 2>/dev/null || true
ip addr add 172.10.0.1/24 dev "$NIC_MGMT" 2>/dev/null || true
ip link set "$NIC_MGMT" up
info "  $NIC_MGMT → 172.10.0.1/24 (管理口)"

# AP trunk (给 AP 分配管理 IP)
if [[ -n "$NIC_AP" ]]; then
    ip addr flush dev "$NIC_AP" 2>/dev/null || true
    ip addr add 10.20.0.1/24 dev "$NIC_AP" 2>/dev/null || true
    ip link set "$NIC_AP" up
    info "  $NIC_AP → 10.20.0.1/24 (AP trunk, VLAN 子接口由 Agent 动态创建)"
fi

# 有线 LAN
if [[ -n "$NIC_LAN" ]]; then
    ip addr flush dev "$NIC_LAN" 2>/dev/null || true
    ip addr add 100.64.1.1/24 dev "$NIC_LAN" 2>/dev/null || true
    ip link set "$NIC_LAN" up
    info "  $NIC_LAN → 100.64.1.1/24 (有线 LAN)"
fi

# 持久化网络配置
cat > /etc/network/interfaces.d/sunipip <<EOF
# SuniPIP 软路由网络配置 — 自动生成

# WAN (DHCP)
auto $NIC_WAN
iface $NIC_WAN inet dhcp

# 管理口
auto $NIC_MGMT
iface $NIC_MGMT inet static
    address 172.10.0.1
    netmask 255.255.255.0
EOF

if [[ -n "$NIC_AP" ]]; then
    cat >> /etc/network/interfaces.d/sunipip <<EOF

# AP trunk (管理 IP，VLAN 子接口由 Agent 动态创建)
auto $NIC_AP
iface $NIC_AP inet static
    address 10.20.0.1
    netmask 255.255.255.0
EOF
fi

if [[ -n "$NIC_LAN" ]]; then
    cat >> /etc/network/interfaces.d/sunipip <<EOF

# 有线 LAN (100.64.x CGNAT 段，防止与上游 192.168.x 同网段冲突；与 RouterConfigService 下发值一致)
auto $NIC_LAN
iface $NIC_LAN inet static
    address 100.64.1.1
    netmask 255.255.255.0
EOF
fi

# 开启转发
echo "net.ipv4.ip_forward=1" > /etc/sysctl.d/99-sunipip.conf
if command -v sysctl &>/dev/null; then
    sysctl -w net.ipv4.ip_forward=1 >/dev/null
else
    echo 1 > /proc/sys/net/ipv4/ip_forward
fi

# ---- 8. 配置 dnsmasq 基础 ----
cat > /etc/dnsmasq.d/sunipip-base.conf <<EOF
# 管理口 DHCP
interface=$NIC_MGMT
dhcp-range=172.10.0.10,172.10.0.250,255.255.255.0,12h
EOF

if [[ -n "$NIC_AP" ]]; then
    cat >> /etc/dnsmasq.d/sunipip-base.conf <<EOF
# AP trunk DHCP (AP 管理 IP 自动分配)
interface=$NIC_AP
dhcp-range=10.20.0.100,10.20.0.200,255.255.255.0,12h
EOF
fi

if [[ -n "$NIC_LAN" ]]; then
    cat >> /etc/dnsmasq.d/sunipip-base.conf <<EOF
# 有线 LAN DHCP
interface=$NIC_LAN
dhcp-range=100.64.1.100,100.64.1.200,255.255.255.0,12h
EOF
fi

cat >> /etc/dnsmasq.d/sunipip-base.conf <<EOF
# DNS 上游
server=223.5.5.5
server=119.29.29.29
# 不读取 /etc/resolv.conf
no-resolv
EOF

# 禁用 systemd-resolved 防止端口冲突
systemctl disable --now systemd-resolved 2>/dev/null || true

# ---- 9. 配置 nftables 基础防火墙 ----
modprobe nf_tables 2>/dev/null || true
cat > /etc/nftables.conf <<EOF
#!/usr/sbin/nft -f
flush ruleset
table inet filter {
    chain input {
        type filter hook input priority 0; policy accept;
    }
    chain forward {
        type filter hook forward priority 0; policy accept;
    }
}
table inet nat {
    chain postrouting {
        type nat hook postrouting priority 100; policy accept;
        oifname "$NIC_WAN" masquerade;
    }
}
EOF
NFT_ERR=$(nft -f /etc/nftables.conf 2>&1) || {
    warn "nftables 加载失败: $NFT_ERR"
}
info "防火墙规则已应用"

# ---- 10. 配置 Nginx ----
cat > /etc/nginx/sites-available/sunipip <<'NGINX'
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
NGINX
ln -sf /etc/nginx/sites-available/sunipip /etc/nginx/sites-enabled/sunipip
rm -f /etc/nginx/sites-enabled/default
mkdir -p /var/www/router-frontend
cat > /var/www/router-frontend/index.html <<'HTMLEOF'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SuniPIP Router</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f2f5;color:#333;min-height:100vh}
.container{max-width:640px;margin:0 auto;padding:20px}
.header{text-align:center;padding:32px 0 24px}
.logo{font-size:28px;font-weight:700;color:#1e293b;letter-spacing:-0.5px}
.logo span{background:linear-gradient(135deg,#667eea,#764ba2);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.subtitle{color:#94a3b8;font-size:13px;margin-top:4px}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.06);padding:24px;margin-bottom:16px}
.card-title{font-size:16px;font-weight:600;color:#1e293b;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.card-title .icon{font-size:18px}
.hw-diagram{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;text-align:center;margin-bottom:16px}
.hw-box{display:inline-block;background:#1e293b;color:#fff;border-radius:8px;padding:16px 20px;position:relative}
.hw-ports{display:flex;gap:8px;margin-bottom:12px}
.hw-port{width:48px;height:32px;border:2px solid #475569;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;background:#334155}
.hw-port.wan{border-color:#22c55e;background:#166534;color:#bbf7d0}
.hw-power{width:32px;height:32px;border:2px solid #475569;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:8px;background:#334155;color:#94a3b8}
.hw-label{font-size:11px;color:#94a3b8;margin-top:4px}
.hw-arrow{color:#22c55e;font-size:12px;margin-top:8px}
.status-row{display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
.status-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}
.status-err{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.status-loading{background:#f0f9ff;border:1px solid #bae6fd;color:#0284c7}
.dot{width:8px;height:8px;border-radius:50%;background:currentColor}
.dot.pulse{animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 20px;border-radius:8px;border:none;font-size:14px;font-weight:500;cursor:pointer;transition:all .2s;width:100%}
.btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5a6fd6}
.btn-outline{background:#fff;color:#475569;border:1px solid #e2e8f0}.btn-outline:hover{background:#f8fafc}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:8px 16px;font-size:13px;width:auto}
.btn:disabled{opacity:.5;cursor:not-allowed}
.info-text{font-size:13px;color:#64748b;line-height:1.7}
.info-text a{color:#667eea;text-decoration:none}
.tip{font-size:12px;color:#94a3b8;margin-top:8px}
.result-text{font-size:14px;font-weight:500;flex:1}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:13px;font-weight:500;color:#475569;margin-bottom:6px}
.form-input{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;outline:none;transition:border-color .2s}
.form-input:focus{border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}
.form-select{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;outline:none;background:#fff;cursor:pointer}
.form-select:focus{border-color:#667eea}
.wan-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.wan-info-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px}
.wan-info-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.wan-info-value{font-size:14px;font-weight:600;color:#1e293b;margin-top:2px;word-break:break-all}
.hidden{display:none}
.mode-tabs{display:flex;gap:4px;background:#f1f5f9;border-radius:8px;padding:4px;margin-bottom:16px}
.mode-tab{flex:1;padding:8px;border:none;background:none;border-radius:6px;font-size:13px;font-weight:500;color:#64748b;cursor:pointer;transition:all .2s}
.mode-tab.active{background:#fff;color:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:12px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="logo"><span>SuniPIP</span> Router</div>
    <div class="subtitle">软路由管理系统 · sunipip.com</div>
  </div>

  <div class="card">
    <div class="card-title"><span class="icon">🔌</span>接口说明</div>
    <div class="hw-diagram">
      <div class="hw-box">
        <div class="hw-ports">
          <div class="hw-port">ETH3</div>
          <div class="hw-port">ETH2</div>
          <div class="hw-port">ETH1</div>
          <div class="hw-port wan">ETH0</div>
          <div class="hw-power">PWR</div>
        </div>
        <div class="hw-label">← 网口 · · · · · · · · · · · 电源 →</div>
      </div>
      <div class="hw-arrow">↑ 将上网网线插入 <b>ETH0</b>（电源口旁边的网口）</div>
    </div>
    <div class="info-text">
      <b>ETH0</b>：上网口（WAN）— 连接光猫/路由器<br>
      <b>ETH1</b>：管理口 — 连接电脑可访问本页面<br>
      <b>ETH2</b>：AP 口 — 连接无线 AP<br>
      <b>ETH3</b>：有线口（LAN）— 直连上网，不走代理
    </div>
  </div>

  <div class="card">
    <div class="card-title"><span class="icon">🌐</span>网络状态</div>
    <div id="net-status" class="status-row status-loading">
      <span class="dot pulse"></span>
      <span class="result-text">点击下方按钮检测网络连接</span>
    </div>
    <button class="btn btn-primary" id="btn-check" onclick="checkNetwork()">检测网络连接</button>
    <p class="tip">检测软路由是否能正常连接互联网（ping 223.5.5.5）</p>
  </div>

  <div class="card">
    <div class="card-title"><span class="icon">🛜</span>上网方式配置（WAN）</div>
    <div id="wan-alert"></div>
    <div id="wan-current" class="wan-info">
      <div class="wan-info-item"><div class="wan-info-label">当前模式</div><div class="wan-info-value" id="wan-mode-text">--</div></div>
      <div class="wan-info-item"><div class="wan-info-label">IP 地址</div><div class="wan-info-value" id="wan-ip-text">--</div></div>
      <div class="wan-info-item"><div class="wan-info-label">网关</div><div class="wan-info-value" id="wan-gw-text">--</div></div>
      <div class="wan-info-item"><div class="wan-info-label">DNS</div><div class="wan-info-value" id="wan-dns-text">--</div></div>
    </div>

    <div class="mode-tabs">
      <button class="mode-tab active" onclick="switchWanMode('dhcp')">自动获取(DHCP)</button>
      <button class="mode-tab" onclick="switchWanMode('static')">静态 IP</button>
      <button class="mode-tab" onclick="switchWanMode('pppoe')">PPPoE 拨号</button>
    </div>

    <div id="wan-form-dhcp">
      <div class="info-text" style="margin-bottom:16px">自动从光猫或上级路由器获取 IP 地址，适用于大多数网络环境。</div>
    </div>

    <div id="wan-form-static" class="hidden">
      <div class="form-group">
        <label class="form-label">IP 地址</label>
        <input class="form-input" id="wan-static-ip" placeholder="192.168.1.100">
      </div>
      <div class="form-group">
        <label class="form-label">子网掩码</label>
        <input class="form-input" id="wan-static-netmask" placeholder="255.255.255.0" value="255.255.255.0">
      </div>
      <div class="form-group">
        <label class="form-label">网关</label>
        <input class="form-input" id="wan-static-gateway" placeholder="192.168.1.1">
      </div>
      <div class="form-group">
        <label class="form-label">DNS 1</label>
        <input class="form-input" id="wan-static-dns1" placeholder="223.5.5.5" value="223.5.5.5">
      </div>
      <div class="form-group">
        <label class="form-label">DNS 2（可选）</label>
        <input class="form-input" id="wan-static-dns2" placeholder="119.29.29.29" value="119.29.29.29">
      </div>
    </div>

    <div id="wan-form-pppoe" class="hidden">
      <div class="form-group">
        <label class="form-label">宽带账号</label>
        <input class="form-input" id="wan-pppoe-user" placeholder="宽带账号">
      </div>
      <div class="form-group">
        <label class="form-label">宽带密码</label>
        <input class="form-input" id="wan-pppoe-pass" type="password" placeholder="宽带密码">
      </div>
      <div class="info-text">PPPoE 拨号上网，适用于直连光猫的场景。</div>
    </div>

    <button class="btn btn-success" id="btn-wan-save" onclick="saveWanConfig()" style="margin-top:12px">保存并应用</button>
    <p class="tip">修改上网方式后网络会短暂中断，请确认配置正确</p>
  </div>

  <div class="card">
    <div class="card-title"><span class="icon">⚙️</span>设备管理</div>
    <div class="info-text" style="margin-bottom:16px">
      WiFi 账号管理、代理节点配置请访问客户管理平台：<br>
      <a href="https://user.sunipip.com/router" target="_blank"><b>user.sunipip.com</b></a>
    </div>
    <a href="https://user.sunipip.com/router" target="_blank" class="btn btn-outline" style="text-decoration:none">
      前往管理平台
    </a>
  </div>
</div>

<script>
var currentWanMode='dhcp';

async function checkNetwork(){
  var btn=document.getElementById('btn-check'),el=document.getElementById('net-status');
  btn.disabled=true;btn.textContent='检测中...';
  el.className='status-row status-loading';
  el.innerHTML='<span class="dot pulse"></span><span class="result-text">正在检测网络连接...</span>';
  try{
    var r=await fetch('/api/network-check');var d=await r.json();
    if(d.data&&d.data.connected){
      el.className='status-row status-ok';
      el.innerHTML='<span class="dot"></span><span class="result-text">网络连接正常'+(d.data.latency?' · 延迟 '+d.data.latency:'')+'</span>';
    }else{
      el.className='status-row status-err';
      el.innerHTML='<span class="dot"></span><span class="result-text">无法连接互联网，请检查 ETH0 网线是否插好</span>';
    }
  }catch(e){
    el.className='status-row status-err';
    el.innerHTML='<span class="dot"></span><span class="result-text">检测失败，Agent 服务可能未运行</span>';
  }
  btn.disabled=false;btn.textContent='重新检测';
}

function switchWanMode(mode){
  currentWanMode=mode;
  document.querySelectorAll('.mode-tab').forEach(function(t){t.classList.remove('active')});
  document.querySelectorAll('.mode-tab').forEach(function(t){if(t.textContent.toLowerCase().indexOf(mode)>=0||(mode==='dhcp'&&t.textContent.indexOf('自动')>=0)||(mode==='static'&&t.textContent.indexOf('静态')>=0)||(mode==='pppoe'&&t.textContent.indexOf('PPPoE')>=0))t.classList.add('active')});
  document.getElementById('wan-form-dhcp').classList.toggle('hidden',mode!=='dhcp');
  document.getElementById('wan-form-static').classList.toggle('hidden',mode!=='static');
  document.getElementById('wan-form-pppoe').classList.toggle('hidden',mode!=='pppoe');
}

async function loadWanStatus(){
  try{
    var r=await fetch('/api/wan-config');
    if(r.status===401){
      document.getElementById('wan-mode-text').textContent='需要登录';
      return;
    }
    var d=await r.json();
    if(d.success&&d.data){
      var w=d.data;
      var modeMap={dhcp:'自动获取(DHCP)',static:'静态 IP',pppoe:'PPPoE 拨号'};
      document.getElementById('wan-mode-text').textContent=modeMap[w.mode]||w.mode;
      document.getElementById('wan-ip-text').textContent=w.ip||'--';
      document.getElementById('wan-gw-text').textContent=w.gateway||'--';
      document.getElementById('wan-dns-text').textContent=(w.dns1||'--')+(w.dns2?' / '+w.dns2:'');
      switchWanMode(w.mode||'dhcp');
      if(w.mode==='static'){
        if(w.ip)document.getElementById('wan-static-ip').value=w.ip;
        if(w.netmask)document.getElementById('wan-static-netmask').value=w.netmask;
        if(w.gateway)document.getElementById('wan-static-gateway').value=w.gateway;
        if(w.dns1)document.getElementById('wan-static-dns1').value=w.dns1;
        if(w.dns2)document.getElementById('wan-static-dns2').value=w.dns2;
      }else if(w.mode==='pppoe'&&w.pppoe_user){
        document.getElementById('wan-pppoe-user').value=w.pppoe_user;
      }
    }
  }catch(e){console.log('WAN status load failed',e)}
}

async function saveWanConfig(){
  var btn=document.getElementById('btn-wan-save');
  var alertEl=document.getElementById('wan-alert');
  btn.disabled=true;btn.textContent='保存中...';alertEl.innerHTML='';

  var body={mode:currentWanMode};
  if(currentWanMode==='static'){
    body.ip=document.getElementById('wan-static-ip').value;
    body.netmask=document.getElementById('wan-static-netmask').value;
    body.gateway=document.getElementById('wan-static-gateway').value;
    body.dns1=document.getElementById('wan-static-dns1').value;
    body.dns2=document.getElementById('wan-static-dns2').value;
    if(!body.ip||!body.gateway){alertEl.innerHTML='<div class="alert alert-error">请填写 IP 地址和网关</div>';btn.disabled=false;btn.textContent='保存并应用';return}
  }else if(currentWanMode==='pppoe'){
    body.pppoe_user=document.getElementById('wan-pppoe-user').value;
    body.pppoe_pass=document.getElementById('wan-pppoe-pass').value;
    if(!body.pppoe_user||!body.pppoe_pass){alertEl.innerHTML='<div class="alert alert-error">请填写宽带账号和密码</div>';btn.disabled=false;btn.textContent='保存并应用';return}
  }

  try{
    var token=localStorage.getItem('sunipip_token')||'';
    var headers={'Content-Type':'application/json'};
    if(token)headers['Authorization']='Bearer '+token;
    var r=await fetch('/api/wan-config',{method:'POST',headers:headers,body:JSON.stringify(body)});
    var d=await r.json();
    if(d.success){
      alertEl.innerHTML='<div class="alert alert-success">上网方式已更新，网络正在重新连接...</div>';
      setTimeout(function(){loadWanStatus();alertEl.innerHTML=''},5000);
    }else{
      alertEl.innerHTML='<div class="alert alert-error">'+(d.message||'保存失败')+'</div>';
    }
  }catch(e){
    alertEl.innerHTML='<div class="alert alert-error">请求失败: '+e.message+'</div>';
  }
  btn.disabled=false;btn.textContent='保存并应用';
}

loadWanStatus();
</script>
</body>
</html>
HTMLEOF

# ---- 11. 配置 FreeRadius 基础 ----
FR_DIR="/etc/freeradius/3.0"
if [[ -d "$FR_DIR" ]]; then
    # 允许 AP trunk 网卡作为 RADIUS 客户端
    cat > "$FR_DIR/clients.conf" <<EOF
client localhost {
    ipaddr = 127.0.0.1
    secret = testing123
}
client ap_network {
    ipaddr = 0.0.0.0/0
    secret = sunipip_radius_secret
}
EOF
    # 空的授权文件，Agent 启动后会覆盖
    echo "# Managed by SuniPIP Agent" > "$FR_DIR/mods-config/files/authorize"

    # 启用 inner-tunnel VLAN 属性透传（PEAP 隧道内的 Tunnel-* 属性需复制到外层 Access-Accept）
    INNER_TUNNEL="$FR_DIR/sites-enabled/inner-tunnel"
    if [[ -f "$INNER_TUNNEL" ]]; then
        sed -i 's/if (0) {/if (1) {/' "$INNER_TUNNEL"
    fi

    info "FreeRadius 基础配置完成"
fi

# ---- 11b. 安装 Mihomo (Clash Meta) 透明代理 ----
info "安装 Mihomo (Clash Meta)..."
if grep -q 'avx2' /proc/cpuinfo 2>/dev/null; then
    MIHOMO_FILE="mihomo-amd64.gz"
    info "  CPU 支持 AVX2，使用标准版"
else
    MIHOMO_FILE="mihomo-amd64-compatible.gz"
    info "  CPU 不支持 AVX2，使用兼容版"
fi
MIHOMO_URL="${PLATFORM_URL}/api/v1/router-downloads/${MIHOMO_FILE}"

if curl -fsSL -o /tmp/mihomo.gz "$MIHOMO_URL" 2>/dev/null; then
    gunzip -f /tmp/mihomo.gz
    chmod 755 /tmp/mihomo
    mv /tmp/mihomo /usr/local/bin/mihomo
    info "Mihomo 已安装"
else
    warn "Mihomo 下载失败，Clash 代理将在手动安装后启用"
fi

mkdir -p /etc/clash

cat > /etc/systemd/system/clash.service <<'CLASHEOF'
[Unit]
Description=Mihomo (Clash Meta) Proxy
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/mihomo -d /etc/clash
Restart=on-failure
RestartSec=5
LimitNOFILE=65535
CapabilityBoundingSet=CAP_NET_ADMIN CAP_NET_BIND_SERVICE CAP_NET_RAW
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE CAP_NET_RAW

[Install]
WantedBy=multi-user.target
CLASHEOF
info "Clash 服务已创建"

# ---- 12. 下载并安装 Agent 二进制 ----
info "下载 Agent 二进制..."
AGENT_BIN="/usr/local/bin/sunipip-router-agent"
AGENT_DL_URL="${PLATFORM_URL}/api/v1/router-agent/download"

if curl -fsSL -o "${AGENT_BIN}.tmp" \
    -H "X-Agent-Key: ${AGENT_KEY}" \
    "$AGENT_DL_URL" 2>/dev/null; then
    chmod 755 "${AGENT_BIN}.tmp"
    mv "${AGENT_BIN}.tmp" "$AGENT_BIN"
    info "Agent 二进制已安装: $AGENT_BIN"
else
    warn "Agent 二进制下载失败（可能尚未上传），跳过。后续可通过平台热更新推送。"
    rm -f "${AGENT_BIN}.tmp"
fi

# ---- 13. 创建 systemd 服务 ----
cat > /etc/systemd/system/sunipip-router-agent.service <<EOF
[Unit]
Description=SuniPIP Router Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/sunipip-router-agent --config /etc/sunipip/agent.json
Restart=always
RestartSec=10
WorkingDirectory=/etc/sunipip
StandardOutput=journal
StandardError=journal
SyslogIdentifier=sunipip-agent

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload

# ---- 14. 启动所有服务 ----
info "启动服务..."
systemctl enable --now nftables 2>/dev/null || true
systemctl restart dnsmasq 2>/dev/null || true
systemctl restart nginx 2>/dev/null || true
systemctl enable --now freeradius 2>/dev/null || true
[[ -f /etc/wireguard/wg0.conf ]] && systemctl enable --now wg-quick@wg0 2>/dev/null || true
[[ -f /etc/wireguard/wg1.conf ]] && systemctl enable --now wg-quick@wg1 2>/dev/null || true
[[ -x /usr/local/bin/mihomo ]] && systemctl enable clash 2>/dev/null || true

# 启动 Agent（如果二进制存在）
if [[ -x "$AGENT_BIN" ]]; then
    systemctl enable --now sunipip-router-agent
    info "Agent 服务已启动"
else
    systemctl enable sunipip-router-agent
    info "Agent 服务已注册（等待二进制部署后自动启动）"
fi

# ---- 15. 完成 ----
info "============================================"
info "  SuniPIP 软路由安装完成!"
info "  设备ID:     $REG_DEVICE_ID"
info "  Agent Key:  ${AGENT_KEY:0:16}..."
info ""
info "  网卡映射:"
info "    WAN:  $NIC_WAN (上网)"
info "    MGMT: $NIC_MGMT → 172.10.0.1 (管理)"
[[ -n "$NIC_AP" ]]  && info "    AP:   $NIC_AP (trunk → OpenWrt)"
[[ -n "$NIC_LAN" ]] && info "    LAN:  $NIC_LAN → 100.64.1.1 (有线)"
info ""
info "  管理页面:   http://172.10.0.1"
[[ -n "$NIC_LAN" ]] && info "  有线 LAN:   http://100.64.1.1"
info "============================================"
info ""
info "Agent 支持热更新 — 平台发布新版本后自动下载并重启。"
BASH;

        $script = str_replace('__TOKEN__', $token, $script);
        $script = str_replace('__PLATFORM_URL__', $platformUrl, $script);
        $script = str_replace('__DEVICE_ID__', (string) $device->id, $script);

        return $script;
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'install_token' => 'required|string',
            'serial_number' => 'required|string|max:100',
            'wg_public_key_1' => 'required|string|max:64',
            'wg_public_key_2' => 'required|string|max:64',
            'hostname' => 'nullable|string|max:100',
            'agent_version' => 'nullable|string|max:30',
        ]);

        try {
            $result = $this->provisionService->registerDevice(
                installToken: $data['install_token'],
                serialNumber: $data['serial_number'],
                wgPubKey1: $data['wg_public_key_1'],
                wgPubKey2: $data['wg_public_key_2'],
                hostname: $data['hostname'] ?? null,
                agentVersion: $data['agent_version'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 401);
        }

        return $this->success($result, '设备注册成功');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->validate([
            'applied_config_version' => 'nullable|integer|min:0',
            'system_info' => 'nullable|array',
            'agent_version' => 'nullable|string|max:30',
            'wan_ip' => 'nullable|string|max:45',
            'ap_ip' => 'nullable|string|max:45',
        ]);

        $updates = [
            'last_heartbeat_at' => now(),
            'last_heartbeat_ip' => $request->ip(),
        ];

        if (isset($data['applied_config_version'])) {
            $updates['applied_config_version'] = $data['applied_config_version'];
        }
        if (isset($data['system_info'])) {
            $updates['system_info'] = $data['system_info'];
        }
        $oldAgentVersion = $device->agent_version;
        if (isset($data['agent_version'])) {
            $updates['agent_version'] = $data['agent_version'];
            if ($device->target_agent_version && $data['agent_version'] === $device->target_agent_version) {
                $updates['target_agent_version'] = null;
            }
        }
        if (isset($data['wan_ip'])) {
            $updates['wan_ip'] = $data['wan_ip'];
        }
        if (isset($data['ap_ip']) && $device->ap_management_enabled) {
            $updates['ap_ip'] = $data['ap_ip'];
        }

        if ($device->status === 'provisioned' || $device->status === 'offline') {
            $updates['status'] = 'online';
        }

        $device->update($updates);

        // Auto-migrate to v2 when agent binary version changes and device is still v1
        $newAgentVersion = $data['agent_version'] ?? $oldAgentVersion;
        if ($oldAgentVersion && $newAgentVersion !== $oldAgentVersion && ($device->wifi_version ?? 1) < 2) {
            $this->autoMigrateToV2($device);
        }

        // target_agent_version overrides global version.txt (grayscale channel)
        $globalVersion = $this->getLatestAgentVersion();
        $latestVersion = $device->fresh()->target_agent_version ?: $globalVersion;
        $platformUrl = rtrim(config('app.url'), '/');

        $hasPending = $device->config_version > ($data['applied_config_version'] ?? $device->applied_config_version);

        $pendingCommands = \App\Models\RouterRemoteCommand::where('router_device_id', $device->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn ($cmd) => [
                'id' => $cmd->id,
                'command' => $cmd->command,
                'timeout' => $cmd->timeout,
            ])
            ->values()
            ->all();

        if (!empty($pendingCommands)) {
            \App\Models\RouterRemoteCommand::where('router_device_id', $device->id)
                ->where('status', 'pending')
                ->whereIn('id', array_column($pendingCommands, 'id'))
                ->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return $this->success([
            'device_id' => $device->id,
            'config_version' => $device->config_version,
            'has_pending_config' => $hasPending,
            'server_time' => now()->toIso8601String(),
            'latest_agent_version' => $latestVersion,
            'agent_download_url' => $latestVersion ? "{$platformUrl}/api/v1/router-agent/download" : null,
            'pending_commands' => $pendingCommands,
        ]);
    }

    public function pullConfig(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return $this->error('Unauthorized', 401);
        }

        $snapshot = $device->configSnapshots()
            ->where('config_version', $device->config_version)
            ->first();

        if (!$snapshot) {
            return $this->error('配置快照不存在，请联系管理员推送配置', 404);
        }

        return $this->success([
            'config_version' => $snapshot->config_version,
            'config' => $snapshot->payload,
        ]);
    }

    public function ackConfig(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->validate([
            'config_version' => 'required|integer|min:1',
        ]);

        $device->update([
            'applied_config_version' => $data['config_version'],
        ]);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'config_applied',
            'severity' => 'info',
            'message' => "配置版本 {$data['config_version']} 已应用",
            'metadata' => ['config_version' => $data['config_version']],
            'created_at' => now(),
        ]);

        return $this->success([
            'applied_config_version' => $data['config_version'],
        ]);
    }

    public function reportEvent(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->validate([
            'event_type' => 'required|string|max:50',
            'severity' => 'nullable|string|in:info,warning,error',
            'message' => 'nullable|string|max:1000',
            'metadata' => 'nullable|array',
        ]);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => $data['event_type'],
            'severity' => $data['severity'] ?? 'info',
            'message' => $data['message'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'created_at' => now(),
        ]);

        return $this->success(null, 'ok');
    }

    public function commandResult(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->validate([
            'command_id' => 'required|integer',
            'exit_code' => 'required|integer',
            'output' => 'nullable|string',
        ]);

        $cmd = \App\Models\RouterRemoteCommand::where('id', $data['command_id'])
            ->where('router_device_id', $device->id)
            ->first();

        if (!$cmd) {
            return $this->error('Command not found', 404);
        }

        $cmd->update([
            'status' => $data['exit_code'] === 0 ? 'completed' : 'failed',
            'exit_code' => $data['exit_code'],
            'output' => $data['output'],
            'completed_at' => now(),
        ]);

        return $this->success(null, 'ok');
    }

    public function reportApDiscovery(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return $this->error('Unauthorized', 401);
        }

        $data = $request->validate([
            'ap_ip' => 'required|string|max:45',
            'device_model' => 'nullable|string|max:200',
            'openwrt_version' => 'nullable|string|max:50',
            'radios' => 'required|array|min:1',
            'radios.*.name' => 'required|string|max:20',
            'radios.*.band' => 'required|string|max:20',
            'radios.*.type' => 'nullable|string|max:50',
            'radios.*.channels' => 'nullable|array',
            'radios.*.htmodes' => 'nullable|array',
            'radios.*.txpower_list' => 'nullable|array',
            'radios.*.current' => 'nullable|array',
        ]);

        $device->update([
            'ap_ip' => $data['ap_ip'],
            'ap_discovery' => [
                'scanned_at' => now()->toIso8601String(),
                'device_model' => $data['device_model'] ?? null,
                'openwrt_version' => $data['openwrt_version'] ?? null,
                'radios' => $data['radios'],
            ],
            'ap_discover_requested' => false,
        ]);

        RouterEventLog::create([
            'router_device_id' => $device->id,
            'event_type' => 'ap_discovery',
            'severity' => 'info',
            'message' => sprintf('AP 扫描完成: %s, %d 个射频', $data['device_model'] ?? 'unknown', count($data['radios'])),
            'metadata' => ['ap_ip' => $data['ap_ip'], 'radios' => count($data['radios'])],
            'created_at' => now(),
        ]);

        return $this->success(null, 'AP 扫描结果已保存');
    }

    public function downloadBinary(Request $request)
    {
        $device = $this->authenticate($request);
        if (!$device) {
            return response('Unauthorized', 401);
        }

        $binaryPath = '/www/uploads/sunipip/router-agent/sunipip-router-agent';
        if (!file_exists($binaryPath)) {
            $binaryPath = storage_path('app/router-agent/sunipip-router-agent');
        }
        if (!file_exists($binaryPath)) {
            abort(404, 'Agent binary not available');
        }

        return response()->download($binaryPath, 'sunipip-router-agent', [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    private function autoMigrateToV2(RouterDevice $device): void
    {
        try {
            $wifiService = app(RouterWifiService::class);
            $configService = app(RouterConfigService::class);

            // Allocate IP ranges for existing accounts without IPs
            $accounts = $device->wifiAccounts()->where('ip_start_index', 0)->get();
            foreach ($accounts as $account) {
                $device->refresh();
                $ipInfo = $wifiService->allocateIpRange($device, $account->max_devices);
                $account->update([
                    'ip_start_index' => $ipInfo['ip_start_index'],
                    'vlan_id' => $ipInfo['vlan_id'],
                    'ip_prefix' => $ipInfo['ip_prefix'],
                    'gateway_ip' => $ipInfo['gateway_ip'],
                ]);
            }

            // Push AP v2 config via remote command (ARP discover AP + sshpass)
            $hasApCommand = RouterRemoteCommand::where('router_device_id', $device->id)
                ->whereIn('status', ['pending', 'sent'])
                ->where('command', 'like', '%ap-v2.sh%')
                ->exists();
            if (!$hasApCommand) {
                $apConfig = $device->ap_config ?? [];
                $apUser = $apConfig['ap_username'] ?? 'root';
                $apPass = $apConfig['ap_password'] ?? 'as204921.net';

                $apStaticIp = '10.20.0.120';
                $device->update(['ap_ip' => $apStaticIp]);

                $command = $this->buildApV2Command($apUser, $apPass, $apStaticIp);

                RouterRemoteCommand::create([
                    'router_device_id' => $device->id,
                    'command' => $command,
                    'timeout' => 180,
                    'status' => 'pending',
                ]);
            }

            // Mark as v2 and push config
            $device->update(['wifi_version' => 2]);
            $configService->pushConfig($device);

            RouterEventLog::create([
                'router_device_id' => $device->id,
                'event_type' => 'auto_migrate_v2',
                'severity' => 'info',
                'message' => 'Auto-migrated to WiFi v2 after agent update',
                'metadata' => [
                    'accounts_migrated' => $accounts->count(),
                    'ap_command_queued' => true,
                ],
            ]);
        } catch (\Throwable $e) {
            RouterEventLog::create([
                'router_device_id' => $device->id,
                'event_type' => 'auto_migrate_v2_failed',
                'severity' => 'error',
                'message' => 'Auto-migration to v2 failed: ' . $e->getMessage(),
            ]);
        }
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

    private function buildApV2Command(string $apUser, string $apPass, string $apStaticIp = '10.20.0.120'): string
    {
        $apScript = $this->buildApV2Script($apStaticIp);
        $b64 = base64_encode($apScript);

        return sprintf(
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
}
