<template>
  <div class="router-devices">
    <el-card class="section-card" shadow="never">
      <div class="section-head">
        <span class="section-title">我的设备</span>
        <el-button type="primary" size="small" @click="$router.push('/router/activate')">激活新设备</el-button>
      </div>

      <div v-loading="loading">
        <div v-if="devices.length === 0 && !loading" class="empty-state">
          <p>您还没有软路由设备</p>
          <el-button type="primary" @click="$router.push('/router/activate')">激活第一台设备</el-button>
        </div>

        <div class="device-grid" v-else>
          <el-card v-for="device in devices" :key="device.id" shadow="hover" class="device-card"
            @click="$router.push(`/router/${device.id}`)">
            <div class="device-top">
              <div class="device-name">{{ device.device_no || device.serial_number }}</div>
              <el-tag :type="device.is_online ? 'success' : 'danger'" size="small">
                {{ device.is_online ? '在线' : '离线' }}
              </el-tag>
            </div>
            <div class="device-info">
              <div class="info-row">
                <span class="info-label">序列号</span>
                <span class="info-value">{{ device.serial_number }}</span>
              </div>
              <div class="info-row">
                <span class="info-label">模块</span>
                <span class="info-value">{{ moduleLabel(device.bound_module) }}</span>
              </div>
              <div class="info-row">
                <span class="info-label">WiFi 账号</span>
                <span class="info-value">{{ device.wifi_accounts_count ?? 0 }} 个</span>
              </div>
              <div class="info-row">
                <span class="info-label">配置</span>
                <span class="info-value">
                  <el-tag v-if="device.config_synced" type="success" size="small">已同步</el-tag>
                  <el-tag v-else type="warning" size="small">待同步</el-tag>
                </span>
              </div>
            </div>
          </el-card>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { getMyDevices } from '@/api/router'

const devices = ref([])
const loading = ref(false)

onMounted(fetchDevices)

async function fetchDevices() {
  loading.value = true
  try {
    devices.value = await getMyDevices() || []
  } catch { /* handled */ }
  finally { loading.value = false }
}

function moduleLabel(m) {
  return { video: '视频专线', live_mobile: '直播专线(手机)', live_pc: '直播专线(电脑)' }[m] || '-'
}
</script>

<style scoped>
.section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.section-title { font-size: 16px; font-weight: 600; color: #1e293b; }
.empty-state { text-align: center; padding: 60px 0; color: #94a3b8; }
.device-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
.device-card { cursor: pointer; transition: transform 0.15s; }
.device-card:hover { transform: translateY(-2px); }
.device-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.device-name { font-size: 15px; font-weight: 600; color: #1e293b; }
.device-info { display: flex; flex-direction: column; gap: 8px; }
.info-row { display: flex; justify-content: space-between; font-size: 13px; }
.info-label { color: #94a3b8; }
.info-value { color: #475569; }

@media (max-width: 768px) {
  .device-grid { grid-template-columns: 1fr; }
}
</style>
