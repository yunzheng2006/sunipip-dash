#!/bin/sh
###############################################################################
# SuniPIP AP 一键配置脚本 v2 — Flat IP Model (无动态 VLAN)
#
# v2 变更 (相比 v1):
#   - NSS offload 开启: 修复 ath11k_pci TX queue leak，PCIe 5GHz 可用
#   - 所有 radio 启用: 包括 PCIe QCN9074 外置网卡
#   - dynamic_vlan=0: WiFi 客户端直接加入 br-trunk，无 VLAN 标签
#   - RADIUS 仅做认证 (WPA-Enterprise)，不做 VLAN 分配
#   - 简化 Watchdog: NSS 消除 TX queue leak 后无需频繁重启 hostapd
#   - 自动清理 v1 遗留配置 (NSS 禁用、模块黑名单等)
#
# 将 AP 配置为 WPA-Enterprise (无 VLAN) 模式，连接软路由 trunk 端口。
# AP WAN 口接软路由 trunk 端口，LAN 口保留管理 IP。
#
# 前置要求:
#   - ImmortalWrt / OpenWrt 23.05+
#   - wpad-openssl 或 wpad-wolfssl (不能用 wpad-basic)
#   - AP WAN 口已接到软路由 trunk 端口（能获取 DHCP）
#
# 用法:
#   scp ap-config-v2.sh root@<AP_IP>:/tmp/
#   ssh root@<AP_IP> "sh /tmp/ap-config-v2.sh"
#
# 参数 (均可选):
#   ROUTER_IP=10.20.0.1    RADIUS 服务器 IP (默认: 自动检测网关)
#   RADIUS_SECRET=xxx      RADIUS 共享密钥 (默认: sunipip_radius_secret)
#   SSID="My WiFi"         WiFi 名称 (默认: SunIPIP.com Streaming LAN)
#   LAN_IP=10.88.0.1       AP 管理 IP (默认: 保持不变)
#
# 示例:
#   ROUTER_IP=10.20.0.1 SSID="Office WiFi" sh /tmp/ap-config-v2.sh
###############################################################################

set -e

# ── 默认值 ──
ROUTER_IP="${ROUTER_IP:-}"
RADIUS_SECRET="${RADIUS_SECRET:-sunipip_radius_secret}"
RADIUS_PORT="${RADIUS_PORT:-1812}"
SSID="${SSID:-SunIPIP.com Streaming LAN}"
LAN_IP="${LAN_IP:-}"
NSS_CHANGED=0

log()  { echo "[INFO]  $*"; }
ok()   { echo "[  OK]  $*"; }
warn() { echo "[WARN]  $*"; }
die()  { echo "[FAIL]  $*"; exit 1; }

echo ""
echo "============================================================"
echo "  SuniPIP AP 配置脚本 v2 (Flat IP / NSS offload)"
echo "============================================================"
echo ""

# ── 前置检查 ──
log "前置检查..."

[ -f /etc/openwrt_release ] || die "需要在 OpenWrt / ImmortalWrt 上运行"

if ! opkg list-installed 2>/dev/null | grep -qE "wpad-(openssl|wolfssl|mbedtls)"; then
    warn "未检测到完整版 wpad，WPA-Enterprise 可能无法工作"
    warn "请执行: opkg update && opkg remove wpad-basic* && opkg install wpad-openssl"
fi
ok "系统检查通过"

# ── 清理 v1 遗留配置 ──
log "清理 v1 遗留配置..."

# 移除 v1 的 NSS VLAN 模块黑名单
if [ -f /etc/modprobe.d/sunipip-no-nss-vlan.conf ]; then
    rm -f /etc/modprobe.d/sunipip-no-nss-vlan.conf
    ok "已移除 v1 NSS VLAN 模块黑名单"
fi

# 移除 v1 的 ath11k_pci 黑名单 (v2 需要 PCIe radio)
if [ -f /etc/modprobe.d/blacklist-ath11k-pci.conf ]; then
    rm -f /etc/modprobe.d/blacklist-ath11k-pci.conf
    ok "已移除 v1 ath11k_pci 黑名单"
fi

# 清理空的 modprobe.d 目录
rmdir /etc/modprobe.d 2>/dev/null || true

ok "v1 遗留配置清理完成"

