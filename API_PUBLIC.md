# SuniPIP 开放 API 文档

对外开放的商店数据 API，面向分销商与合作方。所有接口需通过 `X-API-Key` 认证，价格支持按 Key 独立加成（markup）。

- **Base URL**：`https://admin.sunipip.uk/api/public/v1`
- **认证方式**：Header `X-API-Key`（必填），可选 HMAC 签名
- **返回格式**：JSON（UTF-8）
- **统一响应结构**：
  ```json
  { "code": 0, "message": "ok", "data": { ... } }
  ```

---

## 1. 认证

### 1.1 API Key

每个 API Key 由平台后台统一签发（`https://admin.sunipip.uk/settings/api-keys`），包含：

| 字段 | 说明 |
|------|------|
| `key` | 公开密钥，格式 `sk_xxxxxxxxxxxxxxxx`，请求时放到 `X-API-Key` |
| `secret` | 私密密钥，仅用于 HMAC 签名；**创建/重置时仅返回一次** |
| `scopes` | 权限范围（如 `store.products`、`store.stock`），空数组=全部允许 |
| `price_markup` | 价格倍率（0.1 ~ 10），对客售价 × 倍率 = 最终显示价 |
| `rate_limit` | 每分钟请求上限（默认 60） |
| `expires_at` | 过期时间（可选） |

### 1.2 调用方式

**方式一：Header（推荐）**

```
GET /api/public/v1/products HTTP/1.1
Host: admin.sunipip.uk
X-API-Key: sk_xxxxxxxxxxxxxxxx
```

**方式二：Query（仅用于测试）**

```
GET /api/public/v1/products?api_key=sk_xxxxxxxxxxxxxxxx
```

### 1.3 可选 HMAC 签名（更安全）

若希望防止 Key 泄露后被滥用，可额外加上时间戳与签名。签名方案：

```
signature = HMAC-SHA256(secret, timestamp + path)
```

- `path` 为请求路径，不含域名与 query，例如 `api/public/v1/products`
- `timestamp` 为 UNIX 秒级时间戳；服务端允许前后 5 分钟误差

Header 示例：

```
X-API-Key:       sk_xxxxxxxxxxxxxxxx
X-API-Timestamp: 1713340800
X-API-Signature: 7d3a...
```

### 1.4 认证错误码

| HTTP | message | 说明 |
|------|---------|------|
| 401 | 缺少 API Key | 未传 `X-API-Key` |
| 401 | API Key 无效 | Key 不存在 |
| 401 | 请求时间戳无效或超过 5 分钟 | 时间戳缺失或漂移过大 |
| 401 | 签名不匹配 | HMAC 校验失败 |
| 403 | API Key 已禁用 | Key 的 `is_active=false` |
| 403 | API Key 已过期 | 已过 `expires_at` |
| 403 | 无 {scope} 权限 | 该 Key 未授予对应 scope |
| 429 | 请求过于频繁，请 X 秒后再试 | 超出 `rate_limit` |

---

## 2. 接口列表

### 2.1 产品列表

**GET** `/api/public/v1/products`

返回所有产品详细列表（按产品粒度，未聚合）。价格已按当前 Key 的 `price_markup` 加成。

- **所需 scope**：`store.products`
- **参数**：无

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "total": 120,
    "products": [
      {
        "product_id": "usa_native_isp",
        "country_code": "USA",
        "country_name": "美国",
        "country_en": "United States",
        "continent": "北美洲",
        "isp_type": 3,
        "isp_label": "原生ISP",
        "net_type": 1,
        "net_label": "native",
        "monthly_price": 110.00,
        "currency": "CNY",
        "stock": 58,
        "in_stock": true
      }
    ],
    "updated_at": "2026-04-17 14:30:00"
  }
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `product_id` | string | Spark 上游产品 ID |
| `country_code` | string | ISO 3 位国家代码（如 USA / HKG / TWN） |
| `country_name` | string | 中文国家名 |
| `country_en` | string | 英文国家名 |
| `continent` | string | 所属大洲（北美洲 / 欧洲 / 亚洲 / 东南亚 / 中东 / 南美洲 / 大洋洲 / 非洲） |
| `isp_type` | int | ISP 类型 ID |
| `isp_label` | string | ISP 类型文本：`单ISP` / `双ISP` / `原生ISP` / `机房` |
| `net_type` | int | 网络类型 ID |
| `net_label` | string | 网络类型文本：`native`（原生） / `broadcast`（广播） |
| `monthly_price` | number | 月单价（人民币，已含 markup） |
| `currency` | string | 固定 `CNY` |
| `stock` | int | 当前可用库存数量 |
| `in_stock` | bool | 是否有货 |
| `updated_at` | string | 库存最后刷新时间（每 5 分钟同步） |

---

### 2.2 按国家聚合库存

**GET** `/api/public/v1/stock-by-country`

按国家维度聚合库存与价格区间，适合首页国家卡片展示。

- **所需 scope**：`store.stock`
- **参数**：无

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "total": 35,
    "countries": [
      {
        "country_code": "USA",
        "country_name": "美国",
        "country_en": "United States",
        "continent": "北美洲",
        "stock": 158,
        "min_price": 80.00,
        "max_price": 130.00
      }
    ],
    "updated_at": "2026-04-17 14:30:00"
  }
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `country_code` | string | ISO 3 位国家代码 |
| `country_name` | string | 中文国家名 |
| `country_en` | string | 英文国家名 |
| `continent` | string | 所属大洲 |
| `stock` | int | 该国家所有产品库存之和 |
| `min_price` | number | 该国家最低月单价（含 markup） |
| `max_price` | number | 该国家最高月单价（含 markup） |

---

### 2.3 VIP 等级表

