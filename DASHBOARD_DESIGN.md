# 数据看板设计方案

## 菜单结构

```
管理后台侧边栏
└── 数据看板（权限组控制）
    ├── 营销数据
    ├── 价格数据
    └── 产品数据
```

权限：加到权限组，由管理员在后台分配。

---

## 全局交互规则

### 时间筛选器
- 页面顶部固定，快捷按钮：7天 / 30天 / 90天 / 180天 / 360天
- 只对"存量指标"生效，实时指标不受影响
- 含义：该时段内**新增/新发生**的数据

### 用户卡片展示（第四分支 CRM 要求）
- 每个指标旁显示符合条件的用户列表
- **紧凑模式**：一行一个用户，显示 名字 + 手机号
- **展开详情**：点击某用户 → 弹出小窗口，显示：
  - 客户基本信息（名字、手机、邮箱、公司、注册时间、VIP等级）
  - 余额 + 累计消费
  - 订阅列表（IP、状态、到期时间、价格）
  - 最近交易记录
- 不点开就是纯看板，数字 + 用户简列

---

## 页面 1：营销数据 (`/analytics/marketing`)

### 实时区（不受时间筛选）

| 指标 | 数据源 | 查询方式 |
|------|--------|----------|
| 今日访问量 | `page_views` 表 | `WHERE DATE(created_at) = today` 计数 |
| 今日已登录人数 | `page_views` 表 | `WHERE customer_id IS NOT NULL AND DATE(created_at) = today` 去重 customer_id |
| 实时在线人数 | `page_views` 表 | `WHERE created_at >= now() - 5min` 去重 customer_id（含未登录则去重 ip） |

### 存量指标（受时间筛选）

| 指标 | 查询 | 下钻用户列表 |
|------|------|-------------|
| 注册总量 | `customers WHERE created_at BETWEEN (now-Xd, now)` | 该时段注册的客户 |
| 实名认证总量 | `customers WHERE verified_at IS NOT NULL AND verified_at BETWEEN ...` | 已认证客户 |
| 已购买用户总量 | 有 subscription 的客户，按首笔订阅 started_at 筛选 | 有购买记录的客户 |
| 访问未注册用户总量 | `page_views WHERE customer_id IS NULL` 去重 ip，按 created_at 筛选 | 仅显示 IP 地址列表（无用户信息） |
| 注册未购买用户总量 | `customers` 不存在任何 subscription，按 created_at 筛选 | 注册了但没买过的客户 |
| 购买后连续三月未购买 | 最后一笔 subscription.started_at 或 last_renewed_at 在 90 天前的客户 | 流失客户列表 |

---

## 页面 2：价格数据 (`/analytics/pricing`)

### 存量指标（受时间筛选 = 该时段有消费记录的客户中各层分布）

折扣率定义：取客户的**最优折扣**，优先级 = min(VIP折扣, 特批折扣)。
- VIP折扣：`vip_tiers.discount_percent`（客户当前等级）
- 特批折扣：`customer_special_prices.discount_percent_static`

| 指标 | 条件 | 说明 |
|------|------|------|
| 原价用户 | 无特批价 且 无VIP（或VIP折扣=100） | 全价购买 |
| 总代理价用户 | 有任何折扣（特批 OR VIP < 100） | 非原价的并集 |
| 7折用户 | 最优折扣 = 70 | |
| 6折用户 | 最优折扣 = 60 | |
| 5折用户 | 最优折扣 = 50 | |
| 5折内用户 | 最优折扣 < 50 | 4折、3折等 |

每张卡下方展示用户列表 + CRM 弹窗。

### CRM 额外筛选（来自第四分支）

用户列表支持按累计消费金额筛选：
- 已消费金额 > 10万
- 已消费金额 > 50万
- 已消费金额 > 100万

这些作为列表上方的快捷筛选 tag。

---

## 页面 3：产品数据 (`/analytics/products`)

### 实时快照（不受时间筛选）

| 指标 | 查询 |
|------|------|
| 全部 IP 总量 | `proxy_ips` 非软删除，所有状态 |
| 单 IP 总量 | `proxy_ips WHERE status = 'assigned'` 且对应 subscription 无 forward_rule |
| 视频专线总量 | `proxy_ips` 关联 subscription.forward_rule.forward_plan.module = 'video' |
| 直播专线总量 | 同上，module IN ('live_mobile', 'live_pc') |
| 各地区在线 IP | `proxy_ips WHERE status = 'assigned'` GROUP BY country_name |