# ── 启用 NSS offload ──
# NSS offload 修复 ath11k_pci TX completion 问题:
# - nss_offload=1: 启用 NSS 硬件加速
# - frame_mode=2: NSS Ethernet 模式 (NSS 需要此模式处理帧)
# - 恢复 qca-nss-drv-vlan-mgr / bridge-mgr 模块
if [ -f /etc/modules.d/ath11k ]; then
    # 确保 nss_offload=1
    if grep -q "nss_offload=0" /etc/modules.d/ath11k; then
        sed -i 's/nss_offload=0/nss_offload=1/' /etc/modules.d/ath11k
        ok "已启用 ath11k NSS offload (nss_offload=1)"
        NSS_CHANGED=1
    elif grep -q "nss_offload=1" /etc/modules.d/ath11k; then
        ok "ath11k NSS offload 已启用"
    else
        # nss_offload 参数不存在，追加到 ath11k 模块行
        sed -i 's/^ath11k/& nss_offload=1/' /etc/modules.d/ath11k
        ok "已添加 ath11k NSS offload 参数"
        NSS_CHANGED=1
    fi

    # 确保 frame_mode=2 (NSS Ethernet 模式)
    if grep -q "frame_mode=0" /etc/modules.d/ath11k; then
        sed -i 's/frame_mode=0/frame_mode=2/' /etc/modules.d/ath11k
        ok "已切换 frame_mode 为 NSS Ethernet 模式 (frame_mode=2)"
        NSS_CHANGED=1
    elif grep -q "frame_mode=2" /etc/modules.d/ath11k; then
        ok "frame_mode 已为 NSS Ethernet 模式"
    else
        # frame_mode 参数不存在，追加
        sed -i 's/^ath11k/& frame_mode=2/' /etc/modules.d/ath11k
        ok "已添加 frame_mode=2 参数"
        NSS_CHANGED=1
    fi

    # 恢复 qca-nss-drv-vlan-mgr 和 qca-nss-drv-bridge-mgr 模块
    for mod_file in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
        if [ -f "/etc/modules.d/${mod_file}" ]; then
            mod_content=$(cat "/etc/modules.d/${mod_file}")
            if echo "$mod_content" | grep -q "^#.*disabled"; then
                # v1 禁用了该模块，恢复原始内容
                mod_name=$(echo "$mod_file" | sed 's/^51-//')
                echo "$mod_name" > "/etc/modules.d/${mod_file}"
                ok "已恢复 ${mod_file} 模块"
                NSS_CHANGED=1
            else
                ok "${mod_file} 模块已启用"
            fi
        fi
    done
fi

# 自动检测 RADIUS 服务器 IP (默认网关)
if [ -z "$ROUTER_IP" ]; then
    ROUTER_IP=$(ip route 2>/dev/null | awk '/default/{print $3}' | head -1)
    [ -z "$ROUTER_IP" ] && ROUTER_IP="10.20.0.1"
    log "自动检测 RADIUS 服务器: ${ROUTER_IP}"
fi

echo ""
log "配置参数:"
log "  RADIUS 服务器: ${ROUTER_IP}:${RADIUS_PORT}"
log "  RADIUS 密钥:   ${RADIUS_SECRET}"
log "  WiFi SSID:     ${SSID}"
log "  模式:          Flat IP (无动态 VLAN)"
log "  NSS offload:   启用"
[ -n "$LAN_IP" ] && log "  管理 IP:       ${LAN_IP}"
echo ""

# ── 备份 ──
log "备份当前配置..."
TS=$(date +%Y%m%d%H%M%S)
cp /etc/config/wireless "/etc/config/wireless.bak.${TS}" 2>/dev/null || true
cp /etc/config/network "/etc/config/network.bak.${TS}" 2>/dev/null || true
cp /etc/config/firewall "/etc/config/firewall.bak.${TS}" 2>/dev/null || true
ok "备份完成: /etc/config/*.bak.${TS}"

# ── 检测无线网卡 ──
log "检测无线网卡..."
RADIOS=""
for r in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.${r}" >/dev/null 2>&1 && RADIOS="${RADIOS} ${r}"
done
[ -z "$RADIOS" ] && die "未检测到无线网卡"
ok "检测到:${RADIOS}"

# ── 网络配置 ──
log "配置网络..."

# 删除 IPv6 WAN
uci delete network.wan6 2>/dev/null || true
uci delete network.globals.ula_prefix 2>/dev/null || true

# 检查是否已有 br-trunk，没有则创建
TRUNK_EXISTS=0
IDX=0
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
    ok "创建 br-trunk 桥接设备"
else
    ok "br-trunk 已存在"
fi

# WAN 使用 br-trunk，DHCP 获取管理 IP
uci set network.wan.device='br-trunk'
uci set network.wan.proto='dhcp'

# LAN 管理口保持不变
if [ -n "$LAN_IP" ]; then
    uci set network.lan.ipaddr="$LAN_IP"
    ok "管理口 IP 设置为 ${LAN_IP}"
