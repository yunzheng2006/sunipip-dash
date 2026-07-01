<template>
  <div class="balance-page">
    <h1 class="page-title">账户余额</h1>

    <el-card class="balance-card" shadow="never">
      <div class="balance-inner">
        <div>
          <div class="balance-label">当前余额</div>
          <div class="balance-value">¥{{ balance.toFixed(2) }}</div>
          <div class="balance-sub">可用于购买 IP 和续费</div>
        </div>
        <div class="balance-actions">
          <el-button type="primary" size="large" @click="$router.push('/billing/topup')">
            <el-icon><Wallet /></el-icon>
            立即充值
          </el-button>
          <div class="contact-hint">支持支付宝 / 微信 / USDT 等</div>
        </div>
      </div>
    </el-card>

    <el-alert
      v-if="renewalWarning"
      type="error"
      :closable="false"
      show-icon
      style="border-radius: 12px"
    >
      <template #title>余额不足以支付下次自动续费</template>
      您有 {{ renewalInfo.count }} 条订阅开启了自动续费，下次续费需
      <strong>¥{{ renewalInfo.cost.toFixed(2) }}</strong>，
      还差 <strong style="color:#F56C6C">¥{{ renewalInfo.shortfall.toFixed(2) }}</strong>。
      请及时充值以免服务中断。
    </el-alert>

    <el-card shadow="never" class="tx-card">
      <template #header>
        <div class="card-header">
          <span>最近交易</span>
          <el-link type="primary" @click="$router.push('/billing/transactions')">
            查看完整流水 <el-icon><ArrowRight /></el-icon>
          </el-link>
        </div>
      </template>
      <el-table :data="recentTx" v-loading="loading" stripe size="small">
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="类型" width="110">
          <template #default="{ row }">
            <el-tag size="small" :type="txTagType(row.type)">{{ txLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="130" align="right">
          <template #default="{ row }">
            <span :style="{ color: row.amount >= 0 ? '#48BB78' : '#F56C6C', fontWeight: 600 }">
              {{ row.amount >= 0 ? '+' : '' }}{{ Number(row.amount).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="130" align="right">
          <template #default="{ row }">¥{{ Number(row.balance_after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="说明" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !recentTx.length" description="暂无交易记录" :image-size="60" />
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Wallet, ArrowRight } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { getTransactions } from '@/api/billing'
import { getDashboard } from '@/api/dashboard'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const balance = computed(() => authStore.balance)
const loading = ref(false)
const recentTx = ref([])
const renewalInfo = reactive({ count: 0, cost: 0, shortfall: 0 })
const renewalWarning = computed(() => renewalInfo.shortfall > 0)

function formatTime(t) { return t ? dayjs(t).format('YYYY-MM-DD HH:mm') : '-' }
function txLabel(t) {
  return { topup: '充值', purchase: '购买', subscription_renew: '续费', refund: '退款', deduction: '扣费', adjustment: '调整', withdrawal: '退款' }[t] || t
}
function txTagType(t) {
  if (['topup', 'refund', 'withdrawal'].includes(t)) return 'success'
  if (['purchase', 'subscription_renew', 'deduction'].includes(t)) return 'warning'
  return 'info'
}

async function fetchData() {
  loading.value = true
  try {
    const res = await getTransactions({ page: 1, per_page: 10 })
    recentTx.value = res?.items || []
  } catch {} finally { loading.value = false }
}

async function fetchRenewalInfo() {
  try {
    const res = await getDashboard()
    renewalInfo.count = res?.auto_renew_count || 0
    renewalInfo.cost = res?.next_renewal_cost || 0
    renewalInfo.shortfall = res?.balance_shortfall || 0
  } catch {}
}

onMounted(async () => {
  await authStore.fetchMe()
  fetchData()
  fetchRenewalInfo()
})
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$accent: #F5A623;
$text-primary: #1E293B;
$text-muted: #94A3B8;
$border: #E2E8F0;

.balance-page { display: flex; flex-direction: column; gap: 16px; }
.page-title { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: $text-primary; }

.balance-card {
  border-radius: 16px; border: 1px solid $border;
  background: linear-gradient(135deg, #F8F9FF, $brand-light 60%, #E0E7FF);
  :deep(.el-card__body) { padding: 40px 32px; }
  .balance-inner { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 24px; }
  .balance-label { font-size: 14px; color: #475569; margin-bottom: 8px; }
  .balance-value {
    font-size: 52px; font-weight: 800; color: $brand; line-height: 1.1;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
  }
  .balance-sub { margin-top: 8px; font-size: 13px; color: $text-muted; }
  .balance-actions { text-align: center;
    .contact-hint { margin-top: 10px; font-size: 12px; color: $text-muted; }
  }
}

.tx-card {
  border-radius: 14px; border: 1px solid $border;
  .card-header {
    display: flex; justify-content: space-between; align-items: center;
    font-weight: 600; color: $text-primary;
  }
}

@media (max-width: 768px) {
  .page-title { font-size: 18px; }
  .balance-card {
    :deep(.el-card__body) { padding: 20px 16px; }
    .balance-inner { flex-direction: column; gap: 16px; align-items: flex-start; }
    .balance-value { font-size: 32px; }
    .balance-sub { font-size: 12px; }
    .balance-actions { width: 100%;
      .el-button { width: 100%; }
      .contact-hint { font-size: 11px; }
    }
  }
  .tx-card {
    .card-header { font-size: 14px;
      .el-link { font-size: 12px; }
    }
  }
  :deep(.el-card__body) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  :deep(.el-table) {
    font-size: 12px;
    min-width: 360px;
    // 隐藏余额列
    .el-table__header th:nth-child(n+4),
    .el-table__body td:nth-child(n+4) { display: none; }
  }
}
</style>
