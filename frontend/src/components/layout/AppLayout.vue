<template>
  <el-container class="app-layout" :class="{ 'mobile-sidebar-open': appStore.mobileSidebarOpen }">
    <!-- Mobile overlay -->
    <div
      v-if="appStore.mobileSidebarOpen"
      class="sidebar-overlay"
      @click="appStore.closeMobileSidebar()"
    ></div>

    <!-- Sidebar -->
    <el-aside :width="appStore.sidebarCollapsed ? '64px' : '230px'" class="sidebar">
      <div class="logo">
        <img v-if="appStore.siteLogo" :src="appStore.siteLogo" class="logo-img" />
        <div v-else class="logo-icon">{{ (appStore.siteName || 'S')[0] }}</div>
        <transition name="fade">
          <span v-if="!appStore.sidebarCollapsed" class="logo-text">{{ appStore.siteName || 'SuniPIP' }}</span>
        </transition>
      </div>
      <el-scrollbar>
        <el-menu
          :default-active="activeMenu"
          :collapse="appStore.sidebarCollapsed"
          :collapse-transition="false"
          background-color="transparent"
          text-color="rgba(255,255,255,0.65)"
          active-text-color="#F2A85A"
          router
          class="sidebar-menu"
          @select="onMenuSelect"
        >
          <!-- ===== 核心业务 ===== -->
          <el-menu-item v-if="can('dashboard.view')" index="/dashboard">
            <el-icon><Odometer /></el-icon>
            <template #title>仪表盘</template>
          </el-menu-item>

          <el-menu-item v-if="can('customer.view')" index="/customers">
            <el-icon><User /></el-icon>
            <template #title>客户管理</template>
          </el-menu-item>

          <el-sub-menu v-if="can('ip.view') || can('asset_group.view')" index="proxy-ips">
            <template #title>
              <el-icon><Monitor /></el-icon>
              <span>IP 资产</span>
            </template>
            <el-menu-item v-if="can('ip.view')" index="/proxy-ips">IP 列表</el-menu-item>
            <el-menu-item v-if="can('ip.view')" index="/proxy-ips/test-pool">测试IP池</el-menu-item>
            <el-menu-item v-if="can('asset_group.view')" index="/ip-groups">IP 组</el-menu-item>
            <el-menu-item v-if="can('asset_group.view')" index="/asset-groups">资产组</el-menu-item>
            <el-menu-item v-if="can('ip.import')" index="/proxy-ips/import">批量导入</el-menu-item>
          </el-sub-menu>

          <el-sub-menu v-if="can('subscription.view') || can('approval.view') || can('subscription.submit_approval')" index="orders">
            <template #title>
              <el-icon><Calendar /></el-icon>
              <span>订单中心</span>
            </template>
            <el-menu-item v-if="can('subscription.view')" index="/subscriptions">订阅管理</el-menu-item>
            <el-menu-item v-if="can('subscription.create') || can('subscription.submit_approval')" index="/subscriptions/create">创建订单</el-menu-item>
            <el-menu-item v-if="can('approval.view')" index="/approvals">审批中心</el-menu-item>
            <el-menu-item v-if="can('spark.view')" index="/spark-orders">API 订单</el-menu-item>
          </el-sub-menu>

          <!-- ===== 财务中心 ===== -->
          <el-sub-menu v-if="can('transaction.view') || can('pricing.view') || can('customer.view') || can('performance.view')" index="billing">
            <template #title>
              <el-icon><Wallet /></el-icon>
              <span>财务中心</span>
            </template>
            <el-menu-item v-if="can('pricing.view')" index="/billing/pricing-multipliers">销售倍率</el-menu-item>
            <el-menu-item v-if="can('pricing.manage') || can('pricing.set_discount')" index="/billing/special-prices">客户特批价</el-menu-item>
            <el-menu-item v-if="can('pricing.view')" index="/billing/vip-tiers">VIP会员</el-menu-item>
            <el-menu-item v-if="can('transaction.view')" index="/billing/payment-orders">充值订单</el-menu-item>
            <el-menu-item v-if="can('transaction.view')" index="/billing/transactions">交易流水</el-menu-item>
            <el-menu-item v-if="can('transaction.view')" index="/billing/finance">财务总览</el-menu-item>
            <el-menu-item v-if="can('customer.view')" index="/billing/sales-stats">销售统计</el-menu-item>
            <el-menu-item v-if="can('customer.view')" index="/billing/sales-stats-new">业绩统计</el-menu-item>
            <el-menu-item v-if="can('performance.view')" index="/billing/performance">业绩检索</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/payment-gateways">支付网关</el-menu-item>
          </el-sub-menu>

          <!-- ===== 转发中转 ===== -->
          <el-sub-menu v-if="can('forward.manage') || can('forward.view')" index="forward">
            <template #title>
              <el-icon><Share /></el-icon>
              <span>转发中转</span>
            </template>
            <el-menu-item v-if="can('forward.manage')" index="/settings/forward-plans">中转套餐</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/ny-panels">NY 转发面板</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/xui-panels">3x-ui 中转</el-menu-item>
          </el-sub-menu>

          <!-- ===== 数据看板 ===== -->
          <el-sub-menu v-if="can('analytics.view')" index="analytics">
            <template #title>
              <el-icon><DataAnalysis /></el-icon>
              <span>数据看板</span>
            </template>
            <el-menu-item index="/analytics/bigdata">数据监控中心</el-menu-item>
            <el-menu-item index="/analytics/marketing">营销数据</el-menu-item>
            <el-menu-item index="/analytics/pricing">价格数据</el-menu-item>
            <el-menu-item index="/analytics/products">在线产品数据</el-menu-item>
          </el-sub-menu>

          <!-- ===== 团队管理 ===== -->
          <el-sub-menu v-if="can('user.view') || can('activity_log.view')" index="team">
            <template #title>
              <el-icon><UserFilled /></el-icon>
              <span>团队管理</span>
            </template>
            <el-menu-item v-if="can('user.view')" index="/users">用户账号</el-menu-item>
            <el-menu-item v-if="can('user.assign_role')" index="/roles">权限组</el-menu-item>
            <el-menu-item v-if="can('activity_log.view')" index="/activity-logs">操作日志</el-menu-item>
          </el-sub-menu>

          <!-- ===== 软路由管理 ===== -->
          <el-sub-menu v-if="can('router.view') || can('router.wg_manage')" index="router">
            <template #title>
              <el-icon><Monitor /></el-icon>
              <span>软路由管理</span>
            </template>
            <el-menu-item v-if="can('router.view')" index="/settings/router-catalog">产品目录</el-menu-item>
            <el-menu-item v-if="can('router.view')" index="/settings/router-devices">设备库存</el-menu-item>
            <el-menu-item v-if="can('router.wg_manage')" index="/settings/wg-servers">WG 服务器</el-menu-item>
          </el-sub-menu>

          <!-- ===== 系统设置 ===== -->
          <el-sub-menu v-if="can('setting.manage') || can('webhook.view')" index="system">
            <template #title>
              <el-icon><Setting /></el-icon>
              <span>系统设置</span>
            </template>
            <el-menu-item v-if="can('setting.manage')" index="/settings/upstream-providers">API 管理</el-menu-item>
            <el-menu-item v-if="can('spark.manage')" index="/settings/spark-product-blocks">产品屏蔽</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/site">网站设置</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/store-banner">店铺横幅</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/float-contact">悬浮联系</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/api-keys">API 密钥</el-menu-item>
            <el-menu-item v-if="can('webhook.view')" index="/settings/webhooks">Webhook 通知</el-menu-item>
            <el-menu-item v-if="can('notification.view')" index="/settings/notification-logs">通知记录</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/sms-providers">短信配置</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/sms-logs">短信记录</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/registration">注册设置</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/referral">推广邀请</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/verification">实名认证</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/feishu-sync">飞书同步</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/dns-monitor">DNS 容灾</el-menu-item>
            <el-menu-item v-if="can('setting.manage')" index="/settings/queue-monitor">队列监控</el-menu-item>
          </el-sub-menu>
        </el-menu>
      </el-scrollbar>
    </el-aside>

    <!-- Main area -->
    <el-container>
      <!-- Top navbar -->
      <el-header class="navbar">
        <div class="navbar-left">
          <!-- 桌面端折叠按钮 -->
          <div class="collapse-btn" @click="appStore.toggleSidebar">
            <el-icon :size="18">
              <Fold v-if="!appStore.sidebarCollapsed" />
              <Expand v-else />
            </el-icon>
          </div>
          <!-- 移动端汉堡按钮 -->
          <div class="mobile-menu-btn" @click="appStore.toggleMobileSidebar">
            <el-icon :size="20"><Menu /></el-icon>
          </div>
          <el-breadcrumb separator="/" class="nav-breadcrumb">
            <el-breadcrumb-item :to="{ path: '/dashboard' }">首页</el-breadcrumb-item>
            <el-breadcrumb-item v-if="currentTitle">{{ currentTitle }}</el-breadcrumb-item>
          </el-breadcrumb>
          <!-- 移动端页面标题 -->
          <div class="mobile-page-title">{{ currentTitle || '管理后台' }}</div>
        </div>
        <div class="navbar-right">
          <el-dropdown trigger="click" @command="handleCommand">
            <div class="user-avatar-dropdown">
              <div class="avatar-circle">
                {{ (authStore.userName || '管')[0] }}
              </div>
              <span class="username">{{ authStore.userName || '管理员' }}</span>
              <el-icon :size="12" class="dropdown-arrow"><ArrowDown /></el-icon>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="profile">
                  <el-icon><User /></el-icon>个人信息
                </el-dropdown-item>
                <el-dropdown-item command="password">
                  <el-icon><Lock /></el-icon>修改密码
                </el-dropdown-item>
                <el-dropdown-item divided command="logout">
                  <el-icon><SwitchButton /></el-icon>退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <!-- Content -->
      <el-main class="main-content">
        <router-view v-slot="{ Component }">
          <component :is="Component" />
        </router-view>
      </el-main>
    </el-container>

    <!-- Change password dialog -->
    <el-dialog v-model="passwordDialogVisible" title="修改密码" width="420px">
      <el-form ref="passwordFormRef" :model="passwordForm" :rules="passwordRules" label-width="80px">
        <el-form-item label="旧密码" prop="old_password">
          <el-input v-model="passwordForm.old_password" type="password" show-password />
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input v-model="passwordForm.new_password" type="password" show-password />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirm_password">
          <el-input v-model="passwordForm.confirm_password" type="password" show-password />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="passwordDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="passwordLoading" @click="submitPassword">确定</el-button>
      </template>
    </el-dialog>
  </el-container>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAppStore } from '@/stores/app'
