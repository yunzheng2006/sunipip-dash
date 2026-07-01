# SuniPIP 功能清单 & API 路由 & 权限矩阵 & 实现细节

> 最后更新: 2026-05-22

---

## 权限角色一览

| 角色 | 说明 | 权限范围 |
|------|------|----------|
| `super_admin` | 超级管理员 | 全部权限 |
| `tech_admin` | 技术管理员 | 除 `user.assign_role` 外全部 |
| `ops_admin` | 运营管理员 | 除 `user.assign_role`, `user.delete` 外全部 |
| `admin` | 管理员(旧兼容) | 除 `user.assign_role` 外全部 |
| `manager` | 经理 | 业务+审批+查看全局+业绩管理 |
| `staff` | 业务员 | 客户/IP/订阅日常操作+提交审批 |
| `sales` | 销售 | 最小权限，提交审批 |
| `agent` | 代理商 | 只看自己名下客户/订阅/IP |
| `user` | 客户 | 客户自助面板（独立Token体系） |

---

## 一、公开接口（无需认证）

| 路由 | 方法 | 说明 |
|------|------|------|
| `v1/auth/login` | POST | 管理后台登录 (限速 10/min) |
| `v1/spark/notify` | GET | Spark 回调 |
| `v1/ipipv/callback` | GET | IPIPV 回调 |
| `v1/upstream/{slug}/callback` | GET | 统一上游回调（按 slug 分发到 spark/ipipv） |
| `v1/customer/auth/register` | POST | 客户注册 (限速 5/10min) |
| `v1/customer/auth/login` | POST | 客户登录 (限速 10/min) |
| `v1/customer/auth/login-sms` | POST | 客户短信登录 |
| `v1/customer/sms/send-code` | POST | 发送短信验证码 |
| `v1/customer/sms/captcha` | GET | 获取图形验证码 |
| `v1/payment/epay/notify/{gateway}` | GET/POST | EPay 支付回调 |
| `v1/payment/alipay/notify/{gateway}` | GET/POST | Alipay 支付回调 |
| `v1/agent/heartbeat` | POST | DNS Agent 心跳 (X-Agent-Key) |
| `v1/agent/report` | POST | DNS Agent 上报 |
| `v1/site-info` | GET | 网站信息（站名+Logo） |
| `v1/customer/track-visit` | POST | 页面访问打点 (限速 60/min) |
| `v1/bigdata/dashboard` | GET | BigData 大屏看板 (自带鉴权) |
| `v1/oauth/token` | POST | OIDC/OAuth2 token endpoint |

### 公开 API（需 X-API-Key）

| 路由 | 方法 | 说明 | API Key 权限 |
|------|------|------|-------------|
| `public/v1/products` | GET | 产品列表 | `store.products` |
| `public/v1/stock-by-country` | GET | 按国家库存 | `store.stock` |
| `public/v1/vip-tiers` | GET | VIP等级列表 | `vip.tiers` |

---

## 二、客户自助面板（需 customer token）

中间件链: `auth:sanctum` → `ability:customer` → `customer.auth`

### 无需实名认证

| 路由 | 方法 | 说明 |
|------|------|------|
| `v1/customer/auth/me` | GET | 当前客户信息 |
| `v1/customer/auth/logout` | POST | 登出 |
| `v1/customer/auth/password` | PUT | 修改密码 |
| `v1/customer/profile` | GET/PUT | 个人资料 |
| `v1/customer/dashboard` | GET | 客户仪表盘 |
| `v1/customer/store/products` | GET | 商店产品列表 |
| `v1/customer/store/countries` | GET | 商店国家列表 |
| `v1/customer/store/countries/{code}` | GET | 指定国家产品 |
| `v1/customer/verification/status` | GET | 实名认证状态 |
| `v1/customer/verification/personal/*` | POST | 个人实名认证流程 |
| `v1/customer/verification/enterprise/*` | POST | 企业实名认证流程 |
| `v1/customer/verification/info` | GET | 实名信息 |
| `v1/customer/verification/upgrade-enterprise/*` | POST | 升级企业认证 |
| `v1/customer/forward-plans` | GET | 中转套餐预览 |
| `v1/customer/vip` | GET | VIP等级信息 |
| `v1/customer/subscriptions` | GET | 订阅列表 |
| `v1/customer/subscriptions/identify-ips` | POST | IP识别 |
| `v1/customer/subscriptions/{id}` | GET | 订阅详情 |
| `v1/customer/ips` | GET | 我的 IP 列表 |
| `v1/customer/ips/export` | GET | IP 导出 |
| `v1/customer/ips/export-qr` | GET | IP 二维码导出 |
| `v1/customer/ips/{id}` | GET | IP 详情 |
| `v1/customer/balance` | GET | 余额 |
| `v1/customer/transactions` | GET | 交易流水 |
| `v1/customer/topup/methods` | GET | 充值方式 |
| `v1/customer/topup/orders` | GET | 充值订单列表 |
| `v1/customer/topup/orders/{orderNo}` | GET | 充值订单详情 |

### 需实名认证 (middleware: `customer.verified`)

| 路由 | 方法 | 说明 |
|------|------|------|
| `v1/customer/store/checkout` | POST | 下单 (限速 10/min) |
| `v1/customer/subscriptions/batch-auto-renew` | PUT | 批量切换自动续费 |
| `v1/customer/subscriptions/batch-renew-by-ip` | POST | 按IP批量续费 |
| `v1/customer/subscriptions/{id}/renew` | POST | 续费 |
| `v1/customer/subscriptions/{id}/refund` | POST | 退订 (限速 5/min) |
| `v1/customer/subscriptions/{id}/auto-renew` | PUT | 切换自动续费 |
| `v1/customer/subscriptions/{id}/redeem` | POST | 兑换码 (限速 3/min) |
| `v1/customer/subscriptions/{id}/remark` | PATCH | 修改备注 |
| `v1/customer/topup/create` | POST | 创建充值 (限速 10/min) |
| `v1/customer/referral` | GET | 推广信息 |
| `v1/customer/referral/commissions` | GET | 佣金记录 |
| `v1/customer/referral/withdraw-info` | PUT | 更新提现信息 |
| `v1/customer/referral/withdraw` | POST | 提现 (限速 5/min) |
| `v1/customer/referral/transfer-to-balance` | POST | 佣金转余额 |

### OIDC OAuth2

| 路由 | 方法 | 说明 |
|------|------|------|
| `v1/oauth/authorize` | GET/POST | 授权页 (需 customer token) |
| `v1/oauth/userinfo` | GET | 用户信息 (Bearer token) |

