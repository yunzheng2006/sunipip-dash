<template>
  <div class="customer-layout" :class="{ 'sidebar-open': sidebarOpen }">
    <!-- 模拟登录横幅 -->
    <div v-if="isImpersonating" class="impersonate-bar">
      <el-icon :size="14"><Warning /></el-icon>
      <span>模拟 <strong>{{ impersonateName }}</strong></span>
      <el-button size="small" type="danger" @click="exitImpersonate">退出</el-button>
    </div>

    <!-- 左侧 Sidebar（桌面端） -->
    <aside class="sidebar">
      <div class="sidebar-header" @click="$router.push('/dashboard')">
        <span class="sidebar-name">{{ appStore.siteName || 'SuniPIP' }}</span>
      </div>

      <nav class="sidebar-menu">
        <template v-for="item in computedMenu" :key="item.key || item.path">
          <!-- 分组菜单 -->
          <template v-if="item.children">
            <div
              class="sidebar-group-title"
              :class="{ open: expandedGroups[item.key], 'premium-group': true }"
              @click="toggleGroup(item.key)"
            >
              <el-icon :size="18"><component :is="item.icon" /></el-icon>
              <span>{{ item.label }}</span>
              <el-icon class="group-arrow" :size="12"><ArrowRight /></el-icon>
            </div>
            <div class="sidebar-group-children" :class="{ open: expandedGroups[item.key] }">
              <div class="sidebar-group-inner">
                <router-link
                  v-for="child in item.children"
                  :key="child.path"
                  :to="child.path"
                  class="sidebar-link child"
                  :class="{ active: isMenuActive(child.path) }"
                >
                  <span>{{ child.label }}</span>
                </router-link>
              </div>
            </div>
          </template>
          <!-- 外链菜单 -->
          <a
            v-else-if="item.external"
            :href="item.external"
            target="_blank"
            rel="noopener"
            class="sidebar-link premium-link"
          >
            <el-icon :size="18"><component :is="item.icon" /></el-icon>
            <span>{{ item.label }}</span>
          </a>
          <!-- 普通菜单 -->
          <router-link
            v-else
            :to="item.path"
            class="sidebar-link premium-link"
            :class="{ active: isMenuActive(item.path) }"
          >
            <el-icon :size="18"><component :is="item.icon" /></el-icon>
            <span>{{ item.label }}</span>
          </router-link>
        </template>
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-user" @click="$router.push('/profile')">
          <el-avatar :size="32" :style="{ background: '#6366F1', cursor: 'pointer', flexShrink: 0, fontSize: '13px' }">
            {{ customerInitial }}
          </el-avatar>
          <div class="sidebar-user-info">
            <div class="sidebar-user-name">{{ customerName }}</div>
            <div class="sidebar-user-balance">¥{{ balance.toFixed(2) }}</div>
            <div v-if="commissionBalance > 0" class="sidebar-user-commission" title="返佣余额">返佣 ¥{{ commissionBalance.toFixed(2) }}</div>
          </div>
        </div>
      </div>
    </aside>

    <!-- 右侧主区域 -->
    <div class="main-wrapper">
      <!-- 顶部 Header -->
      <header class="topbar">
        <div class="topbar-inner">
          <!-- 移动端汉堡按钮（由 CSS 控制显示） -->
          <div class="mobile-menu-btn" @click="sidebarOpen = !sidebarOpen">
            <el-icon :size="22"><Fold v-if="sidebarOpen" /><Expand v-else /></el-icon>
          </div>

          <!-- 移动端 logo -->
          <div class="mobile-logo" @click="$router.push('/dashboard')">
            <img v-if="appStore.siteLogo" :src="appStore.siteLogo" class="mobile-logo-img" />
            <span class="mobile-logo-text">{{ appStore.siteName || 'SuniPIP' }}</span>
          </div>

          <!-- Logo -->
          <div class="topbar-logo" @click="$router.push('/dashboard')">
            <img v-if="appStore.siteLogo" :src="appStore.siteLogo" class="topbar-logo-img" />
            <div v-else class="topbar-logo-icon">{{ (appStore.siteName || 'S')[0] }}</div>
          </div>

          <div class="topbar-right">
            <div class="balance-chip" @click="$router.push('/billing/balance')">
              <el-icon><Wallet /></el-icon>
              <span>¥{{ balance.toFixed(2) }}</span>
            </div>
            <el-dropdown @command="onMenuCommand">
              <el-avatar :size="30" :style="{ background: '#6366F1', cursor: 'pointer', flexShrink: 0, fontSize: '12px' }">
                {{ customerInitial }}
              </el-avatar>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item disabled style="font-weight:600;color:#1E293B">{{ customerName }}</el-dropdown-item>
                  <el-dropdown-item divided command="profile"><el-icon><User /></el-icon> 账号</el-dropdown-item>
                  <el-dropdown-item command="security"><el-icon><Lock /></el-icon> 改密码</el-dropdown-item>
                  <el-dropdown-item command="referral"><el-icon><Share /></el-icon> 推广返佣</el-dropdown-item>
                  <el-dropdown-item divided command="logout"><el-icon><SwitchButton /></el-icon> 退出</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </div>
      </header>

      <!-- 主内容区 -->
      <main class="main-content">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </main>
    </div>

    <!-- 侧栏遮罩（移动端） -->
    <div class="sidebar-overlay" v-if="sidebarOpen" @click="sidebarOpen = false"></div>

    <!-- 悬浮联系（订阅管理页隐藏） -->
    <FloatContact v-if="route.name !== 'MySubscriptions'" />

    <!-- 移动端底部 Tab -->
    <nav class="tabbar">
      <router-link v-for="item in mobileMenu" :key="item.path" :to="item.path" class="tab"
        :class="{ active: isMenuActive(item.path) }">
        <el-icon :size="22"><component :is="item.icon" /></el-icon>
        <span>{{ item.mobileLabel || item.label }}</span>
      </router-link>
    </nav>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Warning, Share, Fold, Expand, ArrowRight } from '@element-plus/icons-vue'
