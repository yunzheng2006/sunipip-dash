<template>
  <el-dialog v-model="visible" :title="dialogTitle" width="680px" destroy-on-close class="crm-dialog">
    <div v-loading="loading" class="crm-body">
      <template v-if="detail">
        <!-- 顶部概要 -->
        <div class="summary-row">
          <div class="summary-card balance-card">
            <div class="sc-label">账户余额</div>
            <div class="sc-value money">¥{{ formatNum(detail.balance) }}</div>
          </div>
          <div class="summary-card spent-card">
            <div class="sc-label">累计消费</div>
            <div class="sc-value money">¥{{ formatNum(detail.total_spent) }}</div>
          </div>
          <div class="summary-card vip-card">
            <div class="sc-label">VIP等级</div>
            <div class="sc-value" v-if="detail.vip_tier">
              <el-tag :color="detail.vip_tier.badge_color" size="small" effect="dark">
                {{ detail.vip_tier.name }}
              </el-tag>
              <span class="vip-discount">{{ detail.vip_tier.discount_percent }}折</span>
            </div>
            <div class="sc-value text-muted" v-else>无</div>
          </div>
        </div>

        <!-- 基本信息 -->
        <div class="info-section">
          <div class="info-title">基本信息</div>
          <div class="info-grid">
            <div class="info-item">
              <span class="info-label">客户名</span>
              <span class="info-value">{{ detail.display_name || detail.customer_name }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">手机</span>
              <span class="info-value">{{ detail.phone || '-' }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">邮箱</span>
              <span class="info-value">{{ detail.email || '-' }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">公司</span>
              <span class="info-value">{{ detail.company_name || '-' }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">注册时间</span>
              <span class="info-value">{{ formatDate(detail.created_at) }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">实名认证</span>
              <span class="info-value">
                <el-tag v-if="detail.verified_at" type="success" size="small">已认证</el-tag>
                <el-tag v-else type="info" size="small">未认证</el-tag>
              </span>
            </div>
          </div>
        </div>

        <!-- 订阅列表 -->
        <div class="info-section">
          <div class="info-title">
            订阅列表
            <el-tag size="small" type="info" round>{{ detail.subscriptions?.length || 0 }}</el-tag>
          </div>
          <el-table :data="detail.subscriptions" size="small" max-height="220" stripe class="crm-table">
            <el-table-column prop="ip" label="IP" width="140">
              <template #default="{ row }">
                <span class="mono">{{ row.ip || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="country" label="地区" width="90" />
            <el-table-column prop="status" label="状态" width="72">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : row.status === 'expired' ? 'info' : 'warning'" size="small">
                  {{ statusLabel(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="价格" width="80" align="right">
              <template #default="{ row }">
                <span class="money">¥{{ row.price }}</span>
              </template>
            </el-table-column>
            <el-table-column label="到期" width="100">
              <template #default="{ row }">{{ formatDate(row.expires_at) }}</template>
            </el-table-column>
            <el-table-column label="专线" width="90">
              <template #default="{ row }">
                <el-tag v-if="row.has_forward" type="warning" size="small">{{ row.forward_plan || '专线' }}</el-tag>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <!-- 最近交易 -->
        <div class="info-section">
          <div class="info-title">
            最近交易
            <el-tag size="small" type="info" round>{{ detail.recent_transactions?.length || 0 }}</el-tag>
          </div>
          <el-table :data="detail.recent_transactions" size="small" max-height="200" stripe class="crm-table">
            <el-table-column prop="type" label="类型" width="90">
              <template #default="{ row }">
                <el-tag :type="txTagType(row.type)" size="small">{{ typeLabel(row.type) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="金额" width="110" align="right">
              <template #default="{ row }">
                <span :class="['money', parseFloat(row.amount) >= 0 ? 'text-green' : 'text-red']">
                  {{ parseFloat(row.amount) >= 0 ? '+' : '' }}¥{{ Math.abs(parseFloat(row.amount)).toFixed(2) }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="description" label="说明" show-overflow-tooltip />
            <el-table-column label="时间" width="100">
              <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
            </el-table-column>
          </el-table>
        </div>
      </template>
    </div>
  </el-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { getCustomerDetail } from '@/api/analytics'
import dayjs from 'dayjs'

const props = defineProps({
  modelValue: Boolean,
  customerId: Number,
})
const emit = defineEmits(['update:modelValue'])

const visible = ref(false)
const loading = ref(false)
const detail = ref(null)

const dialogTitle = computed(() => {
  if (!detail.value) return '客户详情'
  return (detail.value.display_name || detail.value.customer_name) + ' · CRM'
})

watch(() => props.modelValue, (val) => {
  visible.value = val
  if (val && props.customerId) fetchDetail()
})
watch(visible, (val) => emit('update:modelValue', val))

async function fetchDetail() {
  loading.value = true
  detail.value = null
  try {
    detail.value = await getCustomerDetail(props.customerId)
  } catch { /* handled by interceptor */ }
  loading.value = false
}

function formatDate(d) {
  return d ? dayjs(d).format('YYYY-MM-DD') : '-'
}

function formatNum(n) {
  return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const typeMap = { topup: '充值', deduction: '消费', refund: '退款', adjustment: '调整', subscription_purchase: '购买', subscription_renew: '续费', commission: '佣金' }
function typeLabel(t) { return typeMap[t] || t }

function txTagType(t) {
  if (t === 'topup') return 'success'
  if (t === 'refund') return 'danger'
  return 'warning'
}

function statusLabel(s) {
  const m = { active: '活跃', expired: '过期', cancelled: '取消', suspended: '暂停' }
  return m[s] || s
}
</script>

<style lang="scss" scoped>
.crm-body { min-height: 200px; }

.summary-row {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
  margin-bottom: 20px;
}

.summary-card {
  padding: 14px 16px; border-radius: 10px;
  .sc-label { font-size: 12px; color: #718096; margin-bottom: 6px; }
  .sc-value { font-size: 18px; font-weight: 700; line-height: 1.2; display: flex; align-items: center; gap: 6px; }

  &.balance-card { background: #F0F7FF; border: 1px solid #B3D4F5; .sc-value { color: #2B6CB0; } }
  &.spent-card { background: #FFF8F0; border: 1px solid #F5D9B5; .sc-value { color: #C87A2E; } }
  &.vip-card { background: #FAF5FF; border: 1px solid #D8B4FE; .sc-value { color: #6D28D9; } }
}

.vip-discount { font-size: 13px; font-weight: 600; color: #8B5CF6; }

.money { font-family: 'SF Mono', Consolas, monospace; }

.info-section {
  margin-bottom: 18px;
  &:last-child { margin-bottom: 0; }
}

.info-title {
  font-size: 14px; font-weight: 600; color: #2D3748;
  margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
  padding-bottom: 8px; border-bottom: 1px solid #EDF2F7;
}

.info-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 16px;
}

.info-item {
  display: flex; flex-direction: column; gap: 2px;
  .info-label { font-size: 11px; color: #A0AEC0; }
  .info-value { font-size: 13px; color: #2D3748; font-weight: 500; }
}

.crm-table {
  :deep(.el-table__body-wrapper) { font-size: 12px; }
}

.mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; }
.text-green { color: #48BB78; }
.text-red { color: #F56565; }
.text-muted { color: #CBD5E0; font-size: 13px; }
</style>