---

## 三、管理后台 API（需 admin token + 权限）

中间件链: `auth:sanctum` → `ability:admin` → `log.activity`

### 1. 认证（所有登录管理员）

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/auth/me` | GET | 当前用户 | - |
| `v1/auth/logout` | POST | 登出 | - |
| `v1/auth/password` | PUT | 修改密码 | - |

### 2. 仪表盘

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/dashboard/stats` | GET | 仪表盘统计 | `dashboard.view` |
| `v1/dashboard/expiring` | GET | 即将到期 | `dashboard.view` |
| `v1/dashboard/recent` | GET | 最近动态 | `dashboard.view` |

**角色覆盖**: 所有角色

### 3. 客户管理

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/customers` | GET | 客户列表 | `customer.view` |
| `v1/customers` | POST | 创建客户 | `customer.create` |
| `v1/customers/merge-preview` | POST | 合并预览 | `customer.edit` |
| `v1/customers/merge` | POST | 合并客户 | `customer.edit` |
| `v1/customers/{id}` | GET | 客户详情 | `customer.view` |
| `v1/customers/{id}` | PUT | 更新客户 | `customer.edit` |
| `v1/customers/{id}` | DELETE | 删除客户 | `customer.delete` |
| `v1/customers/{id}/topup` | POST | 充值 | `customer.topup` |
| `v1/customers/{id}/adjust-balance` | POST | 调整余额 | `customer.topup` |
| `v1/customers/{id}/reset-password` | POST | 重置密码 | `customer.edit` |
| `v1/customers/{id}/impersonate` | POST | 模拟登录 | `customer.view` |
| `v1/customers/{id}/set-referrer` | POST | 设置推荐人 | `customer.edit` |
| `v1/customers/{id}/clear-referrer` | POST | 清除推荐人 | `customer.edit` |
| `v1/customers/{id}/transfer-referrer` | POST | 转移推荐人 | `customer.edit` |
| `v1/customers/{id}/change-sales` | POST | 更换销售 | `customer.change_sales` |
| `v1/customers/{id}/verification-info` | GET | 实名信息 | `customer.view_verification` |
| `v1/customers/{id}/manual-verify` | POST | 人工审核通过 | `customer.reset_verification` |
| `v1/customers/{id}/reset-verification` | POST | 重置实名 | `customer.reset_verification` |

**角色覆盖**: super_admin, tech_admin, ops_admin, admin, manager, staff, sales(部分), agent(仅view+create)

### 4. IP 资产管理

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/proxy-ips` | GET | IP 列表 | `ip.view` |
| `v1/proxy-ips` | POST | 创建 IP | `ip.create` |
| `v1/proxy-ips/batch` | POST | 批量创建 | `ip.create` |
| `v1/proxy-ips/batch-assign` | POST | 批量分配 | `ip.assign` |
| `v1/proxy-ips/batch-release` | POST | 批量释放 | `ip.delete` |
| `v1/proxy-ips/batch-delete` | POST | 批量删除 | `ip.delete` |
| `v1/proxy-ips/batch-move-group` | POST | 批量移动组 | `ip.edit` |
| `v1/proxy-ips/import` | POST | 导入 | `ip.import` |
| `v1/proxy-ips/test-pool` | GET | 测试池列表 | `ip.view` |
| `v1/proxy-ips/batch-test-pool` | POST | 批量加入测试池 | `ip.edit` |
| `v1/proxy-ips/batch-remove-test-pool` | POST | 批量移出测试池 | `ip.edit` |
| `v1/proxy-ips/test-pool-assign` | POST | 测试池分配 | `ip.assign` |
| `v1/proxy-ips/test-pool-unassign` | POST | 测试池取消分配 | `ip.assign` |
| `v1/proxy-ips/{id}` | GET | IP 详情 | `ip.view` |
| `v1/proxy-ips/{id}` | PUT | 更新 IP | `ip.edit` |
| `v1/proxy-ips/{id}` | DELETE | 删除 IP | `ip.delete` |
| `v1/proxy-ips/{id}/assign` | POST | 分配 | `ip.assign` |
| `v1/proxy-ips/{id}/unassign` | POST | 取消分配 | `ip.unassign` |
| `v1/proxy-ips/{id}/release` | POST | 释放 | `ip.delete` |
| `v1/proxy-ips/{id}/verify-spark-release` | POST | 验证 Spark 释放 | `spark.manage` |
| `v1/proxy-ips/{id}/retry-spark-release` | POST | 重试 Spark 释放 | `spark.manage` |
| `v1/proxy-ips-stats` | GET | IP 统计 | `ip.view` |

**角色覆盖**: super_admin~admin 全部; manager 仅 view+assign+unassign; staff/sales/agent 仅 view

### 5. 资产组 & IP组

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/asset-groups/all` | GET | 所有资产组(下拉) | `asset_group.view` |
| `v1/asset-groups` | GET/POST | 列表/创建 | `view` / `create` |
| `v1/asset-groups/merge` | POST | 合并 | `asset_group.edit` |
| `v1/asset-groups/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `view`/`edit`/`delete` |
| `v1/ip-groups/all` | GET | 所有 IP 组 | `asset_group.view` |
| `v1/ip-groups` | GET/POST | 列表/创建 | `view`/`create` |
| `v1/ip-groups/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `view`/`edit`/`delete` |

### 6. Spark API

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/spark/debug` | GET | 调试信息 | `spark.view` |
| `v1/spark/products` | GET | 产品列表 | `spark.view_stock` |
| `v1/spark/stock-by-country` | GET | 按国家库存 | `spark.view_stock` |
| `v1/spark/balance` | GET | Spark 余额 | `spark.view` |
| `v1/spark/reset-password` | POST | 重置密码 | `spark.manage` |
| `v1/spark/ip-segments` | GET | IP 段 | `spark.view_stock` |
| `v1/spark/provision` | POST | 开通 | `spark.manage` |
| `v1/spark/sync-order/{id}` | POST | 同步订单 | `spark.manage` |
| `v1/spark/renew` | POST | 续费 | `spark.manage` |
| `v1/spark/release` | POST | 释放 | `spark.manage` |
| `v1/spark/orders` | GET | 订单列表 | `spark.view` |
| `v1/spark/match` | POST | 匹配实例 | `spark.manage` |
| `v1/spark/bulk-match` | POST | 批量匹配 | `spark.manage` |
| `v1/spark/sync-all` | POST | 全量同步 | `spark.manage` |
| `v1/spark/areas/*` | GET/POST | 地区查询 | 无(所有登录用户) |

