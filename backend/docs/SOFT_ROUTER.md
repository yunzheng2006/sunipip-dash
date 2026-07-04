# 软路由管理模块 (Soft Router Module)

## 概述

SuniPIP 软路由模块：预配置工控机 (Debian 12 + FreeRadius + Clash + WireGuard)，客户插上即用。每个 WiFi 账号通过 VLAN 隔离走不同代理节点。

---

## 一、网络架构

```
Internet ← eth0 (WAN: DHCP/PPPoE)
               ↓
    ┌──────────────────────────────┐
    │      工控机 (Debian 12)       │
    │   FreeRadius + Clash + WG    │
    │   Go Agent + Nginx + dnsmasq │
    └──────────────────────────────┘
       ↓          ↓           ↓
    eth1        eth2         eth3
  172.10.0.1   (trunk)     192.168.1.1
  管理口       → AP          有线LAN
```

| 接口 | 用途 | IP | DHCP |
|------|------|-----|------|
| eth0 | WAN 上网 | DHCP/PPPoE | — |
| eth1 | 管理口 (隔离无网) | 172.10.0.1/24 | 172.10.0.100-200 |
| eth2 | AP trunk (802.1Q) | — | 按 VLAN |
| eth3 | 有线 LAN (不代理) | 192.168.1.1/24 | 192.168.1.100-200 |

### VLAN 策略

- 范围: 10-200 (每设备最多 190 个 WiFi 账号)
- IP: `10.10.{vlan_id}.0/29` (每 VLAN 6 可用 IP)
- 网关: `10.10.{vlan_id}.1`
- 分配: 取设备内最小未使用 VLAN ID

### 流量路径

```
手机连WiFi → FreeRadius认证 → 分配VLAN → AP隔离 → 路由器eth2.{vlan} → br-vlan{id}
  → nftables TProxy (mark 0x1) → Clash 7893 → SRC-IP-CIDR匹配 → SOCKS5代理出站
```

---

## 二、API 参考

Base URL: `https://api-all.sunip.cc/api/v1`

### 2.1 设备 Agent API (公开, 无需登录)

| 方法 | 路由 | 认证 | 说明 |
|------|------|------|------|
| GET | `/router-install/{token}` | token | 下载安装脚本 (注入 env 变量) |
| POST | `/router-agent/register` | install_token | 首次注册设备 |
| POST | `/router-agent/heartbeat` | X-Agent-Key | 心跳上报 |
| GET | `/router-agent/config` | X-Agent-Key | 拉取完整配置 |
| POST | `/router-agent/ack-config` | X-Agent-Key | 确认配置已应用 |
| POST | `/router-agent/event` | X-Agent-Key | 上报事件日志 |
| GET | `/router-downloads/{filename}` | — | 下载二进制文件 |

**POST /router-agent/register**
```json
{
  "install_token": "string (必填)",
  "serial_number": "string (必填, ≤100)",
  "wg_public_key_1": "string (必填, ≤64)",
  "wg_public_key_2": "string (必填, ≤64)",
  "hostname": "string (可选)",
  "agent_version": "string (可选)"
}
// 返回
{
  "device_id": 42,
  "agent_key": "rtr_abc123...",
  "wg_configs": [{ "interface": "wg0", "assigned_ip": "10.10.0.5/32", ... }]
}
```

**POST /router-agent/heartbeat** (Header: `X-Agent-Key`)
```json
{
  "applied_config_version": "int (可选)",
  "system_info": "object (可选, {cpu_temp, mem_used_mb, mem_total_mb, disk_used_mb, uptime_seconds})",
  "agent_version": "string (可选)",
  "wan_ip": "string (可选)"
}
// 返回
{
  "device_id": 42,
  "config_version": 5,
  "has_pending_config": true,
  "server_time": "2026-05-26T..."
}
```

**POST /router-agent/ack-config** (Header: `X-Agent-Key`)
```json
{ "config_version": 5 }
```

**POST /router-agent/event** (Header: `X-Agent-Key`)
```json
{
  "event_type": "string (必填, ≤50)",
  "severity": "info|warning|error (默认 info)",
  "message": "string (可选, ≤1000)",
  "metadata": "object (可选)"
}
```

