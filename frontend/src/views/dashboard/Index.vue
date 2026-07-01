<template>
  <div class="dashboard">
    <div class="page-header">
      <h2>仪表盘</h2>
      <p class="page-desc">数据概览与运营监控</p>
    </div>

    <!-- Stat Cards -->
    <el-row :gutter="16" class="stat-row">
      <el-col :xs="12" :sm="8" :md="6" :lg="{ span: 4, offset: 0 }" :xl="4" v-for="card in statCards" :key="card.key">
        <div class="stat-card" :class="card.theme">
          <div class="stat-card-inner">
            <div class="stat-top">
              <span class="stat-label">{{ card.label }}</span>
              <div class="stat-icon-wrap">
                <el-icon :size="20"><component :is="card.icon" /></el-icon>
              </div>
            </div>
            <div class="stat-value">{{ card.formatter ? card.formatter(stats[card.key]) : (card.prefix || '') + (stats[card.key] ?? '-') }}</div>
            <div class="stat-footer" v-if="card.sub">
              {{ card.sub }}
            </div>
          </div>
        </div>
      </el-col>
    </el-row>

    <!-- 我的邀请码 -->
    <el-card v-if="myInviteCode" shadow="never" class="invite-card" style="margin-bottom: 20px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-weight:600;color:#2C3E50;margin-bottom:4px">我的邀请码</div>
          <div style="font-family:monospace;font-size:18px;font-weight:700;color:#E8913A;letter-spacing:2px">{{ myInviteCode }}</div>
        </div>
        <div style="display:flex;gap:8px">
          <el-button size="small" @click="copyInviteLink">复制邀请链接</el-button>
        </div>
      </div>
    </el-card>

    <!-- Expiring Subscriptions Table -->
    <el-card class="table-card">
      <template #header>
        <div class="card-header">
          <div class="card-title">
            <el-icon :size="18" color="#E8913A"><Warning /></el-icon>
            <span>即将到期的订阅</span>
          </div>
          <el-button type="primary" link @click="$router.push('/subscriptions')">
            查看全部 <el-icon><ArrowRight /></el-icon>
          </el-button>
        </div>
      </template>
      <el-table :data="expiringList" v-loading="loadingExpiring" stripe>
        <el-table-column prop="customer_name" label="客户名称" min-width="140">
          <template #default="{ row }">
            <span class="customer-link">{{ row.customer_name || row.customer?.customer_name || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="asset_name" label="资产名称" min-width="180">
          <template #default="{ row }">
            {{ row.asset_name || row.proxy_ip?.asset_name || '-' }}
          </template>
        </el-table-column>
        <el-table-column prop="expires_at" label="到期时间" min-width="160">
          <template #default="{ row }">
            {{ formatDate(row.expires_at) }}
          </template>
        </el-table-column>
        <el-table-column label="剩余" width="90" align="center">
          <template #default="{ row }">
            <el-tag
              :type="getDaysTagType(row.days_remaining)"
              size="small"
              round
              effect="dark"
            >
              {{ row.days_remaining }}天
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="$router.push(`/subscriptions/${row.id}`)">
              详情
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loadingExpiring && expiringList.length === 0" description="暂无即将到期的订阅" :image-size="80" />
    </el-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import dayjs from 'dayjs'
import { ElMessage } from 'element-plus'
import { getDashboardStats, getExpiringList } from '@/api/dashboard'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const stats = ref({})
const expiringList = ref([])
const loadingExpiring = ref(false)
const myInviteCode = ref(null)

async function copyInviteLink() {
  const portalBase = window.__CUSTOMER_PORTAL_URL || import.meta.env.VITE_CUSTOMER_PORTAL_URL || 'https://user.sunipip.com'
  const link = `${portalBase}/register?invite=${myInviteCode.value}`
  try {
    await navigator.clipboard.writeText(link)
    ElMessage.success('邀请链接已复制')
  } catch { ElMessage.warning('复制失败') }
}

// 根据角色动态显示不同卡片
const statCards = computed(() => {
  const role = stats.value?.role
  if (role === 'sales') {
    return [
      { key: 'my_customers', label: '我的客户', icon: 'User', theme: 'theme-amber', sub: '名下客户数' },
      { key: 'my_active_subs', label: '活跃订阅', icon: 'Calendar', theme: 'theme-blue', sub: '名下进行中' },
      { key: 'my_expiring_soon', label: '即将到期', icon: 'Warning', theme: 'theme-rose', sub: '7天内到期' },
      { key: 'my_pending_approvals', label: '待审批', icon: 'Document', theme: 'theme-orange', sub: '我提交的' },
    ]
  }
  const cards = [
    { key: 'total_customers', label: '客户总数', icon: 'User', theme: 'theme-amber', sub: '系统全部客户' },
    { key: 'active_subscriptions', label: '活跃订阅', icon: 'Calendar', theme: 'theme-blue', sub: '当前进行中' },
    { key: 'expiring_soon', label: '即将到期', icon: 'Warning', theme: 'theme-rose', sub: '7天内到期' },
    { key: 'pending_approvals', label: '待审批', icon: 'Document', theme: 'theme-orange', sub: '需要处理' },
  ]
  if (stats.value?.spark_balance !== undefined) {
    cards.push({
      key: 'spark_balance', label: 'Spark余额', icon: 'CreditCard', theme: 'theme-purple', sub: null,
      formatter: v => v !== null ? '¥' + Number(v).toFixed(2) : '获取中...',
    })
  }
  if (stats.value?.ipipv_balance !== undefined) {
    cards.push({
      key: 'ipipv_balance', label: 'IPIPV余额', icon: 'CreditCard', theme: 'theme-teal', sub: null,
      formatter: v => v !== null ? '¥' + Number(v).toFixed(2) : '获取中...',
    })
  }
  return cards
})

function formatDate(date) {
  return date ? dayjs(date).format('YYYY-MM-DD HH:mm') : '-'
}

function getDaysTagType(days) {
  if (days <= 1) return 'danger'
  if (days <= 3) return 'warning'
  return 'info'
}

async function fetchStats() {
  try {
    const res = await getDashboardStats()
    stats.value = res
  } catch { /* handled */ }
}

async function fetchExpiring() {
  loadingExpiring.value = true
  try {
    const res = await getExpiringList()
    const list = Array.isArray(res) ? res : res.items || []
    // 计算剩余天数
    expiringList.value = list.map(item => ({
      ...item,
      days_remaining: item.expires_at ? Math.max(0, dayjs(item.expires_at).diff(dayjs(), 'day')) : 0,
    }))
  } catch { /* handled */ }
  finally { loadingExpiring.value = false }
}

onMounted(() => {
  fetchStats()
  fetchExpiring()
  // Show invite code if user has one
  try {
    const user = authStore.user
    if (user?.invite_code) {
      myInviteCode.value = user.invite_code
    }
  } catch {}
})
</script>

<style lang="scss" scoped>
.dashboard {
  .page-header {
    margin-bottom: 24px;

    h2 {
      font-size: 22px;
      font-weight: 600;
      color: #2C3E50;
      margin: 0 0 4px 0;
    }

    .page-desc {
      font-size: 13px;
      color: #909399;
      margin: 0;
    }
  }
}

// ===== 统计卡片 =====
.stat-row {
  margin-bottom: 20px;
}

.stat-card {
  border-radius: 14px;
  padding: 22px 20px;
  transition: all 0.3s ease;
  cursor: default;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
  }

  &.theme-amber {
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
    border: 1px solid #F5D9B5;
    .stat-icon-wrap { background: #E8913A; }
    .stat-value { color: #C87A2E; }
  }

  &.theme-emerald {
    background: linear-gradient(135deg, #F0FFF4, #E0F5E8);
    border: 1px solid #B8E6C8;
    .stat-icon-wrap { background: #48BB78; }
    .stat-value { color: #2D8A50; }
  }

  &.theme-blue {
    background: linear-gradient(135deg, #F0F7FF, #E0EFFD);
    border: 1px solid #B3D4F5;
    .stat-icon-wrap { background: #4299E1; }
    .stat-value { color: #2B6CB0; }
  }

  &.theme-rose {
    background: linear-gradient(135deg, #FFF5F5, #FEE2E2);
    border: 1px solid #FECACA;
    .stat-icon-wrap { background: #F56565; }
    .stat-value { color: #C53030; }
  }

  &.theme-purple {
    background: linear-gradient(135deg, #FAF5FF, #E9D5FF);
    border: 1px solid #D8B4FE;
    .stat-icon-wrap { background: #8B5CF6; }
    .stat-value { color: #6D28D9; }
  }

  &.theme-orange {
    background: linear-gradient(135deg, #FFF7ED, #FFEDD5);
    border: 1px solid #FED7AA;
    .stat-icon-wrap { background: #EA580C; }
    .stat-value { color: #C2410C; }
  }

  &.theme-teal {
    background: linear-gradient(135deg, #F0FDFA, #CCFBF1);
    border: 1px solid #99F6E4;
    .stat-icon-wrap { background: #0D9488; }
    .stat-value { color: #0F766E; }
  }

  .stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;

    .stat-label {
      font-size: 13px;
      color: #718096;
      font-weight: 500;
    }

    .stat-icon-wrap {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
    }
  }

  .stat-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.1;
    margin-bottom: 6px;
  }

  .stat-footer {
    font-size: 12px;
    color: #A0AEC0;
  }
}

// ===== 表格卡片 =====
.table-card {
  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    .card-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 600;
      font-size: 15px;
      color: #2C3E50;
    }
  }

  .customer-link {
    color: #E8913A;
    font-weight: 500;
  }
}
</style>
