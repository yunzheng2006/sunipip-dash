<template>
  <div class="device-detail" v-loading="loading">
    <template v-if="device">
      <!-- 设备信息卡片 -->
      <el-card class="section-card" shadow="never">
        <div class="device-header">
          <div>
            <h2 class="device-title">{{ device.device_no || device.serial_number }}</h2>
            <div class="device-meta">
              <el-tag :type="device.is_online ? 'success' : 'danger'" size="small">
                {{ device.is_online ? '在线' : '离线' }}
              </el-tag>
              <el-tag v-if="device.config_synced && !configSyncing" type="success" size="small">配置已同步</el-tag>
              <el-tag v-else type="warning" size="small">
                <el-icon class="sync-spin"><Loading /></el-icon> 配置同步中...
              </el-tag>
              <span class="meta-text">{{ moduleLabel(device.bound_module) }}</span>
            </div>
          </div>
          <div class="device-status-info">
            <div v-if="status" class="status-items">
              <div class="status-item" v-if="status.wired_ip">
                <span class="status-label">设备 IP</span>
                <span class="status-value">{{ status.wired_ip }}</span>
              </div>
              <div class="status-item" v-if="status.agent_version">
                <span class="status-label">Agent</span>
                <span class="status-value">v{{ status.agent_version }}</span>
              </div>
              <div class="status-item" v-if="status.system_info">
                <span class="status-label">内存</span>
                <span class="status-value">{{ status.system_info.mem_used_mb }}/{{ status.system_info.mem_total_mb }}MB</span>
              </div>
            </div>
          </div>
        </div>
      </el-card>

      <!-- WiFi 账号管理 -->
      <el-card class="section-card" shadow="never">
        <div class="section-head">
          <span class="section-title">WiFi 账号</span>
          <div style="display: flex; gap: 8px">
            <el-button type="warning" size="small" @click="handleCleanStale()" :loading="cleaningStale" :disabled="device?.wifi_version < 2" plain>一键清理残留连接</el-button>
            <el-button type="primary" size="small" @click="openWizard()">添加账号</el-button>
          </div>
        </div>

        <div v-if="wifiAccounts.length === 0" class="empty-state">
          <div class="empty-icon" style="font-size: 48px; color: #c0c4cc;">WiFi</div>
          <p class="empty-title">尚未创建 WiFi 账号</p>
          <p class="empty-desc">创建您的第一个 WiFi 账号，连接手机或电脑通过代理上网</p>
          <el-button type="primary" @click="openWizard()">创建 WiFi 账号</el-button>
        </div>

        <!-- 桌面表格 -->
        <el-table v-else :data="wifiAccounts" stripe class="desktop-only">
          <el-table-column prop="username" label="用户名" width="140" />
          <el-table-column prop="password" label="密码" width="140" />
          <el-table-column v-if="device?.wifi_version < 2" label="通道" width="70" align="center">
            <template #default="{ row }"><el-tag size="small">{{ row.vlan_id }}</el-tag></template>
          </el-table-column>
          <el-table-column label="绑定节点" min-width="200">
            <template #default="{ row }">
              <template v-if="row.subscription">
                <span>{{ subDisplayName(row.subscription) }}</span>
              </template>
              <span v-else class="text-muted">未绑定</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="240" fixed="right">
            <template #default="{ row }">
              <el-button size="small" link @click="showWifiGuide(row)">连接信息</el-button>
              <el-button size="small" link @click="openEditDialog(row)">编辑</el-button>
              <el-button size="small" link type="danger" @click="handleDeleteWifi(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <!-- 移动端卡片 -->
        <div class="mobile-only wifi-cards" v-if="wifiAccounts.length > 0">
          <div v-for="account in wifiAccounts" :key="account.id" class="wifi-card">
            <div class="wifi-card-top">
              <span class="wifi-username">{{ account.username }}</span>
              <el-tag size="small" type="success">代理</el-tag>
            </div>
            <div class="wifi-card-info">
              <div>密码: {{ account.password }}</div>
              <div v-if="device?.wifi_version < 2">通道: {{ account.vlan_id }}</div>
              <div v-if="account.subscription">节点: {{ subDisplayName(account.subscription) }}</div>
            </div>
            <div class="wifi-card-actions">
              <el-button size="small" @click="showWifiGuide(account)">连接信息</el-button>
              <el-button size="small" @click="openEditDialog(account)">编辑</el-button>
              <el-button size="small" type="danger" @click="handleDeleteWifi(account)">删除</el-button>
            </div>
          </div>
        </div>
      </el-card>
    </template>

    <!-- WiFi 连接指南 -->
    <el-dialog title="WiFi 连接信息" v-model="wifiGuideVisible" width="480px">
      <template v-if="wifiGuideAccount">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="WiFi 名称">SunIPIP.com Streaming LAN</el-descriptions-item>
          <el-descriptions-item label="安全类型">WPA2-Enterprise</el-descriptions-item>
          <el-descriptions-item label="EAP 方法">TTLS / PAP</el-descriptions-item>
          <el-descriptions-item label="用户名">{{ wifiGuideAccount.username }}</el-descriptions-item>
          <el-descriptions-item label="密码">{{ wifiGuideAccount.password }}</el-descriptions-item>
        </el-descriptions>
        <el-divider />
        <div class="guide-steps">
          <p><b>连接步骤：</b></p>
          <ol>
            <li>打开手机/电脑的 WiFi 设置</li>
            <li>搜索并选择「SunIPIP.com Streaming LAN」</li>
            <li>输入上方的用户名和密码</li>
            <li>如提示证书验证，选择「信任」或「不验证」</li>
            <li>连接成功后即可通过绑定的节点上网</li>
          </ol>
        </div>
      </template>
    </el-dialog>

    <!-- 创建 WiFi 向导 -->
    <el-dialog title="创建 WiFi 账号" v-model="wizardVisible" width="520px" destroy-on-close :close-on-click-modal="false">
      <el-steps :active="wizardStep" finish-status="success" simple style="margin-bottom: 24px">
        <el-step title="选择节点" />
        <el-step title="账号信息" />
        <el-step title="完成" />
      </el-steps>

      <!-- Step 1: 选择代理节点 -->
      <div v-if="wizardStep === 0">
        <p class="wizard-hint">选择一个代理节点，WiFi 连接的所有流量将通过该节点转发。</p>
        <div v-if="subsLoading" v-loading="true" style="height: 120px" />
        <div v-else-if="availableSubs.length === 0" class="empty-hint">
          暂无可用的代理节点，请先在 <a href="/subscriptions" target="_blank">我的订阅</a> 中购买。
        </div>
        <div v-else class="node-list">
          <div v-for="s in availableSubs" :key="s.id"
            :class="['node-item', { selected: wifiForm.proxy_subscription_id === s.id }]"
            @click="wifiForm.proxy_subscription_id = s.id">
            <div class="node-main">
              <span class="node-name">{{ s.forward_plan?.name || '代理节点' }}</span>
              <el-tag size="small" type="info">{{ s.proxy_ip?.country_name || '-' }}</el-tag>
            </div>
            <div class="node-detail">
              <span>{{ s.proxy_ip?.ip_address || '-' }}</span>
              <span v-if="s.forward_plan?.display_host" class="node-host">{{ s.forward_plan.display_host }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 2: 账号信息 -->
      <div v-if="wizardStep === 1">
        <el-form :model="wifiForm" label-width="90px">
          <el-form-item label="登录用户名">
            <el-input v-model="wifiForm.username" />
            <div class="form-tip">连接 WiFi 时使用的账号</div>
          </el-form-item>
          <el-form-item label="登录密码">
            <el-input v-model="wifiForm.password" />
            <div class="form-tip">连接 WiFi 时使用的密码</div>
          </el-form-item>
        </el-form>
      </div>

      <!-- Step 3: 完成 -->
      <div v-if="wizardStep === 2" class="wizard-done">
        <div v-if="configSyncing" class="sync-status syncing">
          <el-icon class="sync-spin" :size="36" color="#e6a23c"><Loading /></el-icon>
          <h3>正在同步到路由器，请稍等...</h3>
          <p class="wizard-hint">WiFi 账号已创建，路由器正在同步配置，通常需要 30-60 秒。</p>
        </div>
        <div v-else class="sync-status synced">
          <div class="done-icon" style="font-size: 48px; color: #67c23a;">&#10003;</div>
          <h3>同步完成，可以使用！</h3>
        </div>
        <el-descriptions :column="1" border size="small" style="margin-top: 16px">
          <el-descriptions-item label="WiFi 名称">SunIPIP.com Streaming LAN</el-descriptions-item>
          <el-descriptions-item label="用户名">{{ createdAccount?.username }}</el-descriptions-item>
          <el-descriptions-item label="密码">{{ createdAccount?.password }}</el-descriptions-item>
          <el-descriptions-item label="代理节点">{{ selectedNodeLabel }}</el-descriptions-item>
          <el-descriptions-item label="同步状态">
            <el-tag v-if="configSyncing" type="warning" size="small">
              <el-icon class="sync-spin"><Loading /></el-icon> 同步中
            </el-tag>
            <el-tag v-else type="success" size="small">已同步</el-tag>
          </el-descriptions-item>
        </el-descriptions>
        <p v-if="!configSyncing" class="wizard-hint" style="margin-top: 12px">打开手机 WiFi 设置，连接「SunIPIP.com Streaming LAN」并输入以上账号密码即可使用。</p>
      </div>

      <template #footer>
        <template v-if="wizardStep === 0">
          <el-button @click="wizardVisible = false">取消</el-button>
          <el-button type="primary" :disabled="!wifiForm.proxy_subscription_id" @click="wizardStep = 1">
            下一步
          </el-button>
        </template>
        <template v-else-if="wizardStep === 1">
          <el-button @click="wizardStep = 0">上一步</el-button>
          <el-button type="primary" :loading="submitting" @click="handleWizardCreate">
            创建账号
          </el-button>
        </template>
        <template v-else>
          <el-button @click="showWifiGuide(createdAccount)">查看连接信息</el-button>
          <el-button type="primary" @click="wizardVisible = false">完成</el-button>
        </template>
      </template>
    </el-dialog>

    <!-- 编辑 WiFi 对话框 -->
    <el-dialog title="编辑 WiFi 账号" v-model="editDialogVisible" width="480px" destroy-on-close>
      <el-form :model="editForm" label-width="80px">
        <el-form-item label="用户名">
          <el-input v-model="editForm.username" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="editForm.password" />
        </el-form-item>
        <el-form-item label="代理节点">
          <el-select v-model="editForm.proxy_subscription_id" clearable placeholder="选择代理节点" style="width: 100%"
            :loading="subsLoading">
            <el-option v-for="s in editAvailableSubs" :key="s.id"
              :label="subOptionLabel(s)" :value="s.id">
              <div style="display: flex; justify-content: space-between; align-items: center">
                <span>{{ s.forward_plan?.name || '代理节点' }}</span>
                <span style="color: #94a3b8; font-size: 12px">{{ s.proxy_ip?.ip_address || '' }} · {{ s.proxy_ip?.country_name || '' }}</span>
              </div>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="最大设备">
          <el-input-number v-model="editForm.max_devices" :min="1" :max="device?.wifi_max_devices_per_account || 5" disabled />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleEditSubmit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Loading } from '@element-plus/icons-vue'
