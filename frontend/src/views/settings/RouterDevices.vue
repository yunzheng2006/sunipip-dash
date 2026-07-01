<template>
  <div class="page-container">
    <div class="page-header">
      <div>
        <h2>软路由设备</h2>
        <p class="text-muted">管理软路由设备库存、绑定与配置</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <el-button @click="openAgentDialog">Agent 版本管理</el-button>
        <el-button type="primary" @click="openCreateDialog">添加设备</el-button>
      </div>
    </div>

    <!-- 统计卡片 -->
    <el-row :gutter="16" class="stats-row">
      <el-col :span="4" v-for="s in statCards" :key="s.label">
        <el-card shadow="never" class="stat-card">
          <div class="stat-value">{{ stats[s.key] ?? '-' }}</div>
          <div class="stat-label">{{ s.label }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 过滤器 -->
    <div class="filter-bar">
      <el-select v-model="filters.status" placeholder="状态" clearable style="width: 130px" @change="fetchList">
        <el-option label="库存" value="inventory" />
        <el-option label="已配置" value="provisioned" />
        <el-option label="在线" value="online" />
        <el-option label="离线" value="offline" />
        <el-option label="已停用" value="decommissioned" />
      </el-select>
      <el-select v-model="filters.bound_module" placeholder="模块" clearable style="width: 130px" @change="fetchList">
        <el-option label="视频专线" value="video" />
        <el-option label="直播专线(手机)" value="live_mobile" />
        <el-option label="直播专线(电脑)" value="live_pc" />
      </el-select>
      <el-input v-model="filters.search" placeholder="搜索设备编号/序列号/备注" clearable style="width: 240px"
        @keyup.enter="fetchList" @clear="fetchList">
        <template #append><el-button @click="fetchList">搜索</el-button></template>
      </el-input>
    </div>

    <!-- 设备表格 -->
    <el-table :data="list" v-loading="loading" stripe @row-click="goDetail">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="设备编号" min-width="160">
        <template #default="{ row }">
          <span>{{ row.device_no || '-' }}</span>
          <div v-if="row.hostname" class="text-muted" style="font-size: 12px;">{{ row.hostname }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="serial_number" label="序列号" width="160" />
      <el-table-column label="路由器型号" width="130">
        <template #default="{ row }">
          <span v-if="row.router_model">{{ row.router_model.name }}</span>
          <span v-else class="text-muted">-</span>
        </template>
      </el-table-column>
      <el-table-column label="AP 型号" width="120">
        <template #default="{ row }">
          <span v-if="row.ap_model">{{ row.ap_model.name }}</span>
          <span v-else class="text-muted">-</span>
        </template>
      </el-table-column>
      <el-table-column label="客户" width="140">
        <template #default="{ row }">
          <span v-if="row.customer">{{ row.customer.customer_name }}</span>
          <el-tag v-else size="small" type="info">未绑定</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="模块" width="120">
        <template #default="{ row }">
          <span v-if="row.bound_module">{{ moduleLabel(row.bound_module) }}</span>
          <span v-else class="text-muted">-</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90" align="center">
        <template #default="{ row }">
          <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="配置" width="80" align="center">
        <template #default="{ row }">
          <el-tag v-if="row.config_version > 0" :type="row.applied_config_version >= row.config_version ? 'success' : 'warning'" size="small">
            v{{ row.config_version }}
          </el-tag>
          <span v-else class="text-muted">-</span>
        </template>
      </el-table-column>
      <el-table-column label="最后心跳" width="160">
        <template #default="{ row }">
          <span v-if="row.last_heartbeat_at">{{ dayjs(row.last_heartbeat_at).format('YYYY-MM-DD HH:mm:ss') }}</span>
          <span v-else class="text-muted">从未</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="100" fixed="right">
        <template #default="{ row }">
          <el-button size="small" link type="primary" @click.stop="goDetail(row)">详情</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination v-if="pagination.total > 0" class="mt-16"
      layout="total, prev, pager, next, sizes" :total="pagination.total"
      :page-size="pagination.per_page" :current-page="pagination.current_page"
      :page-sizes="[15, 30, 50]" @current-change="p => { pagination.current_page = p; fetchList() }"
      @size-change="s => { pagination.per_page = s; fetchList() }" />

    <!-- 创建对话框 -->
    <el-dialog title="添加设备" v-model="createVisible" width="460px" destroy-on-close>
      <el-form :model="createForm" label-width="90px">
        <el-form-item label="序列号">
          <el-input v-model="createForm.serial_number" placeholder="留空自动生成临时编号" />
        </el-form-item>
        <el-form-item label="主机名">
          <el-input v-model="createForm.hostname" placeholder="可选" />
        </el-form-item>
        <el-form-item label="路由器型号">
          <el-select v-model="createForm.router_model_id" clearable placeholder="选择路由器型号" style="width: 100%">
            <el-option v-for="m in catalogOptions.router_models || []" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="AP 型号">
          <el-select v-model="createForm.ap_model_id" clearable placeholder="选择 AP 型号" style="width: 100%">
            <el-option v-for="m in catalogOptions.ap_models || []" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="套餐">
          <el-select v-model="createForm.bundle_id" clearable placeholder="选择套餐搭配" style="width: 100%">
            <el-option v-for="b in catalogOptions.bundles || []" :key="b.id" :label="b.name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="createForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleCreate">确定</el-button>
      </template>
    </el-dialog>
    <!-- Agent 版本管理对话框 -->
    <el-dialog title="Agent 版本管理" v-model="agentDialogVisible" width="560px" destroy-on-close>
      <div v-loading="agentLoading">
        <!-- 当前版本信息 -->
        <el-descriptions :column="2" border size="small" style="margin-bottom: 20px;">
          <el-descriptions-item label="当前版本">
            <el-tag v-if="agentInfo.current_version" type="success">v{{ agentInfo.current_version }}</el-tag>
            <span v-else class="text-muted">未上传</span>
          </el-descriptions-item>
          <el-descriptions-item label="二进制大小">
            {{ agentInfo.binary_size ? (agentInfo.binary_size / 1024 / 1024).toFixed(2) + ' MB' : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="上传时间">{{ agentInfo.uploaded_at || '-' }}</el-descriptions-item>
          <el-descriptions-item label="在线设备">
            <span style="color: #67c23a; font-weight: 600;">{{ agentInfo.online_devices ?? 0 }}</span>
            / {{ agentInfo.total_provisioned ?? 0 }}
          </el-descriptions-item>
        </el-descriptions>

        <!-- 版本分布 -->
        <div v-if="agentInfo.version_distribution?.length" style="margin-bottom: 20px;">
          <div style="font-size: 13px; font-weight: 500; margin-bottom: 8px; color: #606266;">设备版本分布</div>
          <div v-for="v in agentInfo.version_distribution" :key="v.agent_version"
               style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
            <el-tag size="small" :type="v.agent_version === agentInfo.current_version ? 'success' : 'warning'">
              v{{ v.agent_version }}
            </el-tag>
            <el-progress :percentage="Math.round(v.count / agentInfo.total_provisioned * 100)"
                         :stroke-width="14" style="flex: 1;" />
            <span style="font-size: 12px; color: #909399; min-width: 40px; text-align: right;">{{ v.count }} 台</span>
          </div>
        </div>

        <!-- 上传新版本 -->
        <el-divider content-position="left">上传新版本</el-divider>
        <el-form label-width="90px">
          <el-form-item label="版本号" required>
            <el-input v-model="agentUploadForm.version" placeholder="例如: 1.2.0" style="width: 200px;" />
          </el-form-item>
          <el-form-item label="二进制文件" required>
            <el-upload
              ref="agentUploadRef"
              :auto-upload="false"
              :limit="1"
              :on-change="onAgentFileChange"
              :on-remove="() => agentUploadForm.file = null"
              accept="*"
            >
              <el-button size="small">选择文件</el-button>
              <template #tip>
                <div style="font-size: 12px; color: #909399; margin-top: 4px;">
                  上传编译好的 linux/amd64 二进制文件，最大 50MB
                </div>
              </template>
            </el-upload>
          </el-form-item>
        </el-form>

        <el-alert type="info" :closable="false" style="margin-bottom: 12px;">
          上传后，所有在线设备将在下次心跳（约5秒）时自动检测到新版本并完成热更新。
        </el-alert>
      </div>
      <template #footer>
        <el-button @click="agentDialogVisible = false">关闭</el-button>
        <el-button type="primary" :loading="agentUploading"
                   :disabled="!agentUploadForm.version || !agentUploadForm.file"
                   @click="handleAgentUpload">
          上传并推送
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import dayjs from 'dayjs'
import { getRouterDevices, getRouterDeviceStats, createRouterDevice, getAgentVersion, uploadAgentBinary } from '@/api/routerDevices'
import { getRouterCatalogOptions } from '@/api/routerCatalog'

console.log('OEM Contact edward.sun@as204921.net')

const router = useRouter()
const list = ref([])
const loading = ref(false)
const submitting = ref(false)
const createVisible = ref(false)

const stats = ref({})
const statCards = [
  { key: 'total', label: '总计' },
  { key: 'online', label: '在线' },
  { key: 'offline', label: '离线' },
  { key: 'inventory', label: '库存' },
  { key: 'unbound', label: '未绑定' },
  { key: 'decommissioned', label: '已停用' },
]

const filters = reactive({ status: '', bound_module: '', search: '' })
const pagination = reactive({ total: 0, per_page: 15, current_page: 1 })
const createForm = reactive({ serial_number: '', hostname: '', router_model_id: null, ap_model_id: null, bundle_id: null, remark: '' })
const catalogOptions = ref({})

// Agent 版本管理
const agentDialogVisible = ref(false)
const agentLoading = ref(false)
const agentUploading = ref(false)
const agentUploadRef = ref(null)
const agentInfo = ref({})
const agentUploadForm = reactive({ version: '', file: null })

onMounted(() => { fetchStats(); fetchList(); fetchCatalogOptions() })

async function fetchCatalogOptions() {
  try {
    catalogOptions.value = await getRouterCatalogOptions() || {}
  } catch { /* handled */ }
}

async function fetchStats() {
  try {
    const res = await getRouterDeviceStats()
    stats.value = res || {}
  } catch { /* handled */ }
}

async function fetchList() {
  loading.value = true
  try {
    const params = { per_page: pagination.per_page, page: pagination.current_page }
    if (filters.status) params['filter[status]'] = filters.status
    if (filters.bound_module) params['filter[bound_module]'] = filters.bound_module
    if (filters.search) params['filter[search]'] = filters.search
    const res = await getRouterDevices(params)
    list.value = res?.items || []
    Object.assign(pagination, res?.pagination || {})
  } catch { /* handled */ }
  finally { loading.value = false }
}

function openCreateDialog() {
  Object.assign(createForm, { serial_number: '', hostname: '', router_model_id: null, ap_model_id: null, bundle_id: null, remark: '' })
  createVisible.value = true
}

async function handleCreate() {
  submitting.value = true
  try {
    await createRouterDevice(createForm)
    ElMessage.success('设备已添加')
    createVisible.value = false
    fetchList()
    fetchStats()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function goDetail(row) {
  router.push(`/settings/router-devices/${row.id}`)
}

function statusLabel(s) {
  return { inventory: '库存', provisioned: '已配置', online: '在线', offline: '离线', decommissioned: '已停用' }[s] || s
}
function statusType(s) {
  return { inventory: 'info', provisioned: '', online: 'success', offline: 'danger', decommissioned: 'info' }[s] || ''
}
function moduleLabel(m) {
  return { video: '视频专线', live_mobile: '直播(手机)', live_pc: '直播(电脑)' }[m] || m
}

async function openAgentDialog() {
  agentDialogVisible.value = true
  agentLoading.value = true
  agentUploadForm.version = ''
  agentUploadForm.file = null
  try {
    agentInfo.value = await getAgentVersion() || {}
  } catch { /* handled */ }
  finally { agentLoading.value = false }
}

function onAgentFileChange(file) {
  agentUploadForm.file = file.raw
}

async function handleAgentUpload() {
  if (!agentUploadForm.version || !agentUploadForm.file) return
  agentUploading.value = true
  try {
    const fd = new FormData()
    fd.append('version', agentUploadForm.version)
    fd.append('binary', agentUploadForm.file)
    await uploadAgentBinary(fd)
    ElMessage.success(`Agent v${agentUploadForm.version} 已上传，设备将自动更新`)
    agentUploadForm.version = ''
    agentUploadForm.file = null
    if (agentUploadRef.value) agentUploadRef.value.clearFiles()
    agentInfo.value = await getAgentVersion() || {}
  } catch { /* handled */ }
  finally { agentUploading.value = false }
}
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.page-header h2 { margin: 0 0 4px; }
.text-muted { color: #909399; font-size: 13px; margin: 0; }
.stats-row { margin-bottom: 20px; }
.stat-card { text-align: center; }
.stat-value { font-size: 24px; font-weight: 600; }
.stat-label { font-size: 12px; color: #909399; margin-top: 4px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.mt-16 { margin-top: 16px; }
</style>