import FloatContact from '@/components/FloatContact.vue'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import { removeToken } from '@/utils/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const appStore = useAppStore()

const sidebarOpen = ref(false)

const impersonateName = ref(localStorage.getItem('sunipip_impersonate') || '')
const isImpersonating = computed(() => !!impersonateName.value)

function exitImpersonate() {
  localStorage.removeItem('sunipip_impersonate')
  removeToken(); authStore.token = ''; authStore.customer = null; impersonateName.value = ''
  try { window.close() } catch {}
  setTimeout(() => router.push('/login'), 100)
}

onMounted(async () => {
  appStore.fetchSiteInfo()
  if (!authStore.customer) await authStore.fetchMe()
})

// 路由切换关闭移动端侧栏
watch(() => route.path, () => { sidebarOpen.value = false })

const customerName = computed(() => authStore.customer?.customer_name || authStore.customer?.username || '用户')
const customerInitial = computed(() => customerName.value?.charAt(0) || 'U')
const balance = computed(() => authStore.balance)
const commissionBalance = computed(() => authStore.commissionBalance)

const referralRate = computed(() => authStore.customer?.referral_rate || 5)

const menuItems = [
  { label: '首页', icon: 'HomeFilled', external: 'https://sunipip.com' },
  { path: '/dashboard', label: '仪表盘', icon: 'DataLine' },
  {
    key: 'manage', label: 'IP 管理', icon: 'Monitor',
    children: [
      { path: '/store', label: '购买 IP' },
      { path: '/ips', label: '我的 IP' },
      { path: '/subscriptions', label: '续费管理' },
      { path: '/subscriptions/batch-renew', label: '批量续费' },
    ],
  },
  {
    key: 'promo', label: '推广合作', icon: 'Promotion',
    children: [
      { path: '/partnership', label: '成为代理商' },
      { path: '/referral', label: '推广返佣', dynamic: true },
    ],
  },
  {
    key: 'billing', label: '账单管理', icon: 'Tickets',
    children: [
      { path: '/billing/balance', label: '账单明细' },
      { path: '/verification', label: '实名认证' },
    ],
  },
  {
    key: 'router', label: '软路由', icon: 'SetUp',
    children: [
      { path: '/router', label: '我的设备' },
      { path: '/router/activate', label: '激活设备' },
    ],
  },
]