import {
  getDevice, getWifiAccounts, createWifiAccount,
  updateWifiAccount, deleteWifiAccount, getAvailableSubscriptions, getDeviceStatus,
  getWifiProfile, cleanStaleConnections,
} from '@/api/router'

const route = useRoute()
const deviceId = route.params.id

const device = ref(null)
const status = ref(null)
const loading = ref(false)
const submitting = ref(false)

// WiFi
const wifiAccounts = ref([])
const availableSubs = ref([])
const subsLoading = ref(false)

// Clean stale connections
const cleaningStale = ref(false)
async function handleCleanStale() {
  await ElMessageBox.confirm(
    '将清理设备上不活跃的连接，释放 IP 给新设备使用。正在使用中的设备不会受影响。',
    '一键清理残留连接',
    { type: 'warning', confirmButtonText: '确认清理', cancelButtonText: '取消' }
  )
  cleaningStale.value = true
  try {
    await cleanStaleConnections(deviceId)
    ElMessage.success('清理命令已发送，请稍等片刻后重新连接 WiFi')
  } catch { /* handled */ }
  finally { cleaningStale.value = false }
}

// Config sync polling
const configSyncing = ref(false)
let syncPollTimer = null

function startSyncPolling() {
  configSyncing.value = true
  stopSyncPolling()
  syncPollTimer = setInterval(async () => {
    try {
      const s = await getDeviceStatus(deviceId)
      status.value = s
      if (device.value) {
        device.value.is_online = s.is_online
        device.value.config_synced = s.config_synced
      }
      if (s.config_synced) {
        configSyncing.value = false
        stopSyncPolling()
        ElMessage.success('路由器配置已同步完成')
      }
    } catch { /* ignore polling errors */ }
  }, 3000)
}

