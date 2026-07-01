<template>
  <el-container class="layout-container">
    <!-- 移动端顶部栏 -->
    <el-header class="layout-header">
      <div class="header-left">
        <el-icon class="menu-toggle" @click="toggleSidebar" :size="22">
          <Fold v-if="!sidebarCollapsed" />
          <Expand v-else />
        </el-icon>
        <span class="brand-title">SuniPIP Router</span>
      </div>
      <div class="header-right">
        <span class="user-name">{{ userName }}</span>
        <el-button type="danger" text @click="handleLogout">
          <el-icon><SwitchButton /></el-icon>
          退出
        </el-button>
      </div>
    </el-header>

    <el-container class="layout-body">
      <!-- 侧边栏 -->
      <el-aside :width="sidebarWidth" class="layout-aside" :class="{ collapsed: sidebarCollapsed, 'mobile-visible': mobileMenuOpen }">
        <el-menu
          :default-active="activeMenu"
          router
          :collapse="sidebarCollapsed && !isMobile"
          class="sidebar-menu"
        >
          <el-menu-item index="/dashboard">
            <el-icon><Monitor /></el-icon>
            <span>仪表盘</span>
          </el-menu-item>
          <el-menu-item index="/wifi">
            <el-icon><Cellphone /></el-icon>
            <span>WiFi 管理</span>
          </el-menu-item>
          <el-menu-item index="/status">
            <el-icon><DataBoard /></el-icon>
            <span>系统状态</span>
          </el-menu-item>
        </el-menu>
      </el-aside>

      <!-- 移动端遮罩 -->
      <div v-if="mobileMenuOpen" class="mobile-overlay" @click="mobileMenuOpen = false"></div>

      <!-- 主内容区 -->
      <el-main class="layout-main">
        <router-view />
        <div class="layout-footer">
          Powered by SuniPIP
        </div>
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { removeToken } from '../../utils/auth'
import { getMe } from '../../api/auth'
import { Monitor, Cellphone, DataBoard, SwitchButton, Fold, Expand } from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()

const userName = ref('')
const sidebarCollapsed = ref(false)
const mobileMenuOpen = ref(false)
const isMobile = ref(false)

const activeMenu = computed(() => route.path)

const sidebarWidth = computed(() => {
  if (isMobile.value) return '200px'
  return sidebarCollapsed.value ? '64px' : '200px'
})

function toggleSidebar() {
  if (isMobile.value) {
    mobileMenuOpen.value = !mobileMenuOpen.value
  } else {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }
}

function handleLogout() {
  removeToken()
  router.push('/login')
}

function checkMobile() {
  isMobile.value = window.innerWidth < 768
  if (isMobile.value) {
    sidebarCollapsed.value = true
    mobileMenuOpen.value = false
  }
}

// 路由切换时关闭移动菜单
watch(() => route.path, () => {
  if (isMobile.value) {
    mobileMenuOpen.value = false
  }
})

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  try {
    const { data } = await getMe()
    userName.value = data.data?.name || data.data?.email || '用户'
  } catch {
    userName.value = '用户'
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>

<style scoped>
.layout-container {
  height: 100vh;
  flex-direction: column;
}

.layout-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border-bottom: 1px solid #e4e7ed;
  padding: 0 20px;
  height: 56px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
  z-index: 100;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.menu-toggle {
  cursor: pointer;
  color: #606266;
}

.menu-toggle:hover {
  color: #409eff;
}

.brand-title {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.user-name {
  font-size: 14px;
  color: #606266;
}

.layout-body {
  flex: 1;
  overflow: hidden;
}

.layout-aside {
  background: #fff;
  border-right: 1px solid #e4e7ed;
  transition: width 0.3s;
  overflow: hidden;
}

.sidebar-menu {
  border-right: none;
  height: 100%;
}

.layout-main {
  background: #f5f7fa;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.layout-footer {
  text-align: center;
  padding: 20px 0 10px;
  color: #909399;
  font-size: 12px;
  margin-top: auto;
}

/* 移动端样式 */
@media (max-width: 767px) {
  .layout-aside {
    position: fixed;
    top: 56px;
    left: 0;
    bottom: 0;
    z-index: 200;
    transform: translateX(-100%);
    transition: transform 0.3s;
    box-shadow: 2px 0 8px rgba(0, 0, 0, 0.1);
  }

  .layout-aside.mobile-visible {
    transform: translateX(0);
  }

  .mobile-overlay {
    position: fixed;
    top: 56px;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    z-index: 199;
  }

  .layout-main {
    padding: 12px;
  }

  .user-name {
    display: none;
  }
}
</style>