const expandedGroups = reactive({ manage: true, router: false, promo: true, billing: true })

// 当前路由在某个分组下时，自动展开该分组
const groupPathMap = { router: ['/router'] }
watch(() => route.path, (p) => {
  for (const [key, prefixes] of Object.entries(groupPathMap)) {
    if (prefixes.some(pre => p === pre || p.startsWith(pre + '/'))) {
      expandedGroups[key] = true
    }
  }
}, { immediate: true })

function toggleGroup(key) {
  expandedGroups[key] = !expandedGroups[key]
}

const allFlatPaths = computed(() => {
  const paths = []
  for (const item of menuItems) {
    if (item.children) {
      for (const child of item.children) paths.push(child.path)
    } else if (item.path) {
      paths.push(item.path)
    }
  }
  return paths
})

const computedMenu = computed(() => menuItems.map(item => {
  if (item.children) {
    return {
      ...item,
      children: item.children.map(child => {
        if (child.dynamic && child.path === '/referral') {
          return { ...child, label: `推广返佣${referralRate.value}%` }
        }
        return child
      }),
    }
  }
  return item
}))

const mobileMenu = [
  { path: '/dashboard', label: '首页', mobileLabel: '首页', icon: 'HomeFilled' },
  { path: '/store', label: '购买 IP', mobileLabel: '商店', icon: 'Shop' },
  { path: '/ips', label: '我的 IP', mobileLabel: 'IP', icon: 'Monitor' },
  { path: '/subscriptions', label: '续费管理', mobileLabel: '续费', icon: 'Calendar' },
  { path: '/billing/balance', label: '充值消费明细', mobileLabel: '明细', icon: 'Tickets' },
]

function isMenuActive(path) {
  if (route.path === path) return true
  if (route.path.startsWith(path + '/')) {
    const paths = allFlatPaths.value
    return !paths.some(p => p !== path && p.startsWith(path) && (route.path === p || route.path.startsWith(p + '/')))
  }
  return false
}

async function onMenuCommand(cmd) {
  if (cmd === 'logout') {
    try { await ElMessageBox.confirm('确认退出？', '退出', { type: 'warning' }) } catch { return }
    await authStore.logout(); ElMessage.success('已退出'); router.push('/login')
  } else if (cmd === 'profile') { router.push('/profile') }
  else if (cmd === 'security') { router.push('/profile?tab=security') }
  else if (cmd === 'referral') { router.push('/referral') }
}
</script>

<style lang="scss" scoped>
$brand: #6366F1;
$brand-light: #EEF2FF;
$brand-border: #C7D2FE;
$sidebar-bg: #0F172A;
$sidebar-w: 220px;
$dark: #0F172A;
$gray: #64748B;
$muted: #94A3B8;
$bg: #F8FAFC;
$border: #E2E8F0;
$accent: #F59E0B;

$bp: 768px;
$tabH: 56px;

.customer-layout {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  background: $bg;
}

// ===== 模拟登录 =====
.impersonate-bar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 300;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 6px 12px; background: #FEF0F0; border-bottom: 2px solid #F56C6C;
  color: #F56C6C; font-size: 12px;
  strong { color: $dark; }
}

// ===== 左侧 Sidebar =====
.sidebar {
  width: $sidebar-w;
  min-height: 100vh;
  background: $sidebar-bg;
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  z-index: 150;
  transition: transform 0.25s ease;
}

.sidebar-header {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px 18px 16px;
  cursor: pointer;
  border-bottom: 1px solid rgba(255,255,255,0.08);

  .sidebar-name {
    font-size: 16px;
    font-weight: 700;
    color: #F1F5F9;
    letter-spacing: 0.5px;
  }
}

