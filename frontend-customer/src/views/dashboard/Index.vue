<template>
  <div class="dashboard">
    <!-- 风险指标 -->
    <el-card class="section-card" shadow="never" v-loading="loading">
      <div class="section-head">
        <span class="section-title">风险指标</span>
        <el-link type="primary" @click="$router.push('/subscriptions?expiring=7')">优先处理待续费与失败项</el-link>
      </div>
      <div class="kpi-row">
        <div class="kpi-item clickable" @click="$router.push('/subscriptions?expiring=7')">
          <div class="kpi-num warn">{{ stats.expiring_7d || 0 }}</div>
          <div class="kpi-label">待续费</div>
          <div class="kpi-desc">7天内到期的活跃订阅</div>
        </div>
        <div class="kpi-item">
          <div class="kpi-num">¥{{ (stats.total_spent || 0).toFixed(2) }}</div>
          <div class="kpi-label">累计消费</div>
          <div class="kpi-desc">账户历史总消费金额</div>
        </div>
      </div>
    </el-card>

    <!-- 经营指标 -->
    <el-card class="section-card" shadow="never" v-loading="loading">
      <div class="section-head">
        <span class="section-title">经营指标</span>
        <el-link type="primary" @click="$router.push('/billing/balance')">查看资源与钱包状态</el-link>
      </div>
      <div class="kpi-row five">
        <div class="kpi-item clickable" @click="$router.push('/ips')">
          <div class="kpi-num brand">{{ stats.active_ips || 0 }}</div>
          <div class="kpi-label">使用中</div>
        </div>
        <div class="kpi-item clickable" @click="$router.push('/subscriptions?auto_renew=1')">
          <div class="kpi-num brand">{{ stats.auto_renew_count || 0 }}</div>
          <div class="kpi-label">自动续费中</div>
        </div>
        <div class="kpi-item clickable" @click="$router.push('/billing/balance')">
          <div class="kpi-num accent">¥{{ balance.toFixed(0) }}</div>
          <div class="kpi-label">余额（RMB）</div>
        </div>
        <div class="kpi-item">
          <div class="kpi-num muted">¥{{ Number(stats.month_spent || 0).toFixed(0) }}</div>
          <div class="kpi-label">本月消费</div>
        </div>
        <div class="kpi-item">
          <div class="kpi-num safe">¥{{ Number(stats.month_topup || 0).toFixed(0) }}</div>
          <div class="kpi-label">本月充值</div>
        </div>
      </div>
    </el-card>

    <!-- 余额不足续费警告 -->
    <el-alert
      v-if="stats.balance_shortfall > 0"
      type="error"
      :closable="false"
      show-icon
      class="renewal-warning"
    >
      <template #title>余额不足以支付下次自动续费</template>
      您有 {{ stats.auto_renew_count }} 条订阅开启了自动续费，下次续费需 <strong>¥{{ Number(stats.next_renewal_cost).toFixed(2) }}</strong>，
      当前余额 <strong>¥{{ Number(stats.balance).toFixed(2) }}</strong>，
      还差 <strong style="color:#F56C6C">¥{{ Number(stats.balance_shortfall).toFixed(2) }}</strong>。
      <el-button type="danger" size="small" style="margin-left: 12px" @click="$router.push('/billing/topup')">立即充值</el-button>
    </el-alert>

    <!-- 快速搜索 -->
    <el-card class="search-card" shadow="never">
      <div class="search-inner">
        <el-icon :size="18" class="search-icon"><Search /></el-icon>
        <el-input
          v-model="quickSearch"
          placeholder="快速搜索 IP 地址或资产名..."
          size="large"
          clearable
          class="search-input"
          @keyup.enter="handleQuickSearch"
        />
        <el-button type="primary" @click="handleQuickSearch" :disabled="!quickSearch.trim()">搜索</el-button>
        <el-button type="primary" plain @click="$router.push('/store')">
          <el-icon><Shop /></el-icon> 去商店
        </el-button>
      </div>
    </el-card>

    <!-- VIP 状态卡片 -->
    <el-card v-if="vipTier" class="vip-card" shadow="never">
      <div style="display: flex; align-items: center; gap: 12px">
        <div class="vip-badge-large" :style="{ background: vipTier.badge_color || '#8B5CF6' }">{{ vipTier.name }}</div>
        <div>
          <div style="font-weight: 600; color: #1E293B">您当前享受 {{ formatDiscount(vipTier.discount_percent) }} 折优惠</div>
          <div style="font-size: 12px; color: #94A3B8">累计消费 {{ totalSpent.toFixed(2) }} 元</div>
        </div>
      </div>
    </el-card>

    <!-- 实名认证提示 -->
    <el-card v-if="verificationRequired && !isVerified" class="verify-prompt-card" shadow="never">
      <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap">
        <el-icon :size="32" style="color: #F5A623"><Warning /></el-icon>
        <div style="flex: 1">
          <div style="font-weight: 600; color: #1E293B; margin-bottom: 4px">请完成实名认证</div>
          <div style="font-size: 13px; color: #94A3B8">根据相关法规要求，下单前需完成身份认证。点击右侧按钮进行认证。</div>
        </div>
        <el-button type="warning" @click="showVerifyDialog = true">立即认证</el-button>
      </div>
    </el-card>

    <VerificationDialog v-model="showVerifyDialog" :pending="verificationPending" @verified="onVerified" />

    <!-- 最近操作 -->
    <el-card shadow="never" class="recent-tx-card">
      <template #header>
        <div class="card-header">
          <span>最近操作</span>
          <el-link type="primary" @click="$router.push('/billing/transactions')">
            查看全部 <el-icon><ArrowRight /></el-icon>
          </el-link>
        </div>
      </template>
      <div class="activity-list" v-if="(stats.recent_transactions || []).length">
        <div v-for="tx in stats.recent_transactions" :key="tx.id" class="activity-item">
          <div class="activity-dot" :class="txDotClass(tx.type)"></div>
          <div class="activity-body">
            <div class="activity-desc">
              <el-tag size="small" :type="txTagType(tx.type)" effect="plain">{{ txLabel(tx.type) }}</el-tag>
              <span class="activity-text">{{ tx.description || '-' }}</span>
            </div>
            <div class="activity-meta">
              <span class="activity-time">{{ formatTime(tx.created_at) }}</span>
              <span class="activity-amount" :style="{ color: tx.amount >= 0 ? '#48BB78' : '#F56C6C' }">
                {{ tx.amount >= 0 ? '+' : '' }}¥{{ Number(tx.amount).toFixed(2) }}
              </span>
            </div>
          </div>
        </div>
      </div>
      <el-empty v-else description="暂无操作记录" :image-size="60" />
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Shop, ArrowRight, Warning, Search } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { useAuthStore } from '@/stores/auth'
import { getDashboard } from '@/api/dashboard'
import { getVipInfo } from '@/api/vip'
import VerificationDialog from '@/components/VerificationDialog.vue'
import { getVerificationStatus } from '@/api/verification'