### 存量指标（受时间筛选）

| 指标 | 查询 |
|------|------|
| 连续续费三月在线 IP | subscription.renewed_count >= 3 且 status = 'active'，按最近续费时间筛选 |
| 过期 IP 总量 | `proxy_ips WHERE status = 'expired'`，按 upstream_expires_at 筛选 |
| 各地区过期 IP | 同上 GROUP BY country_name |
| 回收 IP 总量 | `proxy_ips WHERE status = 'expired' AND assigned_customer_id IS NULL`，按 updated_at 筛选 |
| 各地区回收 IP | 同上 GROUP BY country_name |

"各地区"指标用表格或柱状图展示，按 proxy_ips.country_name 分组。

---

## 技术实现清单

### 数据库

**新建迁移：`create_page_views_table`**

```
page_views
├── id (bigint PK)
├── customer_id (bigint nullable FK → customers) — 已登录则记录
├── ip_address (varchar 45) — 访客 IP
├── path (varchar 500) — 访问路径
├── created_at (timestamp)
```

索引：`(created_at)`, `(customer_id, created_at)`, `(ip_address, created_at)`

无 updated_at（只写不改），定期清理 > 360 天的数据。

### 后端

**客户前端埋点接口**
- `POST /api/v1/customer/track-visit` — 公开接口（无需登录），记录 path + ip + customer_id（如有token）
- 限流：同 IP 同 path 1分钟内最多记录 1 次（防刷）

**数据看板 API**（管理后台，需权限）
- `GET /api/v1/analytics/marketing?days=30` — 营销数据
- `GET /api/v1/analytics/pricing?days=30` — 价格数据
- `GET /api/v1/analytics/products?days=30` — 产品数据
- `GET /api/v1/analytics/customer-detail/{id}` — CRM 弹窗详情

每个接口返回：
```json
{
  "realtime": { ... },       // 实时指标（不受 days 影响）
  "metrics": [
    {
      "key": "registered_total",
      "label": "注册总量",
      "value": 1234,
      "customers": [          // 前 100 条，紧凑模式
        { "id": 1, "name": "轻语", "phone": "138xxxx" },
        ...
      ],
      "total_customers": 1234  // 总数（可能 > 100）
    },
    ...
  ]
}
```

**Controller**: `App\Http\Controllers\Api\V1\AnalyticsController`

### 前端（管理后台）

**新增文件**
- `frontend/src/views/analytics/Marketing.vue`
- `frontend/src/views/analytics/Pricing.vue`
- `frontend/src/views/analytics/Products.vue`
- `frontend/src/views/analytics/components/MetricCard.vue` — 可复用的指标卡组件
- `frontend/src/views/analytics/components/CustomerDetailDialog.vue` — CRM 弹窗
- `frontend/src/api/analytics.js`

**路由**
```js
{
  path: '/analytics',
  meta: { title: '数据看板' },
  children: [
    { path: 'marketing', component: Marketing, meta: { title: '营销数据' } },
    { path: 'pricing', component: Pricing, meta: { title: '价格数据' } },
    { path: 'products', component: Products, meta: { title: '产品数据' } },
  ]
}
```

**MetricCard 组件设计**
```
┌─────────────────────────────────┐
│  注册总量              1,234 人  │
├─────────────────────────────────┤
│  张三   138****1234             │
│  李四   139****5678             │
│  王五   137****9012             │
│  ... 查看全部 (1,234) →         │
└─────────────────────────────────┘
```

点击用户名 → 弹出 CustomerDetailDialog。

### 客户前端埋点

**`frontend-customer/src/router/index.js`** — afterEach 钩子

```js
router.afterEach((to) => {
  // 静默打点，不阻塞页面
  fetch('/api/v1/customer/track-visit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ path: to.path }),
    keepalive: true,
  }).catch(() => {})
})
```

带 token 时自动附带 Authorization header（已登录用户）；未登录也会打点（仅记录 IP）。

---

## UI 风格

- 与现有管理后台风格统一（Element Plus）
- 实时区用亮色/渐变卡片突出（类似 dashboard 统计卡）
- 存量指标用标准卡片网格布局
- 各地区数据用横向柱状图（ECharts）
- 时间筛选器用 el-radio-group 按钮样式
