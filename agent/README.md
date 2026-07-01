# SuniPIP DNS 容灾 Agent

部署在中国大陆 VPS 上的探测 Agent，定时访问 `admin.sunipip.uk` 拉取待探测节点，
对 vless+reality 节点做 TLS 握手检测，失败后由后端自动触发 Cloudflare DNS 切换。

## 编译

```bash
cd agent
# 本机编译
go build -ldflags="-s -w" -o sunipip-agent

# 交叉编译给 Linux x86_64 VPS
GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -o sunipip-agent-linux-amd64

# ARM VPS
GOOS=linux GOARCH=arm64 go build -ldflags="-s -w" -o sunipip-agent-linux-arm64
```

单二进制约 7 MB，无任何外部依赖。

## 部署到中国大陆 VPS

```bash
# 1. 上传二进制
scp sunipip-agent-linux-amd64 root@cn-vps:/usr/local/bin/sunipip-agent
ssh root@cn-vps 'chmod +x /usr/local/bin/sunipip-agent'

# 2. 在 admin 面板 /settings/dns-monitor 创建 Agent，复制 agent_key
```

## systemd 服务

创建 `/etc/systemd/system/sunipip-agent.service`：

```ini
[Unit]
Description=SuniPIP DNS Agent
After=network.target

[Service]
Type=simple
Environment="SUNIPIP_ADMIN_URL=https://admin.sunipip.uk"
Environment="SUNIPIP_AGENT_KEY=YOUR_KEY_HERE"
Environment="SUNIPIP_INTERVAL=20m"
ExecStart=/usr/local/bin/sunipip-agent
Restart=always
RestartSec=10
User=nobody

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now sunipip-agent
systemctl status sunipip-agent
journalctl -u sunipip-agent -f
```

## 探测原理

Agent 对每个节点执行：

1. **DNS 解析** — 确认域名能解析到 IP
2. **TCP 连接** — 连 host:port（默认 8 秒超时）
3. **TLS ClientHello** — 发送 SNI=`www.intel.com` 的握手（Reality 的标准伪装域名）

判定规则：

| 情况 | 判定 |
|---|---|
| TCP 连不上 | ❌ 失败 |
| TLS "connection reset by peer" 在 ClientHello 后 | ❌ 失败（GFW 特征） |
| TLS handshake timeout | ❌ 失败 |
| TLS 其他错误（bad cert / protocol mismatch 等） | ✅ 成功（对方 ServerHello 能发出来即算通畅） |
| TLS 握手完整成功 | ✅ 成功 |

连续 `failure_threshold` 次（后台可配，默认 3）失败后，admin 后端自动切换 CF DNS 到备机。

## 命令行参数

```
Usage: sunipip-agent [flags]

-url          Admin panel URL (env SUNIPIP_ADMIN_URL, default https://admin.sunipip.uk)
-key          Agent key (env SUNIPIP_AGENT_KEY, required)
-interval     Heartbeat interval (env SUNIPIP_INTERVAL, default 20m)
-jitter       Random jitter seconds (default 60)
-http-timeout HTTP request timeout (default 20s)
-v            Verbose logging
```

## 故障排查

**Agent 一直探测失败但节点实际正常**：
- 检查 CN VPS 到境外 443 的基础连通：`curl -v https://www.intel.com`
- VPS 本身如果已经被墙某些境外 IP，Agent 会误报

**Heartbeat 401 Unauthorized**：
- 检查 `X-Agent-Key` 是否正确
- admin 面板 Agent 管理页重置 key

**看不到 agent 心跳**：
- 确认 admin 面板 DNS Agent 列表里该 agent 的 `last_heartbeat_at` 是否在更新
- `journalctl -u sunipip-agent -f` 查看日志