function stopSyncPolling() {
  if (syncPollTimer) {
    clearInterval(syncPollTimer)
    syncPollTimer = null
  }
}

onUnmounted(() => stopSyncPolling())

// WiFi guide
const wifiGuideVisible = ref(false)
const wifiGuideAccount = ref(null)

// Wizard
const wizardVisible = ref(false)
const wizardStep = ref(0)
const createdAccount = ref(null)
const wifiForm = reactive({
  username: '', password: '', label: '',
  proxy_mode: 'proxy', proxy_subscription_id: null, max_devices: 10,
})

// Edit dialog
const editDialogVisible = ref(false)
const editingWifi = ref(null)
const editAvailableSubs = ref([])
const editForm = reactive({
  username: '', password: '', label: '',
  proxy_mode: 'proxy', proxy_subscription_id: null, max_devices: 10,
})

const selectedNodeLabel = computed(() => {
  const s = availableSubs.value.find(s => s.id === wifiForm.proxy_subscription_id)
  if (!s) return '-'
  return `${s.forward_plan?.name || '代理节点'} (${s.proxy_ip?.country_name || ''} ${s.proxy_ip?.ip_address || ''})`
})

onMounted(async () => {
  loading.value = true
  try {
    const [d, accounts, s] = await Promise.all([
      getDevice(deviceId),
      getWifiAccounts(deviceId),
      getDeviceStatus(deviceId),
    ])
    device.value = d
    wifiAccounts.value = accounts || []
    status.value = s
    if (!s.config_synced) startSyncPolling()
  } catch { /* handled */ }
  finally { loading.value = false }
})