**允许下载的文件**: `sunipip-router-agent-linux-amd64`, `router-frontend-dist.tar.gz`

### 2.2 管理后台 API (Sanctum + 权限)

| 方法 | 路由 | 权限 | 说明 |
|------|------|------|------|
| GET | `/router-devices/stats` | router.view | 设备统计 |
| GET | `/router-devices` | router.view | 设备列表 (分页) |
| POST | `/router-devices` | router.create | 添加设备 |
| GET | `/router-devices/{id}` | router.view | 设备详情 |
| PUT | `/router-devices/{id}` | router.edit | 编辑设备 |
| DELETE | `/router-devices/{id}` | router.delete | 停用设备 |
| POST | `/router-devices/{id}/install-token` | router.edit | 生成安装令牌 |
| POST | `/router-devices/{id}/bind` | router.bind | 绑定客户 |
| POST | `/router-devices/{id}/unbind` | router.bind | 解绑客户 |
| POST | `/router-devices/{id}/push-config` | router.edit | 推送配置 |
| POST | `/router-devices/{id}/reboot` | router.edit | 远程重启设备 |
| POST | `/router-devices/{id}/restart-service` | router.edit | 远程重启服务 |
| GET | `/router-devices/{id}/events` | router.view | 事件日志 (分页) |
| GET | `/router-devices/{id}/wifi-accounts` | router.view | WiFi 账号列表 |
| POST | `/router-devices/{id}/wifi-accounts` | router.edit | 创建 WiFi 账号 |
| PUT | `/router-wifi-accounts/{id}` | router.edit | 更新 WiFi 账号 |
| DELETE | `/router-wifi-accounts/{id}` | router.edit | 删除 WiFi 账号 |

**POST /router-devices**
```json
{
  "serial_number": "string (可选, ≤100, unique. 留空自动生成 PENDING-xxx)",
  "hostname": "string (可选)",
  "remark": "string (可选)"
}
```

**PUT /router-devices/{id}**
```json
{
  "remark": "string (可选)",
  "ap_management_enabled": "boolean (可选)",
  "ap_ip": "string (可选)"
}
```

**POST /router-devices/{id}/bind**
```json
{
  "customer_id": "int (必填)",
  "module": "video|live_mobile|live_pc (必填)"
}
```

**POST /router-devices/{id}/restart-service**
```json
{ "service": "clash|freeradius|dnsmasq|sunipip-router-agent (必填)" }
```

**POST /router-devices/{id}/wifi-accounts**
```json
{
  "username": "string (可选, 留空自动生成)",
  "password": "string (可选, 留空自动生成)",
  "label": "string (可选)",
  "proxy_subscription_id": "int (可选)",
  "proxy_mode": "proxy|direct (默认 proxy)",
  "max_devices": "int (可选, 1-50, 默认 10)"
}
```

**PUT /router-wifi-accounts/{id}**
```json
{
  "username": "string (可选)",
  "password": "string (可选)",
  "label": "string (可选)",
  "proxy_subscription_id": "int (可选)",
  "proxy_mode": "proxy|direct (可选)",
  "is_active": "boolean (可选)",
  "max_devices": "int (可选, 1-50)"
}
```

**GET /router-devices 查询参数**

| 参数 | 说明 |
|------|------|
| `filter[status]` | inventory / provisioned / online / offline / decommissioned |
| `filter[customer_id]` | 按客户筛选 |
| `filter[bound_module]` | video / live_mobile / live_pc |
| `filter[search]` | 模糊搜索: serial_number / hostname / remark |
| `filter[online]` | true=在线 / false=离线 |
| `sort` | id / serial_number / status / last_heartbeat_at / created_at (前缀 - 降序) |
| `per_page` | 每页条数 (默认 15) |

#### WG 服务器管理

| 方法 | 路由 | 权限 |
|------|------|------|
| GET | `/wg-servers` | router.wg_manage |
| POST | `/wg-servers` | router.wg_manage |
| GET | `/wg-servers/{id}` | router.wg_manage |
| PUT | `/wg-servers/{id}` | router.wg_manage |
| DELETE | `/wg-servers/{id}` | router.wg_manage |
| GET | `/wg-servers/{id}/config` | router.wg_manage |
| POST | `/wg-servers/{id}/sync-peers` | router.wg_manage |
| POST | `/wg-servers/{id}/deploy-peer` | router.wg_manage |