.sidebar-menu {
  flex: 1;
  min-height: 0;
  padding: 12px 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,0.15) transparent;
  &::-webkit-scrollbar { width: 4px; }
  &::-webkit-scrollbar-track { background: transparent; }
  &::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 2px; }
  &::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }

  .sidebar-group-title {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    color: rgba(255,255,255,0.45);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    user-select: none;
    margin-top: 6px;

    .group-arrow {
      margin-left: auto;
      transition: transform 0.2s ease;
    }
    &.open .group-arrow {
      transform: rotate(90deg);
    }
    &:hover {
      color: rgba(255,255,255,0.75);
    }

    &.premium-group {
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(99, 130, 255, 0.25);
      background: rgba(99, 130, 255, 0.06);
      color: rgba(255,255,255,0.6);

      span, .el-icon { position: relative; z-index: 1; }

      &::before {
        content: '';
        position: absolute; inset: 0;
        z-index: 0;
        background: linear-gradient(
          105deg,
          transparent 40%,
          rgba(120, 150, 255, 0.18) 47%,
          rgba(170, 195, 255, 0.28) 50%,
          rgba(120, 150, 255, 0.18) 53%,
          transparent 60%
        );
        background-size: 400% 100%;
        animation: menu-shimmer 10s ease-in-out infinite;
        pointer-events: none;
      }

      &:hover {
        background: rgba(99, 130, 255, 0.14);
        border-color: rgba(99, 130, 255, 0.4);
        color: rgba(255,255,255,0.85);
      }
      &.open {
        background: rgba(99, 130, 255, 0.1);
        color: rgba(255,255,255,0.8);
      }
    }
  }

  .sidebar-group-children {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows 0.2s ease;
    &.open {
      grid-template-rows: 1fr;
    }
    .sidebar-group-inner {
      overflow: hidden;
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
  }

  .sidebar-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: rgba(255,255,255,0.55);
    font-size: 14px;
    font-weight: 500;
    transition: all 0.15s;
    white-space: nowrap;

    &.child {
      padding-left: 42px;
      font-size: 12.5px;
    }

    &:hover {
      background: rgba(255,255,255,0.08);
      color: rgba(255,255,255,0.9);
    }
    &.active {
      background: rgba($brand, 0.25);
      color: #fff;
      font-weight: 600;
    }

    &.premium-link {
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(99, 130, 255, 0.25);
      background: rgba(99, 130, 255, 0.06);
      color: rgba(255,255,255,0.6);
      font-weight: 600;

      span, .el-icon { position: relative; z-index: 1; }

      &::before {
        content: '';
        position: absolute; inset: 0;
        z-index: 0;
        background: linear-gradient(
          105deg,
          transparent 40%,
          rgba(120, 150, 255, 0.18) 47%,
          rgba(170, 195, 255, 0.28) 50%,
          rgba(120, 150, 255, 0.18) 53%,
          transparent 60%
        );
        background-size: 400% 100%;
        animation: menu-shimmer 10s ease-in-out infinite;
        pointer-events: none;
      }

      &:hover {
        background: rgba(99, 130, 255, 0.14);
        border-color: rgba(99, 130, 255, 0.4);
        color: rgba(255,255,255,0.85);
      }
      &.active {
        background: rgba($brand, 0.3);
        border-color: rgba($brand, 0.5);
        color: #fff;
      }
    }
  }
}

@keyframes menu-shimmer {
  0%   { background-position: 0% center; }
  30%  { background-position: 100% center; }
  100% { background-position: 100% center; }
}

.sidebar-footer {
  padding: 12px 14px 16px;
  border-top: 1px solid rgba(255,255,255,0.08);
}

.sidebar-user {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.15s;
  &:hover { background: rgba(255,255,255,0.06); }
}