async function fetchWifi() {
  wifiAccounts.value = await getWifiAccounts(deviceId) || []
}

async function fetchAvailableSubs() {
  subsLoading.value = true
  try {
    availableSubs.value = await getAvailableSubscriptions(deviceId) || []
  } catch { /* handled */ }
  finally { subsLoading.value = false }
}

function genRandomId() {
  return String(Math.floor(100 + Math.random() * 900))
}

function openWizard() {
  wizardStep.value = 0
  createdAccount.value = null
  const rid = genRandomId()
  Object.assign(wifiForm, {
    username: `sunip-${rid}`, password: `sunip-${rid}`,
    label: '',
    proxy_mode: 'proxy', proxy_subscription_id: null,
    max_devices: device.value?.wifi_max_devices_per_account || 5,
  })
  fetchAvailableSubs()
  wizardVisible.value = true
}

async function handleWizardCreate() {
  submitting.value = true
  try {
    const result = await createWifiAccount(deviceId, wifiForm)
    createdAccount.value = result
    wizardStep.value = 2
    fetchWifi()
    startSyncPolling()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function openEditDialog(row) {
  editingWifi.value = row
  Object.assign(editForm, {
    username: row.username, password: row.password, label: row.label || '',
    proxy_mode: row.proxy_mode, proxy_subscription_id: row.proxy_subscription_id,
    max_devices: row.max_devices,
  })
  subsLoading.value = true
  getAvailableSubscriptions(deviceId).then(subs => {
    const list = subs || []
    if (row.subscription && !list.find(s => s.id === row.proxy_subscription_id)) {
      list.unshift({ id: row.proxy_subscription_id, ...row.subscription })
    }
    editAvailableSubs.value = list
  }).finally(() => { subsLoading.value = false })
  editDialogVisible.value = true
}

async function handleEditSubmit() {
  submitting.value = true
  try {
    await updateWifiAccount(editingWifi.value.id, editForm)
    ElMessage.success('已更新，正在同步到路由器...')
    editDialogVisible.value = false
    fetchWifi()
    startSyncPolling()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDeleteWifi(row) {
  await ElMessageBox.confirm(
    `确认删除 WiFi 账号「${row.username}」？删除后对应通道将释放。`,
    '确认删除', { type: 'warning' }
  )
  try {
    await deleteWifiAccount(row.id)
    ElMessage.success('已删除，正在同步到路由器...')
    fetchWifi()
    startSyncPolling()
  } catch { /* handled */ }
}

function showWifiGuide(account) {
  wifiGuideAccount.value = account
  wifiGuideVisible.value = true
  wizardVisible.value = false
}

async function downloadIosProfile(account) {
  try {
    const blob = await getWifiProfile(account.id)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `wifi-${account.username}.mobileconfig`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    ElMessage.error('下载失败')
  }
}

function subDisplayName(sub) {
  if (sub.proxy_ip) return `${sub.proxy_ip.country_name || ''} ${sub.proxy_ip.ip_address || ''}`.trim()
  const plan = sub.forward_rule?.forward_plan || sub.forward_plan
  if (plan) return plan.name
  return `订阅 #${sub.id}`
}

function subOptionLabel(s) {
  const plan = s.forward_plan?.name || '代理节点'
  const ip = s.proxy_ip?.ip_address || ''
  return `${plan} (${ip})`
}

function moduleLabel(m) {
  return { video: '视频专线', live_mobile: '直播专线(手机)', live_pc: '直播专线(电脑)' }[m] || '-'
}
</script>

<style scoped>
.section-card { margin-bottom: 16px; }
.section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.section-title { font-size: 16px; font-weight: 600; color: #1e293b; }

.device-header { display: flex; justify-content: space-between; align-items: flex-start; }
.device-title { margin: 0 0 8px; font-size: 18px; color: #1e293b; }
.device-meta { display: flex; gap: 8px; align-items: center; }
.meta-text { color: #64748b; font-size: 13px; }

.status-items { display: flex; gap: 16px; }
.status-item { text-align: right; }
.status-label { display: block; font-size: 11px; color: #94a3b8; }
.status-value { font-size: 13px; color: #475569; }

.empty-state { text-align: center; padding: 48px 0; }
.empty-icon { font-size: 48px; margin-bottom: 12px; }
.empty-title { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 4px; }
.empty-desc { font-size: 13px; color: #94a3b8; margin: 0 0 20px; }
.empty-hint { text-align: center; padding: 24px 0; color: #94a3b8; font-size: 13px; }
.text-muted { color: #94a3b8; }

.desktop-only { display: block; }
.mobile-only { display: none; }

.wifi-cards { display: flex; flex-direction: column; gap: 12px; }
.wifi-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
.wifi-card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.wifi-username { font-weight: 600; color: #1e293b; }
.wifi-card-info { font-size: 13px; color: #64748b; line-height: 1.8; }
.wifi-card-actions { margin-top: 8px; display: flex; gap: 8px; }

.guide-steps { font-size: 13px; color: #606266; line-height: 1.8; }
.guide-steps ol { padding-left: 20px; margin: 4px 0 12px; }
.guide-steps p { margin: 8px 0 4px; }

/* Wizard */
.wizard-hint { font-size: 13px; color: #64748b; margin-bottom: 16px; }
.form-tip { font-size: 12px; color: #94a3b8; margin-top: 2px; }

.node-list { display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }
.node-item { border: 2px solid #e2e8f0; border-radius: 8px; padding: 12px; cursor: pointer; transition: all .2s; }
.node-item:hover { border-color: #a0aec0; }
.node-item.selected { border-color: #409eff; background: #f0f7ff; }
.node-main { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.node-name { font-weight: 600; color: #1e293b; }
.node-detail { font-size: 12px; color: #94a3b8; display: flex; gap: 12px; }
.node-host { color: #64748b; }

.wizard-done { text-align: center; }
.done-icon { font-size: 48px; margin-bottom: 8px; }
.wizard-done h3 { margin: 0; color: #1e293b; }

.sync-spin { animation: spin 1.2s linear infinite; display: inline-flex; vertical-align: middle; margin-right: 2px; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.sync-status { text-align: center; padding: 8px 0; }
.sync-status.syncing h3 { color: #e6a23c; margin: 12px 0 4px; }
.sync-status.synced h3 { color: #67c23a; margin: 0; }

@media (max-width: 768px) {
  .desktop-only { display: none !important; }
  .mobile-only { display: block !important; }
  .device-header { flex-direction: column; gap: 12px; }
  .status-items { flex-wrap: wrap; }
}
</style>
