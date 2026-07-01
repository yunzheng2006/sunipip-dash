<template>
  <div class="topup-success-page">
    <el-card class="result-card" shadow="never">
      <!-- 等待确认 -->
      <div v-if="status === 'pending'" class="status-content">
        <el-icon :size="72" class="loading-icon"><Loading /></el-icon>
        <h2 class="title">正在确认支付结果…</h2>
        <p class="sub">
          如果您已完成支付，系统正在处理中。
          <br>
          通常 3-10 秒内到账，请耐心等待。
        </p>
        <div class="order-info">
          <div>订单号：<span class="mono">{{ orderNo }}</span></div>
          <div v-if="amount">金额：¥{{ Number(amount).toFixed(2) }}</div>
        </div>
        <div class="poll-note">已自动检测 {{ pollCount }} 次</div>
      </div>

      <!-- 成功 -->
      <div v-else-if="status === 'paid'" class="status-content success">
        <el-icon :size="80" class="success-icon"><CircleCheckFilled /></el-icon>
        <h2 class="title">支付成功 🎉</h2>
        <p class="sub">已入账 ¥{{ Number(amount).toFixed(2) }}</p>
        <div class="new-balance">
          <div class="label">当前余额</div>
          <div class="value">¥{{ Number(newBalance).toFixed(2) }}</div>
        </div>
        <div class="actions">
          <el-button type="primary" size="large" @click="$router.push('/store')">
            去商店购买
          </el-button>
          <el-button size="large" @click="$router.push('/billing/balance')">
            查看账单
          </el-button>
        </div>
      </div>

      <!-- 失败/超时 -->
      <div v-else class="status-content failed">
        <el-icon :size="72" class="fail-icon"><CircleCloseFilled /></el-icon>
        <h2 class="title">支付未完成</h2>
        <p class="sub">
          {{ failReason || '订单状态：' + statusLabel(status) }}
        </p>
        <div class="actions">
          <el-button type="primary" size="large" @click="$router.push('/billing/topup')">
            重新充值
          </el-button>
          <el-button size="large" @click="$router.push('/billing/balance')">
            返回账单
          </el-button>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { Loading, CircleCheckFilled, CircleCloseFilled } from '@element-plus/icons-vue'
import { getTopupOrder } from '@/api/billing'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()

const orderNo = ref(route.query.order_no || '')
const status = ref('pending')
const amount = ref(0)
const newBalance = ref(0)
const failReason = ref('')
const pollCount = ref(0)

let timer = null
const MAX_POLL = 30 // 最多轮询 30 次 = 约 90 秒

function statusLabel(s) {
  return { pending: '待支付', paid: '已支付', failed: '失败', expired: '已过期', cancelled: '已取消' }[s] || s
}

async function fetchOrder() {
  if (!orderNo.value) {
    status.value = 'failed'
    failReason.value = '缺少订单号'
    return
  }
  try {
    const res = await getTopupOrder(orderNo.value)
    status.value = res.status
    amount.value = res.amount
    newBalance.value = res.balance
    if (res.status === 'paid') {
      authStore.updateBalance(res.balance)
      stopPolling()
    } else if (['failed', 'expired', 'cancelled'].includes(res.status)) {
      stopPolling()
    }
  } catch (err) {
    // 继续轮询
  }
}

function startPolling() {
  stopPolling()
  timer = setInterval(async () => {
    pollCount.value++
    if (pollCount.value >= MAX_POLL) {
      stopPolling()
      if (status.value === 'pending') {
        status.value = 'failed'
        failReason.value = '等待支付结果超时。如果您已完成支付，请稍后到账单页查看。'
      }
      return
    }
    await fetchOrder()
  }, 3000)
}

function stopPolling() {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

onMounted(async () => {
  await fetchOrder()
  if (status.value === 'pending') {
    startPolling()
  }
})

onBeforeUnmount(stopPolling)
</script>

<style lang="scss" scoped>
.topup-success-page {
  max-width: 600px;
  margin: 40px auto 0;
}

.result-card {
  border-radius: 16px;
  border: 1px solid #EADFD2;
  :deep(.el-card__body) { padding: 60px 40px; }
}

.status-content {
  text-align: center;
  .title {
    margin: 20px 0 10px;
    font-size: 24px;
    font-weight: 700;
    color: #2C3E50;
  }
  .sub {
    margin: 0 0 20px;
    font-size: 14px;
    color: #718096;
    line-height: 1.6;
  }
  .order-info {
    display: inline-block;
    padding: 12px 20px;
    background: #FAF7F2;
    border-radius: 8px;
    font-size: 13px;
    color: #4A5568;
    margin-bottom: 16px;
    div { margin: 4px 0; }
  }
  .poll-note {
    font-size: 12px;
    color: #C0C4CC;
  }

  .loading-icon {
    color: #E8913A;
    animation: spin 2s linear infinite;
  }
  .success-icon { color: #67C23A; }
  .fail-icon { color: #F56C6C; }

  &.success .new-balance {
    margin: 24px 0;
    padding: 20px;
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
    border-radius: 12px;
    .label { font-size: 13px; color: #909399; margin-bottom: 6px; }
    .value {
      font-size: 40px;
      font-weight: 800;
      color: #E8913A;
      font-family: 'SF Mono', Consolas, Monaco, monospace;
    }
  }

  .actions {
    display: flex;
    justify-content: center;
    gap: 12px;
    margin-top: 24px;
  }
}

.mono { font-family: 'SF Mono', Consolas, Monaco, monospace; }

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