const router = useRouter()
const authStore = useAuthStore()
const loading = ref(false)
const stats = reactive({
  balance: 0,
  active_ips: 0,
  expiring_7d: 0,
  expiring_3d: 0,
  month_spent: 0,
  month_topup: 0,
  auto_renew_count: 0,
  renew_failed: 0,
  recent_transactions: [],
})

const verificationRequired = ref(false)
const isVerified = ref(false)
const showVerifyDialog = ref(false)
const verificationPending = ref(null)
const vipTier = ref(null)
const totalSpent = ref(0)

function formatDiscount(percent) {
  const n = Number(percent) / 10
  return Number.isInteger(n) ? String(n) : n.toFixed(1)
}
const quickSearch = ref('')

function onVerified() { isVerified.value = true }

const customerName = computed(() => authStore.customer?.customer_name || '用户')
const balance = computed(() => Number(stats.balance ?? authStore.balance))

function formatTime(t) { return t ? dayjs(t).format('MM-DD HH:mm') : '-' }
function txLabel(t) {
  return {
    topup: '充值', purchase: '购买', subscription_renew: '续费',
    refund: '退款', deduction: '扣费', adjustment: '调整', withdrawal: '退款',
  }[t] || t
}
function txTagType(t) {
  if (['topup', 'refund', 'withdrawal'].includes(t)) return 'success'
  if (['purchase', 'subscription_renew', 'deduction'].includes(t)) return 'warning'
  return 'info'
}
function txDotClass(t) {
  if (['topup', 'refund'].includes(t)) return 'dot-green'
  if (['purchase', 'subscription_renew', 'deduction'].includes(t)) return 'dot-blue'
  return 'dot-gray'
}

function handleQuickSearch() {
  const q = quickSearch.value.trim()
  if (!q) return
  router.push({ path: '/ips', query: { keyword: q } })
}