### 7. IPIPV API

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/ipipv/products` | GET | 产品列表 | `spark.view_stock` |
| `v1/ipipv/balance` | GET | IPIPV 余额 | `spark.view` |
| `v1/ipipv/provision` | POST | 开通 | `spark.manage` |
| `v1/ipipv/sync-order/{id}` | POST | 同步订单 | `spark.manage` |
| `v1/ipipv/renew` | POST | 续费 | `spark.manage` |
| `v1/ipipv/release` | POST | 释放 | `spark.manage` |
| `v1/ipipv/orders` | GET | 订单列表 | `spark.view` |
| `v1/ipipv/areas` | GET | 地区 | `spark.view` |
| `v1/ipipv/cities` | GET | 城市 | `spark.view` |
| `v1/ipipv/stock-by-country` | GET | 按国家库存 | `spark.view_stock` |

### 8. 上游 API 管理

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/upstream-providers/display-names` | GET | 显示名称 | `spark.view_stock` |
| `v1/upstream-providers` | GET/POST | 列表/创建 | `setting.manage` |
| `v1/upstream-providers/{id}` | PUT/DELETE | 更新/删除 | `setting.manage` |
| `v1/upstream-providers/{id}/test` | POST | 测试连接 | `setting.manage` |

### 9. 订阅管理

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/subscriptions/expiring` | GET | 即将到期 | `subscription.view` |
| `v1/subscriptions/available-ips` | GET | 可用 IP | `subscription.view` |
| `v1/subscriptions/create-order` | POST | 创建订单 | `subscription.create` |
| `v1/subscriptions` | GET | 订阅列表 | `subscription.view` |
| `v1/subscriptions/batch-forward-status/{batchId}` | GET | 批量转发状态 | `subscription.view` |
| `v1/subscriptions/batch-xui-forward-status/{batchId}` | GET | 批量XUI转发状态 | `subscription.view` |
| `v1/subscriptions/bulk-renew` | POST | 批量续费 | `subscription.renew` |
| `v1/subscriptions/batch-attach-forward` | POST | 批量挂载转发 | `subscription.edit_price` |
| `v1/subscriptions/batch-attach-xui-forward` | POST | 批量挂载XUI转发 | `subscription.create` |
| `v1/subscriptions/batch-update-expiry` | POST | 批量更新到期时间 | `subscription.update_expiry` |
| `v1/subscriptions/batch-update-price` | POST | 批量修改价格（下次续费生效） | `subscription.edit_price` |
| `v1/subscriptions/{id}` | GET | 订阅详情 | `subscription.view` |
| `v1/subscriptions/{id}/renew` | POST | 续费 | `subscription.renew` |
| `v1/subscriptions/{id}/cancel` | POST | 取消 ⚠️ | `subscription.cancel` |
| `v1/subscriptions/{id}/refund` | POST | 退订(Wizard) | `subscription.refund` |
| `v1/subscriptions/{id}/convert-test` | POST | 转测试 | `subscription.create` |
| `v1/subscriptions/{id}/transfer` | POST | 划转 | `subscription.transfer` |
| `v1/subscriptions/{id}/remark` | PATCH | 修改备注 | `subscription.view` |

> ⚠️ `cancel` 路由仍存在于后端，但前端已移除取消按钮，所有终止操作统一走退订 Wizard。建议后续移除此路由。

**角色覆盖**: super_admin~admin 全部; manager 全部; staff 仅 view+submit_approval+renew+update_expiry; sales 仅 view+submit_approval; agent 仅 view+renew

### 10. 中转套餐

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/forward-plans` | GET | 套餐列表 | `forward.view` |
| `v1/forward-plans` | POST | 创建套餐 | `forward.manage` |
| `v1/forward-plans/{id}` | PUT | 更新 | `forward.manage` |
| `v1/forward-plans/{id}` | DELETE | 删除 | `forward.manage` |

### 11. 定价系统

#### 客户特批价

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/customer-special-prices` | GET | 列表 | `pricing.manage` |
| `v1/customer-special-prices/debug-match` | GET | 调试匹配 | `pricing.manage` |
| `v1/customer-special-prices` | POST | 创建 | `pricing.manage` |
| `v1/customer-special-prices/{id}` | PUT/DELETE | 更新/删除 | `pricing.manage` |

#### VIP 会员等级

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/vip-tiers` | GET | 列表 | `pricing.view` |
| `v1/vip-tiers` | POST | 创建 | `pricing.manage` |
| `v1/vip-tiers/recalculate-all` | POST | 全量重算 | `pricing.manage` |
| `v1/vip-tiers/{id}` | PUT/DELETE | 更新/删除 | `pricing.manage` |

#### 定价规则 (v1)

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/pricing-rules/lookup` | GET | 价格查询 | `pricing.view` |
| `v1/pricing-rules` | GET/POST | 列表/创建 | `view`/`manage` |
| `v1/pricing-rules/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `view`/`manage`/`manage` |

#### Spark 定价 (v2)

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/spark-pricing/countries` | GET | 国家列表 | `pricing.view` |
| `v1/spark-pricing/lookup` | GET | 价格查询 | `pricing.view` |
| `v1/spark-pricing` | GET/POST | 列表/创建 | `view`/`manage` |
| `v1/spark-pricing/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `view`/`manage`/`manage` |

#### 统一产品定价

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/product-pricing/countries-overview` | GET | 国家总览 | `pricing.view` |
| `v1/product-pricing/country/{code}` | GET | 国家定价 | `pricing.view` |
| `v1/product-pricing/save-country` | POST | 保存国家定价 | `pricing.manage` |
| `v1/product-pricing/batch-set` | POST | 批量设置 | `pricing.manage` |
| `v1/product-pricing/sync-spark-cost` | POST | 同步 Spark 成本 | `pricing.manage` |
| `v1/product-pricing` | GET/POST | 列表/创建 | `view`/`manage` |
| `v1/product-pricing/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `view`/`manage`/`manage` |

