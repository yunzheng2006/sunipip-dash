<template>
  <div class="status-page">
    <div class="page-header">
      <h2>系统状态</h2>
      <el-button :icon="Refresh" @click="fetchAll" :loading="refreshing">
        刷新
      </el-button>
    </div>

    <!-- 网络接口 -->
    <el-card shadow="never" class="section-card">
      <template #header>
        <div class="section-header">
          <el-icon><Connection /></el-icon>
          <span>网络接口</span>
        </div>
      </template>
      <el-table v-loading="loadingNetwork" :data="interfaces" stripe class="desktop-table">
        <el-table-column prop="name" label="接口名称" min-width="120">
          <template #default="{ row }">
            <strong>{{ row.name }}</strong>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP 地址" min-width="140">
          <template #default="{ row }">
            <span class="mono">{{ row.ip || '--' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="state" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.state === 'up' ? 'success' : 'danger'" size="small">
              {{ row.state === 'up' ? '正常' : '断开' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="type" label="类型" width="120" align="center">
          <template #default="{ row }">
            <el-tag type="info" size="small">{{ row.type || '未知' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="mac" label="MAC 地址" min-width="160">
          <template #default="{ row }">
            <span class="mono">{{ row.mac || '--' }}</span>
          </template>
        </el-table-column>
      </el-table>

      <!-- 移动端卡片 -->
      <div class="mobile-cards">
        <div v-if="loadingNetwork" class="card-loading">
          <el-skeleton :rows="3" animated />
        </div>
        <el-card
          v-for="iface in interfaces"
          :key="iface.name"
          shadow="hover"
          class="item-card"
        >
          <div class="item-card-header">
            <strong>{{ iface.name }}</strong>
            <el-tag :type="iface.state === 'up' ? 'success' : 'danger'" size="small">
              {{ iface.state === 'up' ? '正常' : '断开' }}
            </el-tag>
          </div>
          <div class="item-card-body">
            <div class="item-field">
              <span class="field-label">IP</span>
              <span class="mono">{{ iface.ip || '--' }}</span>
            </div>
            <div class="item-field">
              <span class="field-label">类型</span>
              <el-tag type="info" size="small">{{ iface.type || '未知' }}</el-tag>
            </div>
            <div class="item-field">
              <span class="field-label">MAC</span>
              <span class="mono">{{ iface.mac || '--' }}</span>
            </div>
          </div>
        </el-card>
      </div>
    </el-card>

    <!-- 服务状态 -->
    <el-card shadow="never" class="section-card">
      <template #header>
        <div class="section-header">
          <el-icon><SetUp /></el-icon>
          <span>服务状态</span>
        </div>
      </template>
      <div v-loading="loadingServices" class="services-grid">
        <el-card
          v-for="svc in services"
          :key="svc.name"
          shadow="hover"
          class="service-card"
        >
          <div class="service-info">
            <div class="service-name">{{ serviceLabels[svc.name] || svc.name }}</div>
            <el-tag
              :type="svc.running ? 'success' : 'danger'"
              size="small"
            >
              {{ svc.running ? '运行中' : '已停止' }}
            </el-tag>
          </div>
          <div class="service-action">
            <el-button
              size="small"
              type="warning"
              plain
              :loading="restartingService === svc.name"
              @click="handleRestart(svc.name)"
            >
              <el-icon><RefreshRight /></el-icon>
              重启
            </el-button>
          </div>
        </el-card>
        <el-empty v-if="!loadingServices && services.length === 0" description="暂无服务信息" />
      </div>
    </el-card>

    <!-- 系统信息 -->
    <el-card shadow="never" class="section-card">
      <template #header>
        <div class="section-header">
          <el-icon><InfoFilled /></el-icon>
          <span>系统信息</span>
        </div>
      </template>
      <div v-loading="loadingStatus">
        <el-descriptions :column="isMobile ? 1 : 2" border>
          <el-descriptions-item label="CPU 温度">
            <span :class="{ 'text-warning': systemInfo.cpu_temp >= 60, 'text-danger': systemInfo.cpu_temp >= 80 }">
              {{ systemInfo.cpu_temp || '--' }}°C
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="内存使用">
            <el-progress
              :percentage="systemInfo.memory_percent || 0"
              :stroke-width="14"
              :color="progressColor(systemInfo.memory_percent)"
              style="width: 200px;"
            />
            <span class="progress-text">
              {{ systemInfo.memory_used || '--' }} / {{ systemInfo.memory_total || '--' }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="磁盘使用">
            <el-progress
              :percentage="systemInfo.disk_percent || 0"
              :stroke-width="14"
              :color="progressColor(systemInfo.disk_percent)"
              style="width: 200px;"
            />
            <span class="progress-text">
              {{ systemInfo.disk_used || '--' }} / {{ systemInfo.disk_total || '--' }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="运行时间">
            {{ systemInfo.uptime || '--' }}
          </el-descriptions-item>
          <el-descriptions-item label="Agent 版本">
            {{ systemInfo.agent_version || '--' }}
          </el-descriptions-item>
          <el-descriptions-item label="配置版本">
            <span class="mono">{{ systemInfo.config_version || '--' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="主机名">
            {{ systemInfo.hostname || '--' }}
          </el-descriptions-item>
          <el-descriptions-item label="系统架构">
            {{ systemInfo.arch || '--' }}
          </el-descriptions-item>
        </el-descriptions>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Connection, SetUp, InfoFilled, Refresh, RefreshRight } from '@element-plus/icons-vue'
import { getStatus, getNetwork, getServices, restartService } from '../../api/local'

const loadingNetwork = ref(true)
const loadingServices = ref(true)
const loadingStatus = ref(true)
const refreshing = ref(false)
const isMobile = ref(false)

const interfaces = ref([])
const services = ref([])
const systemInfo = ref({})
const restartingService = ref(null)

const serviceLabels = {
  freeradius: 'FreeRADIUS',
  clash: 'Clash',
  dnsmasq: 'DNSMasq',
  wireguard: 'WireGuard',
  nftables: 'NFTables'
}

function progressColor(percent) {
  if (percent >= 90) return '#f56c6c'
  if (percent >= 70) return '#e6a23c'
  return '#409eff'
}

async function fetchNetwork() {
  loadingNetwork.value = true
  try {
    const { data } = await getNetwork()
    interfaces.value = data.data || data || []
  } catch {
    interfaces.value = []
  } finally {
    loadingNetwork.value = false
  }
}

async function fetchServices() {
  loadingServices.value = true
  try {
    const { data } = await getServices()
    services.value = data.data || data || []
  } catch {
    services.value = []
  } finally {
    loadingServices.value = false
  }
}

async function fetchStatus() {
  loadingStatus.value = true
  try {
    const { data } = await getStatus()
    systemInfo.value = data.data || data || {}
  } catch {
    systemInfo.value = {}
  } finally {
    loadingStatus.value = false
  }
}

async function fetchAll() {
  refreshing.value = true
  await Promise.allSettled([fetchNetwork(), fetchServices(), fetchStatus()])
  refreshing.value = false
}

async function handleRestart(serviceName) {
  restartingService.value = serviceName
  try {
    await restartService(serviceName)
    ElMessage.success(`${serviceLabels[serviceName] || serviceName} 重启成功`)
    // 重新获取服务状态
    await fetchServices()
  } catch (err) {
    ElMessage.error(err.response?.data?.message || '重启失败')
  } finally {
    restartingService.value = null
  }
}

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  fetchAll()
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>

<style scoped>
.status-page {
  max-width: 1200px;
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

.section-card {
  margin-bottom: 20px;
  border: none;
}

.section-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 16px;
}

.mono {
  font-family: 'SF Mono', 'Fira Code', monospace;
  font-size: 13px;
}

/* 服务网格 */
.services-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}

.service-card {
  border: 1px solid #ebeef5;
}

.service-card :deep(.el-card__body) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px;
}

.service-info {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.service-name {
  font-weight: 600;
  color: #303133;
}

/* 进度条文本 */
.progress-text {
  margin-left: 12px;
  font-size: 12px;
  color: #909399;
}

.text-warning {
  color: #e6a23c;
  font-weight: 600;
}

.text-danger {
  color: #f56c6c;
  font-weight: 600;
}

/* 移动端卡片 */
.mobile-cards {
  display: none;
}

.desktop-table {
  display: block;
}

.item-card {
  margin-bottom: 10px;
}

.item-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.item-card-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.item-field {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
}

.field-label {
  color: #909399;
}

.card-loading {
  padding: 16px 0;
}

@media (max-width: 767px) {
  .desktop-table {
    display: none;
  }

  .mobile-cards {
    display: block;
  }

  .services-grid {
    grid-template-columns: 1fr;
  }

  .progress-text {
    display: block;
    margin-left: 0;
    margin-top: 4px;
  }
}
</style>