fi

ok "网络配置完成"

# ── 无线配置 ──
log "配置 WPA-Enterprise (Flat IP, 无动态 VLAN)..."

for radio in $RADIOS; do
    IFACE="default_${radio}"

    # 确保 wifi-iface 存在
    if ! uci -q get "wireless.${IFACE}" >/dev/null 2>&1; then
        uci set "wireless.${IFACE}=wifi-iface"
        uci set "wireless.${IFACE}.device=${radio}"
        uci set "wireless.${IFACE}.mode=ap"
    fi

    # 启用网卡 (v2: 所有 radio 都启用，包括 PCIe)
    uci set "wireless.${radio}.disabled=0"
    uci set "wireless.${radio}.country=HK"

    # 检测是否为 PCIe 设备 (QCN9074 等外置网卡)
    RADIO_PATH=$(uci -q get "wireless.${radio}.path" 2>/dev/null || echo "")
    IS_PCIE=0
    echo "$RADIO_PATH" | grep -q "pci" && IS_PCIE=1

    # 根据频段设置带宽
    BAND=$(uci -q get "wireless.${radio}.band" 2>/dev/null || echo "")
    case "$BAND" in
        2g)
            uci set "wireless.${radio}.channel=6"
            uci set "wireless.${radio}.htmode=HE40"
            uci set "wireless.${radio}.noscan=1"
            ok "${radio}: 2.4GHz / HE40 / CH6"
            ;;
        5g)
            if [ "$IS_PCIE" = "1" ]; then
                # PCIe 5GHz (QCN9074): NSS offload 修复 TX completion
                # UNII-1+3 全频段可用，HE160 最大带宽
                uci set "wireless.${radio}.channel=36"
                uci set "wireless.${radio}.htmode=HE160"
                ok "${radio}: 5GHz PCIe / HE160 / CH36 (NSS offload)"
            else
                # SoC 5GHz (ath11k_ahb): IPQ6018 仅支持 UNII-3 (ch149-165), HE80 max
                uci set "wireless.${radio}.channel=149"
                uci set "wireless.${radio}.htmode=HE80"
                ok "${radio}: 5GHz SoC / HE80 / CH149"
            fi
            ;;
        6g)
            uci set "wireless.${radio}.channel=1"
            uci set "wireless.${radio}.htmode=HE160"
            ok "${radio}: 6GHz / HE160"
            ;;
        *)
            uci set "wireless.${radio}.htmode=HE20"
            warn "${radio}: 未识别频段，使用 HE20"
            ;;
    esac

    # 同一 SSID (2.4G + 5G 漫游)
    uci set "wireless.${IFACE}.ssid=${SSID}"

    # WPA2-Enterprise
    uci set "wireless.${IFACE}.encryption=wpa2+ccmp"
    uci set "wireless.${IFACE}.network=wan"
    uci set "wireless.${IFACE}.auth_server=${ROUTER_IP}"
    uci set "wireless.${IFACE}.auth_port=${RADIUS_PORT}"
    uci set "wireless.${IFACE}.auth_secret=${RADIUS_SECRET}"

    # Flat IP: 无动态 VLAN，客户端直接加入 br-trunk
    uci set "wireless.${IFACE}.dynamic_vlan=0"

    # 清理 v1 遗留的 VLAN 配置
    uci delete "wireless.${IFACE}.vlan_tagged_interface" 2>/dev/null || true
    uci delete "wireless.${IFACE}.vlan_naming" 2>/dev/null || true

    # 802.11w 保护管理帧 (可选但推荐)
    uci set "wireless.${IFACE}.ieee80211w=1"

    # ath11k PTK rekey bug workaround:
    # 1) wpa_group_rekey=0: 禁用组密钥轮换，消除 GTK 触发的 rekey
    # 2) eap_reauth_period=0: 禁用定时 EAP 重认证 (hostapd 默认 3600s，
    #    是每小时强制 PTK 替换/断线的根源)
    # 3) wpa_deny_ptk0_rekey=2: rekey 仍被触发时主动断开客户端让其干净重连
    # 4) disable_pmksa_caching=0 + okc=1: 启用 PMKSA/OKC 缓存，
    #    客户端重连/漫游时使用缓存密钥，避免触发 PTK 替换
    # 5) disassoc_low_ack=0: 不因丢 ACK 踢弱信号客户端
    uci set "wireless.${IFACE}.wpa_group_rekey=0"
    uci set "wireless.${IFACE}.disassoc_low_ack=0"
    uci add_list "wireless.${IFACE}.hostapd_bss_options=eap_reauth_period=0"
    uci add_list "wireless.${IFACE}.hostapd_bss_options=wpa_deny_ptk0_rekey=2"
    uci add_list "wireless.${IFACE}.hostapd_bss_options=disable_pmksa_caching=0"
    uci add_list "wireless.${IFACE}.hostapd_bss_options=okc=1"

    ok "${radio}: WPA2-Enterprise (Flat IP) 已配置"