#### 销售倍率定价 (v3)

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/pricing-multipliers/preview` | GET | 预览 | `pricing.view` |
| `v1/pricing-multipliers/product-list` | GET | 产品列表 | `pricing.view` |
| `v1/pricing-multipliers/debug-match` | GET | 调试匹配 | `pricing.view` |
| `v1/pricing-multipliers/batch-set` | POST | 批量设置 | `pricing.manage` |
| `v1/pricing-multipliers` | GET/POST | 列表/创建 | `view`/`manage` |
| `v1/pricing-multipliers/{id}` | PUT/DELETE | 更新/删除 | `manage` |

### 12. 审批中心

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/approvals/stats` | GET | 审批统计 | `approval.view` |
| `v1/approvals` | GET | 审批列表 | `approval.view` |
| `v1/approvals` | POST | 提交审批 | `subscription.submit_approval` |
| `v1/approvals/{id}` | GET | 审批详情 | `approval.view` |
| `v1/approvals/{id}/approve` | POST | 通过 | `approval.review` |
| `v1/approvals/{id}/reject` | POST | 驳回 | `approval.review` |
| `v1/approvals/{id}/cancel` | POST | 撤回 | `approval.view` |

### 13. 交易流水

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/transactions` | GET | 交易列表 | `transaction.view` |
| `v1/transactions/{id}` | GET | 交易详情 | `transaction.view` |

### 14. 充值订单 & 原路退款

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/payment-orders` | GET | 充值订单列表 | `transaction.view` |
| `v1/payment-orders/{id}/refund` | POST | 原路退款(Alipay) | `payment.gateway_refund` |
| `v1/payment-refunds` | GET | 退款记录列表 | `transaction.view` |
| `v1/customers/{id}/refundable-orders` | GET | 可退款订单 | `payment.gateway_refund` |

### 15. 推广 & 佣金

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/settings/referral` | GET/PUT | 推广设置 | `setting.manage` |
| `v1/referral-stats` | GET | 推广统计 | `pricing.view` |
| `v1/referral-commissions` | GET | 推广佣金列表 | `pricing.view` |
| `v1/referral-commissions/{id}/credit` | POST | 发放推广佣金 | `pricing.manage` |
| `v1/sales-commissions` | GET | 销售佣金列表 | `pricing.view` |
| `v1/sales-commissions/{id}/credit` | POST | 发放销售佣金 | `pricing.manage` |

### 16. 业绩

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/performance/search` | GET | 业绩检索 | `performance.view` |
| `v1/sales-stats` | GET | 销售统计 | `customer.view` |
| `v1/manual-performances` | GET | 手动业绩列表 | `performance.view` |
| `v1/manual-performances` | POST | 添加手动业绩 | `performance.manage` |
| `v1/manual-performances/{id}` | DELETE | 删除手动业绩 | `performance.manage` |

### 17. 财务总览

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/finance/overview` | GET | 财务总览 | `transaction.view` |
| `v1/finance/trend` | GET | 趋势 | `transaction.view` |
| `v1/finance/ranking` | GET | 排行 | `transaction.view` |

### 18. 数据看板

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/analytics/marketing` | GET | 营销数据 | `analytics.view` |
| `v1/analytics/pricing` | GET | 定价数据 | `analytics.view` |
| `v1/analytics/products` | GET | 产品数据 | `analytics.view` |
| `v1/analytics/customer-detail/{id}` | GET | 客户详情 | `analytics.view` |

### 19. 后台用户管理

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/users` | GET | 用户列表 | `user.view` |
| `v1/users/{id}` | GET | 用户详情 | `user.view` |
| `v1/users` | POST | 创建用户 | `user.create` |
| `v1/users/{id}` | PUT | 更新用户 | `user.edit` |
| `v1/users/{id}` | DELETE | 删除用户 | `user.delete` |
| `v1/users/{id}/reset-password` | POST | 重置密码 | `user.edit` |
| `v1/users/{id}/generate-invite-code` | POST | 生成邀请码 | `user.edit` |
| `v1/users/{id}/regenerate-invite-code` | POST | 重新生成邀请码 | `user.edit` |
| `v1/users/{id}/auto-approve` | PUT | 设置自动审批 | `user.set_auto_approve` |

### 20. 角色与权限

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/roles/all-permissions` | GET | 所有权限 | `user.assign_role` |
| `v1/roles` | GET/POST | 列表/创建 | `user.assign_role` |
| `v1/roles/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `user.assign_role` |

### 21. 系统设置

#### 短信服务

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/sms-providers` | GET/POST | `setting.manage` |
| `v1/sms-providers/{id}` | PUT/DELETE | `setting.manage` |
| `v1/sms-providers/{id}/test` | POST | `setting.manage` |

#### API Keys

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/api-keys` | GET/POST | `setting.manage` |
| `v1/api-keys/{id}` | PUT/DELETE | `setting.manage` |
| `v1/api-keys/{id}/regenerate` | POST | `setting.manage` |

#### 网站设置

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/settings/site` | GET/PUT | `setting.manage` |
| `v1/settings/site/logo` | POST/DELETE | `setting.manage` |
| `v1/settings/site/favicon` | POST/DELETE | `setting.manage` |
| `v1/settings/store-banner` | GET/PUT | `setting.manage` |
| `v1/settings/store-banner/upload-image` | POST | `setting.manage` |
| `v1/settings/float-contact` | GET/PUT | `setting.manage` |
| `v1/settings/float-contact/upload-image` | POST | `setting.manage` |
| `v1/settings/partnership-contact` | GET | `setting.manage` |
| `v1/settings/partnership-contact/image` | POST/DELETE | `setting.manage` |

#### 注册 & 实名认证

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/settings/registration` | GET/PUT | `setting.manage` |
| `v1/settings/verification` | GET/PUT | `setting.manage` |
| `v1/settings/verification/test` | POST | `setting.manage` |
| `v1/verification-providers` | CRUD+toggle+test | `setting.manage` |

#### 支付网关

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/payment-gateways/domain-settings` | GET/PUT | `setting.manage` |
| `v1/payment-gateways` | CRUD | `setting.manage` |
| `v1/payment-gateways/{id}/test-sign` | POST | `setting.manage` |

