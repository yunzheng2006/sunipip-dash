import{_ as gl,o as kl,m as xl,D as re,c as v,a as s,t as u,k as i,e as l,w as t,n as c,s as b,q as $l,r as m,B as Sl,g as _,i as d,F as V,C as X,p as Al,I as h,E as S,A as Il,x as pe,G as le}from"./index-CRx3aTat.js";import{d as Pl,r as Vl,e as Cl,t as Dl,f as Xe,h as El,i as Nl,j as Ul,k as zl,l as Wl,m as Fl,n as Rl,o as Tl,p as Hl,q as Ol,s as Ll}from"./routerDevices-BK2yNphP.js";import{getCustomers as ql}from"./customers-DSwKVG8j.js";import{g as Xl}from"./routerCatalog-B-3mA-oN.js";const hl={class:"page-container"},Gl={key:0,class:"page-header"},Ml={class:"text-muted"},Bl={class:"header-actions"},Zl={style:{"margin-bottom":"12px"}},jl={style:{"font-family":"monospace","font-size":"12px"}},Kl={key:1},Yl={key:0},Jl={key:1},Ql={key:1,class:"text-muted"},et={key:0,style:{"margin-top":"12px",display:"flex","justify-content":"flex-end"}},lt={style:{display:"flex","justify-content":"space-between","align-items":"center"}},tt={key:0},at={style:{background:"#f0f2f5",padding:"1px 6px","border-radius":"3px","user-select":"all"}},it={class:"ap-script-block"},nt={key:0},ot={key:0,style:{height:"120px"}},st={key:1,style:{"text-align":"center",padding:"24px 0",color:"#94a3b8","font-size":"13px"}},ut={key:2,class:"node-list"},dt=["onClick"],rt={class:"node-main"},pt={class:"node-name"},ft={class:"node-detail"},mt={key:0,style:{color:"#64748b"}},vt={key:1},_t={style:{"font-size":"12px",color:"#94a3b8","margin-top":"2px"}},ct={key:2,style:{"text-align":"center"}},bt={style:{display:"flex","justify-content":"space-between","align-items":"center"}},yt={style:{color:"#94a3b8","font-size":"12px"}},wt={class:"install-section"},gt={class:"install-section"},kt={class:"install-guide"},fe=15,xt={__name:"RouterDeviceDetail",setup($t){console.log("OEM Contact edward.sun@as204921.net");const k=$l().params.id,o=m(null),me=m(!1),x=m(!1),ve=m("overview"),F=m([]),_e=m(!1),ce=m(1),he=pe(()=>{const n=(ce.value-1)*fe;return F.value.slice(n,n+fe)}),te=m([]),H=m(!1),G=m(!1),E=m(0),M=m(null),A=le({username:"",password:"",label:"",proxy_mode:"proxy",proxy_subscription_id:null,max_devices:5}),B=m(!1),De=m(null),Ee=m([]),$=le({username:"",password:"",label:"",proxy_mode:"proxy",proxy_subscription_id:null,max_devices:5,is_active:1}),be=m([]),ye=m(!1),z=le({total:0,per_page:20,current_page:1}),Z=m(!1),O=le({customer_id:null,module:"video"}),Ne=m([]),we=m(!1),j=m(!1),w=le({serial_number:"",remark:"",router_model_id:null,ap_model_id:null,bundle_id:null,target_agent_version:"",wifi_max_devices_per_account:5}),ae=m({}),ge=m(!1),L=m(""),Ue=pe(()=>L.value?`curl -fsSL '${L.value}' | bash`:""),ke=m(!1),U=m(null),ie=m(!1),K=m(!1),ne=m("clash"),xe=pe(()=>`#!/bin/sh
# ============================================================
#  SuniPIP AP v2 配置脚本 — ImmortalWrt / OpenWrt
#  Flat IP + NSS 硬件加速 + WPA-Enterprise
# ============================================================
set -e

SSID="SunIPIP.com Streaming LAN"
RADIUS_SECRET="sunipip_radius_secret"
RADIUS_PORT="1812"
NSS_CHANGED=0

log()  { echo "[INFO]  $*"; }
ok()   { echo "[  OK]  $*"; }
warn() { echo "[WARN]  $*"; }

echo ""
echo "============================================================"
echo "  SuniPIP AP v2 配置脚本 (Flat IP + NSS)"
echo "============================================================"
echo ""

# ---- 1. 启用 NSS offload (硬件加速) ----
if [ -f /etc/modules.d/ath11k ]; then
    echo 'ath11k nss_offload=1 frame_mode=2' > /etc/modules.d/ath11k
    ok "ath11k nss_offload=1 frame_mode=2"
    NSS_CHANGED=1

    for f in 51-qca-nss-drv-vlan-mgr 51-qca-nss-drv-bridge-mgr; do
        if [ -f "/etc/modules.d/$f" ]; then
            if grep -q "disabled" "/etc/modules.d/$f" 2>/dev/null; then
                MOD=$(echo "$f" | sed 's/^[0-9]*-//' | tr '-' '_')
                echo "$MOD" > "/etc/modules.d/$f"
                ok "Re-enabled $f"
            fi
        fi
    done

    rm -f /etc/modprobe.d/sunipip-no-nss-vlan.conf
    rm -f /etc/modprobe.d/blacklist-ath11k-pci.conf
    ok "已移除 v1 NSS 黑名单"
fi

# ---- 2. 自动检测 RADIUS 服务器 (默认网关) ----
ROUTER_IP=$(ip route 2>/dev/null | awk '/default/{print $3}' | head -1)
[ -z "$ROUTER_IP" ] && ROUTER_IP="10.20.0.1"
log "RADIUS 服务器: \${ROUTER_IP}:\${RADIUS_PORT}"

# ---- 3. 网络配置 (br-trunk + WAN) ----
uci delete network.wan6 2>/dev/null || true
uci delete network.globals.ula_prefix 2>/dev/null || true

# 从 br-lan 移除 wan 端口（出厂可能在 br-lan 里）
IDX=0
while uci -q get "network.@device[\${IDX}]" >/dev/null 2>&1; do
    NAME=$(uci -q get "network.@device[\${IDX}].name")
    if [ "$NAME" = "br-lan" ]; then
        uci del_list "network.@device[\${IDX}].ports=wan" 2>/dev/null
        ok "从 br-lan 移除 wan 端口"
        break
    fi
    IDX=$((IDX + 1))
done

# 创建 br-trunk 桥接
TRUNK_EXISTS=0; IDX=0
while uci -q get "network.@device[\${IDX}]" >/dev/null 2>&1; do
    NAME=$(uci -q get "network.@device[\${IDX}].name")
    [ "$NAME" = "br-trunk" ] && TRUNK_EXISTS=1 && break
    IDX=$((IDX + 1))
done
if [ "$TRUNK_EXISTS" = "0" ]; then
    uci add network device
    uci set "network.@device[-1].name=br-trunk"
    uci set "network.@device[-1].type=bridge"
    uci add_list "network.@device[-1].ports=wan"
    ok "创建 br-trunk (wan)"
fi
uci set network.wan.device='br-trunk'
uci set network.wan.proto='static'
uci set network.wan.ipaddr='10.20.0.120'
uci set network.wan.netmask='255.255.255.0'
uci set network.wan.gateway='10.20.0.1'
uci set network.wan.dns='223.5.5.5 119.29.29.29'

# 关闭 AP 自身 DHCP
uci set dhcp.lan.ignore='1'
ok "网络配置完成 (静态 IP 10.20.0.120, DHCP 已关闭)"

# ---- 4. 无线配置 (v2: dynamic_vlan=0) ----
log "配置 WPA-Enterprise (Flat IP, 无 VLAN)..."

# 先删除所有出厂 wifi-iface（可能叫 wifinet0 等，绑 br-lan）
while uci -q get "wireless.@wifi-iface[0]" >/dev/null 2>&1; do
    uci delete "wireless.@wifi-iface[0]"
done
ok "清除所有出厂 wifi-iface"

# 为每个 radio 创建全新的 wifi-iface，绑定 wan (br-trunk)
for radio in radio0 radio1 radio2 radio3; do
    uci -q get "wireless.\${radio}" >/dev/null 2>&1 || continue
    IFACE="default_\${radio}"

    uci set "wireless.\${IFACE}=wifi-iface"
    uci set "wireless.\${IFACE}.device=\${radio}"
    uci set "wireless.\${IFACE}.mode=ap"

    uci set "wireless.\${radio}.disabled=0"
    uci set "wireless.\${radio}.country=HK"

    RADIO_PATH=$(uci -q get "wireless.\${radio}.path" 2>/dev/null || echo "")
    IS_PCIE=0
    echo "$RADIO_PATH" | grep -q "pci" && IS_PCIE=1

    BAND=$(uci -q get "wireless.\${radio}.band" 2>/dev/null || echo "")
    case "$BAND" in
        2g)
            uci set "wireless.\${radio}.channel=6"
            uci set "wireless.\${radio}.htmode=HE40"
            uci set "wireless.\${radio}.noscan=1"
            ok "\${radio}: 2.4GHz / HE40 / CH6"
            ;;
        5g)
            if [ "$IS_PCIE" = "1" ]; then
                uci set "wireless.\${radio}.channel=36"
                uci set "wireless.\${radio}.htmode=HE160"
                ok "\${radio}: PCIe 5GHz / HE160 / CH36 (NSS enabled)"
            else
                uci set "wireless.\${radio}.channel=149"
                uci set "wireless.\${radio}.htmode=HE80"
                ok "\${radio}: SoC 5GHz / HE80 / CH149"
            fi
            ;;
        6g)
            uci set "wireless.\${radio}.channel=1"
            uci set "wireless.\${radio}.htmode=HE160"
            ok "\${radio}: 6GHz / HE160"
            ;;
        *)
            uci set "wireless.\${radio}.htmode=HE20"
            warn "\${radio}: 未识别频段"
            ;;
    esac

    uci set "wireless.\${IFACE}.ssid=\${SSID}"
    uci set "wireless.\${IFACE}.encryption=wpa2+ccmp"
    uci set "wireless.\${IFACE}.network=wan"
    uci set "wireless.\${IFACE}.auth_server=\${ROUTER_IP}"
    uci set "wireless.\${IFACE}.auth_port=\${RADIUS_PORT}"
    uci set "wireless.\${IFACE}.auth_secret=\${RADIUS_SECRET}"
    uci set "wireless.\${IFACE}.dynamic_vlan=0"
    uci set "wireless.\${IFACE}.ieee80211w=1"
    ok "\${radio}: WPA2-Enterprise → wan (br-trunk)"
done

# ---- 5. 防火墙 ----
ZONE_IDX=0
while uci -q get "firewall.@zone[\${ZONE_IDX}]" >/dev/null 2>&1; do
    ZNAME=$(uci -q get "firewall.@zone[\${ZONE_IDX}].name")
    if [ "$ZNAME" = "wan" ]; then
        uci set "firewall.@zone[\${ZONE_IDX}].masq=0"
        uci set "firewall.@zone[\${ZONE_IDX}].input=ACCEPT"
        uci set "firewall.@zone[\${ZONE_IDX}].forward=ACCEPT"
        ok "WAN zone: 禁用 NAT，开放转发"
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
    ok "WAN SSH 已开放"
fi

# ---- 6. 提交并重启 ----
uci commit network
uci commit wireless
uci commit dhcp
uci commit firewall
/etc/init.d/network restart
sleep 3
wifi down; sleep 2; wifi up; sleep 5
/etc/init.d/firewall restart
ok "配置已应用"

echo ""
echo "============================================================"
echo "  v2 配置完成!"
echo "  WiFi SSID:     \${SSID}"
echo "  RADIUS:        \${ROUTER_IP}:\${RADIUS_PORT}"
echo "  dynamic_vlan:  0 (Flat IP)"
echo "  NSS offload:   1 (硬件加速)"
echo "============================================================"

if [ "\${NSS_CHANGED}" = "1" ]; then
    echo ""
    echo "  ⚠  NSS 模块配置已更改，需要重启: reboot"
fi`);function Ge(){navigator.clipboard.writeText(xe.value).then(()=>{S.success("脚本已复制")})}function Me(){var r;const e="ssh root@"+(((r=o.value)==null?void 0:r.ap_ip)||"<AP_IP>")+` 'sh -s' << 'SETUP_EOF'
`+xe.value+`
SETUP_EOF`;navigator.clipboard.writeText(e).then(()=>{S.success("SSH 命令已复制，粘贴到终端即可执行")})}kl(()=>{N(),Be()});async function Be(){try{ae.value=await Xl()||{}}catch{}}xl(ve,n=>{n==="wifi"&&F.value.length===0&&oe(),n==="events"&&be.value.length===0&&ze()});async function N(){me.value=!0;try{o.value=await Pl(k)}catch{}finally{me.value=!1}}async function oe(){_e.value=!0;try{F.value=await Tl(k)||[]}catch{}finally{_e.value=!1}}async function ze(){ye.value=!0;try{const n=await Nl(k,{per_page:z.per_page,page:z.current_page});be.value=(n==null?void 0:n.items)||[],Object.assign(z,(n==null?void 0:n.pagination)||{})}catch{}finally{ye.value=!1}}async function We(n){we.value=!0;try{const e={per_page:30};n&&(e["filter[keyword]"]=n);const r=await ql(e);Ne.value=(r==null?void 0:r.items)||[]}catch{}finally{we.value=!1}}async function Ze(){if(!O.customer_id)return S.warning("请选择客户");x.value=!0;try{await Ul(k,O),S.success("已绑定"),Z.value=!1,N()}catch{}finally{x.value=!1}}async function je(){await h.confirm("解绑将删除所有 WiFi 账号并重新生成配置，确认？","解绑客户",{type:"warning"});try{await Ol(k),S.success("已解绑"),N(),F.value=[]}catch{}}async function Ke(){try{await Ll(k),S.success("配置已推送"),N()}catch{}}async function Ye(){var n;try{const e=!["inventory","provisioned"].includes((n=o.value)==null?void 0:n.status);e&&await h.confirm("重装将重置设备的 Agent 密钥，旧系统上的 Agent 将无法连接。确认继续？","重装确认",{type:"warning"});const r=await Hl(k);L.value=(r==null?void 0:r.install_url)||"",ge.value=!0,e&&N()}catch{}}async function Je(){await h.confirm("停用后设备将无法连接，确认停用？","停用设备",{type:"warning"});try{await Cl(k),S.success("设备已停用"),N()}catch{}}function Qe(){o.value&&Object.assign(w,{serial_number:o.value.serial_number||"",remark:o.value.remark||"",router_model_id:o.value.router_model_id||null,ap_model_id:o.value.ap_model_id||null,bundle_id:o.value.bundle_id||null,target_agent_version:o.value.target_agent_version||"",wifi_max_devices_per_account:o.value.wifi_max_devices_per_account||5})}async function el(){x.value=!0;try{const n={...w,target_agent_version:w.target_agent_version||null};await zl(k,n),S.success("已更新"),j.value=!1,N()}catch{}finally{x.value=!1}}const ll=pe(()=>{var e,r,p;const n=te.value.find(I=>I.id===A.proxy_subscription_id);return n?`${((e=n.forward_plan)==null?void 0:e.name)||"代理节点"} (${((r=n.proxy_ip)==null?void 0:r.country_name)||""} ${((p=n.proxy_ip)==null?void 0:p.ip_address)||""})`:"-"});function tl(){return String(Math.floor(100+Math.random()*900))}async function al(){H.value=!0;try{te.value=await Xe(k)||[]}catch{}finally{H.value=!1}}function il(){var e;E.value=0,M.value=null;const n=tl();Object.assign(A,{username:`sunip-${n}`,password:`sunip-${n}`,label:F.value.length===0?"SunIPIP.com Streaming LAN":"",proxy_mode:"proxy",proxy_subscription_id:null,max_devices:((e=o.value)==null?void 0:e.wifi_max_devices_per_account)||5}),al(),G.value=!0}async function nl(){x.value=!0;try{const n=await Wl(k,A);M.value=n,E.value=2,oe(),N(),S.success("WiFi 账号已创建")}catch{}finally{x.value=!1}}function ol(n){De.value=n,Object.assign($,{username:n.username,password:n.password,label:n.label||"",proxy_mode:n.proxy_mode,proxy_subscription_id:n.proxy_subscription_id,max_devices:n.max_devices,is_active:n.is_active}),H.value=!0,Xe(k).then(e=>{const r=e||[];n.proxy_subscription_id&&!r.find(p=>p.id===n.proxy_subscription_id)&&r.unshift({id:n.proxy_subscription_id,...n.subscription||{}}),Ee.value=r}).finally(()=>{H.value=!1}),B.value=!0}async function sl(){x.value=!0;try{await Fl(De.value.id,$),S.success("已更新"),B.value=!1,oe(),N()}catch{}finally{x.value=!1}}function ul(n){var p,I;const e=((p=n.forward_plan)==null?void 0:p.name)||"代理节点",r=((I=n.proxy_ip)==null?void 0:I.ip_address)||"";return`${e} (${r})`}async function dl(n){await h.confirm(`确认删除 WiFi 账号「${n.username}」？`,"确认",{type:"warning"});try{await El(n.id),S.success("已删除"),oe(),N()}catch{}}function Fe(n){U.value=n,ke.value=!0}async function Re(n){const e=n?"开启":"关闭";await h.confirm(n?"临时开启管理段 DHCP (10.20.0.100-200)，用于连接 AP 进行管理。完成后请手动关闭。":"关闭管理段 DHCP，WiFi 客户端将仅通过 RADIUS 认证获取业务 IP。",`${e}管理段 DHCP`,{type:n?"warning":"info"}),ie.value=!0;try{const r=await Dl(k,{enabled:n});S.success((r==null?void 0:r.message)||`${e}命令已下发`)}catch{}finally{ie.value=!1}}async function rl(){await h.confirm("确认远程重启此设备？设备将暂时离线。","重启设备",{type:"warning"});try{await Vl(k),S.success("重启命令已发送")}catch{}}async function pl(){x.value=!0;try{await Rl(k,{service:ne.value}),S.success(`服务 ${ne.value} 重启命令已发送`),K.value=!1}catch{}finally{x.value=!1}}function fl(n){if(!n.ip_start_index||n.ip_start_index<2)return n.ip_prefix;const r=(10<<24|655360)+n.ip_start_index,p=r+(n.max_devices||1)-1,I=q=>`${q>>24&255}.${q>>16&255}.${q>>8&255}.${q&255}`;return`${I(r)} ~ ${I(p)}`}function ml(){return crypto.randomUUID().toUpperCase()}function se(n){navigator.clipboard.writeText(n),S.success("已复制")}function $e(n){return n?Il(n).format("YYYY-MM-DD HH:mm:ss"):"-"}function vl(n){if(!n)return"-";const e=Math.floor(n/86400),r=Math.floor(n%86400/3600);return e>0?`${e}天${r}小时`:`${r}小时`}function Te(n){return{inventory:"库存",provisioned:"已配置",online:"在线",offline:"离线",decommissioned:"已停用"}[n]||n}function He(n){return{inventory:"info",provisioned:"",online:"success",offline:"danger",decommissioned:"info"}[n]||""}function _l(n){return{video:"视频专线",live_mobile:"直播(手机)",live_pc:"直播(电脑)"}[n]||""}return(n,e)=>{const r=_("el-tag"),p=_("el-button"),I=_("el-dropdown-item"),q=_("el-dropdown-menu"),cl=_("el-dropdown"),f=_("el-descriptions-item"),Y=_("el-descriptions"),ue=_("el-col"),Oe=_("el-row"),J=_("el-tab-pane"),g=_("el-table-column"),Se=_("el-table"),Le=_("el-pagination"),Ae=_("el-alert"),Ie=_("el-card"),bl=_("el-tabs"),P=_("el-option"),R=_("el-select"),y=_("el-form-item"),Q=_("el-form"),T=_("el-dialog"),C=_("el-input"),Pe=_("el-input-number"),Ve=_("el-step"),yl=_("el-steps"),wl=_("el-switch"),qe=_("el-divider"),de=Sl("loading");return re((d(),v("div",hl,[o.value?(d(),v("div",Gl,[s("div",null,[s("h2",null,u(o.value.device_no||o.value.hostname||o.value.serial_number),1),s("p",Ml,[i(u(o.value.serial_number)+" · ",1),l(r,{type:He(o.value.status),size:"small"},{default:t(()=>[i(u(Te(o.value.status)),1)]),_:1},8,["type"])])]),s("div",Bl,[o.value.status!=="decommissioned"?(d(),c(p,{key:0,onClick:Ye},{default:t(()=>[i(u(["inventory","provisioned"].includes(o.value.status)?"生成安装令牌":"重装系统令牌"),1)]),_:1})):b("",!0),!o.value.customer_id&&o.value.status!=="decommissioned"?(d(),c(p,{key:1,type:"primary",onClick:e[0]||(e[0]=a=>{Z.value=!0,We("")})},{default:t(()=>[...e[50]||(e[50]=[i("绑定客户",-1)])]),_:1})):b("",!0),o.value.customer_id?(d(),c(p,{key:2,onClick:je},{default:t(()=>[...e[51]||(e[51]=[i("解绑",-1)])]),_:1})):b("",!0),o.value.status!=="decommissioned"?(d(),c(p,{key:3,onClick:Ke},{default:t(()=>[...e[52]||(e[52]=[i("推送配置",-1)])]),_:1})):b("",!0),l(cl,{trigger:"click"},{dropdown:t(()=>[l(q,null,{default:t(()=>[l(I,{onClick:e[1]||(e[1]=a=>j.value=!0)},{default:t(()=>[...e[54]||(e[54]=[i("编辑设备",-1)])]),_:1}),o.value.status!=="decommissioned"?(d(),c(I,{key:0,onClick:rl},{default:t(()=>[...e[55]||(e[55]=[i("远程重启设备",-1)])]),_:1})):b("",!0),o.value.status!=="decommissioned"?(d(),c(I,{key:1,onClick:e[2]||(e[2]=a=>K.value=!0)},{default:t(()=>[...e[56]||(e[56]=[i("重启服务",-1)])]),_:1})):b("",!0),l(I,{divided:"",onClick:Je,style:{color:"#f56c6c"}},{default:t(()=>[...e[57]||(e[57]=[i("停用设备",-1)])]),_:1})]),_:1})]),default:t(()=>[l(p,null,{default:t(()=>[...e[53]||(e[53]=[i("更多",-1)])]),_:1})]),_:1})])])):b("",!0),o.value?(d(),c(bl,{key:1,modelValue:ve.value,"onUpdate:modelValue":e[8]||(e[8]=a=>ve.value=a)},{default:t(()=>[l(J,{label:"概览",name:"overview"},{default:t(()=>[l(Oe,{gutter:20},{default:t(()=>[l(ue,{span:12},{default:t(()=>[l(Y,{column:1,border:""},{default:t(()=>[l(f,{label:"设备编号"},{default:t(()=>[i(u(o.value.device_no||"-"),1)]),_:1}),l(f,{label:"设备 ID"},{default:t(()=>[i(u(o.value.id),1)]),_:1}),l(f,{label:"序列号"},{default:t(()=>[i(u(o.value.serial_number),1)]),_:1}),l(f,{label:"主机名"},{default:t(()=>[i(u(o.value.hostname||"-"),1)]),_:1}),l(f,{label:"状态"},{default:t(()=>[l(r,{type:He(o.value.status),size:"small"},{default:t(()=>[i(u(Te(o.value.status)),1)]),_:1},8,["type"])]),_:1}),l(f,{label:"客户"},{default:t(()=>{var a;return[i(u(((a=o.value.customer)==null?void 0:a.customer_name)||"未绑定"),1)]}),_:1}),l(f,{label:"模块"},{default:t(()=>[i(u(_l(o.value.bound_module)||"-"),1)]),_:1}),l(f,{label:"路由器型号"},{default:t(()=>{var a;return[i(u(((a=o.value.router_model)==null?void 0:a.name)||"-"),1)]}),_:1}),l(f,{label:"AP 型号"},{default:t(()=>{var a;return[i(u(((a=o.value.ap_model)==null?void 0:a.name)||"-"),1)]}),_:1}),l(f,{label:"套餐"},{default:t(()=>{var a;return[i(u(((a=o.value.bundle)==null?void 0:a.name)||"-"),1)]}),_:1}),l(f,{label:"备注"},{default:t(()=>[i(u(o.value.remark||"-"),1)]),_:1})]),_:1})]),_:1}),l(ue,{span:12},{default:t(()=>[l(Y,{column:1,border:""},{default:t(()=>[l(f,{label:"配置版本"},{default:t(()=>[i(" v"+u(o.value.config_version)+" ",1),o.value.config_synced?(d(),c(r,{key:0,type:"success",size:"small",class:"ml-8"},{default:t(()=>[...e[58]||(e[58]=[i("已同步",-1)])]),_:1})):(d(),c(r,{key:1,type:"warning",size:"small",class:"ml-8"},{default:t(()=>[i("待同步 (applied: v"+u(o.value.applied_config_version)+")",1)]),_:1}))]),_:1}),l(f,{label:"Agent 版本"},{default:t(()=>[i(u(o.value.agent_version||"-"),1)]),_:1}),l(f,{label:"WiFi 架构"},{default:t(()=>[l(r,{type:o.value.wifi_version>=2?"success":"",size:"small"},{default:t(()=>[i(u(o.value.wifi_version>=2?"v2 (Flat IP)":"v1 (VLAN)"),1)]),_:1},8,["type"])]),_:1}),o.value.wifi_version>=2?(d(),c(f,{key:0,label:"每 WiFi 最大设备"},{default:t(()=>[l(r,{size:"small"},{default:t(()=>[i(u(o.value.wifi_max_devices_per_account||5)+" 台",1)]),_:1})]),_:1})):b("",!0),o.value.wifi_version>=2?(d(),c(f,{key:1,label:"管理段 DHCP"},{default:t(()=>[l(p,{size:"small",type:"warning",onClick:e[3]||(e[3]=a=>Re(!0)),loading:ie.value},{default:t(()=>[...e[59]||(e[59]=[i(" 临时开启 ",-1)])]),_:1},8,["loading"]),l(p,{size:"small",onClick:e[4]||(e[4]=a=>Re(!1)),loading:ie.value},{default:t(()=>[...e[60]||(e[60]=[i(" 关闭 ",-1)])]),_:1},8,["loading"]),e[61]||(e[61]=s("span",{class:"text-muted",style:{"margin-left":"8px","font-size":"12px"}},"开启后可连接 AP 进行管理",-1))]),_:1})):b("",!0),o.value.target_agent_version?(d(),c(f,{key:2,label:"灰度 Agent"},{default:t(()=>[l(r,{type:"warning",size:"small"},{default:t(()=>[i(u(o.value.target_agent_version),1)]),_:1})]),_:1})):b("",!0),l(f,{label:"公网 IP"},{default:t(()=>[i(u(o.value.wan_ip||"-"),1)]),_:1}),l(f,{label:"WG IP 1"},{default:t(()=>[i(u(o.value.wg_ip_1||"-"),1)]),_:1}),l(f,{label:"WG IP 2"},{default:t(()=>[i(u(o.value.wg_ip_2||"-"),1)]),_:1}),l(f,{label:"最后心跳"},{default:t(()=>[i(u(o.value.last_heartbeat_at?$e(o.value.last_heartbeat_at):"从未"),1)]),_:1}),o.value.system_info?(d(),c(f,{key:3,label:"系统信息"},{default:t(()=>[i(" CPU: "+u(o.value.system_info.cpu_temp??"-")+"°C · 内存: "+u(o.value.system_info.mem_used_mb??"-")+"/"+u(o.value.system_info.mem_total_mb??"-")+"MB · 运行: "+u(vl(o.value.system_info.uptime_seconds)),1)]),_:1})):b("",!0),l(f,{label:"创建时间"},{default:t(()=>[i(u($e(o.value.created_at)),1)]),_:1})]),_:1})]),_:1})]),_:1})]),_:1}),l(J,{label:"WiFi 账号",name:"wifi"},{default:t(()=>[s("div",Zl,[l(p,{type:"primary",size:"small",onClick:e[5]||(e[5]=a=>il()),disabled:!o.value.customer_id},{default:t(()=>[...e[62]||(e[62]=[i("添加账号",-1)])]),_:1},8,["disabled"])]),re((d(),c(Se,{data:he.value,stripe:""},{default:t(()=>[o.value.wifi_version<2?(d(),c(g,{key:0,prop:"vlan_id",label:"VLAN",width:"70"})):b("",!0),l(g,{prop:"username",label:"用户名",width:"130"}),l(g,{prop:"password",label:"密码",width:"130"}),l(g,{prop:"label",label:"标签",width:"120"}),l(g,{label:"IP 分配",width:"200"},{default:t(({row:a})=>[o.value.wifi_version>=2&&a.ip_start_index>=2?(d(),v(V,{key:0},[s("span",jl,u(fl(a)),1),l(r,{size:"small",type:"info",style:{"margin-left":"4px"}},{default:t(()=>[i(u(a.max_devices)+"台",1)]),_:2},1024)],64)):(d(),v("span",Kl,u(a.ip_prefix),1))]),_:1}),l(g,{label:"代理模式",width:"100"},{default:t(({row:a})=>[l(r,{type:a.proxy_mode==="proxy"?"":"info",size:"small"},{default:t(()=>[i(u(a.proxy_mode==="proxy"?"代理":"直连"),1)]),_:2},1032,["type"])]),_:1}),l(g,{label:"绑定节点","min-width":"200"},{default:t(({row:a})=>[a.subscription?(d(),v(V,{key:0},[a.subscription.proxy_ip?(d(),v("span",Yl,u(a.subscription.proxy_ip.country_name)+" "+u(a.subscription.proxy_ip.ip_address),1)):(d(),v("span",Jl,"订阅 #"+u(a.proxy_subscription_id),1))],64)):(d(),v("span",Ql,"未绑定"))]),_:1}),l(g,{label:"状态",width:"70",align:"center"},{default:t(({row:a})=>[l(r,{type:a.is_active?"success":"info",size:"small"},{default:t(()=>[i(u(a.is_active?"启用":"停用"),1)]),_:2},1032,["type"])]),_:1}),l(g,{label:"操作",width:"180",fixed:"right"},{default:t(({row:a})=>[l(p,{size:"small",link:"",onClick:D=>Fe(a)},{default:t(()=>[...e[63]||(e[63]=[i("连接信息",-1)])]),_:1},8,["onClick"]),l(p,{size:"small",link:"",onClick:D=>ol(a)},{default:t(()=>[...e[64]||(e[64]=[i("编辑",-1)])]),_:1},8,["onClick"]),l(p,{size:"small",link:"",type:"danger",onClick:D=>dl(a)},{default:t(()=>[...e[65]||(e[65]=[i("删除",-1)])]),_:1},8,["onClick"])]),_:1})]),_:1},8,["data"])),[[de,_e.value]]),F.value.length>fe?(d(),v("div",et,[l(Le,{"current-page":ce.value,"onUpdate:currentPage":e[6]||(e[6]=a=>ce.value=a),"page-size":fe,total:F.value.length,layout:"total, prev, pager, next",small:""},null,8,["current-page","total"])])):b("",!0)]),_:1}),l(J,{label:"WireGuard",name:"wg"},{default:t(()=>[l(Se,{data:o.value.wg_peers||[],stripe:""},{default:t(()=>[l(g,{label:"接口",width:"80"},{default:t(({$index:a})=>[i("wg"+u(a),1)]),_:1}),l(g,{label:"服务器",width:"200"},{default:t(({row:a})=>{var D,W;return[i(u((D=a.server)==null?void 0:D.name)+" ("+u((W=a.server)==null?void 0:W.endpoint)+")",1)]}),_:1}),l(g,{prop:"assigned_ip",label:"分配 IP",width:"150"}),l(g,{prop:"peer_public_key",label:"公钥","min-width":"200","show-overflow-tooltip":""}),l(g,{label:"状态",width:"80"},{default:t(({row:a})=>[l(r,{type:a.is_active?"success":"info",size:"small"},{default:t(()=>[i(u(a.is_active?"活跃":"停用"),1)]),_:2},1032,["type"])]),_:1})]),_:1},8,["data"])]),_:1}),l(J,{label:"AP 配置",name:"ap"},{default:t(()=>[l(Ie,{shadow:"never"},{header:t(()=>[s("div",lt,[e[68]||(e[68]=s("span",{style:{"font-weight":"600"}},"OpenWrt AP 一键配置脚本",-1)),s("div",null,[l(p,{size:"small",onClick:Ge},{default:t(()=>[...e[66]||(e[66]=[i("复制脚本",-1)])]),_:1}),l(p,{size:"small",type:"primary",onClick:Me},{default:t(()=>[...e[67]||(e[67]=[i("复制 SSH 命令",-1)])]),_:1})])])]),default:t(()=>[l(Ae,{type:"info",closable:!1,"show-icon":"",style:{"margin-bottom":"16px"}},{default:t(()=>{var a;return[e[70]||(e[70]=i(" 通过 SSH 登录 AP 后运行此脚本，自动完成三频 WiFi、WPA2-EAP 认证、NSS 硬件加速、WAN SSH 等全部配置。 ",-1)),(a=o.value)!=null&&a.ap_ip?(d(),v("span",tt,[e[69]||(e[69]=i(" 快捷命令: ",-1)),s("code",at,"ssh root@"+u(o.value.ap_ip),1)])):b("",!0)]}),_:1}),s("pre",it,[s("code",null,u(xe.value),1)])]),_:1}),l(Oe,{gutter:24,style:{"margin-top":"20px"}},{default:t(()=>[l(ue,{span:12},{default:t(()=>[l(Ie,{shadow:"never"},{header:t(()=>[...e[71]||(e[71]=[s("span",{style:{"font-weight":"600"}},"配置说明",-1)])]),default:t(()=>[e[72]||(e[72]=s("div",{style:{"font-size":"13px",color:"#606266","line-height":"1.8"}},[s("p",null,[s("b",null,"脚本功能 (v2 Flat IP):")]),s("ul",{style:{"padding-left":"20px",margin:"4px 0 12px"}},[s("li",null,"自动检测所有射频 (2.4G / 5G / 6G)，含 PCIe 5GHz"),s("li",null,[i("统一配置 SSID: "),s("code",null,"SunIPIP.com Streaming LAN")]),s("li",null,"NSS 硬件加速开启 (nss_offload=1)"),s("li",null,"WPA2-EAP 企业级认证 (RADIUS)"),s("li",null,"Flat IP 模式 (dynamic_vlan=0，无 VLAN 下发)"),s("li",null,"WAN 口 SSH 放行 (方便远程管理)")]),s("p",null,[s("b",null,"幂等性:"),i(" 脚本可重复运行，不会产生重复规则。")])],-1))]),_:1})]),_:1}),l(ue,{span:12},{default:t(()=>[l(Ie,{shadow:"never"},{header:t(()=>[...e[73]||(e[73]=[s("span",{style:{"font-weight":"600"}},"RADIUS 参数",-1)])]),default:t(()=>[l(Y,{column:1,border:""},{default:t(()=>[l(f,{label:"RADIUS 服务器"},{default:t(()=>[...e[74]||(e[74]=[i("10.20.0.1",-1)])]),_:1}),l(f,{label:"认证端口"},{default:t(()=>[...e[75]||(e[75]=[i("1812",-1)])]),_:1}),l(f,{label:"共享密钥"},{default:t(()=>[...e[76]||(e[76]=[s("code",{style:{background:"#f5f7fa",padding:"2px 8px","border-radius":"3px","user-select":"all"}},"sunipip_radius_secret",-1)])]),_:1}),l(f,{label:"认证方式"},{default:t(()=>[...e[77]||(e[77]=[i("WPA2-EAP (PEAP)",-1)])]),_:1}),l(f,{label:"VLAN 模式"},{default:t(()=>[...e[78]||(e[78]=[i("v2: disabled (dynamic_vlan=0)",-1)])]),_:1}),l(f,{label:"NSS 加速"},{default:t(()=>[...e[79]||(e[79]=[i("已启用 (nss_offload=1)",-1)])]),_:1})]),_:1})]),_:1})]),_:1})]),_:1})]),_:1}),l(J,{label:"事件日志",name:"events"},{default:t(()=>[re((d(),c(Se,{data:be.value,stripe:""},{default:t(()=>[l(g,{label:"时间",width:"170"},{default:t(({row:a})=>[i(u($e(a.created_at)),1)]),_:1}),l(g,{prop:"event_type",label:"类型",width:"130"},{default:t(({row:a})=>[l(r,{size:"small"},{default:t(()=>[i(u(a.event_type),1)]),_:2},1024)]),_:1}),l(g,{label:"级别",width:"80",align:"center"},{default:t(({row:a})=>[l(r,{type:{info:"",warning:"warning",error:"danger"}[a.severity],size:"small"},{default:t(()=>[i(u(a.severity),1)]),_:2},1032,["type"])]),_:1}),l(g,{prop:"message",label:"消息","min-width":"300","show-overflow-tooltip":""})]),_:1},8,["data"])),[[de,ye.value]]),z.total>0?(d(),c(Le,{key:0,class:"mt-16",layout:"total, prev, pager, next",total:z.total,"page-size":z.per_page,"current-page":z.current_page,onCurrentChange:e[7]||(e[7]=a=>{z.current_page=a,ze()})},null,8,["total","page-size","current-page"])):b("",!0)]),_:1})]),_:1},8,["modelValue"])):b("",!0),l(T,{title:"绑定客户",modelValue:Z.value,"onUpdate:modelValue":e[12]||(e[12]=a=>Z.value=a),width:"460px","destroy-on-close":""},{footer:t(()=>[l(p,{onClick:e[11]||(e[11]=a=>Z.value=!1)},{default:t(()=>[...e[80]||(e[80]=[i("取消",-1)])]),_:1}),l(p,{type:"primary",loading:x.value,onClick:Ze},{default:t(()=>[...e[81]||(e[81]=[i("确定",-1)])]),_:1},8,["loading"])]),default:t(()=>[l(Q,{"label-width":"70px"},{default:t(()=>[l(y,{label:"客户",required:""},{default:t(()=>[l(R,{modelValue:O.customer_id,"onUpdate:modelValue":e[9]||(e[9]=a=>O.customer_id=a),filterable:"",remote:"","reserve-keyword":"",placeholder:"搜索客户","remote-method":We,loading:we.value,style:{width:"100%"}},{default:t(()=>[(d(!0),v(V,null,X(Ne.value,a=>(d(),c(P,{key:a.id,label:`${a.customer_name} (${a.company_name||a.phone||a.id})`,value:a.id},null,8,["label","value"]))),128))]),_:1},8,["modelValue","loading"])]),_:1}),l(y,{label:"模块",required:""},{default:t(()=>[l(R,{modelValue:O.module,"onUpdate:modelValue":e[10]||(e[10]=a=>O.module=a),style:{width:"100%"}},{default:t(()=>[l(P,{label:"视频专线",value:"video"}),l(P,{label:"直播专线(手机)",value:"live_mobile"}),l(P,{label:"直播专线(电脑)",value:"live_pc"})]),_:1},8,["modelValue"])]),_:1})]),_:1})]),_:1},8,["modelValue"]),l(T,{title:"编辑设备",modelValue:j.value,"onUpdate:modelValue":e[22]||(e[22]=a=>j.value=a),width:"460px","destroy-on-close":"",onOpen:Qe},{footer:t(()=>[l(p,{onClick:e[21]||(e[21]=a=>j.value=!1)},{default:t(()=>[...e[85]||(e[85]=[i("取消",-1)])]),_:1}),l(p,{type:"primary",loading:x.value,onClick:el},{default:t(()=>[...e[86]||(e[86]=[i("保存",-1)])]),_:1},8,["loading"])]),default:t(()=>[l(Q,{model:w,"label-width":"100px"},{default:t(()=>[l(y,{label:"序列号"},{default:t(()=>[l(C,{modelValue:w.serial_number,"onUpdate:modelValue":e[14]||(e[14]=a=>w.serial_number=a),placeholder:"设备唯一序列号（UUID）"},{append:t(()=>[l(p,{onClick:e[13]||(e[13]=a=>w.serial_number=ml())},{default:t(()=>[...e[82]||(e[82]=[i("自动生成",-1)])]),_:1})]),_:1},8,["modelValue"])]),_:1}),l(y,{label:"路由器型号"},{default:t(()=>[l(R,{modelValue:w.router_model_id,"onUpdate:modelValue":e[15]||(e[15]=a=>w.router_model_id=a),clearable:"",placeholder:"选择路由器型号",style:{width:"100%"}},{default:t(()=>[(d(!0),v(V,null,X(ae.value.router_models||[],a=>(d(),c(P,{key:a.id,label:a.name,value:a.id},null,8,["label","value"]))),128))]),_:1},8,["modelValue"])]),_:1}),l(y,{label:"AP 型号"},{default:t(()=>[l(R,{modelValue:w.ap_model_id,"onUpdate:modelValue":e[16]||(e[16]=a=>w.ap_model_id=a),clearable:"",placeholder:"选择 AP 型号",style:{width:"100%"}},{default:t(()=>[(d(!0),v(V,null,X(ae.value.ap_models||[],a=>(d(),c(P,{key:a.id,label:a.name,value:a.id},null,8,["label","value"]))),128))]),_:1},8,["modelValue"])]),_:1}),l(y,{label:"套餐"},{default:t(()=>[l(R,{modelValue:w.bundle_id,"onUpdate:modelValue":e[17]||(e[17]=a=>w.bundle_id=a),clearable:"",placeholder:"选择套餐搭配",style:{width:"100%"}},{default:t(()=>[(d(!0),v(V,null,X(ae.value.bundles||[],a=>(d(),c(P,{key:a.id,label:a.name,value:a.id},null,8,["label","value"]))),128))]),_:1},8,["modelValue"])]),_:1}),o.value.wifi_version>=2?(d(),c(y,{key:0,label:"WiFi 最大设备"},{default:t(()=>[l(Pe,{modelValue:w.wifi_max_devices_per_account,"onUpdate:modelValue":e[18]||(e[18]=a=>w.wifi_max_devices_per_account=a),min:1,max:50},null,8,["modelValue"]),e[83]||(e[83]=s("div",{style:{"font-size":"12px",color:"#94a3b8","margin-top":"2px"}},"每个 WiFi 账号最多绑定多少台设备（分配多少个 /32 IP）",-1))]),_:1})):b("",!0),l(y,{label:"灰度 Agent"},{default:t(()=>[l(C,{modelValue:w.target_agent_version,"onUpdate:modelValue":e[19]||(e[19]=a=>w.target_agent_version=a),placeholder:"指定版本号(如 1.3.0)，留空跟随全局",clearable:""},null,8,["modelValue"]),e[84]||(e[84]=s("div",{style:{"font-size":"12px",color:"#94a3b8","margin-top":"2px"}},"设置后此设备心跳将收到该版本号，用于灰度测试新 agent",-1))]),_:1}),l(y,{label:"备注"},{default:t(()=>[l(C,{modelValue:w.remark,"onUpdate:modelValue":e[20]||(e[20]=a=>w.remark=a),type:"textarea",rows:2},null,8,["modelValue"])]),_:1})]),_:1},8,["model"])]),_:1},8,["modelValue"]),l(T,{title:"创建 WiFi 账号",modelValue:G.value,"onUpdate:modelValue":e[31]||(e[31]=a=>G.value=a),width:"520px","destroy-on-close":"","close-on-click-modal":!1},{footer:t(()=>[E.value===0?(d(),v(V,{key:0},[l(p,{onClick:e[26]||(e[26]=a=>G.value=!1)},{default:t(()=>[...e[92]||(e[92]=[i("取消",-1)])]),_:1}),l(p,{type:"primary",disabled:!A.proxy_subscription_id,onClick:e[27]||(e[27]=a=>E.value=1)},{default:t(()=>[...e[93]||(e[93]=[i("下一步",-1)])]),_:1},8,["disabled"])],64)):E.value===1?(d(),v(V,{key:1},[l(p,{onClick:e[28]||(e[28]=a=>E.value=0)},{default:t(()=>[...e[94]||(e[94]=[i("上一步",-1)])]),_:1}),l(p,{type:"primary",loading:x.value,onClick:nl},{default:t(()=>[...e[95]||(e[95]=[i("创建账号",-1)])]),_:1},8,["loading"])],64)):(d(),v(V,{key:2},[l(p,{onClick:e[29]||(e[29]=a=>Fe(M.value))},{default:t(()=>[...e[96]||(e[96]=[i("查看连接信息",-1)])]),_:1}),l(p,{type:"primary",onClick:e[30]||(e[30]=a=>G.value=!1)},{default:t(()=>[...e[97]||(e[97]=[i("完成",-1)])]),_:1})],64))]),default:t(()=>[l(yl,{active:E.value,"finish-status":"success",simple:"",style:{"margin-bottom":"24px"}},{default:t(()=>[l(Ve,{title:"选择节点"}),l(Ve,{title:"账号信息"}),l(Ve,{title:"完成"})]),_:1},8,["active"]),E.value===0?(d(),v("div",nt,[e[87]||(e[87]=s("p",{style:{"font-size":"13px",color:"#64748b","margin-bottom":"16px"}},"选择一个代理节点，WiFi 连接的所有流量将通过该节点转发。",-1)),H.value?re((d(),v("div",ot,null,512)),[[de,!0]]):te.value.length===0?(d(),v("div",st," 该客户暂无可用的代理节点。 ")):(d(),v("div",ut,[(d(!0),v(V,null,X(te.value,a=>{var D,W,ee;return d(),v("div",{key:a.id,class:Al(["node-item",{selected:A.proxy_subscription_id===a.id}]),onClick:Ce=>A.proxy_subscription_id=a.id},[s("div",rt,[s("span",pt,u(((D=a.forward_plan)==null?void 0:D.name)||"代理节点"),1),l(r,{size:"small",type:"info"},{default:t(()=>{var Ce;return[i(u(((Ce=a.proxy_ip)==null?void 0:Ce.country_name)||"-"),1)]}),_:2},1024)]),s("div",ft,[s("span",null,u(((W=a.proxy_ip)==null?void 0:W.ip_address)||"-"),1),(ee=a.forward_plan)!=null&&ee.display_host?(d(),v("span",mt,u(a.forward_plan.display_host),1)):b("",!0)])],10,dt)}),128))]))])):b("",!0),E.value===1?(d(),v("div",vt,[l(Q,{model:A,"label-width":"90px"},{default:t(()=>[l(y,{label:"WiFi 名称"},{default:t(()=>[l(C,{"model-value":"SunIPIP.com Streaming LAN",disabled:""}),e[88]||(e[88]=s("div",{style:{"font-size":"12px",color:"#94a3b8","margin-top":"2px"}},"WiFi SSID 固定为 SunIPIP.com Streaming LAN，此标签仅用于列表区分",-1))]),_:1}),l(y,{label:"登录用户名"},{default:t(()=>[l(C,{modelValue:A.username,"onUpdate:modelValue":e[23]||(e[23]=a=>A.username=a)},null,8,["modelValue"])]),_:1}),l(y,{label:"登录密码"},{default:t(()=>[l(C,{modelValue:A.password,"onUpdate:modelValue":e[24]||(e[24]=a=>A.password=a)},null,8,["modelValue"])]),_:1}),l(y,{label:"最大设备数"},{default:t(()=>[l(Pe,{modelValue:A.max_devices,"onUpdate:modelValue":e[25]||(e[25]=a=>A.max_devices=a),min:1,max:o.value.wifi_max_devices_per_account||5},null,8,["modelValue","max"]),s("div",_t,"每台设备分配一个独立 /32 IP（上限 "+u(o.value.wifi_max_devices_per_account||5)+"，可在设备编辑中调整）",1)]),_:1})]),_:1},8,["model"])])):b("",!0),E.value===2?(d(),v("div",ct,[e[90]||(e[90]=s("div",{style:{"font-size":"48px",color:"#67c23a","margin-bottom":"8px"}},"OK",-1)),e[91]||(e[91]=s("h3",{style:{margin:"0",color:"#1e293b"}},"WiFi 账号创建成功",-1)),l(Y,{column:1,border:"",size:"small",style:{"margin-top":"16px"}},{default:t(()=>[l(f,{label:"WiFi 名称"},{default:t(()=>[...e[89]||(e[89]=[i("SunIPIP.com Streaming LAN",-1)])]),_:1}),l(f,{label:"用户名"},{default:t(()=>{var a;return[i(u((a=M.value)==null?void 0:a.username),1)]}),_:1}),l(f,{label:"密码"},{default:t(()=>{var a;return[i(u((a=M.value)==null?void 0:a.password),1)]}),_:1}),l(f,{label:"代理节点"},{default:t(()=>[i(u(ll.value),1)]),_:1})]),_:1})])):b("",!0)]),_:1},8,["modelValue"]),l(T,{title:"编辑 WiFi 账号",modelValue:B.value,"onUpdate:modelValue":e[39]||(e[39]=a=>B.value=a),width:"500px","destroy-on-close":""},{footer:t(()=>[l(p,{onClick:e[38]||(e[38]=a=>B.value=!1)},{default:t(()=>[...e[98]||(e[98]=[i("取消",-1)])]),_:1}),l(p,{type:"primary",loading:x.value,onClick:sl},{default:t(()=>[...e[99]||(e[99]=[i("保存",-1)])]),_:1},8,["loading"])]),default:t(()=>[l(Q,{model:$,"label-width":"90px"},{default:t(()=>[l(y,{label:"用户名"},{default:t(()=>[l(C,{modelValue:$.username,"onUpdate:modelValue":e[32]||(e[32]=a=>$.username=a)},null,8,["modelValue"])]),_:1}),l(y,{label:"密码"},{default:t(()=>[l(C,{modelValue:$.password,"onUpdate:modelValue":e[33]||(e[33]=a=>$.password=a)},null,8,["modelValue"])]),_:1}),l(y,{label:"标签"},{default:t(()=>[l(C,{modelValue:$.label,"onUpdate:modelValue":e[34]||(e[34]=a=>$.label=a),placeholder:"如：客厅电视"},null,8,["modelValue"])]),_:1}),l(y,{label:"代理节点"},{default:t(()=>[l(R,{modelValue:$.proxy_subscription_id,"onUpdate:modelValue":e[35]||(e[35]=a=>$.proxy_subscription_id=a),clearable:"",placeholder:"选择代理节点",style:{width:"100%"},loading:H.value},{default:t(()=>[(d(!0),v(V,null,X(Ee.value,a=>(d(),c(P,{key:a.id,label:ul(a),value:a.id},{default:t(()=>{var D,W,ee;return[s("div",bt,[s("span",null,u(((D=a.forward_plan)==null?void 0:D.name)||"代理节点"),1),s("span",yt,u(((W=a.proxy_ip)==null?void 0:W.ip_address)||"")+" · "+u(((ee=a.proxy_ip)==null?void 0:ee.country_name)||""),1)])]}),_:2},1032,["label","value"]))),128))]),_:1},8,["modelValue","loading"])]),_:1}),l(y,{label:"最大设备数"},{default:t(()=>[l(Pe,{modelValue:$.max_devices,"onUpdate:modelValue":e[36]||(e[36]=a=>$.max_devices=a),min:1,max:o.value.wifi_max_devices_per_account||5},null,8,["modelValue","max"])]),_:1}),l(y,{label:"状态"},{default:t(()=>[l(wl,{modelValue:$.is_active,"onUpdate:modelValue":e[37]||(e[37]=a=>$.is_active=a),"active-value":1,"inactive-value":0},null,8,["modelValue"])]),_:1})]),_:1},8,["model"])]),_:1},8,["modelValue"]),l(T,{title:"安装令牌",modelValue:ge.value,"onUpdate:modelValue":e[43]||(e[43]=a=>ge.value=a),width:"620px"},{default:t(()=>[l(Ae,{type:"warning",closable:!1,"show-icon":"",style:{"margin-bottom":"16px"}},{default:t(()=>[...e[100]||(e[100]=[i(" 此令牌有效期 72 小时，注册后自动失效。请妥善保管，勿泄露给无关人员。 ",-1)])]),_:1}),s("div",wt,[e[102]||(e[102]=s("div",{class:"install-label"},"安装链接",-1)),l(C,{modelValue:L.value,"onUpdate:modelValue":e[41]||(e[41]=a=>L.value=a),readonly:""},{append:t(()=>[l(p,{onClick:e[40]||(e[40]=a=>se(L.value))},{default:t(()=>[...e[101]||(e[101]=[i("复制",-1)])]),_:1})]),_:1},8,["modelValue"])]),s("div",gt,[e[104]||(e[104]=s("div",{class:"install-label"},"一键安装命令",-1)),l(C,{"model-value":Ue.value,readonly:"",type:"textarea",rows:2,resize:"none"},null,8,["model-value"]),l(p,{size:"small",style:{"margin-top":"4px"},onClick:e[42]||(e[42]=a=>se(Ue.value))},{default:t(()=>[...e[103]||(e[103]=[i("复制命令",-1)])]),_:1})]),l(qe),s("div",kt,[e[106]||(e[106]=s("div",{class:"install-label"},"安装步骤",-1)),e[107]||(e[107]=s("ol",null,[s("li",null,[i("将工控机 4 个网口按顺序连接："),s("b",null,"eth0"),i("(WAN 上网) · "),s("b",null,"eth1"),i("(管理口) · "),s("b",null,"eth2"),i("(接 AP) · "),s("b",null,"eth3"),i("(有线 LAN)")]),s("li",null,[i("确保工控机已安装 "),s("b",null,"Debian 12"),i("，并能通过 eth0 上网")]),s("li",null,"SSH 登录工控机（root），执行上方的一键安装命令"),s("li",null,"脚本将自动安装依赖、生成 WG 密钥、向平台注册、配置网络和防火墙"),s("li",null,"安装完成后，设备状态变为「已配置」，等待 Agent 部署后上线")],-1)),l(Ae,{type:"info",closable:!1,style:{"margin-top":"8px"}},{title:t(()=>[...e[105]||(e[105]=[s("span",{style:{"font-weight":"normal"}},[i("安装完成后，管理页面: "),s("b",null,"http://172.10.0.1"),i(" · 有线LAN: "),s("b",null,"http://192.168.1.1")],-1)])]),_:1})])]),_:1},8,["modelValue"]),l(T,{title:"WiFi 连接信息",modelValue:ke.value,"onUpdate:modelValue":e[46]||(e[46]=a=>ke.value=a),width:"480px"},{default:t(()=>[U.value?(d(),v(V,{key:0},[l(Y,{column:1,border:"",size:"small"},{default:t(()=>[l(f,{label:"WiFi 名称"},{default:t(()=>[...e[108]||(e[108]=[i("SunIPIP.com Streaming LAN",-1)])]),_:1}),l(f,{label:"安全类型"},{default:t(()=>[...e[109]||(e[109]=[i("WPA2-Enterprise",-1)])]),_:1}),l(f,{label:"EAP 方法"},{default:t(()=>[...e[110]||(e[110]=[i("TTLS / PAP",-1)])]),_:1}),l(f,{label:"用户名"},{default:t(()=>[i(u(U.value.username)+" ",1),l(p,{size:"small",link:"",style:{"margin-left":"8px"},onClick:e[44]||(e[44]=a=>se(U.value.username))},{default:t(()=>[...e[111]||(e[111]=[i("复制",-1)])]),_:1})]),_:1}),l(f,{label:"密码"},{default:t(()=>[i(u(U.value.password)+" ",1),l(p,{size:"small",link:"",style:{"margin-left":"8px"},onClick:e[45]||(e[45]=a=>se(U.value.password))},{default:t(()=>[...e[112]||(e[112]=[i("复制",-1)])]),_:1})]),_:1}),o.value.wifi_version<2?(d(),c(f,{key:0,label:"VLAN"},{default:t(()=>[i(u(U.value.vlan_id),1)]),_:1})):b("",!0),l(f,{label:"子网"},{default:t(()=>[i(u(U.value.ip_prefix),1)]),_:1}),l(f,{label:"代理模式"},{default:t(()=>[i(u(U.value.proxy_mode==="proxy"?"代理":"直连"),1)]),_:1})]),_:1}),l(qe),e[113]||(e[113]=s("div",{style:{"font-size":"13px",color:"#606266","line-height":"1.8"}},[s("p",null,[s("b",null,"Android / 电脑:"),i(" WiFi 设置 → 选择「SunIPIP.com Streaming LAN」→ 安全类型选 WPA2-Enterprise → EAP 方法 TTLS → 阶段2 PAP → 输入用户名和密码")]),s("p",null,[s("b",null,"iPhone / iPad:"),i(" 推荐使用 iOS 描述文件自动配置（客户端可下载）")])],-1))],64)):b("",!0)]),_:1},8,["modelValue"]),l(T,{title:"重启服务",modelValue:K.value,"onUpdate:modelValue":e[49]||(e[49]=a=>K.value=a),width:"400px","destroy-on-close":""},{footer:t(()=>[l(p,{onClick:e[48]||(e[48]=a=>K.value=!1)},{default:t(()=>[...e[114]||(e[114]=[i("取消",-1)])]),_:1}),l(p,{type:"warning",loading:x.value,onClick:pl},{default:t(()=>[...e[115]||(e[115]=[i("重启",-1)])]),_:1},8,["loading"])]),default:t(()=>[l(Q,{"label-width":"60px"},{default:t(()=>[l(y,{label:"服务"},{default:t(()=>[l(R,{modelValue:ne.value,"onUpdate:modelValue":e[47]||(e[47]=a=>ne.value=a),style:{width:"100%"}},{default:t(()=>[l(P,{label:"Clash (代理引擎)",value:"clash"}),l(P,{label:"FreeRadius (WiFi 认证)",value:"freeradius"}),l(P,{label:"dnsmasq (DHCP/DNS)",value:"dnsmasq"}),l(P,{label:"Agent (设备代理)",value:"sunipip-router-agent"})]),_:1},8,["modelValue"])]),_:1})]),_:1})]),_:1},8,["modelValue"])])),[[de,me.value]])}}},Ct=gl(xt,[["__scopeId","data-v-c3825727"]]);export{Ct as default};