done

# ── 防火墙 ──
log "配置防火墙 (全 ACCEPT，不过滤流量)..."

# 所有 zone 都设为 ACCEPT，不做任何过滤
ZONE_IDX=0
while uci -q get "firewall.@zone[${ZONE_IDX}]" >/dev/null 2>&1; do
    ZNAME=$(uci -q get "firewall.@zone[${ZONE_IDX}].name")
    uci set "firewall.@zone[${ZONE_IDX}].input=ACCEPT"
    uci set "firewall.@zone[${ZONE_IDX}].output=ACCEPT"
    uci set "firewall.@zone[${ZONE_IDX}].forward=ACCEPT"
    uci set "firewall.@zone[${ZONE_IDX}].masq=0"
    ok "${ZNAME} zone: 全部 ACCEPT"
    ZONE_IDX=$((ZONE_IDX + 1))
done

# 设置默认策略为 ACCEPT
uci set firewall.@defaults[0].input='ACCEPT'
uci set firewall.@defaults[0].output='ACCEPT'
uci set firewall.@defaults[0].forward='ACCEPT'
ok "防火墙默认策略: 全部 ACCEPT"

# ── 提交并应用 ──
log "提交配置..."
uci commit network
uci commit wireless
uci commit firewall
ok "UCI 配置已保存"

log "重启网络..."
/etc/init.d/network restart
sleep 3

log "重启无线..."
wifi down
sleep 2
wifi up
sleep 5

ok "服务重启完成"

# ── 验证 ──
echo ""
echo "============================================================"
echo "  配置完成! (v2 Flat IP / NSS offload)"
echo "============================================================"
echo ""
echo "  WiFi SSID:       ${SSID}"
echo "  加密方式:        WPA2-Enterprise (802.1X)"
echo "  RADIUS 服务器:   ${ROUTER_IP}:${RADIUS_PORT}"
echo "  动态 VLAN:       已禁用 (Flat IP 模式)"
echo "  NSS offload:     已启用"
echo "  管理口 IP:       $(uci -q get network.lan.ipaddr || echo '未变更')"
echo "  WAN IP:          $(ip addr show br-trunk 2>/dev/null | awk '/inet /{print $2}' | head -1 || echo '等待 DHCP')"
echo ""

# 显示 WiFi 状态
for radio in $RADIOS; do
    IFACE="default_${radio}"
    DEV=$(uci -q get "wireless.${IFACE}.ifname" 2>/dev/null || echo "phy${radio#radio}-ap0")
    echo "  ${radio}:"
    iwinfo "$DEV" info 2>/dev/null | grep -E "ESSID|Channel|HT Mode|Encryption" | sed 's/^/    /'
    echo ""
done

echo "  验证命令:"
echo "    logread -f | grep -E 'hostapd|radius'   # 查看认证日志"
echo "    brctl show                                # 查看桥接状态"
echo "    iwinfo                                    # 查看 WiFi 状态"
echo "    cat /sys/kernel/debug/ath11k/*/stats/fw_stats/pdev_stats  # NSS 统计"
echo ""
echo "  如果认证失败，检查:"
echo "    1. 软路由 FreeRADIUS 是否运行"
echo "    2. RADIUS 密钥是否匹配"
echo "    3. WiFi 账号是否已在平台创建"
echo ""

# ── Watchdog 安装 (简化版) ──
log "安装简化版 Watchdog..."

# 移除旧版 watchdog (如果存在)
rm -f /usr/bin/radius_watchdog.sh 2>/dev/null
crontab -l 2>/dev/null | grep -v "radius_watchdog" | crontab - 2>/dev/null || true

# 安装 v2 watchdog: 仅检查 hostapd 运行和 wan IP
cat > /usr/bin/ap_watchdog.sh << 'WATCHDOG_EOF'
#!/bin/sh
# SuniPIP AP Watchdog v2 (Flat IP / NSS offload)
# Simplified: NSS eliminates TX queue leak, only basic health checks needed.
# Runs via cron every 2 minutes.

LOG="/tmp/ap_watchdog.log"

NEED_FIX=0
REASON=""