#### NY 面板

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/ny-panels/enabled-device-groups` | GET | `subscription.create` 或 `subscription.submit_approval` |
| `v1/ny-panels` | CRUD | `setting.manage` |
| `v1/ny-panels/{id}/test` | POST | `setting.manage` |
| `v1/ny-panels/{id}/sync-device-groups` | POST | `setting.manage` |
| `v1/ny-panels/{id}/device-groups` | PUT | `setting.manage` |

#### 3x-ui 面板

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/xui-panels/usable` | GET | `subscription.create` 或 `subscription.submit_approval` |
| `v1/xui-panels` | CRUD | `setting.manage` |
| `v1/xui-panels/{id}/test` | POST | `setting.manage` |
| `v1/xui-panels/{id}/create-forward` | POST | `setting.manage` |
| `v1/xui-panels/{id}/batch-create-forward` | POST | `setting.manage` |
| `v1/xui-panels/{id}/batch-status/{batchId}` | GET | `setting.manage` |
| `v1/xui-panels/{id}/sync-all-to-mirror` | POST | `setting.manage` |
| `v1/xui-panels/inbounds/{id}/resync-mirror` | POST | `setting.manage` |
| `v1/xui-panels/{id}/inbounds` | GET | `setting.manage` |
| `v1/xui-panels/inbounds/{id}` | DELETE | `setting.manage` |

#### DNS 容灾

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/dns-agents` | GET/POST | `setting.manage` |
| `v1/dns-agents/{id}` | DELETE | `setting.manage` |
| `v1/dns-agents/{id}/regenerate-key` | POST | `setting.manage` |
| `v1/dns-targets` | CRUD | `setting.manage` |
| `v1/dns-targets/{id}/probes` | GET | `setting.manage` |
| `v1/dns-targets/{id}/events` | GET | `setting.manage` |
| `v1/dns-targets/{id}/failover` | POST | `setting.manage` |
| `v1/dns-targets/{id}/failback` | POST | `setting.manage` |

#### 飞书同步

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/feishu-sync` | CRUD | `setting.manage` |
| `v1/feishu-sync/{id}/test` | POST | `setting.manage` |
| `v1/feishu-sync/{id}/sync` | POST | `setting.manage` |
| `v1/feishu-sync/{id}/preview` | GET | `setting.manage` |

#### 队列监控

| 路由 | 方法 | 权限 |
|------|------|------|
| `v1/queue-monitor/stats` | GET | `setting.manage` |
| `v1/queue-monitor/failed` | GET | `setting.manage` |
| `v1/queue-monitor/retry-all-failed` | POST | `setting.manage` |
| `v1/queue-monitor/flush-failed` | POST | `setting.manage` |

### 22. Webhook 通知

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/webhooks/events` | GET | 事件类型 | `webhook.view` |
| `v1/webhooks/logs` | GET | 日志 | `notification.view` |
| `v1/webhooks` | GET/POST | 列表/创建 | `webhook.view`/`manage` |
| `v1/webhooks/{id}` | GET/PUT/DELETE | 详情/更新/删除 | `view`/`manage`/`manage` |
| `v1/webhooks/{id}/test` | POST | 测试 | `webhook.test` |

### 23. 活动日志

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/activity-logs` | GET | 日志列表 | `activity_log.view` |
| `v1/activity-logs/{id}` | GET | 日志详情 | `activity_log.view` |
| `v1/activity-logs/clean` | POST | 清理日志 | `activity_log.view_all` |

### 24. OAuth 客户端管理

| 路由 | 方法 | 说明 | 权限 |
|------|------|------|------|
| `v1/oauth-clients` | CRUD | OAuth 客户端 | `setting.manage` |
| `v1/oauth-clients/{id}/regenerate-secret` | POST | 重新生成密钥 | `setting.manage` |

---

## 四、权限清单 (58 项)

| 模块 | 权限 | 说明 | 使用路由数 |
|------|------|------|-----------|
| **后台用户** | `user.view` | 查看后台用户列表 | 2 |
| | `user.create` | 创建后台用户 | 1 |
| | `user.edit` | 编辑/重置密码/邀请码 | 4 |
| | `user.delete` | 删除后台用户 | 1 |
| | `user.assign_role` | 角色权限管理 | 5 |
| | `user.set_auto_approve` | 设置销售自动审批 | 1 |
| **客户** | `customer.view` | 查看客户（仅自己名下） | 4 |
| | `customer.view_all` | 查看所有客户（跨销售数据隔离） | 控制器级 |
| | `customer.create` | 创建客户 | 1 |
| | `customer.edit` | 编辑/合并/推荐人/重置密码 | 6 |
| | `customer.delete` | 删除客户 | 1 |
| | `customer.topup` | 充值/余额调整 | 2 |
| | `customer.change_sales` | 更换业务归属人 | 1 |
| | `customer.view_verification` | 查看实名认证信息 | 1 |
| | `customer.reset_verification` | 重置/人工审核实名 | 2 |
| **IP资产** | `ip.view` | 查看 IP 列表/统计 | 4 |
| | `ip.create` | 添加 IP/批量添加 | 2 |
| | `ip.edit` | 编辑/移组/测试池管理 | 4 |
| | `ip.delete` | 删除/释放 IP | 4 |
| | `ip.import` | 批量导入 | 1 |
| | `ip.assign` | 分配/测试池分配 | 4 |
| | `ip.unassign` | 取消分配 | 1 |
| **资产组** | `asset_group.view` | 查看资产组/IP组 | 5 |
| | `asset_group.create` | 创建 | 2 |
| | `asset_group.edit` | 编辑/合并 | 3 |
| | `asset_group.delete` | 删除 | 2 |
| **订阅** | `subscription.view` | 查看订阅/修改备注 | 5 |
| | `subscription.create` | 直接创建订单/转测试 | 4 |
| | `subscription.renew` | 续费/批量续费 | 2 |
| | `subscription.cancel` | 取消订阅 | 1 |
| | `subscription.refund` | 退订（退款到余额+释放选项） | 1 |
| | `subscription.transfer` | 订阅划转到其他客户 | 1 |
| | `subscription.edit_price` | 修改价格/挂载转发 | 1 |
| | `subscription.update_expiry` | 修改到期时间 | 1 |
| | `subscription.submit_approval` | 提交审批订单 | 3 |
| **审批** | `approval.view` | 查看/撤回审批 | 4 |
| | `approval.review` | 审批通过/驳回 | 2 |
| **定价** | `pricing.view` | 查看定价/VIP/佣金 | ~15 |
| | `pricing.manage` | 管理定价/特批价/发放佣金 | ~18 |
| | `pricing.view_cost` | 查看成本价（控制器级过滤） | 控制器级 |
| | `transaction.view` | 查看交易流水/财务/充值订单 | 6 |
| | `payment.gateway_refund` | 原路退款到支付渠道 | 2 |
| **业绩** | `performance.view` | 查看业绩/手动业绩记录 | 2 |
| | `performance.manage` | 添加/删除手动业绩 | 2 |
| **Spark/IPIPV** | `spark.view` | 查看上游订单/余额 | 6 |
| | `spark.manage` | 上游开通/续费/释放/匹配 | 12 |
| | `spark.view_stock` | 查看上游库存/产品 | 6 |
| **转发** | `forward.view` | 查看中转套餐 | 1 |
| | `forward.manage` | 管理中转套餐 | 2 |
| **通知** | `notification.view` | 查看通知发送日志 | 1 |
| | `webhook.view` | 查看 Webhook 配置 | 3 |
| | `webhook.manage` | 管理 Webhook | 3 |
| | `webhook.test` | 测试 Webhook 推送 | 1 |
| **系统** | `setting.manage` | 所有系统设置 | ~50 |
| **仪表盘** | `dashboard.view` | 仪表盘概览 | 3 |
| **数据看板** | `analytics.view` | 营销/定价/产品分析 | 4 |
| **日志** | `activity_log.view` | 查看操作日志 | 2 |
| | `activity_log.view_all` | 查看所有日志/清理 | 1 |

