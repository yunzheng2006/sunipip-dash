# SuniPIP 客户中心 (Customer Portal)

终端客户自助面板，与 `frontend/`（管理后台）并列的独立 Vue 3 项目。

- **部署域名**：`user.sunipip.uk`
- **构建产物**：`../backend/public-customer/`
- **API 前缀**：`/api/v1/customer/*`

## 开发

```bash
cd frontend-customer
npm install
npm run dev        # 本地 http://localhost:3001
```

Dev 模式下 `/api/*` 会被 vite 代理到 `http://localhost:8000`（Laravel dev 服务器）。

## 构建

```bash
npm run build
```

产物直接输出到 `backend/public-customer/`。

## 目录约定

```
src/
  api/              # 对应 /customer/* 后端接口
  stores/           # Pinia store（auth 等）
  router/           # Vue Router，未登录自动跳 /login
  utils/
    request.js      # axios 实例，自动带 token 和解包响应
    auth.js         # token 存取，key='sunipip_customer_token'
  views/
    auth/           # Login / Register
    dashboard/      # 首页
    store/          # (PR2) IP 商店
    ips/            # (PR2) 我的 IP 资产
    subscriptions/  # (PR2) 订阅管理
    billing/        # (PR3) 账单 + 充值
    profile/        # 账号设置
  components/
    layout/
      CustomerLayout.vue  # 带顶部导航的主布局
```

## 与 admin 面板的区别

| 项 | admin (`frontend/`) | customer (`frontend-customer/`) |
|---|---|---|
| Token key | `sunipip_token` | `sunipip_customer_token` |
| API baseURL | `/api/v1` | `/api/v1/customer` |
| 认证模型 | `User` (Spatie 权限) | `Customer` (`HasApiTokens`) |
| Sanctum ability | `admin` | `customer` |
| 布局 | 侧边菜单 | 顶部导航 + 余额显示 |

## 分阶段交付

- **PR1**（当前）：注册 / 登录 / 空 Dashboard
- **PR2**：商店 + 下单 + 我的 IP + 订阅管理
- **PR3**：余额 + Stripe 充值