# Check 1: hostapd not running
if ! pidof hostapd >/dev/null 2>&1; then
    NEED_FIX=1
    REASON="hostapd not running"
fi

# Check 2: wan interface has no IP (DHCP not ready)
if [ "$NEED_FIX" = "0" ]; then
    WAN_IP=$(ip addr show br-trunk 2>/dev/null | awk '/inet /{print $2}' | head -1)
    if [ -z "$WAN_IP" ]; then
        NEED_FIX=1
        REASON="wan has no IP"
    fi
fi

if [ "$NEED_FIX" = "1" ]; then
    echo "$(date) [watchdog] FIXING: $REASON" >> $LOG

    # If no WAN IP, restart network first
    if [ "$REASON" = "wan has no IP" ]; then
        /etc/init.d/network restart 2>&1 >> $LOG
        sleep 5
    fi

    # Restart wifi
    wifi up 2>&1 >> $LOG
    echo "$(date) [watchdog] wifi restarted" >> $LOG
else
    MINUTE=$(date +%M)
    if [ "$((MINUTE % 10))" = "0" ]; then
        echo "$(date) [watchdog] OK" >> $LOG
    fi
fi

# Keep log small
if [ -f "$LOG" ] && [ "$(wc -l < "$LOG" 2>/dev/null)" -gt 200 ]; then
    tail -100 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
fi
WATCHDOG_EOF
chmod +x /usr/bin/ap_watchdog.sh
ok "Watchdog v2 脚本已安装"

# Add cron job (every 2 minutes — less aggressive than v1)
CRON_LINE="*/2 * * * * /usr/bin/ap_watchdog.sh"
(crontab -l 2>/dev/null | grep -v "ap_watchdog" ; echo "$CRON_LINE") | crontab -
ok "Watchdog cron 已配置 (每 2 分钟)"

# Add rc.local delayed start for boot timing fix
RCLOCAL="/etc/rc.local"
MARKER="# sunipip-watchdog"

# Clean up old v1 rc.local entries
if [ -f "$RCLOCAL" ]; then
    # Remove v1 watchdog lines
    sed -i '/# sunipip-watchdog.*delayed hostapd/d' "$RCLOCAL"
    sed -i '/radius_watchdog\.sh/d' "$RCLOCAL"
fi

if [ -f "$RCLOCAL" ] && ! grep -q "ap_watchdog" "$RCLOCAL" 2>/dev/null; then
    sed -i '/^exit 0$/d' "$RCLOCAL" 2>/dev/null
    cat >> "$RCLOCAL" << 'RCEOF'
# sunipip-watchdog-v2: delayed wifi check after boot
(sleep 45 && /usr/bin/ap_watchdog.sh) &
RCEOF
    echo "exit 0" >> "$RCLOCAL"
    chmod +x "$RCLOCAL"
    ok "rc.local 启动延迟修复已配置"
fi

# ── 远程执行服务 ──
log "安装远程执行服务 (端口 9191)..."

AP_TOKEN="${AP_TOKEN:-sunipip_ap_exec_token}"

# Handler script: reads token + command via TCP, returns output
cat > /usr/bin/sunipip_exec.sh << EXECEOF
#!/bin/sh
read -r TOKEN
read -r CMD
if [ "\$TOKEN" != "${AP_TOKEN}" ]; then
    echo "ERR: auth failed"
    exit 1
fi
if [ -z "\$CMD" ]; then
    echo "ERR: no command"
    exit 1
fi
eval "\$CMD" 2>&1
EXECEOF
chmod +x /usr/bin/sunipip_exec.sh

# Start socat listener
killall socat 2>/dev/null
sleep 1
socat TCP-LISTEN:9191,reuseaddr,fork EXEC:/usr/bin/sunipip_exec.sh &

# Add to rc.local for boot persistence
RCLOCAL="/etc/rc.local"
if ! grep -q "sunipip-exec" "$RCLOCAL" 2>/dev/null; then
    sed -i '/^exit 0$/d' "$RCLOCAL" 2>/dev/null
    echo "# sunipip-exec: remote command service" >> "$RCLOCAL"
    echo "socat TCP-LISTEN:9191,reuseaddr,fork EXEC:/usr/bin/sunipip_exec.sh &" >> "$RCLOCAL"
    echo "exit 0" >> "$RCLOCAL"
fi
ok "远程执行服务已启动 (socat :9191, token: ${AP_TOKEN})"

if [ "${NSS_CHANGED:-0}" = "1" ]; then
    echo ""
    echo "  !!  NSS 模块配置已更改，需要重启才能完全生效:"
    echo "      reboot"
    echo ""
fi