---

## 五、审计发现

### ⚠️ 废弃路由

| 路由 | 说明 |
|------|------|
| `POST v1/subscriptions/{id}/cancel` (line 384) | 前端已移除取消按钮，所有终止操作统一走退订 Wizard。此路由仍可被 API 调用，建议移除或标记 deprecated。 |

### 注意事项

- `sales-stats` 路由使用 `customer.view` 权限，所有有客户查看权限的角色都能看到销售统计
- `customer.view_all` 权限控制数据隔离：无此权限的用户只能看到自己名下客户，影响客户列表、订阅列表、IP列表、仪表盘、销售统计
- `pricing.view_cost` 在 SparkController / IpipvController 中用于控制是否展示成本价字段（非路由级）
- NY/XUI 面板的 `enabled-device-groups`/`usable` 接口使用 OR 逻辑: `subscription.create` 或 `subscription.submit_approval`

---

## 六、数据隔离架构

### customer.view_all 权限控制

系统使用 `customer.view_all` 权限（而非硬编码角色判断）实现跨销售数据隔离。无此权限的用户只能看到 `sales_person == $user->name` 的客户及关联数据。

**影响的控制器 (6个)**:

| 控制器 | 隔离逻辑 |
|--------|---------|
| `CustomerController::index()` | `$query->where('sales_person', $user->name)` |
| `CustomerController::store()` | 强制 `sales_person` 为当前用户 |
| `CustomerController::update()` | 不能编辑他人名下客户 |
| `CustomerController::impersonate()` | 不能模拟登录他人客户 |
| `SubscriptionController::index()` | `$query->whereIn('customer_id', 自己名下客户IDs)` |
| `ProxyIpController::index()` | 只能看到自己客户的 IP + status=available 的 IP |
| `DashboardController` | stats/expiring/recent 均按角色过滤 |
| `SalesStatsController` | 只能看到自己名下客户的统计 |

**代码模式**:
```php
$user = $request->user();
if ($user && !$user->can('customer.view_all')) {
    $customerIds = Customer::where('sales_person', $user->name)->pluck('id');
    $query->whereIn('customer_id', $customerIds);
}
```

### Dashboard 角色差异

| 角色判断 | 看到的数据 | 返回字段 |
|---------|-----------|---------|
| `!can('customer.view_all')` (sales) | 仅自己名下客户 | my_customers, my_active_subs, my_expiring_soon, my_pending_approvals |
| `hasRole('manager') && can('customer.view_all')` | 全局 + 自己视角 | total_customers, total_ips, active_subscriptions, total_revenue, spark/ipipv_balance |
| admin 级 | 全系统 | 同 manager 但无 sales_person 过滤 |

---

## 七、订阅操作实现细节

### 退订 Wizard (`SubscriptionController::refund()`)

**文件**: `backend/app/Http/Controllers/Api/V1/SubscriptionController.php`

**请求参数**:
- `reason` (string, optional) — 退订原因
- `refund_amount` (numeric, optional) — 退款金额，默认 `subscription->price`
- `release_upstream` (boolean, optional, default true) — 是否释放上游资源

**业务逻辑**:
1. 验证订阅状态为 `active`
2. 支持部分退款：`refund_amount` 可小于 `price`
3. `release_upstream=true` → 调用 Spark/IPIPV 释放 API，IP 状态变 `released`
4. `release_upstream=false` → IP 移入测试池（`is_test_pool=true`），保留资源
5. 清理 NY 中转规则 + 3x-ui 中转规则
6. DB 事务：订阅状态→`refunded`，客户余额 += refund_amount
7. 反转推广佣金 + 销售佣金
8. 触发飞书同步

**返回**: `forward_deleted` 数量、Spark/IPIPV 释放状态、`test_pool` 标记

### 订阅划转 (`SubscriptionController::transfer()`)

**请求参数**:
- `target_customer_id` (required) — 目标客户
- `charge_target` (boolean) — 是否向目标客户收费
- `charge_method` (string: balance/offline, default balance) — 收费方式

**业务逻辑**:
1. 验证订阅状态为 `active`
2. 更新 `subscription.customer_id` 和 `proxy_ip.assigned_customer_id`
3. 创建 IP 分配日志（source unassign + target assign）
4. `charge_target=true && charge_method=balance` → 扣目标余额，退源余额
5. 反转源客户佣金，为目标客户重新生成佣金（如收费）
6. 触发双方飞书同步

**重要**: `forward_rules` 表**没有 `customer_id` 列**，中转规则通过 `subscription_id` 关联，订阅转移后自动跟随，无需额外更新。

### 批量改价 (`SubscriptionController::batchUpdatePrice()`)

**请求参数**:
- `subscription_ids` (array, max 5000)
- `new_price` (numeric, ≥0)

**逻辑**: 遍历更新每条订阅的 `price` 字段，下次续费生效。返回每条的 old_price、new_price、diff、diff_note（如 "+¥10" / "-¥5" / "无差价"）。

### 批量修改到期 (`SubscriptionController::batchUpdateExpiry()`)

**请求参数**:
- `subscription_ids` (array)
- `expires_at` (date) — 目标到期日（endOfDay）
- `sync_proxy_ip` (boolean, optional) — 同步 `proxy_ips.upstream_expires_at`

**逻辑**: 设置精确到期日，状态自动判断（future→active, past→expired）。可选同步 IP 上游到期时间。

### 批量挂载 NY 中转 (`SubscriptionController::batchAttachForward()`)

