<template>
  <div class="dashboard-page">
    <div class="page-header">
      <h2>仪表盘</h2>
      <el-tag type="info" size="small">
        自动刷新：每 30 秒
      </el-tag>
    </div>

    <el-row :gutter="16">
      <!-- 系统状态 -->
      <el-col :xs="24" :sm="12" :lg="6">
        <el-card shadow="hover" class="stat-card">
          <template #header>
            <div class="card-header">
              <el-icon color="#409eff"><Cpu /></el-icon>
              <span>系统状态</span>
            </div>
          </template>
          <div v-if="loading" class="card-loading">
            <el-skeleton :rows="3" animated />
          </div>
          <div v-else class="card-body">
            <div class="stat-item">
              <span class="stat-label">CPU 温度</span>
              <span class="stat-value" :class="tempClass">{{ status.cpu_temp || '--' }}°C</span>
            </div>
            <div class="stat-item">
              <span class="stat-label">内存使用</span>
              <el-progress
                :percentage="status.memory_percent || 0"
                :stroke-width="12"
                :color="progressColor(status.memory_percent)"
              />
            </div>
            <div class="stat-item">
              <span class="stat-label">磁盘使用</span>
              <el-progress
                :percentage="status.disk_percent || 0"
                :stroke-width="12"
                :color="progressColor(status.disk_percent)"
              />
            </div>
            <div class="stat-item">
              <span class="stat-label">运行时间</span>
              <span class="stat-value">{{ status.uptime || '--' }}</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 网络状态 -->
      <el-col :xs="24" :sm="12" :lg="6">
        <el-card shadow="hover" class="stat-card">
          <template #header>
            <div class="card-header">
              <el-icon color="#67c23a"><Connection /></el-icon>
              <span>网络状态</span>
            </div>
          </template>
          <div v-if="loading" class="card-loading">
            <el-skeleton :rows="3" animated />
          </div>
          <div v-else class="card-body">
            <div class="stat-item">
              <span class="stat-label">WAN IP</span>
              <span class="stat-value mono">{{ status.wan_ip || '--' }}</span>
            </div>
            <div class="stat-item">
              <span class="stat-label">WAN 状态</span>
              <el-tag :type="status.wan_state === 'up' ? 'success' : 'danger'" size="small">
                {{ status.wan_state === 'up' ? '已连接' : '未连接' }}
              </el-tag>
            </div>
            <div class="stat-item">
              <span class="stat-label">管理接口</span>
              <span class="stat-value mono">172.10.0.1</span>
            </div>
            <div class="stat-item">
              <span class="stat-label">LAN 接口</span>
              <span class="stat-value mono">192.168.1.1</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 配置状态 -->
      <el-col :xs="24" :sm="12" :lg="6">
        <el-card shadow="hover" class="stat-card">
          <template #header>
            <div class="card-header">
              <el-icon color="#e6a23c"><Setting /></el-icon>
              <span>配置状态</span>
            </div>
          </template>
          <div v-if="loading" class="card-loading">
            <el-skeleton :rows="3" animated />
          </div>
          <div v-else class="card-body">
            <div class="stat-item">
              <span class="stat-label">配置版本</span>
              <span class="stat-value mono">{{ status.config_version || '--' }}</span>
            </div>
            <div class="stat-item">
              <span class="stat-label">同步状态</span>
              <el-tag :type="status.config_synced ? 'success' : 'warning'" size="small">
                {{ status.config_synced ? '已同步' : '待同步' }}
              </el-tag>
            </div>
            <div class="stat-item">
              <span class="stat-label">Agent 版本</span>
              <span class="stat-value">{{ status.agent_version || '--' }}</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 连接设备 -->
      <el-col :xs="24" :sm="12" :lg="6">
        <el-card shadow="hover" class="stat-card">
          <template #header>
            <div class="card-header">
              <el-icon color="#909399"><User /></el-icon>
              <span>已连接设备</span>
            </div>
          </template>
          <div v-if="loading" class="card-loading">
            <el-skeleton :rows="3" animated />
          </div>
          <div v-else class="card-body">
            <div class="stat-item">
              <span class="stat-label">设备总数</span>
              <span class="stat-value big">{{ totalDevices }}</span>
            </div>
            <div v-for="(count, vlan) in devicesByVlan" :key="vlan" class="stat-item">
              <span class="stat-label">VLAN {{ vlan }}</span>
              <el-tag size="small">{{ count }} 台</el-tag>
            </div>
            <div v-if="Object.keys(devicesByVlan).length === 0" class="stat-item">
              <span class="stat-label muted">暂无连接设备</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Cpu, Connection, Setting, User } from '@element-plus/icons-vue'
import { getStatus, getConnectedDevices } from '../../api/local'

const loading = ref(true)
const status = ref({})
const connectedDevices = ref([])
let refreshTimer = null

const totalDevices = computed(() => connectedDevices.value.length)

const devicesByVlan = computed(() => {
  const map = {}
  connectedDevices.value.forEach(d => {
    const vlan = d.vlan || 'default'
    map[vlan] = (map[vlan] || 0) + 1
  })
  return map
})

const tempClass = computed(() => {
  const temp = status.value.cpu_temp || 0
  if (temp >= 80) return 'danger'
  if (temp >= 60) return 'warning'
  return ''
})

function progressColor(percent) {
  if (percent >= 90) return '#f56c6c'
  if (percent >= 70) return '#e6a23c'
  return '#409eff'
}

async function fetchData() {
  try {
    const [statusRes, devicesRes] = await Promise.allSettled([
      getStatus(),
      getConnectedDevices()
    ])
    if (statusRes.status === 'fulfilled') {
      status.value = statusRes.value.data?.data || statusRes.value.data || {}
    }
    if (devicesRes.status === 'fulfilled') {
      connectedDevices.value = devicesRes.value.data?.data || devicesRes.value.data || []
    }
  } catch {
    // 静默处理刷新失败
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
  refreshTimer = setInterval(fetchData, 30000)
})

onUnmounted(() => {
  if (refreshTimer) {
    clearInterval(refreshTimer)
  }
})
</script>

<style scoped>
.dashboard-page {
  max-width: 1400px;
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.page-header h2 {
  font-size: 20px;
  color: #303133;
}

.stat-card {
  margin-bottom: 16px;
}

.card-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
}

.card-loading {
  padding: 8px 0;
}

.card-body {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.stat-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.stat-label {
  font-size: 13px;
  color: #909399;
}

.stat-label.muted {
  color: #c0c4cc;
  font-style: italic;
}

.stat-value {
  font-size: 14px;
  color: #303133;
  font-weight: 500;
}

.stat-value.mono {
  font-family: 'SF Mono', 'Fira Code', monospace;
  font-size: 13px;
}

.stat-value.big {
  font-size: 28px;
  font-weight: 700;
  color: #409eff;
}

.stat-value.warning {
  color: #e6a23c;
}

.stat-value.danger {
  color: #f56c6c;
}
</style>