import { useAuthStore } from '@/stores/auth'
import { changePassword } from '@/api/auth'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const authStore = useAuthStore()

const activeMenu = computed(() => route.path)
const currentTitle = computed(() => route.meta?.title || '')

// 权限判断
function can(permission) {
  if (!authStore.user) return false
  if (authStore.user.roles?.includes('super_admin')) return true
  return (authStore.user.permissions || []).includes(permission)
}

// 手机端：路由切换 / 选中菜单项时自动关闭侧边栏
function onMenuSelect() {
  if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
    appStore.closeMobileSidebar()
  }
}
watch(() => route.path, () => {
  appStore.closeMobileSidebar()
})

// Change password
const passwordDialogVisible = ref(false)
const passwordLoading = ref(false)
const passwordFormRef = ref(null)
const passwordForm = ref({
  old_password: '',
  new_password: '',
  confirm_password: '',
})

const passwordRules = {
  old_password: [{ required: true, message: '请输入旧密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, message: '密码至少6个字符', trigger: 'blur' },
  ],
  confirm_password: [
    { required: true, message: '请确认新密码', trigger: 'blur' },
    {
      validator: (rule, value, callback) => {
        if (value !== passwordForm.value.new_password) {
          callback(new Error('两次输入的密码不一致'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

async function submitPassword() {
  const valid = await passwordFormRef.value.validate().catch(() => false)
  if (!valid) return
  passwordLoading.value = true
  try {
    await changePassword({
      old_password: passwordForm.value.old_password,
      new_password: passwordForm.value.new_password,
    })
    ElMessage.success('密码修改成功')
    passwordDialogVisible.value = false
    passwordForm.value = { old_password: '', new_password: '', confirm_password: '' }
  } catch {
    // Error handled by interceptor
  } finally {
    passwordLoading.value = false
  }
}

function handleCommand(command) {
  if (command === 'logout') {
    authStore.logout()
    router.push('/login')
  } else if (command === 'password') {
    passwordDialogVisible.value = true
  } else if (command === 'profile') {
    router.push('/settings')
  }
}

if (authStore.isLoggedIn && !authStore.user) {
  authStore.fetchUser()
}
appStore.fetchSiteInfo()
</script>

<style lang="scss" scoped>
.app-layout {
  height: 100vh;
  position: relative;
}

// ===== 侧边栏 =====
.sidebar {
  background: linear-gradient(180deg, #1A1A2E 0%, #16213E 100%);
  overflow: hidden;
  transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  border-right: 1px solid rgba(255, 255, 255, 0.04);

  .logo {
    height: 56px;
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);

    .logo-img {
      height: 34px;
      max-width: 34px;
      object-fit: contain;
      border-radius: 8px;
      flex-shrink: 0;
    }

    .logo-icon {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      background: linear-gradient(135deg, #E8913A, #F2A85A);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 18px;
      font-weight: 700;
      flex-shrink: 0;
    }

    .logo-text {
      color: #fff;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 2px;
    }
  }

  .sidebar-menu {
    border-right: none !important;

    :deep(.el-menu-item),
    :deep(.el-sub-menu__title) {
      height: 48px;
      line-height: 48px;
      margin: 2px 8px;
      border-radius: 8px;
      transition: all 0.2s;

      &:hover {
        background: rgba(242, 168, 90, 0.12) !important;
        color: rgba(255, 255, 255, 0.9) !important;
      }
    }

    :deep(.el-menu-item.is-active) {
      background: rgba(242, 168, 90, 0.15) !important;
      color: #F2A85A !important;
      font-weight: 500;

      &::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 20px;
        border-radius: 0 2px 2px 0;
        background: #F2A85A;
      }
    }

    :deep(.el-sub-menu .el-menu-item) {
      padding-left: 52px !important;
      height: 44px;
      line-height: 44px;
      font-size: 13px;
    }
  }
}

// ===== 顶部导航栏 =====
.navbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border-bottom: 1px solid #F0E6DA;
  padding: 0 24px;
  height: 56px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);

  .navbar-left {
    display: flex;
    align-items: center;
    gap: 16px;

    .collapse-btn {
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      cursor: pointer;
      color: #4A5568;
      transition: all 0.2s;

      &:hover {
        background: #FEF7F0;
        color: #E8913A;
      }
    }

    // 汉堡按钮默认隐藏，手机端显示
    .mobile-menu-btn {
      display: none;
      width: 36px;
      height: 36px;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      cursor: pointer;
      color: #4A5568;
      transition: all 0.2s;

      &:hover {
        background: #FEF7F0;
        color: #E8913A;
      }
    }

    .mobile-page-title {
      display: none;
      font-size: 15px;
      font-weight: 600;
      color: #2C3E50;
      max-width: 180px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .navbar-right {
    .user-avatar-dropdown {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 8px;
      transition: background 0.2s;

      &:hover {
        background: #FEF7F0;
      }

      .avatar-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #E8913A, #F2A85A);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 600;
      }

      .username {
        font-size: 14px;
        color: #4A5568;
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }
  }
}

// ===== 内容区 =====
.main-content {
  background: #FBF7F2;
  min-height: 0;
  overflow-y: auto;
  padding: 20px;
}

// ===== 遮罩层（手机端） =====
.sidebar-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 240;
  animation: overlay-fade 0.2s ease-out;
}
@keyframes overlay-fade {
  from { opacity: 0; }
  to { opacity: 1; }
}

// ===== 过渡动画 =====
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.fade-slide-enter-active {
  transition: all 0.25s ease-out;
}
.fade-slide-leave-active {
  transition: all 0.15s ease-in;
}
.fade-slide-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.fade-slide-leave-to {
  opacity: 0;
}

// ===== 手机端适配 =====
@media (max-width: 768px) {
  .app-layout {
    flex-direction: row; // 保持根 el-container 默认 row，让 sidebar 绝对定位
  }

  // 侧边栏变抽屉
  .sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 240px !important;
    max-width: 75vw;
    z-index: 250;
    transform: translateX(-100%);
    transition: transform 0.25s ease;
    box-shadow: none;
  }

  .mobile-sidebar-open .sidebar {
    transform: translateX(0);
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
  }

  // 主体区不再给侧边栏让位
  .app-layout > .el-container {
    width: 100%;
    min-width: 0;
  }

  // Navbar 精简
  .navbar {
    padding: 0 12px !important;
    height: 52px;

    .navbar-left {
      gap: 8px;
      flex: 1;
      min-width: 0;

      // 桌面端折叠按钮隐藏
      .collapse-btn {
        display: none;
      }

      // 汉堡按钮显示
      .mobile-menu-btn {
        display: flex;
      }

      // 面包屑隐藏
      .nav-breadcrumb {
        display: none;
      }

      // 移动端标题显示
      .mobile-page-title {
        display: block;
        flex: 1;
        min-width: 0;
      }
    }

    .navbar-right {
      .user-avatar-dropdown {
        padding: 4px;
        gap: 4px;

        .username,
        .dropdown-arrow {
          display: none;
        }
      }
    }
  }

  // 遮罩层显示
  .sidebar-overlay {
    display: block;
  }

  // 内容区 padding 缩小
  .main-content {
    padding: 12px 10px;
  }
}
</style>