**请求参数**:
- `subscription_ids` (array)
- `device_group_id` — NY 设备组
- `speed_limit_mbps` — 限速
- `forward_fee` — 中转费
- `deduct_balance` (string: current/next) — 立即扣费还是下次续费扣

**逻辑**:
1. 验证设备组已启用，NY 面板活跃
2. 跳过已有中转的订阅
3. 创建 `ForwardRule` 记录（status=pending）
4. 队列 `AttachForwardJob` 异步处理
5. `deduct_balance=current` → 立即扣余额（加锁+事务），处理推广/销售佣金
6. 返回 `batch_id` 用于进度轮询

### 批量挂载 XUI 中转 (`SubscriptionController::batchAttachXuiForward()`)

**请求参数**: `subscription_ids`, `xui_panel_id`

**逻辑**: 创建 `XuiInbound` 记录（protocol=vless, status=pending），队列处理。无费用环节。

### 转测试 (`SubscriptionController::convertTest()`)

**请求参数**: `duration`, `unit`, `price`, `charge_customer` (boolean)

**逻辑**: `is_test=true` → `is_test=false`，设置价格/时长/到期，可选扣客户余额。同步 IP 上游到期时间。

---

## 八、续费定价计算

**文件**: `backend/app/Services/SubscriptionService.php` → `renewOne()`

### 月均价计算 (calcRenewalMonthlyPrice)

```
durationInMonths = match(unit) {
    1 (days)   => duration / 30
    2 (weeks)  => duration / 4
    3 (months) => duration        // 默认
    4 (years)  => duration * 12
}

unitPrice = durationInMonths > 1
    ? round(price / durationInMonths, 2)
    : price
```

**示例**: 3个月 ¥90 → 月均 ¥30；1年 ¥100 → 月均 ¥8.33

### renewOne() 完整流程

1. **扣费**: 检查余额 ≥ price（`skip_deduct=false` 时），扣除并记录 before/after
2. **到期计算**: 当前未过期 → 在 expires_at 基础上延长；已过期 → 从 now() 开始
3. **上游续费** (reactivate=true):
   - Spark → `renewProxy()` 续 1 个月
   - IPIPV → `renewProxy()` 续 1 周期
   - 恢复软删除的 proxy_ip，状态→assigned
4. **佣金**: 按 list_price（无则 price）计算推广佣金 + 销售佣金
5. **交易记录**: type=subscription_renew，金额为负（余额减少）

### 允许续费条件
- `active` 状态：始终可续
- `expired` 状态：过期 3 天内可续（含上游重新激活）

---

## 九、原路退款实现

### 架构

**两步退款**：订阅退款返回到余额（已有流程），然后管理员可发起原路退款从余额扣回到支付渠道。

### AlipayService::refund()

**文件**: `backend/app/Services/Payment/AlipayService.php`

- 方法: `alipay.trade.refund`（同步 API，无回调）
- 签名: RSA2 (SHA256WithRSA)，与支付签名共用 `sign()` 方法
- 必填: `trade_no`（provider_trade_no）, `out_trade_no`, `refund_amount`, `out_request_no`（refundNo）, `refund_reason`
- 成功判断: response `code === '10000'`
- 返回: `{success, trade_no, refund_fee, code, msg, sub_msg, response}`

### PaymentRefundService::refund()

**文件**: `backend/app/Services/Payment/PaymentRefundService.php`

**验证规则**:
1. 订单状态必须 `paid`
2. 网关类型必须 `alipay`（EPay 暂不支持）
3. `amount > 0` 且 `<= refundable_amount`（= amount - refunded_amount）
4. 客户余额 ≥ amount（"客户余额不足"）
5. 支付时间在 11 个月内（Alipay 1 年退款期限）

**执行流程**:
1. 创建 `PaymentRefund` 记录（status=pending）
2. 调用 `AlipayService::refund()`
3. 成功 → DB事务（lockForUpdate）:
   - 客户余额 -= amount
   - 创建 Transaction (type=gateway_refund, 金额为负)
   - PaymentOrder.refunded_amount += amount
   - PaymentRefund status→success
4. 失败 → PaymentRefund status→failed + error_message

### PaymentRefund Model

**编号格式**: `REF` + YYYYMMDDHHmmss + 8字符hex（`bin2hex(random_bytes(4))`）

**关系**: belongsTo PaymentOrder, Customer, Subscription(nullable), PaymentGateway, User(operator)

### PaymentOrder Model

**编号格式**: `PAY` + YYYYMMDDHHmmss + 8字符hex

**可退金额**: `refundable_amount = amount - refunded_amount`

---

## 十、商店 & 下单流程

### 客户端商店 (`frontend-customer/src/views/store/Index.vue`)

**产品展示**:
- 按洲分组，支持搜索（国家名/代码/区域/城市/ISP/产品名）
- 排序：按库存（默认）、价格升降序
- 库存颜色：绿(>50)、橙(5-50)、红(<5)、灰(0)
- 数量限制：1 ~ stock（无硬编码上限）

**模块定价**:
- Static IP：正常定价 + 折扣
- Video：IP 价格 + 视频中转费（可有 `discount_percent_video`）
- Live：固定定价或叠加定价（取决于 `pricing_mode`）

**自动下单 (Auto-checkout on Topup)**:
- 用户充值完成后触发 `onTopupPaid()`
- 如果购物车有商品且余额足够 → 自动调用 `submitOrder()`
- 否则仅显示 "充值成功！可以继续下单了"

**下单参数**: items (product_id, quantity, cidr), duration (30/60/90/120/360天), auto_renew, module (static/video/live_mobile/live_pc)

### 后端 StoreController::checkout()

**文件**: `backend/app/Http/Controllers/Api/V1/Customer/StoreController.php`

**产品加载**: 合并 Spark + IPIPV 产品 → 应用 `PricingMultiplier::calcSalePrice()` → 叠加客户特批价/VIP 折扣

**定价优先级**:
1. 客户特批价 (`CustomerSpecialPrice::findPriceTrace()`) — 最高优先
2. VIP 折扣 — 仅在无特批价时生效（防止叠加）
3. 销售倍率 (`PricingMultiplier`) — 基础定价

**执行**: 调用 `CheckoutService->purchaseByProducts()` 返回 subscription_ids + new_balance

---

## 十一、中转规则架构

### ForwardRule Model

**文件**: `backend/app/Models/ForwardRule.php`

**关键**: **没有 `customer_id` 列**，所有客户关联通过 `subscription_id` 实现。

