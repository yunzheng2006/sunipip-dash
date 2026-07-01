<template>
  <div class="finance-overview">
    <h2 class="page-title">财务总览</h2>

    <!-- Top 4 Summary Cards -->
    <div class="summary-cards" v-loading="overviewLoading">
      <el-card class="summary-card topup">
        <div class="card-icon">
          <el-icon :size="24"><Upload /></el-icon>
        </div>
        <div class="card-body">
          <div class="card-label">本月充值</div>
          <div class="card-value">¥{{ formatNum(overview.topup?.month) }}</div>
          <div class="card-sub">累计 ¥{{ formatNum(overview.topup?.all) }} · 上月 ¥{{ formatNum(overview.topup?.last_month) }}</div>
        </div>
      </el-card>
      <el-card class="summary-card purchase">
        <div class="card-icon">
          <el-icon :size="24"><ShoppingCart /></el-icon>
        </div>
        <div class="card-body">
          <div class="card-label">本月消费</div>
          <div class="card-value">¥{{ formatNum(overview.purchase?.month) }}</div>
          <div class="card-sub">累计 ¥{{ formatNum(overview.purchase?.all) }}</div>
        </div>
      </el-card>
      <el-card class="summary-card refund">
        <div class="card-icon">
          <el-icon :size="24"><RefreshLeft /></el-icon>
        </div>
        <div class="card-body">
          <div class="card-label">本月退款</div>
          <div class="card-value">¥{{ formatNum(overview.refund?.month) }}</div>
          <div class="card-sub">累计 ¥{{ formatNum(overview.refund?.all) }}</div>
        </div>
      </el-card>
      <el-card class="summary-card balance">
        <div class="card-icon">
          <el-icon :size="24"><Wallet /></el-icon>
        </div>
        <div class="card-body">
          <div class="card-label">客户总余额</div>
          <div class="card-value">¥{{ formatNum(overview.total_customer_balance) }}</div>
          <div class="card-sub">活跃客户 {{ overview.active_customers || 0 }} · 活跃订阅 {{ overview.active_subscriptions || 0 }}</div>
        </div>
      </el-card>
    </div>

    <!-- Trend Table (replacing echarts) -->
    <el-card class="trend-card">
      <template #header>
        <div class="card-header">
          <span>近期趋势</span>
          <el-radio-group v-model="trendDays" size="small" @change="fetchTrend">
            <el-radio-button :value="7">7天</el-radio-button>
            <el-radio-button :value="15">15天</el-radio-button>
            <el-radio-button :value="30">30天</el-radio-button>
          </el-radio-group>
        </div>
      </template>
      <el-table :data="trendData" v-loading="trendLoading" stripe size="small" max-height="400">
        <el-table-column prop="date" label="日期" width="80" />
        <el-table-column label="充值" align="right" width="120">
          <template #default="{ row }">
            <span class="money topup-color">{{ row.topup > 0 ? '¥' + formatNum(row.topup) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="消费" align="right" width="120">
          <template #default="{ row }">
            <span class="money purchase-color">{{ row.purchase > 0 ? '¥' + formatNum(row.purchase) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="退款" align="right" width="120">
          <template #default="{ row }">
            <span class="money refund-color">{{ row.refund > 0 ? '¥' + formatNum(row.refund) : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="净额" align="right" width="120">
          <template #default="{ row }">
            <span :class="['money', row.net >= 0 ? 'topup-color' : 'refund-color']">
              {{ row.net !== 0 ? (row.net > 0 ? '+' : '') + '¥' + formatNum(row.net) : '-' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="可视化" min-width="200">
          <template #default="{ row }">
            <div class="bar-visual">
              <div class="bar bar-topup" :style="{ width: barWidth(row.topup) }"></div>
              <div class="bar bar-purchase" :style="{ width: barWidth(row.purchase) }"></div>
              <div class="bar bar-refund" :style="{ width: barWidth(row.refund) }"></div>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Bottom Row: Breakdown + Ranking -->
    <div class="bottom-row">
      <!-- Transaction Type Breakdown -->
      <el-card class="breakdown-card">
        <template #header>交易类型分布</template>
        <el-table :data="breakdown" v-loading="overviewLoading" stripe size="small">
          <el-table-column prop="type" label="类型" width="120">
            <template #default="{ row }">
              <el-tag :type="typeTagMap[row.type] || 'info'" size="small">{{ typeLabel(row.type) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="收入" align="right">
            <template #default="{ row }">
              <span class="money topup-color">¥{{ formatNum(row.income) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="支出" align="right">
            <template #default="{ row }">
              <span class="money refund-color">¥{{ formatNum(row.expense) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="count" label="笔数" align="right" width="80" />
        </el-table>
      </el-card>

      <!-- Customer Ranking -->
      <el-card class="ranking-card">
        <template #header>
          <div class="card-header">
            <span>客户消费排行</span>
            <el-tag size="small" type="warning">Top {{ rankingData.length }}</el-tag>
          </div>
        </template>
        <el-table :data="rankingData" v-loading="rankingLoading" stripe size="small" max-height="400">
          <el-table-column label="#" width="40" align="center">
            <template #default="{ $index }">
              <span :class="['rank-num', $index < 3 ? 'top3' : '']">{{ $index + 1 }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="customer_name" label="客户" min-width="120" show-overflow-tooltip />
          <el-table-column prop="sales_person" label="业务员" width="80" show-overflow-tooltip />
          <el-table-column label="总消费" align="right" width="110">
            <template #default="{ row }">
              <span class="money">¥{{ formatNum(row.total_spent) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="总充值" align="right" width="110">
            <template #default="{ row }">
              <span class="money topup-color">¥{{ formatNum(row.total_topup) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="余额" align="right" width="90">
            <template #default="{ row }">
              <span :style="{ color: row.balance > 0 ? '#67C23A' : '#909399' }">¥{{ formatNum(row.balance) }}</span>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Upload, ShoppingCart, RefreshLeft, Wallet } from '@element-plus/icons-vue'
import { getFinanceOverview, getFinanceTrend, getFinanceRanking } from '@/api/finance'

const overviewLoading = ref(false)
const trendLoading = ref(false)
const rankingLoading = ref(false)

const overview = ref({})
const breakdown = ref([])
const trendDays = ref(30)
const trendRaw = ref({ dates: [], topups: [], purchases: [], refunds: [] })
const rankingData = ref([])

function formatNum(n) {
  return Number(n || 0).toFixed(2)
}

const typeTagMap = {
  topup: 'success',
  purchase: 'warning',
  subscription_renew: 'warning',
  refund: 'danger',
  adjust: 'info',
  consume: '',
}

function typeLabel(t) {
  const map = {
    topup: '充值',
    purchase: '消费',
    subscription_renew: '续费扣款',
    refund: '退款',
    adjust: '调整',
    consume: '消费',
    referral_commission: '推广佣金',
  }
  return map[t] || t
}

// Trend data for table
const trendData = computed(() => {
  const { dates, topups, purchases, refunds } = trendRaw.value
  return dates.map((d, i) => ({
    date: d,
    topup: topups[i] || 0,
    purchase: purchases[i] || 0,
    refund: refunds[i] || 0,
    net: (topups[i] || 0) - (purchases[i] || 0) - (refunds[i] || 0),
  })).reverse()
})

// Max value for bar visualization
const trendMax = computed(() => {
  const all = trendData.value.flatMap(r => [r.topup, r.purchase, r.refund])
  return Math.max(...all, 1)
})

function barWidth(val) {
  const pct = Math.min(100, (val / trendMax.value) * 100)
  return pct > 0 ? `${pct}%` : '0'
}

async function fetchOverview() {
  overviewLoading.value = true
  try {
    const res = await getFinanceOverview()
    overview.value = res || {}
    breakdown.value = res?.breakdown || []
  } catch { /* handled */ }
  finally { overviewLoading.value = false }
}

async function fetchTrend() {
  trendLoading.value = true
  try {
    const res = await getFinanceTrend(trendDays.value)
    trendRaw.value = res || { dates: [], topups: [], purchases: [], refunds: [] }
  } catch { /* handled */ }
  finally { trendLoading.value = false }
}

async function fetchRanking() {
  rankingLoading.value = true
  try {
    const res = await getFinanceRanking(20)
    rankingData.value = res || []
  } catch { /* handled */ }
  finally { rankingLoading.value = false }
}

onMounted(() => {
  fetchOverview()
  fetchTrend()
  fetchRanking()
})
</script>

<style lang="scss" scoped>
.finance-overview {
  .page-title {
    margin: 0 0 20px;
    font-size: 20px;
    font-weight: 600;
    color: #2C3E50;
  }

  .summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 16px;

    .summary-card {
      :deep(.el-card__body) {
        padding: 18px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
      }

      .card-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }

      &.topup .card-icon { background: #E8F5E9; color: #4CAF50; }
      &.purchase .card-icon { background: #FFF3E0; color: #E8913A; }
      &.refund .card-icon { background: #FFEBEE; color: #F56C6C; }
      &.balance .card-icon { background: #E3F2FD; color: #409EFF; }

      .card-body {
        flex: 1;
        .card-label { font-size: 13px; color: #909399; margin-bottom: 4px; }
        .card-value {
          font-size: 22px;
          font-weight: 700;
          color: #2C3E50;
          font-family: 'SF Mono', Consolas, monospace;
        }
        .card-sub { font-size: 12px; color: #B0B0B0; margin-top: 4px; }
      }
    }
  }

  .trend-card {
    margin-bottom: 16px;
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 600;
    }
  }

  .bottom-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;

    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 600;
    }
  }

  .money {
    font-family: 'SF Mono', Consolas, monospace;
  }

  .topup-color { color: #67C23A; }
  .purchase-color { color: #E8913A; }
  .refund-color { color: #F56C6C; }

  .bar-visual {
    display: flex;
    flex-direction: column;
    gap: 2px;

    .bar {
      height: 6px;
      border-radius: 3px;
      min-width: 0;
      transition: width 0.3s;

      &.bar-topup { background: #67C23A; }
      &.bar-purchase { background: #E8913A; }
      &.bar-refund { background: #F56C6C; }
    }
  }

  .rank-num {
    font-weight: 600;
    color: #909399;
    &.top3 {
      color: #E8913A;
      font-size: 15px;
    }
  }
}

// ===== 手机端适配 =====
@media (max-width: 768px) {
  .finance-overview {
    .page-title {
      font-size: 17px;
      margin-bottom: 10px;
    }

    // Summary cards 改为 2 列
    .summary-cards {
      grid-template-columns: repeat(2, 1fr);
      gap: 10px;
      margin-bottom: 12px;

      .summary-card {
        :deep(.el-card__body) {
          padding: 12px !important;
          gap: 10px;
        }
        .card-icon { width: 36px; height: 36px; }
        .card-body {
          .card-label { font-size: 11px; }
          .card-value { font-size: 16px; }
          .card-sub { font-size: 10px; }
        }
      }
    }

    // 趋势表 - 6 列：1-日期, 2-充值, 3-消费, 4-退款, 5-净额, 6-可视化
    // 手机保留: 1, 2, 3, 5
    .trend-card {
      :deep(.el-table__body-wrapper) {
        .el-table__row > td.el-table__cell:nth-child(4),
        .el-table__row > td.el-table__cell:nth-child(6) {
          display: none;
        }
      }
      :deep(.el-table__header-wrapper) {
        thead tr > th.el-table__cell:nth-child(4),
        thead tr > th.el-table__cell:nth-child(6) {
          display: none;
        }
      }
      .card-header {
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
      }
    }

    // Bottom row 改为单列
    .bottom-row {
      grid-template-columns: 1fr;
      gap: 10px;
    }

    // Ranking - 6 列：1-#, 2-客户, 3-业务员, 4-总消费, 5-总充值, 6-余额
    // 手机保留: 1, 2, 4, 6
    .ranking-card {
      :deep(.el-table__body-wrapper) {
        .el-table__row > td.el-table__cell:nth-child(3),
        .el-table__row > td.el-table__cell:nth-child(5) {
          display: none;
        }
      }
      :deep(.el-table__header-wrapper) {
        thead tr > th.el-table__cell:nth-child(3),
        thead tr > th.el-table__cell:nth-child(5) {
          display: none;
        }
      }
    }
  }
}
</style>