### 2.3 客户端 API (Sanctum, customer auth)

| 方法 | 路由 | 中间件 | 说明 |
|------|------|--------|------|
| GET | `/router/devices` | auth | 我的设备列表 |
| GET | `/router/devices/{id}` | auth | 设备详情 |
| POST | `/router/activate` | customer.verified | 激活设备 |
| GET | `/router/devices/{id}/wifi-accounts` | auth | WiFi 账号列表 |
| POST | `/router/devices/{id}/wifi-accounts` | customer.verified | 创建 WiFi 账号 |
| PUT | `/router/wifi-accounts/{id}` | customer.verified | 更新 WiFi 账号 |
| DELETE | `/router/wifi-accounts/{id}` | customer.verified | 删除 WiFi 账号 |
| GET | `/router/devices/{id}/available-subscriptions` | auth | 可绑定的订阅 |
| GET | `/router/devices/{id}/status` | auth | 设备实时状态 |
| GET | `/router/wifi-accounts/{id}/ios-profile` | auth | 下载 iOS .mobileconfig |

**POST /router/activate**
```json
{
  "serial_number": "string (必填)",
  "module": "video|live_mobile|live_pc (必填)"
}
```

---

## 三、权限

| 权限 | 说明 | 角色 |
|------|------|------|
| router.view | 查看设备/事件 | super_admin, tech_admin, ops_admin, admin, manager |
| router.create | 添加设备 | super_admin, tech_admin, ops_admin, admin |
| router.edit | 编辑/推送配置/远程操作 | super_admin, tech_admin, ops_admin, admin |
| router.delete | 停用设备 | super_admin, tech_admin, ops_admin, admin |
| router.bind | 绑定/解绑客户 | super_admin, tech_admin, ops_admin, admin, manager |
| router.wg_manage | WG 服务器管理 | super_admin, tech_admin, ops_admin, admin |

依赖: create/edit/delete/wg_manage → view, bind → view + customer.view

---

## 四、设备状态机

```
inventory → provisioned → online ⇄ offline → decommissioned
```

| 状态 | 触发 |
|------|------|
| inventory | 管理员入库 (createDevice) |
| provisioned | Agent 首次注册 (register) |
| online | 收到心跳 (heartbeat) |
| offline | 心跳超时 >5分钟 (定时任务 router:check-online) |
| decommissioned | 管理员停用 (decommission) |

---

## 五、配置同步协议

```
WiFi/订阅变更 → pushConfig() → config_version++ → 保存 snapshot
          ↓
Agent heartbeat (60s) → has_pending_config=true → GET /config → Apply → POST /ack-config
```

| 触发点 | 操作 |
|--------|------|
| WiFi 账号 CRUD | bump + snapshot |
| 订阅绑定/解绑 | bump + snapshot |
| 设备绑定/解绑/设置变更 | bump + snapshot |
| 管理员推送配置 | 强制 regenerate + bump |
| 订阅过期/取消 | Subscription Observer → 自动 pushConfig |

### 配置 JSON 结构

```json
{
  "config_version": 5,
  "generated_at": "2026-05-26T...",
  "device": { "id", "serial_number", "hostname", "bound_module", "ap_management_enabled" },
  "network": {
    "wan": { "interface": "eth0", "mode": "dhcp" },
    "management": { "interface": "eth1", "ip": "172.10.0.1/24", "dhcp": {...} },
    "wired": { "interface": "eth3", "ip": "192.168.1.1/24", "dhcp": {...} },
    "trunk": { "interface": "eth2" },
    "vlans": [{ "vlan_id", "interface", "bridge", "ip", "dhcp": { "range_start", "range_end", "lease", "gateway", "dns" } }]
  },
  "freeradius": {
    "clients": [{ "name", "ip", "secret" }],
    "users": [{ "username", "password", "vlan_id", "label", "max_devices" }]
  },
  "clash": {
    "proxies": [{ "name", "type": "socks5", "server", "port", "username", "password" }],
    "rules": [{ "type", "value", "proxy" }]
  },
  "wireguard": {
    "peers": [{ "interface", "private_key", "address", "mtu", "table": "off", "peer": { "public_key", "endpoint", "allowed_ips", "persistent_keepalive" } }]
  }
}
```

