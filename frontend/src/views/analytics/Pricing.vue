<template>
  <div class="analytics-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">价格数据</h2>
        <p class="page-desc">客户折扣分层与消费分析</p>
      </div>
      <el-radio-group v-model="days" size="small" @change="fetchData">
        <el-radio-button :value="7">7天</el-radio-button>
        <el-radio-button :value="30">30天</el-radio-button>
        <el-radio-button :value="90">90天</el-radio-button>
        <el-radio-button :value="180">180天</el-radio-button>
        <el-radio-button :value="360">360天</el-radio-button>
      </el-radio-group>
    </div>

    <!-- 消费金额筛选 -->
    <div class="filter-bar">
      <div class="filter-group">
        <span class="filter-label">累计消费：</span>
        <el-radio-group v-model="minSpent" size="small" @change="fetchData">
          <el-radio-button :value="null">全部</el-radio-button>
          <el-radio-button :value="100000">> 10万</el-radio-button>
          <el-radio-button :value="500000">> 50万</el-radio-button>
          <el-radio-button :value="1000000">> 100万</el-radio-button>
        </el-radio-group>
      </div>
    </div>

    <!-- 总览条 -->
    <div class="overview-bar" v-if="metrics.length && !loading">
      <div class="overview-item" v-for="m in metrics" :key="m.key">
        <span class="ov-dot" :class="`dot-${themeFor(m.key)}`"></span>
        <span class="ov-label">{{ m.label }}</span>
        <span class="ov-value">{{ m.value.toLocaleString() }}</span>
      </div>
    </div>

    <!-- 指标卡 -->
    <div class="metrics-grid" v-loading="loading">
      <MetricCard
        v-for="m in metricConfigs"
        :key="m.key"
        :label="m.label"
        :value="m.data?.value ?? 0"
        :unit="'人'"
        :icon="m.icon"
        :theme="m.theme"
        :customers="m.data?.customers"
        :total-customers="m.data?.total_customers ?? 0"
        @detail="openDetail"
      />
    </div>

    <CustomerDetailDialog v-model="detailVisible" :customer-id="detailCustomerId" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { getPricingData } from '@/api/analytics'
import MetricCard from './components/MetricCard.vue'
import CustomerDetailDialog from './components/CustomerDetailDialog.vue'

const days = ref(30)
const minSpent = ref(null)
const loading = ref(false)
const metrics = ref([])
const detailVisible = ref(false)
const detailCustomerId = ref(null)

const themeMap = {
  full_price: 'slate',
  agent_price: 'purple',
  discount_70: 'blue',
  discount_60: 'teal',
  discount_50: 'amber',
  discount_below_50: 'rose',
}
const iconMap = {
  full_price: 'Coin',
  agent_price: 'Medal',
  discount_70: 'Discount',
  discount_60: 'Discount',
  discount_50: 'Discount',
  discount_below_50: 'StarFilled',
}

function themeFor(key) { return themeMap[key] || 'blue' }

const metricConfigs = computed(() => {
  return (metrics.value || []).map(m => ({
    key: m.key,
    label: m.label,
    icon: iconMap[m.key] || 'DataLine',
    theme: themeMap[m.key] || 'blue',
    data: m,
  }))
})

async function fetchData() {
  loading.value = true
  try {
    const data = await getPricingData(days.value, minSpent.value)
    metrics.value = data.metrics || []
  } catch { /* interceptor handles */ }
  loading.value = false
}

function openDetail(id) {
  detailCustomerId.value = id
  detailVisible.value = true
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.analytics-page { padding: 0; }

.page-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
}
.page-title { font-size: 22px; font-weight: 600; color: #2C3E50; margin: 0 0 4px; }
.page-desc { font-size: 13px; color: #909399; margin: 0; }

.filter-bar {
  display: flex; align-items: center; gap: 20px;
  margin-bottom: 20px; padding: 14px 18px;
  background: #FAFBFC; border: 1px solid #EDF2F7; border-radius: 10px;
}
.filter-group { display: flex; align-items: center; gap: 10px; }
.filter-label { font-size: 13px; color: #4A5568; font-weight: 500; white-space: nowrap; }

.overview-bar {
  display: flex; flex-wrap: wrap; gap: 6px 20px;
  padding: 14px 18px; margin-bottom: 20px;
  background: #FAFBFC; border: 1px solid #EDF2F7; border-radius: 10px;
}
.overview-item {
  display: flex; align-items: center; gap: 6px; font-size: 13px;
}
.ov-dot {
  width: 8px; height: 8px; border-radius: 50%;
  &.dot-slate { background: #64748B; }
  &.dot-purple { background: #8B5CF6; }
  &.dot-blue { background: #4299E1; }
  &.dot-teal { background: #0D9488; }
  &.dot-amber { background: #E8913A; }
  &.dot-rose { background: #F56565; }
}
.ov-label { color: #718096; }
.ov-value { font-weight: 700; color: #2D3748; font-family: 'SF Mono', Consolas, monospace; }

.metrics-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
}

@media (max-width: 1200px) {
  .metrics-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
  .metrics-grid { grid-template-columns: 1fr; }
  .filter-bar { flex-direction: column; align-items: flex-start; }
}
</style>
