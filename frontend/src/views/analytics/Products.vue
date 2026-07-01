<template>
  <div class="analytics-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">在线产品数据</h2>
        <p class="page-desc">IP 资产与地区分布概览</p>
      </div>
      <el-radio-group v-model="days" size="small" @change="fetchData">
        <el-radio-button :value="7">7天</el-radio-button>
        <el-radio-button :value="30">30天</el-radio-button>
        <el-radio-button :value="90">90天</el-radio-button>
        <el-radio-button :value="180">180天</el-radio-button>
        <el-radio-button :value="360">360天</el-radio-button>
      </el-radio-group>
    </div>

    <!-- 实时快照 -->
    <div class="section-label">
      <span class="live-dot"></span>实时快照
      <span class="live-hint">不受时间筛选影响</span>
    </div>
    <div class="realtime-cards" v-loading="loading">
      <div class="stat-card theme-blue">
        <div class="stat-top">
          <span class="stat-label">全部IP总量</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><Monitor /></el-icon></div>
        </div>
        <div class="stat-value">{{ (realtime.total_ips || 0).toLocaleString() }}</div>
        <div class="stat-footer">在线 <strong>{{ (realtime.assigned_ips || 0).toLocaleString() }}</strong></div>
      </div>
      <div class="stat-card theme-green">
        <div class="stat-top">
          <span class="stat-label">单IP总量</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><Position /></el-icon></div>
        </div>
        <div class="stat-value">{{ (realtime.single_ip_total || 0).toLocaleString() }}</div>
        <div class="stat-footer">无中转的在线 IP</div>
      </div>
      <div class="stat-card theme-amber">
        <div class="stat-top">
          <span class="stat-label">视频专线总量</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><VideoPlay /></el-icon></div>
        </div>
        <div class="stat-value">{{ (realtime.video_line_total || 0).toLocaleString() }}</div>
        <div class="stat-footer">video 模块</div>
      </div>
      <div class="stat-card theme-rose">
        <div class="stat-top">
          <span class="stat-label">直播专线总量</span>
          <div class="stat-icon-wrap"><el-icon :size="18"><Mic /></el-icon></div>
        </div>
        <div class="stat-value">{{ (realtime.live_line_total || 0).toLocaleString() }}</div>
        <div class="stat-footer">live 模块</div>
      </div>
    </div>

    <!-- 各地区在线 IP -->
    <div v-if="regionOnline.length" class="region-section">
      <div class="section-label">
        各地区在线 IP
        <span class="live-hint">{{ regionOnline.length }} 个地区</span>
      </div>
      <div class="region-card">
        <div class="region-list">
          <div v-for="(r, i) in regionOnline" :key="r.country_name" class="region-row">
            <span class="region-rank" :class="{ top: i < 3 }">{{ i + 1 }}</span>
            <span class="region-name">{{ r.country_name || '未知' }}</span>
            <div class="region-bar-wrap">
              <div class="region-bar" :style="{ width: barWidth(r.count, maxOnline) }"></div>
              <span class="bar-label" v-if="r.count / maxOnline > 0.15">{{ r.count }}</span>
            </div>
            <span class="region-count">{{ r.count.toLocaleString() }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 存量指标 -->
    <div class="section-label" style="margin-top: 24px;">
      存量指标
      <span class="live-hint">近 {{ days }} 天</span>
    </div>
    <div class="metrics-grid" v-loading="loading">
      <MetricCard
        v-for="m in metricConfigs"
        :key="m.key"
        :label="m.label"
        :value="m.data?.value ?? 0"
        :icon="m.icon"
        :theme="m.theme"
        :regions="m.data?.regions"
        :total-customers="m.data?.total_customers || m.data?.value || 0"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Monitor, Position, VideoPlay, Mic } from '@element-plus/icons-vue'
import { getProductsData } from '@/api/analytics'
import MetricCard from './components/MetricCard.vue'

const days = ref(30)
const loading = ref(false)
const realtime = ref({})
const regionOnline = ref([])
const metrics = ref([])

const maxOnline = computed(() => {
  if (!regionOnline.value.length) return 1
  return Math.max(...regionOnline.value.map(r => r.count), 1)
})

const metricConfigs = computed(() => {
  const map = Object.fromEntries((metrics.value || []).map(m => [m.key, m]))
  return [
    { key: 'renewed_3m_active', label: '连续续费三月在线IP数量', icon: 'RefreshRight', theme: 'green', data: map.renewed_3m_active },
    { key: 'expired_total', label: '过期IP总量', icon: 'Clock', theme: 'amber', data: map.expired_total },
    { key: 'region_expired', label: '各地区过期IP数量', icon: 'Location', theme: 'amber', data: map.region_expired },
    { key: 'refunded_total', label: '退款IP总量', icon: 'CircleClose', theme: 'rose', data: map.refunded_total },
    { key: 'region_refunded', label: '各地区退款IP总量', icon: 'Location', theme: 'rose', data: map.region_refunded },
  ]
})

async function fetchData() {
  loading.value = true
  try {
    const data = await getProductsData(days.value)
    realtime.value = data.realtime || {}
    regionOnline.value = data.realtime?.region_online || []
    metrics.value = data.metrics || []
  } catch { /* interceptor handles */ }
  loading.value = false
}

function barWidth(count, max) {
  return Math.round((count / max) * 100) + '%'
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.analytics-page { padding: 0; }

.page-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-title { font-size: 22px; font-weight: 600; color: #2C3E50; margin: 0 0 4px; }
.page-desc { font-size: 13px; color: #909399; margin: 0; }

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

// Realtime stat cards
.realtime-cards {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
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
  &.theme-rose {
    background: linear-gradient(135deg, #FFF5F5, #FEE2E2); border: 1px solid #FECACA;
    .stat-icon-wrap { background: #F56565; }
    .stat-value { color: #C53030; }
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

// Region section
.region-section { margin-bottom: 8px; }

.region-card {
  padding: 18px 20px; border-radius: 14px;
  background: #FAFBFC; border: 1px solid #EDF2F7;
  margin-bottom: 24px;
}

.region-list { max-height: 400px; overflow-y: auto; }

.region-row {
  display: flex; align-items: center; gap: 10px;
  padding: 5px 0; font-size: 13px;
}

.region-rank {
  flex: 0 0 24px; text-align: center; font-size: 12px; font-weight: 700; color: #A0AEC0;
  &.top { color: #E8913A; font-size: 14px; }
}

.region-name {
  flex: 0 0 80px; color: #4A5568; font-weight: 500; font-size: 13px;
  text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.region-bar-wrap {
  flex: 1; height: 22px; background: rgba(0, 0, 0, 0.04);
  border-radius: 4px; overflow: hidden; position: relative;
}

.region-bar {
  height: 100%; border-radius: 4px;
  background: linear-gradient(90deg, #4299E1, #63B3ED);
  transition: width .4s ease; min-width: 2px;
}

.bar-label {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  font-size: 11px; font-weight: 600; color: #fff;
}

.region-count {
  flex: 0 0 52px; text-align: right;
  color: #2D3748; font-weight: 700; font-size: 13px;
  font-family: 'SF Mono', Consolas, monospace;
}

// Metric grid
.metrics-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
  margin-bottom: 16px;
}

@media (max-width: 1200px) {
  .realtime-cards { grid-template-columns: repeat(2, 1fr); }
  .metrics-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
  .realtime-cards { grid-template-columns: 1fr; }
  .metrics-grid { grid-template-columns: 1fr; }
}
</style>
