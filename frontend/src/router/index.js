import { createRouter, createWebHistory } from 'vue-router'
import { isLoggedIn } from '@/utils/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/auth/Login.vue'),
    meta: { requiresAuth: false },
  },
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        redirect: '/dashboard',
      },
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/Index.vue'),
        meta: { title: '仪表盘', permission: 'dashboard.view' },
      },
      {
        path: 'customers',
        name: 'CustomerList',
        component: () => import('@/views/customers/Index.vue'),
        meta: { title: '客户列表', permission: 'customer.view' },
      },
      {
        path: 'customers/create',
        name: 'CustomerCreate',
        component: () => import('@/views/customers/Create.vue'),
        meta: { title: '新建客户', permission: 'customer.create' },
      },
      {
        path: 'customers/:id',
        name: 'CustomerDetail',
        component: () => import('@/views/customers/Detail.vue'),
        meta: { title: '客户详情', permission: 'customer.view' },
      },
      {
        path: 'proxy-ips',
        name: 'ProxyIpList',
        component: () => import('@/views/proxy-ips/Index.vue'),
        meta: { title: 'IP列表', permission: 'ip.view' },
      },
      {
        path: 'proxy-ips/test-pool',
        name: 'TestPool',
        component: () => import('@/views/proxy-ips/TestPool.vue'),
        meta: { title: '测试IP池', permission: 'ip.view' },
      },
      {
        path: 'proxy-ips/import',
        name: 'ProxyIpImport',
        component: () => import('@/views/proxy-ips/Import.vue'),
        meta: { title: '批量导入', permission: 'ip.import' },
      },
      {
        path: 'proxy-ips/:id',
        name: 'ProxyIpDetail',
        component: () => import('@/views/proxy-ips/Detail.vue'),
        meta: { title: 'IP详情', permission: 'ip.view' },
      },
      {
        path: 'asset-groups',
        name: 'AssetGroupList',
        component: () => import('@/views/asset-groups/Index.vue'),
        meta: { title: '资产组', permission: 'asset_group.view' },
      },
      {
        path: 'ip-groups',
        name: 'IpGroupList',
        component: () => import('@/views/ip-groups/Index.vue'),
        meta: { title: 'IP组', permission: 'asset_group.view' },
      },
      {
        path: 'approvals',
        name: 'Approvals',
        component: () => import('@/views/approvals/Index.vue'),
        meta: { title: '审批中心', permission: 'approval.view' },
      },
      {
        path: 'subscriptions',
        name: 'SubscriptionList',
        component: () => import('@/views/subscriptions/Index.vue'),
        meta: { title: '订阅列表', permission: 'subscription.view' },
      },
      {
        path: 'subscriptions/create',
        name: 'CreateOrder',
        component: () => import('@/views/subscriptions/CreateOrder.vue'),
        meta: { title: '创建订单', permission: ['subscription.create', 'subscription.submit_approval'] },
      },
      {
        path: 'spark-orders',
        name: 'SparkOrders',
        component: () => import('@/views/subscriptions/SparkOrders.vue'),
        meta: { title: 'API 订单', permission: 'spark.view' },
      },
      {
        path: 'subscriptions/:id',
        name: 'SubscriptionDetail',
        component: () => import('@/views/subscriptions/Detail.vue'),
        meta: { title: '订阅详情', permission: 'subscription.view' },
      },
      {
        path: 'billing/payment-orders',
        name: 'PaymentOrders',
        component: () => import('@/views/billing/PaymentOrders.vue'),
        meta: { title: '充值订单', permission: 'transaction.view' },
      },
      {
        path: 'billing/transactions',
        name: 'TransactionList',
        component: () => import('@/views/billing/Transactions.vue'),
        meta: { title: '交易流水', permission: 'transaction.view' },
      },
      {
        path: 'billing/pricing',
        name: 'PricingRuleList',
        component: () => import('@/views/billing/PricingRules.vue'),
        meta: { title: '定价规则', permission: 'pricing.view' },
      },
      {
        path: 'billing/pricing-multipliers',
        name: 'PricingMultipliers',
        component: () => import('@/views/billing/PricingMultipliers.vue'),
        meta: { title: '销售倍率', permission: 'pricing.manage' },
      },
      {
        path: 'billing/special-prices',
        name: 'SpecialPrices',
        component: () => import('@/views/billing/SpecialPrices.vue'),
        meta: { title: '客户特批价', permission: ['pricing.manage', 'pricing.set_discount'] },
      },
      {
        path: 'billing/vip-tiers',
        name: 'VipTiers',
        component: () => import('@/views/billing/VipTiers.vue'),
        meta: { title: 'VIP会员等级', permission: 'pricing.manage' },
      },
      {
        path: 'billing/finance',
        name: 'Finance',
        component: () => import('@/views/billing/Finance.vue'),
        meta: { title: '财务总览', permission: 'transaction.view' },
      },
      {
        path: 'billing/sales-stats',
        name: 'SalesStats',
        component: () => import('@/views/billing/SalesStats.vue'),
        meta: { title: '销售统计', permission: 'customer.view' },
      },
      {
        path: 'billing/sales-stats-new',
        name: 'SalesStatsNew',
        component: () => import('@/views/billing/SalesStatsNew.vue'),
        meta: { title: '业绩统计', permission: 'customer.view' },
      },
      {
        path: 'billing/performance',
        name: 'Performance',
        component: () => import('@/views/billing/Performance.vue'),
        meta: { title: '业绩检索', permission: 'performance.view' },
      },
      {
        path: 'analytics/bigdata',
        name: 'BigData',
        component: () => import('@/views/analytics/BigData.vue'),
        meta: { title: '数据监控中心', permission: 'analytics.view' },
      },
      {
        path: 'analytics/marketing',
        name: 'AnalyticsMarketing',
        component: () => import('@/views/analytics/Marketing.vue'),
        meta: { title: '营销数据', permission: 'analytics.view' },
      },
      {
        path: 'analytics/pricing',
        name: 'AnalyticsPricing',
        component: () => import('@/views/analytics/Pricing.vue'),
        meta: { title: '价格数据', permission: 'analytics.view' },
      },
      {
        path: 'analytics/products',
        name: 'AnalyticsProducts',
        component: () => import('@/views/analytics/Products.vue'),
        meta: { title: '在线产品数据', permission: 'analytics.view' },
      },
      {
        path: 'users',
        name: 'UserList',
        component: () => import('@/views/users/Index.vue'),
        meta: { title: '用户管理', permission: 'user.view' },
      },
      {
        path: 'roles',
        name: 'RoleList',
        component: () => import('@/views/roles/Index.vue'),
        meta: { title: '权限组管理', permission: 'user.assign_role' },
      },
      {
        path: 'activity-logs',
        name: 'ActivityLogs',
        component: () => import('@/views/activity-logs/Index.vue'),
        meta: { title: '操作日志', permission: 'activity_log.view' },
      },
      {
        path: 'settings',
        name: 'Settings',
        component: () => import('@/views/settings/Index.vue'),
        meta: { title: '系统设置', permission: 'setting.manage' },
      },
      {
        path: 'settings/webhooks',
        name: 'Webhooks',
        component: () => import('@/views/settings/Webhooks.vue'),
        meta: { title: 'Webhook 通知', permission: 'webhook.view' },
      },
      {
        path: 'settings/notification-logs',
        name: 'NotificationLogs',
        component: () => import('@/views/settings/NotificationLogs.vue'),
        meta: { title: '通知记录', permission: 'notification.view' },
      },
      {
        path: 'settings/payment-gateways',
        name: 'PaymentGateways',
        component: () => import('@/views/settings/PaymentGateways.vue'),
        meta: { title: '支付网关', permission: 'setting.manage' },
      },
      {
        path: 'settings/forward-plans',
        name: 'ForwardPlans',
        component: () => import('@/views/settings/ForwardPlans.vue'),
        meta: { title: '中转套餐', permission: 'forward.manage' },
      },
      {
        path: 'settings/upstream-providers',
        name: 'UpstreamProviders',
        component: () => import('@/views/settings/UpstreamProviders.vue'),
        meta: { title: 'API 管理', permission: 'setting.manage' },
      },
      {
        path: 'settings/ny-panels',
        name: 'NyPanels',
        component: () => import('@/views/settings/NyPanels.vue'),
        meta: { title: 'NY 面板', permission: 'setting.manage' },
      },
      {
        path: 'settings/xui-panels',
        name: 'XuiPanels',
        component: () => import('@/views/settings/XuiPanels.vue'),
        meta: { title: '3x-ui 面板', permission: 'setting.manage' },
      },
      {
        path: 'settings/dns-monitor',
        name: 'DnsMonitor',
        component: () => import('@/views/settings/DnsMonitor.vue'),
        meta: { title: 'DNS 容灾监控', permission: 'setting.manage' },
      },
      {
        path: 'settings/feishu-sync',
        name: 'FeishuSync',
        component: () => import('@/views/settings/FeishuSync.vue'),
        meta: { title: '飞书同步', permission: 'setting.manage' },
      },
      {
        path: 'settings/queue-monitor',
        name: 'QueueMonitor',
        component: () => import('@/views/settings/QueueMonitor.vue'),
        meta: { title: '队列监控', permission: 'setting.manage' },
      },
      {
        path: 'settings/sms-providers',
        name: 'SmsProviders',
        component: () => import('@/views/settings/SmsProviders.vue'),
        meta: { title: '短信配置', permission: 'setting.manage' },
      },
      {
        path: 'settings/sms-logs',
        name: 'SmsLogs',
        component: () => import('@/views/settings/SmsLogs.vue'),
        meta: { title: '短信记录', permission: 'setting.manage' },
      },
      {
        path: 'settings/api-keys',
        name: 'ApiKeys',
        component: () => import('@/views/settings/ApiKeys.vue'),
        meta: { title: 'API 密钥', permission: 'setting.manage' },
      },
      {
        path: 'settings/store-banner',
        name: 'StoreBanner',
        component: () => import('@/views/settings/StoreBanner.vue'),
        meta: { title: '店铺横幅', permission: 'setting.manage' },
      },
      {
        path: 'settings/float-contact',
        name: 'FloatContact',
        component: () => import('@/views/settings/FloatContact.vue'),
        meta: { title: '悬浮联系', permission: 'setting.manage' },
      },
      {
        path: 'settings/site',
        name: 'SiteSettings',
        component: () => import('@/views/settings/Site.vue'),
        meta: { title: '网站设置', permission: 'setting.manage' },
      },
      {
        path: 'settings/registration',
        name: 'RegistrationSettings',
        component: () => import('@/views/settings/RegistrationSettings.vue'),
        meta: { title: '注册设置', permission: 'setting.manage' },
      },
      {
        path: 'settings/verification',
        name: 'VerificationSettings',
        component: () => import('@/views/settings/Verification.vue'),
        meta: { title: '实名认证设置', permission: 'setting.manage' },
      },
      {
        path: 'settings/referral',
        name: 'ReferralSettings',
        component: () => import('@/views/settings/Referral.vue'),
        meta: { title: '推广设置', permission: 'setting.manage' },
      },
      {
        path: 'settings/router-catalog',
        name: 'RouterCatalog',
        component: () => import('@/views/settings/RouterCatalog.vue'),
        meta: { title: '产品目录', permission: 'router.view' },
      },
      {
        path: 'settings/router-devices',
        name: 'RouterDevices',
        component: () => import('@/views/settings/RouterDevices.vue'),
        meta: { title: '软路由设备', permission: 'router.view' },
      },
      {
        path: 'settings/router-devices/:id',
        name: 'RouterDeviceDetail',
        component: () => import('@/views/settings/RouterDeviceDetail.vue'),
        meta: { title: '设备详情', permission: 'router.view' },
      },
      {
        path: 'settings/wg-servers',
        name: 'WgServers',
        component: () => import('@/views/settings/WgServers.vue'),
        meta: { title: 'WireGuard 服务器', permission: 'router.wg_manage' },
      },
      {
        path: 'settings/spark-product-blocks',
        name: 'SparkProductBlocks',
        component: () => import('@/views/settings/SparkProductBlocks.vue'),
        meta: { title: '产品屏蔽', permission: 'spark.manage' },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth !== false)

  if (requiresAuth && !isLoggedIn()) {
    return next('/login')
  }
  if (to.path === '/login' && isLoggedIn()) {
    return next('/dashboard')
  }

  // 权限检查
  if (requiresAuth && to.meta?.permission) {
    const { useAuthStore } = await import('@/stores/auth')
    const auth = useAuthStore()
    // 确保用户信息已加载
    if (!auth.user) {
      try { await auth.fetchUser() } catch { /* ignore */ }
    }
    if (auth.user) {
      const isSuper = auth.user.roles?.includes('super_admin')
      if (!isSuper) {
        const userPerms = auth.user.permissions || []
        const requiredPerm = to.meta.permission
        // Support array of permissions (OR logic: user needs at least one)
        const hasPerm = Array.isArray(requiredPerm)
          ? requiredPerm.some(p => userPerms.includes(p))
          : userPerms.includes(requiredPerm)
        if (!hasPerm) {
          return next('/dashboard')
        }
      }
    }
  }

  next()
})

export default router