async function fetchData() {
  loading.value = true
  try {
    const res = await getDashboard()
    Object.assign(stats, res)
    authStore.updateBalance(res.balance)
    try {
      const vStatus = await getVerificationStatus()
      verificationRequired.value = vStatus?.required || false
      isVerified.value = vStatus?.verified || false
      verificationPending.value = vStatus?.has_pending ? vStatus : null
    } catch {}
    try {
      const vipRes = await getVipInfo()
      vipTier.value = vipRes?.current_tier || null
      totalSpent.value = vipRes?.total_spent || 0
    } catch {}
  } catch {}
  finally { loading.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$accent: #F5A623;
$text-primary: #1E293B;
$text-secondary: #475569;
$text-muted: #94A3B8;
$border: #E2E8F0;

.dashboard { display: flex; flex-direction: column; gap: 16px; }

.renewal-warning {
  border-radius: 12px;
  :deep(.el-alert__description) { font-size: 13px; line-height: 1.8; }
}

// ===== Section Cards =====
.section-card {
  border-radius: 12px; border: 1px solid $border;
  :deep(.el-card__body) { padding: 20px 24px; }
}
.section-head {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
  .section-title { font-size: 15px; font-weight: 700; color: $text-primary; }
}

// ===== KPI Row =====
.kpi-row {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;
  &.five { grid-template-columns: repeat(5, 1fr); }
}
.kpi-item {
  padding: 16px; border: 1px solid $border; border-radius: 10px;
  text-align: center; transition: all 0.15s;
  &.clickable { cursor: pointer;
    &:hover { border-color: $brand; background: rgba($brand, 0.03); }
  }
}
.kpi-num {
  font-size: 32px; font-weight: 800; line-height: 1.2;
  font-family: 'SF Mono', Consolas, Monaco, monospace;
  &.brand { color: $brand; }
  &.accent { color: $accent; }
  &.warn { color: #EA580C; }
  &.danger { color: #DC2626; }
  &.safe { color: #16A34A; }
  &.muted { color: $text-secondary; }
}
.kpi-label { font-size: 13px; color: $text-secondary; margin-top: 6px; }
.kpi-desc { font-size: 11px; color: $text-muted; margin-top: 4px; }

// ===== Quick Search =====
.search-card {
  border-radius: 12px; border: 1px solid $border;
  :deep(.el-card__body) { padding: 14px 20px; }
  .search-inner {
    display: flex; align-items: center; gap: 12px;
    .search-icon { color: $text-muted; flex-shrink: 0; }
    .search-input { flex: 1; }
  }
}

// ===== Activity List =====
.recent-tx-card {
  border-radius: 12px; border: 1px solid $border;
  .card-header {
    display: flex; justify-content: space-between; align-items: center;
    font-weight: 600; color: $text-primary; font-size: 15px;
  }
}

.activity-list { display: flex; flex-direction: column; }

.activity-item {
  display: flex; gap: 12px; padding: 10px 0;
  border-bottom: 1px solid #F8FAFC;
  &:last-child { border-bottom: none; }
}

.activity-dot {
  width: 8px; height: 8px; border-radius: 50%; margin-top: 6px; flex-shrink: 0;
  &.dot-green { background: #48BB78; }
  &.dot-blue { background: $brand; }
  &.dot-gray { background: $text-muted; }
}

.activity-body { flex: 1; min-width: 0; }
.activity-desc {
  display: flex; align-items: center; gap: 8px;
  .activity-text { font-size: 13px; color: $text-secondary; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
}
.activity-meta {
  display: flex; justify-content: space-between; align-items: center; margin-top: 4px;
  .activity-time { font-size: 12px; color: $text-muted; }
  .activity-amount { font-size: 13px; font-weight: 600; font-family: 'SF Mono', Consolas, monospace; }
}

// VIP + Verify cards
.vip-card {
  border-radius: 12px; border: 2px solid #E9D5FF;
  background: linear-gradient(135deg, #FDFAFF, #F3E8FF);
  .vip-badge-large {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 8px 18px; border-radius: 24px; color: #fff; font-size: 14px; font-weight: 700;
  }
}

.verify-prompt-card {
  border-radius: 12px; border: 2px solid #FDE68A;
  background: linear-gradient(135deg, #FFFBEB, #FEF3C7);
}

@media (max-width: 768px) {
  .dashboard { gap: 10px; }
  .section-card :deep(.el-card__body) { padding: 12px; }
  .section-head { margin-bottom: 10px;
    .section-title { font-size: 14px; }
    .el-link { font-size: 12px; }
  }
  .kpi-row { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .kpi-row.five { grid-template-columns: repeat(3, 1fr); gap: 6px; }
  .kpi-item { padding: 10px 6px; }
  .kpi-num { font-size: 18px; }
  .kpi-label { font-size: 11px; }
  .kpi-desc { font-size: 10px; display: none; }
  .search-card {
    :deep(.el-card__body) { padding: 10px 12px; }
    .search-inner { flex-wrap: wrap; gap: 8px;
      .search-icon { display: none; }
      .search-input { width: 100%; }
      .el-button { flex: 1; }
    }
  }
  .renewal-warning {
    :deep(.el-alert__content) { word-break: break-word; }
    :deep(.el-alert__description) { font-size: 12px; line-height: 1.6; }
    .el-button { margin-left: 0 !important; margin-top: 8px; width: 100%; }
  }
  .vip-card :deep(.el-card__body) { padding: 12px; }
  .verify-prompt-card :deep(.el-card__body) { padding: 12px; }
  .recent-tx-card {
    :deep(.el-card__body) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    :deep(.el-table) {
      min-width: 320px;
      .el-table__header th:nth-child(n+3),
      .el-table__body td:nth-child(n+3) { display: none; }
    }
  }
  .activity-desc { flex-wrap: wrap; gap: 4px; }
  .activity-item { .activity-text { font-size: 12px; white-space: normal; } }
  .activity-meta { flex-wrap: wrap; gap: 4px; }
}
</style>
