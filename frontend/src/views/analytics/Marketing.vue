<template>
  <div class="analytics-page">
    <div class="page-header">
      <h2 class="page-title">营销数据（今日/全部）</h2>
      <el-radio-group v-model="mode" size="small" @change="fetchData">
        <el-radio-button value="today">今日</el-radio-button>
        <el-radio-button value="all">全部</el-radio-button>
      </el-radio-group>
    </div>

    <!-- 实时区 -->
    <div class="section-label">
      <span class="live-dot"></span>实时数据
      <span class="live-hint">不受时间筛选影响</span>
    </div>
    <div class="realtime-cards" v-loading="loading">
      <div class="stat-card theme-blue">
        <div class="stat-top">
          <span class="stat-label">官网访问总量</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><View /></el-icon></div>
        </div>
        <div class="stat-value">{{ (realtime.today_views || 0).toLocaleString() }}</div>
        <div class="stat-footer">今日页面浏览次数</div>
      </div>
      <div class="stat-card theme-green">
        <div class="stat-top">
          <span class="stat-label">在线用户数量</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><Connection /></el-icon></div>
        </div>
        <div class="stat-value">{{ realtime.online_count || 0 }}</div>
        <div class="stat-footer">
          登录 <strong>{{ realtime.online_customers || 0 }}</strong> · 访客 <strong>{{ realtime.online_guests || 0 }}</strong>
        </div>
      </div>
      <div class="stat-card theme-amber">
        <div class="stat-top">
          <span class="stat-label">今日已登录</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><User /></el-icon></div>
        </div>
        <div class="stat-value">{{ (realtime.today_logged_in || 0).toLocaleString() }}</div>
        <div class="stat-footer">去重登录用户</div>
      </div>
    </div>

    <!-- 在线时间曲线图 -->
    <div class="section-label">在线时间曲线图（时间数量）</div>
    <div class="chart-card" v-loading="loading">
      <v-chart :option="chartOption" autoresize style="height: 280px" />
    </div>

    <!-- 存量指标 -->
    <div class="section-label">
      存量指标
      <span class="live-hint">{{ mode === 'today' ? '今日数据' : '全部数据' }}</span>
    </div>
    <div class="metrics-grid" v-loading="loading">
      <MetricCard
        v-for="m in metricConfigs"
        :key="m.key"
        :label="m.label"
        :value="m.data?.value ?? 0"
        :unit="m.unit"
        :icon="m.icon"
        :theme="m.theme"
        :customers="m.data?.customers"
        :ip-list="m.data?.ip_list"
        :total-customers="m.data?.total_customers ?? 0"
        @detail="openDetail"
      />
    </div>

    <CustomerDetailDialog v-model="detailVisible" :customer-id="detailCustomerId" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { View, User, Connection } from '@element-plus/icons-vue'
import VChart from 'vue-echarts'
import { use } from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { getMarketingData } from '@/api/analytics'
import MetricCard from './components/MetricCard.vue'
import CustomerDetailDialog from './components/CustomerDetailDialog.vue'

use([LineChart, GridComponent, TooltipComponent, CanvasRenderer])

const mode = ref('all')
const loading = ref(false)
const realtime = ref({})
const metrics = ref([])
const hourlyChart = ref([])
const detailVisible = ref(false)
const detailCustomerId = ref(null)

const chartOption = computed(() => {
  const data = hourlyChart.value
  return {
    tooltip: {
      trigger: 'axis',
      formatter: (params) => {
        const p = params[0]
        return `${p.name}<br/>在线用户: <b>${p.value}</b>`
      },
    },
    grid: { left: 48, right: 24, top: 16, bottom: 32 },
    xAxis: {
      type: 'category',
      data: data.map(d => d.label),
      axisLabel: { fontSize: 11, color: '#94A3B8' },
      axisLine: { lineStyle: { color: '#E2E8F0' } },
      axisTick: { show: false },
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: '#F1F5F9' } },
      axisLabel: { fontSize: 11, color: '#94A3B8' },
    },
    series: [{
      type: 'line',
      data: data.map(d => d.count),
      smooth: true,
      symbol: 'circle',
      symbolSize: 6,
      lineStyle: { color: '#6366F1', width: 2.5 },
      itemStyle: { color: '#6366F1' },
      areaStyle: {
        color: {
          type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(99, 102, 241, 0.2)' },
            { offset: 1, color: 'rgba(99, 102, 241, 0.02)' },
          ],
        },
      },
    }],
  }
})