**字段**: subscription_id, proxy_ip_id, ny_panel_id, ny_device_group_id, remote_rule_id, name, dest_host, dest_port, listen_port, speed_limit_mbps, forward_fee, forward_plan_id, traffic_used/limit_bytes, overage_charged, status, batch_id, error_message, last_synced_at

**关系**: belongsTo Subscription, ProxyIp(withTrashed), NyPanel(panel), NyDeviceGroup(deviceGroup), ForwardPlan

**连接串生成** (`toDisplayConnection()`):
- 格式: `{host}:{listen_port}:{auth_user}:{auth_pass}`
- Host 来源: `forwardPlan->display_host` 优先，否则 `deviceGroup->effectiveHost()`

---

## 十二、业绩管理

### ManualPerformanceController

**文件**: `backend/app/Http/Controllers/Api/V1/ManualPerformanceController.php`

**创建参数**: customer_id, amount (收入), profit (利润，默认=amount), performance_date, note
- 自动填充 `sales_person` = customer.sales_person
- 自动填充 `created_by` = 当前用户

**在 SalesStats 中的作用**: 手动业绩的 amount 和 profit 被加入客户的期间统计中：
- `period_profit = revenue - cost - forward_cost - referral_deduction + manual_profit`
- `period_spent` 加上 manual_performance 的 amount

### SalesStats 计算逻辑

**文件**: `backend/app/Http/Controllers/Api/V1/SalesStatsController.php`

**每客户指标** (批量查询避免 N+1):
1. 国家分布：活跃订阅按 proxy_ip.country_code 分组
2. 中转使用数：活跃 forward_rules 计数
3. IP 总数：assigned_customer_id 聚合
4. 推广扣减：已发放/待发放佣金合计
5. 订阅收入 & 成本：revenue=sum(price), cost 按月标准化
6. 期间利润 = 收入 - 成本 - 中转费 - 推广扣减 + 手动利润
7. 消费流水：排除 withdrawal/adjustment_out/refund 类型

**期间对比**: 默认今日 vs 昨日，支持自定义日期范围（用等长前期对比）

---

## 十三、角色权限细化

### 权限模块 (13 模块, 54 项权限)

**文件**: `backend/app/Http/Controllers/Api/V1/RoleController.php` → `$permissionModules`

| 模块 | 权限数 | 关键权限 |
|------|-------|---------|
| dashboard | 1 | view |
| customer | 8 | view, view_all, create, edit, delete, topup, change_sales, view/reset_verification |
| ip | 7 | view, create, edit, delete, import, assign, unassign |
| asset_group | 4 | view, create, edit(含merge), delete |
| subscription | 9 | view, create, submit_approval, renew, cancel, refund, transfer, edit_price, update_expiry |
| approval | 2 | view(含撤回), review(通过/驳回) |
| spark | 3 | view, view_stock, manage |
| billing | 4 | pricing.view/manage, transaction.view, payment.gateway_refund |
| forward | 2 | view, manage |
| notification | 4 | notification.view, webhook.view/manage/test |
| performance | 2 | view, manage |
| analytics | 1 | view |
| user(团队) | 6 | view, create, edit, delete, assign_role, set_auto_approve |
| system | 3 | setting.manage, activity_log.view/view_all |

### 权限依赖 ($permissionDependencies, 43条)

关键依赖链：
- `customer.view_all` → `customer.view`
- `customer.change_sales` → `customer.view` + `customer.view_all`
- `subscription.create` / `subscription.submit_approval` → `subscription.view` + `customer.view` + `pricing.view`
- `subscription.transfer` → `subscription.view` + `customer.view`
- `subscription.edit_price` → `subscription.view` + `pricing.view`
- `ip.assign` → `ip.view` + `customer.view`
- `spark.manage` → `spark.view` + `spark.view_stock`
- `payment.gateway_refund` → `transaction.view`
- `pricing.view_cost` → `pricing.view`

### 角色权限分配

**文件**: `backend/database/seeders/RolePermissionSeeder.php`

| 角色 | 权限数 | 关键权限 |
|------|-------|---------|
| super_admin | 54 | 全部 |
| tech_admin | 53 | 全部 - user.assign_role |
| ops_admin | 52 | 全部 - user.assign_role, user.delete |
| admin(旧兼容) | 53 | 同 ops_admin |
| manager | 23 | 客户/IP/订阅全管理, 审批review, 定价view+cost, spark view/manage, payment refund, forward, analytics, performance manage, user view only |
| staff | 15 | 客户 view/create/edit, IP view, 订阅 view/submit_approval, 审批 view, 定价 view, spark.view_stock, forward view, notification view |
| sales | 11 | 客户 view/create/edit, IP view, 订阅 view/submit_approval, 审批 view, 定价 view, spark.view_stock, forward view, notification view |
| agent | 6 | 客户 view/create, IP view, 订阅 view/renew, 定价 view, dashboard |
| user | 0 | 客户面板通过 `ability:customer` 中间件控制，不走权限系统 |

---

## 十四、前端响应解析模式

### 分页接口

**后端 `ApiResponse::paginated()`**: 返回 `{ success: true, data: { items: [...], pagination: {...} } }`

**前端 `request.js` 拦截器**: 自动解包 `res.data`，所以组件拿到的是 `{ items: [...], pagination: {...} }`

**正确用法**: `res?.items` 或 `res.items`（不是 `res?.data`）

### 非分页接口

**后端 `success()`**: 返回 `{ success: true, data: ... }`

**前端拿到**: 直接是 `data` 的内容

---

## 十五、IP 生命周期

| 状态 | 说明 | 转换 |
|------|------|------|
| `available` | 空闲可用 | → assigned (分配给客户) |
| `assigned` | 已分配 | → released (释放) / → available (取消分配) |
| `released` | 已释放 | → available (重新入库) |
| `test_pool` | 测试池 | → assigned (测试分配) / → available (移出测试池) |

**测试池**: 管理员可将 IP 加入测试池，供客户试用。退订时 `release_upstream=false` 会将 IP 移入测试池而非释放上游。

**批量操作**:
- `batchAssign`: 为多个 IP 创建订阅+分配客户
- `batchRelease`: 释放到 released，可选调用 Spark DelProxy
- `batchDestroy`: 硬删除仅限 available 状态的 IP
- `batchMoveGroup`: 变更 asset_group_id
- `batchAddToTestPool`: 标记测试池，取消活跃订阅
- `testPoolAssign`: 临时分配测试 IP 给客户（可带中转）
- `testPoolUnassign`: 归还到 available