.sidebar-user-info {
  flex: 1;
  min-width: 0;
}
.sidebar-user-name {
  font-size: 13px;
  font-weight: 600;
  color: rgba(255,255,255,0.9);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sidebar-user-balance {
  font-size: 12px;
  color: $accent;
  font-weight: 600;
  font-family: 'SF Mono', Consolas, monospace;
}
.sidebar-user-commission {
  font-size: 11px;
  color: #10B981;
  font-weight: 600;
  font-family: 'SF Mono', Consolas, monospace;
  margin-top: 1px;
}

// ===== 右侧主区域 =====
.main-wrapper {
  flex: 1;
  margin-left: $sidebar-w;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

// ===== 顶栏 =====
.topbar {
  background: #fff;
  border-bottom: 1px solid $border;
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-inner {
  height: 52px;
  padding: 0 24px;
  display: flex;
  align-items: center;
  gap: 16px;
}

.mobile-menu-btn { display: none; }
.mobile-logo { display: none; }

.topbar-logo {
  cursor: pointer;
  flex-shrink: 0;
  display: flex;
  align-items: center;

  .topbar-logo-img {
    height: 48px;
    object-fit: contain;
    border-radius: 8px;
  }
  .topbar-logo-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, $brand, #818CF8);
    color: #fff;
    font-size: 18px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
  }
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  margin-left: auto;
}

.balance-chip {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 5px 14px;
  background: $dark;
  border-radius: 20px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
  color: #F1F5F9;
  font-family: 'SF Mono', Consolas, monospace;
  transition: all 0.15s;
  .el-icon { font-size: 14px; color: $accent; }
  &:hover { background: #1E293B; }
}

// ===== 主内容 =====
.main-content {
  flex: 1;
  max-width: 1440px;
  margin: 0 auto;
  width: 100%;
  padding: 24px 28px;
  box-sizing: border-box;
}

// ===== 底部 Tab（默认隐藏，移动端显示） =====
.tabbar { display: none; }
.sidebar-overlay { display: none; }

// ===== 过渡 =====
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

// =========================================================
// 移动端
// =========================================================
@media (max-width: $bp) {
  .customer-layout { flex-direction: column; }

  // 隐藏桌面端 sidebar
  .sidebar {
    transform: translateX(-100%);
    z-index: 250;
  }
  .customer-layout.sidebar-open .sidebar {
    transform: translateX(0);
  }

  .sidebar-overlay {
    display: block;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    z-index: 200;
  }

  .main-wrapper {
    margin-left: 0;
  }

  .mobile-menu-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: $gray;
    padding: 4px;
  }

  .mobile-logo {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    .mobile-logo-img {
      height: 32px;
      object-fit: contain;
      border-radius: 5px;
    }
    .mobile-logo-text {
      font-size: 15px;
      font-weight: 700;
      color: $dark;
    }
  }

  .topbar-logo { display: none; }
  .balance-chip { padding: 3px 8px; font-size: 11px;
    .el-icon { font-size: 12px; }
  }

  .topbar-inner { height: 44px; padding: 0 10px; gap: 8px; }

  .main-content {
    padding: 10px 8px calc(#{$tabH} + env(safe-area-inset-bottom, 0px) + 8px) 8px;
    max-width: 100%;
    overflow-x: hidden;
  }

  // 底部 Tab
  .tabbar {
    display: flex;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    background: #fff;
    border-top: 1px solid $border;
    height: $tabH;
    padding-bottom: env(safe-area-inset-bottom, 0px);
    box-shadow: 0 -1px 8px rgba(0,0,0,0.06);

    .tab {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 2px;
      text-decoration: none;
      color: $muted;
      font-size: 10px;
      font-weight: 500;
      -webkit-tap-highlight-color: transparent;
      transition: color 0.15s;
      min-width: 0;
      padding: 0 2px;
      overflow: hidden;
      white-space: nowrap;
      &.active { color: $brand; font-weight: 600; }
    }
  }

  // 全局移动优化
  :deep(.el-card) {
    border-radius: 10px;
    &>.el-card__body { padding: 14px 12px; }
  }
  :deep(.el-dialog) {
    width: 94vw !important;
    max-width: 94vw !important;
    margin: 8vh auto !important;
    .el-dialog__header { padding: 12px 14px 8px; }
    .el-dialog__body { padding: 8px 14px; }
    .el-dialog__footer { padding: 8px 14px 12px; }
  }
  :deep(.el-table) { font-size: 12px; }
  :deep(.el-pagination) {
    flex-wrap: wrap;
    justify-content: center;
    .el-pagination__sizes, .el-pagination__jump { display: none; }
  }
  :deep(.el-message-box) { width: 90vw !important; }
  :deep(.el-tabs__item) { padding: 0 10px; font-size: 13px; }
}
</style>
