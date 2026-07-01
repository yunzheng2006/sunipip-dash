import { createRouter, createWebHistory } from 'vue-router'
import { isLoggedIn, getToken } from '@/utils/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/auth/Login.vue'),
    meta: { requiresAuth: false, title: '登录' },
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('@/views/auth/Register.vue'),
    meta: { requiresAuth: false, title: '注册' },
  },
  {
    path: '/',
    component: () => import('@/components/layout/CustomerLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: '/dashboard' },
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/Index.vue'),
        meta: { title: '首页' },
      },
      {
        path: 'store',
        name: 'Store',
        component: () => import('@/views/store/Index.vue'),
        meta: { title: 'IP 商店' },
      },
      {
        path: 'ips',
        name: 'MyIps',
        component: () => import('@/views/ips/Index.vue'),
        meta: { title: '我的 IP' },
      },
      {
        path: 'billing/vip-tiers',
        name: 'VipTiers',
        component: () => import('@/views/billing/VipTiers.vue'),
        meta: { title: '充值折扣' },
      },
      {
        path: 'partnership',
        name: 'Partnership',
        component: () => import('@/views/partnership/Index.vue'),
        meta: { title: '代理合作' },
      },
      {
        path: 'subscriptions',
        name: 'MySubscriptions',
        component: () => import('@/views/subscriptions/Index.vue'),
        meta: { title: '订阅管理' },
      },
      {
        path: 'subscriptions/batch-renew',
        name: 'BatchRenew',
        component: () => import('@/views/subscriptions/BatchRenew.vue'),
        meta: { title: '批量续费' },
      },
      {
        path: 'billing/balance',
        name: 'Balance',
        component: () => import('@/views/billing/Balance.vue'),
        meta: { title: '账户余额' },
      },
      {
        path: 'billing/transactions',
        name: 'Transactions',
        component: () => import('@/views/billing/Transactions.vue'),
        meta: { title: '交易流水' },
      },
      {
        path: 'billing/topup',
        name: 'Topup',
        component: () => import('@/views/billing/Topup.vue'),
        meta: { title: '账户充值' },
      },
      {
        path: 'billing/topup/success',
        name: 'TopupSuccess',
        component: () => import('@/views/billing/TopupSuccess.vue'),
        meta: { title: '支付结果' },
      },
      {
        path: 'referral',
        name: 'Referral',
        component: () => import('@/views/referral/Index.vue'),
        meta: { title: '推广返佣' },
      },
      {
        path: 'verification',
        name: 'Verification',
        component: () => import('@/views/verification/Index.vue'),
        meta: { title: '实名认证' },
      },
      {
        path: 'profile',
        name: 'Profile',
        component: () => import('@/views/profile/Index.vue'),
        meta: { title: '账号设置' },
      },
      {
        path: 'router',
        name: 'RouterDevices',
        component: () => import('@/views/router/Devices.vue'),
        meta: { title: '我的设备' },
      },
      {
        path: 'router/activate',
        name: 'ActivateDevice',
        component: () => import('@/views/router/ActivateDevice.vue'),
        meta: { title: '激活设备' },
      },
      {
        path: 'router/:id',
        name: 'RouterDeviceDetail',
        component: () => import('@/views/router/DeviceDetail.vue'),
        meta: { title: '设备管理' },
      },
    ],
  },
  {
    path: '/verification/complete',
    name: 'VerificationComplete',
    component: () => import('@/views/verification/Complete.vue'),
    meta: { requiresAuth: false, title: '认证完成' },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard',
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth !== false)

  if (requiresAuth && !isLoggedIn()) {
    return next({ name: 'Login', query: { redirect: to.fullPath } })
  }

  if ((to.name === 'Login' || to.name === 'Register') && isLoggedIn()) {
    return next('/dashboard')
  }

  if (to.meta?.title) {
    document.title = `${to.meta.title} · SuniPIP 客户中心`
  }

  next()
})

router.afterEach((to) => {
  const base = import.meta.env.VITE_API_URL || ''
  const headers = { 'Content-Type': 'application/json' }
  const token = getToken()
  if (token) headers['Authorization'] = `Bearer ${token}`
  fetch(`${base}/api/v1/customer/track-visit`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ path: to.path }),
    keepalive: true,
  }).catch(() => {})
})

export default router