---

## 六、Go Agent 服务管理

| 服务 | 配置文件 | 重载方式 |
|------|---------|---------|
| FreeRadius | /etc/freeradius/3.0/mods-config/files/authorize | systemctl reload freeradius |
| Clash | /etc/clash/config.yaml | REST API localhost:9090 或 restart |
| WireGuard | /etc/wireguard/wg{0,1}.conf | wg syncconf |
| dnsmasq | /etc/dnsmasq.d/vlan-*.conf | systemctl reload dnsmasq |
| nftables | /etc/nftables.conf | nft -f /etc/nftables.conf |

Agent 配置: `/etc/sunipip/agent.json`
```json
{
  "device_id": 42,
  "agent_key": "rtr_abc123...",
  "platform_url": "https://api-all.sunip.cc",
  "heartbeat_interval_seconds": 60,
  "config_poll_interval_seconds": 30,
  "local_api_listen": "172.10.0.1:8080",
  "serial_number": "ABC123XYZ"
}
```

### TProxy 流量劫持

nftables mangle 表将 VLAN 子网流量 TProxy 到 Clash 7893:
- TCP/UDP 标记 fwmark 0x1 → ip rule table 100 → local default dev lo
- DNS (port 53) 从 br-vlan* 重定向到 Clash 1053

---

## 七、数据库表

| 表 | 说明 |
|----|------|
| wg_servers | WG 服务器 (endpoint, 密钥, CIDR, IP分配) |
| router_devices | 设备 (序列号, 状态, 客户绑定, WG隧道, 配置版本) |
| router_wifi_accounts | WiFi 账号 (用户名/密码, VLAN, 代理订阅) |
| router_device_wg_peers | 设备 WG Peer (公钥, 分配IP, 关联服务器) |
| router_config_snapshots | 配置快照 (版本号, 完整 JSON payload) |
| router_event_logs | 事件日志 (类型, 级别, 消息) |

### 关键约束

- `router_devices.serial_number` UNIQUE
- `router_devices.agent_key` UNIQUE
- `(router_device_id, username)` UNIQUE on router_wifi_accounts
- `(router_device_id, vlan_id)` UNIQUE on router_wifi_accounts
- `(router_device_id, wg_server_id)` UNIQUE on router_device_wg_peers
- `(router_device_id, config_version)` UNIQUE on router_config_snapshots

---

## 八、常量与枚举

### 设备状态
`inventory` | `provisioned` | `online` | `offline` | `decommissioned`

### 模块类型
`video` (视频专线) | `live_mobile` (直播手机) | `live_pc` (直播电脑)

### 模块→订阅产品类型映射
```
video → video_dedicated, video_shared
live_mobile → live_mobile_dedicated, live_mobile_shared
live_pc → live_pc_dedicated, live_pc_shared
```

### 代理模式
`proxy` (走SOCKS5) | `direct` (直连不代理)

### 事件类型
`register` | `heartbeat` | `config_applied` | `config_failed` | `bind` | `unbind` | `wifi_change` | `service_restart` | `remote_reboot` | `remote_restart_service` | `device_offline` | `decommission`

### 事件级别
`info` | `warning` | `error`

### 可远程重启的服务
`clash` | `freeradius` | `dnsmasq` | `sunipip-router-agent`

### VLAN 参数
- 范围: 10-200
- 子网: /29 (6 可用 IP)
- IP 模板: `10.10.{vlan_id}.0/29`, 网关 `10.10.{vlan_id}.1`
- DHCP 范围: `.2` - `.6`

### WiFi 配置
- SSID: `SuniPIP-Proxy`
- 安全: WPA2-Enterprise
- EAP: TTLS / PAP
- FreeRadius 共享密钥: `sunipip_radius_secret`
- max_devices 默认: 10, 范围 1-50