const metricConfigs = computed(() => {
  const map = Object.fromEntries((metrics.value || []).map(m => [m.key, m]))
  return [
    { key: 'views_total', label: '官网访问总量', icon: 'View', theme: 'blue', unit: '次', data: map.views_total },
    { key: 'registered_total', label: '官网注册总量', icon: 'UserFilled', theme: 'green', unit: '人', data: map.registered_total },
    { key: 'verified_total', label: '实名认证总量', icon: 'CircleCheck', theme: 'teal', unit: '人', data: map.verified_total },
    { key: 'purchased_total', label: '已购买用户总量', icon: 'ShoppingCart', theme: 'blue', unit: '人', data: map.purchased_total },
    { key: 'registered_no_purchase', label: '注册未购买用户总量', icon: 'Warning', theme: 'amber', unit: '人', data: map.registered_no_purchase },
    { key: 'unregistered_visitors', label: '访问未注册用户总量', icon: 'Hide', theme: 'slate', unit: 'IP', data: map.unregistered_visitors },
    { key: 'churned_users', label: '购买后连续三月未复购用户总量', icon: 'WarningFilled', theme: 'rose', unit: '人', data: map.churned_users },
  ]
})

async function fetchData() {
  loading.value = true
  try {
    const data = await getMarketingData(mode.value)
    realtime.value = data.realtime || {}
    metrics.value = data.metrics || []
    hourlyChart.value = data.hourly_chart || []
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
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-title { font-size: 22px; font-weight: 600; color: #2C3E50; margin: 0; }

.section-label {
  display: flex; align-items: center; gap: 8px;
  font-size: 14px; font-weight: 600; color: #4A5568;
  margin-bottom: 14px;
}
.live-dot {
  width: 8px; height: 8px; border-radius: 50%; background: #48BB78;
  animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.4); }
  50% { opacity: 0.8; box-shadow: 0 0 0 6px rgba(72, 187, 120, 0); }
}
.live-hint { font-size: 12px; font-weight: 400; color: #A0AEC0; margin-left: 4px; }

.realtime-cards {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
  margin-bottom: 28px;
}

.stat-card {
  border-radius: 14px; padding: 20px;
  transition: all 0.3s ease; cursor: default;
  &:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06); }

  &.theme-blue {
    background: linear-gradient(135deg, #F0F7FF, #E0EFFD); border: 1px solid #B3D4F5;
    .stat-icon-wrap { background: #4299E1; }
    .stat-value { color: #2B6CB0; }
  }
  &.theme-green {
    background: linear-gradient(135deg, #F0FFF4, #E0F5E8); border: 1px solid #B8E6C8;
    .stat-icon-wrap { background: #48BB78; }
    .stat-value { color: #276749; }
  }
  &.theme-amber {
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2); border: 1px solid #F5D9B5;
    .stat-icon-wrap { background: #E8913A; }
    .stat-value { color: #C87A2E; }
  }
}

.stat-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.stat-label { font-size: 13px; color: #718096; font-weight: 500; }
.stat-icon-wrap {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; color: #fff;
}
.stat-value {
  font-size: 36px; font-weight: 700; line-height: 1.1; margin-bottom: 6px;
  font-family: 'SF Mono', 'Cascadia Code', Consolas, monospace;
}
.stat-footer {
  font-size: 12px; color: #A0AEC0;
  strong { color: #718096; }
}

.chart-card {
  background: #fff;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  padding: 16px;
  margin-bottom: 28px;
}

.metrics-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
  margin-bottom: 16px;
}

@media (max-width: 1200px) {
  .metrics-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
  .realtime-cards { grid-template-columns: 1fr; }
  .metrics-grid { grid-template-columns: 1fr; }
}
</style>
