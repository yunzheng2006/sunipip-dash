<template>
  <div class="batch-renew-page">
    <div class="page-head">
      <div>
        <h1 class="page-title">批量续费</h1>
        <p class="page-sub">粘贴 IP 列表，自动识别并批量续费</p>
      </div>
      <el-button plain @click="$router.push('/subscriptions')">
        <el-icon><ArrowLeft /></el-icon> 返回订阅管理
      </el-button>
    </div>

    <div class="batch-body">
      <!-- 左侧：输入区 -->
      <el-card class="input-card" shadow="never">
        <template #header>
          <div class="card-title">
            <el-icon><EditPen /></el-icon>
            <span>粘贴 IP 列表</span>
          </div>
        </template>
        <el-input
          v-model="ipText"
          type="textarea"
          :rows="12"
          placeholder="每行一个 IP，支持以下格式：
ip:port:user:pass
ip:port
ip
也可以直接粘贴包含 IP 的文本"
          resize="vertical"
        />
        <div class="input-actions">
          <span class="line-count">{{ lineCount }} 行</span>
          <el-button type="primary" @click="handleIdentify" :loading="identifying" :disabled="!ipText.trim()">
            <el-icon><Search /></el-icon> 识别
          </el-button>
          <el-button @click="ipText = ''; matched = []; unmatched = []">清空</el-button>
        </div>
      </el-card>

      <!-- 右侧：结果区 -->
      <div class="result-area">
        <!-- 匹配到的订阅 -->
        <el-card class="result-card" shadow="never" v-if="matched.length">
          <template #header>
            <div class="card-title">
              <el-icon style="color:#48BB78"><CircleCheck /></el-icon>
              <span>已匹配 <strong>{{ matched.length }}</strong> 条订阅</span>
            </div>
          </template>
          <el-table :data="matched" size="small" stripe max-height="320">
            <el-table-column label="IP" width="140">
              <template #default="{ row }">
                <span class="mono">{{ row.ip }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="asset_name" label="资产名" min-width="120" show-overflow-tooltip />
            <el-table-column prop="country_name" label="地区" width="80" />
            <el-table-column label="月价" width="80" align="right">
              <template #default="{ row }">
                <span class="price">¥{{ Number(row.renewal_price || getMonthlyPrice(row)).toFixed(0) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="到期时间" width="100">
              <template #default="{ row }">
                <span :style="{ color: daysToExpire(row.expires_at) <= 7 ? '#F56C6C' : '' }">
                  {{ formatDate(row.expires_at) }}
                </span>
              </template>
            </el-table-column>
          </el-table>

          <!-- 续费配置 -->
          <div class="renew-config">
            <div class="config-row">
              <span class="config-label">续费时长</span>
              <el-radio-group v-model="duration" size="small">
                <el-radio-button :value="1">1 月</el-radio-button>
                <el-radio-button :value="3">3 月</el-radio-button>
                <el-radio-button :value="6">6 月</el-radio-button>
                <el-radio-button :value="12">12 月</el-radio-button>
              </el-radio-group>
            </div>
            <div class="config-summary">
              <div>
                合计：<strong class="total-price">¥{{ totalCost.toFixed(2) }}</strong>
                <span class="total-detail">({{ matched.length }} 条 x {{ duration }} 月)</span>
              </div>
              <div>
                <template v-if="canAfford">
                  <span style="color:#48BB78">余额充足 ¥{{ balance.toFixed(2) }}</span>
                </template>
                <template v-else>
                  <span style="color:#F56C6C;font-weight:600">余额不足 ¥{{ balance.toFixed(2) }}</span>
                </template>
              </div>
            </div>
            <el-button
              type="primary"
              size="large"
              :loading="renewing"
              :disabled="!canAfford || !matched.length"
              @click="handleBatchRenew"
              style="width: 100%; margin-top: 12px"
            >
              确认批量续费 ¥{{ totalCost.toFixed(2) }}
            </el-button>
          </div>
        </el-card>

        <!-- 未匹配 -->
        <el-card class="result-card unmatched-card" shadow="never" v-if="unmatched.length">
          <template #header>
            <div class="card-title">
              <el-icon style="color:#F56C6C"><CircleClose /></el-icon>
              <span>未匹配 <strong>{{ unmatched.length }}</strong> 条</span>
            </div>
          </template>
          <el-table :data="unmatched" size="small" max-height="200">
            <el-table-column prop="input" label="输入" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="mono">{{ row.input || row.ip || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="reason" label="原因" min-width="140" />
          </el-table>
        </el-card>

        <!-- 续费结果 -->
        <el-card class="result-card" shadow="never" v-if="renewResult">
          <template #header>
            <div class="card-title">
              <el-icon style="color:#4F6AF6"><Finished /></el-icon>
              <span>续费结果</span>
            </div>
          </template>
          <div class="result-summary">
            <div class="result-stat success">
              <div class="result-num">{{ renewResult.summary.success }}</div>
              <div class="result-label">成功</div>
            </div>
            <div class="result-stat fail" v-if="renewResult.summary.failed">
              <div class="result-num">{{ renewResult.summary.failed }}</div>
              <div class="result-label">失败</div>
            </div>
            <div class="result-stat skip" v-if="renewResult.summary.not_found">
              <div class="result-num">{{ renewResult.summary.not_found }}</div>
              <div class="result-label">未找到</div>
            </div>
          </div>
          <div v-if="renewResult.results.failed.length" style="margin-top:12px">
            <div v-for="f in renewResult.results.failed" :key="f.ip" class="fail-item">
              <span class="mono">{{ f.ip }}</span> — {{ f.reason }}
            </div>
          </div>
        </el-card>

        <!-- 空状态 -->
        <el-card class="result-card" shadow="never" v-if="!matched.length && !unmatched.length && !renewResult">
          <el-empty description="在左侧粘贴 IP 列表，点击识别" :image-size="80" />
        </el-card>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, ArrowLeft, EditPen, CircleCheck, CircleClose, Finished } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { identifyIps, batchRenewByIp } from '@/api/subscriptions'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const ipText = ref('')
const identifying = ref(false)
const renewing = ref(false)
const matched = ref([])
const unmatched = ref([])
const duration = ref(1)
const renewResult = ref(null)

const balance = computed(() => authStore.balance)
const lineCount = computed(() => {
  const lines = ipText.value.split('\n').filter(l => l.trim())
  return lines.length
})

const totalCost = computed(() => {
  return matched.value.reduce((sum, m) => sum + Number(m.renewal_price || getMonthlyPrice(m)) * duration.value, 0)
})

const canAfford = computed(() => balance.value >= totalCost.value)

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function daysToExpire(d) { return d ? Math.max(0, dayjs(d).diff(dayjs(), 'day')) : 0 }
function getMonthlyPrice(row) {
  const d = Number(row.duration || 1), u = Number(row.unit || 3)
  let m = d
  if (u === 1) m = Math.max(1, Math.ceil(d / 30))
  else if (u === 2) m = Math.max(1, Math.ceil(d * 7 / 30))
  else if (u === 4) m = d * 12
  return Number(row.price || 0) / Math.max(m, 1)
}

function parseIpLines() {
  return ipText.value
    .split('\n')
    .map(l => l.trim())
    .filter(Boolean)
}

async function handleIdentify() {
  const lines = parseIpLines()
  if (!lines.length) {
    ElMessage.warning('请先粘贴 IP 列表')
    return
  }

  identifying.value = true
  renewResult.value = null
  try {
    const res = await identifyIps(lines)
    matched.value = res?.matched || []
    unmatched.value = res?.unmatched || []
    if (!matched.value.length) {
      ElMessage.warning('未匹配到任何订阅')
    } else {
      ElMessage.success(`成功匹配 ${matched.value.length} 条订阅`)
    }
  } catch {
    ElMessage.error('识别失败')
  } finally {
    identifying.value = false
  }
}

async function handleBatchRenew() {
  if (!matched.value.length) return

  try {
    await ElMessageBox.confirm(
      `即将续费 ${matched.value.length} 条订阅，时长 ${duration.value} 个月，合计 ¥${totalCost.value.toFixed(2)}`,
      '确认批量续费',
      { type: 'info', confirmButtonText: '确认续费', cancelButtonText: '取消' }
    )
  } catch { return }

  renewing.value = true
  try {
    const ips = matched.value.map(m => m.ip)
    const res = await batchRenewByIp({ ips, duration: duration.value })
    renewResult.value = res
    authStore.updateBalance(res.new_balance)

    if (res.summary.success > 0) {
      ElMessage.success(`成功续费 ${res.summary.success} 条`)
    }
    if (res.summary.failed > 0) {
      ElMessage.warning(`${res.summary.failed} 条续费失败`)
    }

    // 清空匹配列表
    matched.value = []
    unmatched.value = []
  } catch {
    ElMessage.error('批量续费失败')
  } finally {
    renewing.value = false
  }
}
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$accent: #F5A623;
$text-primary: #1E293B;
$text-secondary: #475569;
$text-muted: #94A3B8;
$border: #E2E8F0;

.batch-renew-page { display: flex; flex-direction: column; gap: 16px; }

.page-head {
  display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px;
  .page-title { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: $text-primary; }
  .page-sub { margin: 0; font-size: 13px; color: $text-muted; }
}

.batch-body {
  display: flex; gap: 16px;
}

.input-card {
  width: 380px; flex-shrink: 0;
  border-radius: 12px; border: 1px solid $border;
  .card-title {
    display: flex; align-items: center; gap: 6px;
    font-size: 14px; font-weight: 600; color: $text-primary;
  }
  .input-actions {
    display: flex; align-items: center; gap: 8px; margin-top: 12px;
    .line-count { font-size: 12px; color: $text-muted; margin-right: auto; }
  }
}

.result-area { flex: 1; display: flex; flex-direction: column; gap: 14px; }

.result-card {
  border-radius: 12px; border: 1px solid $border;
  .card-title {
    display: flex; align-items: center; gap: 6px;
    font-size: 14px; font-weight: 600; color: $text-primary;
    strong { color: $brand; }
  }
}

.mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 12px; color: $text-secondary; }
.price { color: $accent; font-weight: 600; }

.renew-config {
  margin-top: 16px; padding-top: 14px; border-top: 1px solid $border;
  .config-row {
    display: flex; align-items: center; gap: 12px; margin-bottom: 12px;
    .config-label { font-size: 13px; font-weight: 500; color: $text-secondary; }
  }
  .config-summary {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 14px; background: $brand-light; border-radius: 8px;
    font-size: 13px; color: $text-secondary;
    .total-price {
      font-size: 22px; font-weight: 800; color: $accent;
      font-family: 'SF Mono', Consolas, monospace;
    }
    .total-detail { font-size: 12px; color: $text-muted; margin-left: 6px; }
  }
}

.result-summary {
  display: flex; gap: 16px;
  .result-stat {
    padding: 14px 24px; border-radius: 10px; text-align: center; min-width: 80px;
    .result-num { font-size: 28px; font-weight: 800; font-family: 'SF Mono', Consolas, monospace; }
    .result-label { font-size: 12px; margin-top: 2px; }
    &.success { background: #F0FFF4; .result-num { color: #48BB78; } .result-label { color: #2F855A; } }
    &.fail { background: #FFF5F5; .result-num { color: #F56C6C; } .result-label { color: #C53030; } }
    &.skip { background: #FFFBEB; .result-num { color: $accent; } .result-label { color: #92400E; } }
  }
}

.fail-item {
  padding: 6px 0; font-size: 12px; color: #F56C6C; border-bottom: 1px solid #FEE2E2;
}

@media (max-width: 768px) {
  .page-head {
    flex-direction: column; align-items: flex-start; gap: 8px;
    .page-title { font-size: 18px; }
  }
  .batch-body { flex-direction: column; }
  .input-card { width: 100%; }
  .result-summary { flex-wrap: wrap; gap: 8px;
    .result-stat { min-width: 70px; padding: 10px 16px; .result-num { font-size: 22px; } }
  }
  .renew-config .config-summary { flex-direction: column; gap: 6px; padding: 8px 10px;
    .total-price { font-size: 18px; }
  }
  :deep(.el-card__body) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  :deep(.el-table) { min-width: 400px; }
}
</style>