**GET** `/api/public/v1/vip-tiers`

返回平台所有启用中的 VIP 等级（按 `sort_order` 升序），供分销商展示"充值送折扣"。

- **所需 scope**：`vip.tiers`
- **参数**：无

**响应示例**：

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "total": 3,
    "currency": "CNY",
    "tiers": [
      {
        "id": 1,
        "name": "银卡",
        "spending_threshold": 5000,
        "topup_threshold": 3000,
        "discount_percent": 95,
        "save_percent": 5,
        "badge_color": "#B0BEC5",
        "description": "新手入门，享 95 折",
        "sort_order": 1
      },
      {
        "id": 2,
        "name": "金卡",
        "spending_threshold": 20000,
        "topup_threshold": 10000,
        "discount_percent": 85,
        "save_percent": 15,
        "badge_color": "#FFC107",
        "description": "热门选择，购 IP 立省 15%",
        "sort_order": 2
      }
    ]
  }
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 等级 ID |
| `name` | string | 等级名称（如 银卡 / 金卡 / 钻石） |
| `spending_threshold` | number | 累计消费门槛（人民币） |
| `topup_threshold` | number \| null | 单笔充值门槛；为 null 表示不支持此维度 |
| `discount_percent` | int | 折扣率（如 85 = 85 折） |
| `save_percent` | int | 节省百分比（= 100 - discount_percent） |
| `badge_color` | string | 徽章颜色 CSS 值 |
| `description` | string | 等级文案 |
| `sort_order` | int | 等级顺序（数字越大等级越高） |

> **说明**：达成条件为"满足任一"—— `spending_threshold`（累计消费）或 `topup_threshold`（单笔充值）任一条件达到即升级。

---

## 3. 权限范围（Scopes）

| Scope | 允许调用的接口 |
|-------|----------------|
| `store.products` | `GET /products` |
| `store.stock` | `GET /stock-by-country` |
| `vip.tiers` | `GET /vip-tiers` |

> **注意**：创建 Key 时若 `scopes` 为空数组，则默认允许所有接口。生产环境建议按最小权限原则明确授予。

---

## 4. 限流与使用统计

- **限流**：按 Key 维度每分钟 `rate_limit` 次，使用滑动窗口；超限返回 `429`
- **使用记录**：每次请求会自动记录
  - `request_count` +1
  - `last_used_at` 更新为当前时间
  - `last_used_ip` 更新为调用方 IP

运营方可在管理后台实时查看调用量与最近一次 IP，用于异常检测。

---

## 5. 示例代码

### 5.1 cURL

```bash
curl -H "X-API-Key: sk_xxxxxxxxxxxxxxxx" \
  https://admin.sunipip.uk/api/public/v1/products
```

### 5.2 Node.js（带 HMAC 签名）

```javascript
const crypto = require('crypto')
const axios = require('axios')

const KEY    = 'sk_xxxxxxxxxxxxxxxx'
const SECRET = 'your_secret_here'
const path   = 'api/public/v1/products'
const ts     = Math.floor(Date.now() / 1000).toString()
const sig    = crypto.createHmac('sha256', SECRET).update(ts + path).digest('hex')

const { data } = await axios.get(`https://admin.sunipip.uk/${path}`, {
  headers: {
    'X-API-Key':       KEY,
    'X-API-Timestamp': ts,
    'X-API-Signature': sig,
  },
})
console.log(data)
```

### 5.3 PHP

```php
<?php
$key    = 'sk_xxxxxxxxxxxxxxxx';
$secret = 'your_secret_here';
$path   = 'api/public/v1/products';
$ts     = (string) time();
$sig    = hash_hmac('sha256', $ts . $path, $secret);

$ch = curl_init("https://admin.sunipip.uk/$path");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: $key",
    "X-API-Timestamp: $ts",
    "X-API-Signature: $sig",
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
curl_close($ch);

echo $resp;
```

### 5.4 Python

```python
import hashlib, hmac, time, requests

KEY    = 'sk_xxxxxxxxxxxxxxxx'
SECRET = 'your_secret_here'
path   = 'api/public/v1/products'
ts     = str(int(time.time()))
sig    = hmac.new(SECRET.encode(), (ts + path).encode(), hashlib.sha256).hexdigest()

r = requests.get(f'https://admin.sunipip.uk/{path}', headers={
    'X-API-Key':       KEY,
    'X-API-Timestamp': ts,
    'X-API-Signature': sig,
})
print(r.json())
```

---

## 6. 价格加成（Markup）机制

平台统一对客单价 × 该 Key 的 `price_markup` = 最终返回价格。

| 对客单价 | price_markup | 返回价格 |
|----------|--------------|----------|
| ¥100 | 1.00 | ¥100.00 |
| ¥100 | 1.30 | ¥130.00 |
| ¥100 | 0.90 | ¥90.00（罕见，内部合作时使用） |

分销商可基于此价格直接展示给自己的终端用户。

---

## 7. 常见问题

**Q: Secret 忘记了怎么办？**  
在后台「重置 Secret」，仅在重置时返回一次，原 Secret 立即失效。

**Q: 为什么 `stock` 数字会变？**  
库存来自上游 Spark API，每 5 分钟同步一次。`updated_at` 字段可见最新同步时间。

**Q: 某国家某产品在列表中看不到？**  
- 该产品未配置对客售价（`PricingMultiplier` 未设置）
- 当前 Key 的 `scopes` 不包含对应权限

**Q: 需要下单接口吗？**  
当前开放 API 仅返回商店数据（只读）。下单走客户自助面板或管理后台，如需分销商直连下单，请联系运营单独开通。

---

## 8. 联系方式

如需申请 API Key、调整限流或增加权限范围，请通过管理后台联系运营团队。
