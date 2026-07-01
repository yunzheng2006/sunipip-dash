import { createRouter, createWebHistory } from 'vue-router'
import { isAuthenticated } from '../utils/auth'

const Login = () => import('../views/Login.vue')
const Wizard = () => import('../views/setup/Wizard.vue')
const DeviceLayout = () => import('../components/layout/DeviceLayout.vue')
const Dashboard = () => import('../views/dashboard/Index.vue')
const WifiIndex = () => import('../views/wifi/Index.vue')
const StatusIndex = () => import('../views/status/Index.vue')

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: { requiresAuth: false }
  },
  {
    path: '/setup',
    name: 'Setup',
    component: Wizard,
    meta: { requiresAuth: true }
  },
  {
    path: '/',
    component: DeviceLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: '/dashboard' },
      { path: 'dashboard', name: 'Dashboard', component: Dashboard },
      { path: 'wifi', name: 'WiFi', component: WifiIndex },
      { path: 'status', name: 'Status', component: StatusIndex }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// 导航守卫
router.beforeEach((to, from, next) => {
  const requiresAuth = to.meta.requiresAuth !== false
  if (requiresAuth && !isAuthenticated()) {
    next('/login')
  } else if (to.path === '/login' && isAuthenticated()) {
    next('/dashboard')
  } else {
    next()
  }
})

export default router