### 安装令牌
- 长度: 64 字节 hex (128 字符)
- 有效期: 72 小时
- 一次性消费 (注册后置 null)

### Agent Key
- 格式: `rtr_` + 64 字节 hex
- 持久有效, 通过 `X-Agent-Key` header 认证

### 心跳超时
- 5 分钟无心跳 → 标记 offline
- 检测频率: 每分钟 (router:check-online command)

---

## 九、前端页面

### 管理后台 (admin-manager.sunipip.com)

| 页面 | 路径 | 功能 |
|------|------|------|
| RouterDevices.vue | /settings/router-devices | 设备列表+统计+筛选+CRUD |
| RouterDeviceDetail.vue | /settings/router-devices/:id | 4 Tab: 概览/WiFi/WG/事件 + 远程操作 |
| WgServers.vue | /settings/wg-servers | WG 服务器 CRUD + 配置下载 |

### 客户端 (user.sunipip.com)

| 页面 | 路径 | 功能 |
|------|------|------|
| Devices.vue | /router | 我的设备列表 (卡片) |
| ActivateDevice.vue | /router/activate | 激活向导: 序列号→模块→确认 |
| DeviceDetail.vue | /router/:id | WiFi 管理 + 连接指南 + iOS描述文件下载 |

---

## 十、安装部署流程

### 管理员操作
1. 管理后台 → 添加设备 (可选序列号)
2. 点击「生成安装令牌」→ 获得 URL
3. 在裸机 Debian 12 上执行: `curl -fsSL {url} | bash`

### install.sh 流程
1. 检查 4 网卡 + 读取 board_serial
2. 安装: freeradius, wireguard-tools, clash, dnsmasq, nftables, nginx
3. 生成 2 组 WG 密钥
4. POST /router-agent/register → 获取 agent_key + WG 配置
5. 配置网络接口 + 服务
6. 下载 Agent 二进制 + 前端 SPA
7. 配置 Nginx + systemd, 启动所有服务

### 服务器文件准备
```bash
# 编译 Agent
cd sunipip-router-agent && GOOS=linux GOARCH=amd64 go build -o sunipip-router-agent-linux-amd64 cmd/agent/main.go
# 上传到服务器
scp sunipip-router-agent-linux-amd64 root@47.84.199.147:/www/wwwroot/api-all.sunip.cc/storage/app/router-downloads/

# 编译前端
cd frontend-router && npm run build
tar -czf router-frontend-dist.tar.gz -C dist .
scp router-frontend-dist.tar.gz root@47.84.199.147:/www/wwwroot/api-all.sunip.cc/storage/app/router-downloads/
```

---

## 十一、远程管理

通过 WG 隧道 SSH 到设备:
- 从 `wg_ip_1` 取设备 WG IP (去 /32 后缀)
- 使用 WG 服务器的 SSH 私钥
- `ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -i {key} root@{ip} {command}`

支持操作:
- 远程重启: `reboot`
- 远程重启服务: `systemctl restart {service}`

---

## 十二、文件清单

### Backend
- `Models/`: WgServer, RouterDevice, RouterWifiAccount, RouterDeviceWgPeer, RouterConfigSnapshot, RouterEventLog
- `Services/Router/`: RouterConfigService, RouterProvisionService, RouterWifiService, WgServerService
- `Controllers/Api/V1/`: RouterDeviceController, RouterAgentController, WgServerController
- `Controllers/Api/V1/Customer/`: RouterController
- `Console/Commands/`: CheckRouterDevicesOnline
- `database/migrations/`: 000111-000117

### Frontend (Admin)
- `views/settings/`: RouterDevices.vue, RouterDeviceDetail.vue, WgServers.vue
- `api/`: routerDevices.js, wgServers.js

### Frontend (Customer)
- `views/router/`: Devices.vue, ActivateDevice.vue, DeviceDetail.vue
- `api/`: router.js

### Go Agent
- `sunipip-router-agent/`: cmd/agent, internal/{config,api,services,manager,health,localapi}

### Scripts
- `scripts/install.sh`: 设备一键安装
- `scripts/ap-config.sh`: OpenWrt AP 配置
