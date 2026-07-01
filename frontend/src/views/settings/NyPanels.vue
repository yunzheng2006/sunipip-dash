<template>
  <div class="ny-panels-page">
    <div class="page-header">
      <h2 class="page-title">Nyanpass 面板对接</h2>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon> 添加 NY 面板
      </el-button>
    </div>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      <template #title>
        配置 NY 面板账户后可为客户 IP 自动创建端口转发规则。
        <strong>每添加一个面板后，点击"同步设备组"拉取节点列表，然后勾选可用的设备组并为每个组配置对外域名。</strong>
      </template>
    </el-alert>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="名称" min-width="150">
          <template #default="{ row }">
            <strong>{{ row.name }}</strong>
            <div v-if="row.description" style="font-size: 11px; color: #909399; margin-top: 2px">
              {{ row.description }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="对接地址" min-width="240" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono" style="font-size: 12px">{{ row.api_url }}</span>
          </template>
        </el-table-column>
        <el-table-column label="账户" width="140">
          <template #default="{ row }">
            <span class="mono">{{ row.username }}</span>
          </template>
        </el-table-column>
        <el-table-column label="设备组" width="130" align="center">
          <template #default="{ row }">
            <el-tag size="small" type="success" effect="plain">
              启用 {{ row.enabled_device_groups_count || 0 }} / {{ row.device_groups_count || 0 }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="活跃规则" width="100" align="center">
          <template #default="{ row }">{{ row.active_rules_count || 0 }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">
              {{ row.is_active ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" @click="handleTest(row)" :loading="testingId === row.id">
              测试
            </el-button>
            <el-button type="warning" link size="small" @click="handleSync(row)" :loading="syncingId === row.id">
              同步设备组
            </el-button>
            <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !tableData.length" description="尚未配置 NY 面板" />
    </el-card>

    <!-- Form Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑 NY 面板' : '添加 NY 面板'"
      width="900px"
      :close-on-click-modal="false"
      top="5vh"
    >
      <el-tabs v-model="activeTab">
        <el-tab-pane label="基本配置" name="basic">
          <el-form :model="form" :rules="rules" ref="formRef" label-width="120px">
            <el-form-item label="名称" prop="name">
              <el-input v-model="form.name" placeholder="如：Nyanpass-主账号" />
            </el-form-item>
            <el-form-item label="对接地址" prop="api_url">
              <el-input v-model="form.api_url" placeholder="https://panel.example.com" />
              <div class="field-hint">
                面板主域名（不含 /api/v1）；例：<code>https://panel.nyanpass.com</code>
              </div>
            </el-form-item>
            <el-form-item label="账户" prop="username">
              <el-input v-model="form.username" placeholder="NY 面板登录账号" />
            </el-form-item>
            <el-form-item label="密码" prop="password">
              <el-input
                v-model="form.password"
                type="password"
                show-password
                :placeholder="editing ? '留空表示不修改' : '面板登录密码'"
              />
            </el-form-item>
            <el-form-item label="启用状态">
              <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="备注">
              <el-input v-model="form.description" type="textarea" :rows="2" placeholder="选填" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane v-if="editing" label="设备组" name="device-groups">
          <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 12px">
            勾选可用的设备组并为每个填写对外暴露的<strong>自定义域名/IP</strong>。
            客户连接时将使用这个地址（常用于 CNAME 到 NY 节点真实 IP）。
          </el-alert>
          <div class="sync-bar">
            <el-button type="primary" size="small" @click="handleSyncCurrent" :loading="syncingCurrent">
              <el-icon><Refresh /></el-icon> 从面板同步最新设备组
            </el-button>
            <span class="sync-hint">共 {{ deviceGroups.length }} 个设备组</span>
          </div>
          <el-table :data="deviceGroups" stripe size="small" max-height="420" empty-text="请先点击同步">
            <el-table-column label="启用" width="60" align="center">
              <template #default="{ row }">
                <el-checkbox v-model="row.is_enabled" :true-value="1" :false-value="0" />
              </template>
            </el-table-column>
            <el-table-column label="NY ID" width="80">
              <template #default="{ row }">{{ row.remote_id }}</template>
            </el-table-column>
            <el-table-column label="组名" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">{{ row.name }}</template>
            </el-table-column>
            <el-table-column label="NY 原始 host" min-width="180" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="mono" style="font-size: 11px; color: #909399">
                  {{ row.original_connect_host || '-' }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="对客自定义域名/IP" min-width="240">
              <template #default="{ row }">
                <el-input
                  v-model="row.custom_connect_host"
                  size="small"
                  placeholder="如 hk-node.sunipip.uk"
                  :disabled="!row.is_enabled"
                />
              </template>
            </el-table-column>
            <el-table-column label="端口范围" width="130">
              <template #default="{ row }">
                <span class="mono" style="font-size: 11px">{{ row.port_range || '-' }}</span>
              </template>
            </el-table-column>
          </el-table>
          <div style="margin-top: 12px">
            <el-button type="primary" @click="handleSaveDeviceGroups" :loading="savingDG">
              保存设备组配置
            </el-button>
          </div>
        </el-tab-pane>
      </el-tabs>

      <template #footer>
        <el-button @click="dialogVisible = false">关闭</el-button>
        <el-button
          v-if="activeTab === 'basic'"
          type="primary"
          :loading="submitting"
          @click="handleSubmit"
        >
          {{ editing ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Test Result Dialog -->
    <el-dialog v-model="testDialogVisible" title="连接测试结果" width="500px">
      <el-descriptions v-if="testResult" :column="1" border>
        <el-descriptions-item label="状态">
          <el-tag type="success">连接成功</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="用户名">{{ testResult.user.username || '-' }}</el-descriptions-item>
        <el-descriptions-item label="用户组">{{ testResult.user.group_name || '-' }}</el-descriptions-item>
        <el-descriptions-item label="套餐">{{ testResult.user.plan_name || '-' }}</el-descriptions-item>
        <el-descriptions-item label="最大规则">{{ testResult.user.max_rules || '-' }}</el-descriptions-item>
        <el-descriptions-item label="流量配额">
          {{ formatBytes(testResult.user.traffic_enable) }}
        </el-descriptions-item>
        <el-descriptions-item label="已用流量">
          {{ formatBytes(testResult.user.traffic_used) }}
        </el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh } from '@element-plus/icons-vue'
import {
  getNyPanels, createNyPanel, updateNyPanel, deleteNyPanel,
  testNyPanel, syncNyDeviceGroups, updateNyDeviceGroups, getNyPanel,
} from '@/api/nyPanels'

const loading = ref(false)
const tableData = ref([])
const testingId = ref(null)
const syncingId = ref(null)

const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formRef = ref()
const activeTab = ref('basic')

const form = reactive({
  name: '',
  api_url: '',
  username: '',
  password: '',
  is_active: 1,
  description: '',
})

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  api_url: [
    { required: true, message: '请输入对接地址', trigger: 'blur' },
    { type: 'url', message: '请输入有效的 URL', trigger: 'blur' },
  ],
  username: [{ required: true, message: '请输入账户', trigger: 'blur' }],
}

async function fetchData() {
  loading.value = true
  try {
    tableData.value = (await getNyPanels()) || []
  } catch { /* handled */ }
  finally { loading.value = false }
}

function resetForm() {
  form.name = ''
  form.api_url = ''
  form.username = ''
  form.password = ''
  form.is_active = 1
  form.description = ''
}

function openCreate() {
  editing.value = null
  resetForm()
  activeTab.value = 'basic'
  dialogVisible.value = true
}

async function openEdit(row) {
  editing.value = row
  form.name = row.name
  form.api_url = row.api_url
  form.username = row.username
  form.password = ''
  form.is_active = row.is_active
  form.description = row.description || ''
  activeTab.value = 'basic'
  dialogVisible.value = true
  await loadDeviceGroups(row.id)
}

// Device groups (in edit mode)
const deviceGroups = ref([])
const syncingCurrent = ref(false)
const savingDG = ref(false)

async function loadDeviceGroups(panelId) {
  try {
    const detail = await getNyPanel(panelId)
    deviceGroups.value = (detail.device_groups || []).map(g => ({
      ...g,
      is_enabled: g.is_enabled ? 1 : 0,
      custom_connect_host: g.custom_connect_host || '',
    }))
  } catch { /* handled */ }
}

async function handleSyncCurrent() {
  if (!editing.value) return
  syncingCurrent.value = true
  try {
    const res = await syncNyDeviceGroups(editing.value.id)
    ElMessage.success(`已同步 ${res?.synced || 0} 个设备组`)
    deviceGroups.value = (res?.device_groups || []).map(g => ({
      ...g,
      is_enabled: g.is_enabled ? 1 : 0,
      custom_connect_host: g.custom_connect_host || '',
    }))
  } catch { /* handled */ }
  finally { syncingCurrent.value = false }
}

async function handleSaveDeviceGroups() {
  savingDG.value = true
  try {
    await updateNyDeviceGroups(editing.value.id, deviceGroups.value.map(g => ({
      id: g.id,
      is_enabled: !!g.is_enabled,
      custom_connect_host: g.custom_connect_host || null,
    })))
    ElMessage.success('设备组已保存')
    fetchData()
  } catch { /* handled */ }
  finally { savingDG.value = false }
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    const payload = { ...form }
    if (editing.value && !payload.password) {
      delete payload.password
    }
    if (!editing.value && !payload.password) {
      ElMessage.warning('请输入密码')
      submitting.value = false
      return
    }
    if (editing.value) {
      await updateNyPanel(editing.value.id, payload)
      ElMessage.success('已保存')
      // 保持对话框打开，允许用户继续配置设备组
      editing.value = { ...editing.value, ...payload }
    } else {
      const res = await createNyPanel(payload)
      ElMessage.success('已创建，请继续同步设备组')
      editing.value = res
      activeTab.value = 'device-groups'
    }
    fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除 NY 面板「${row.name}」？`, '确认', { type: 'warning' })
    await deleteNyPanel(row.id)
    ElMessage.success('已删除')
    fetchData()
  } catch { /* cancelled or handled */ }
}

async function handleTest(row) {
  testingId.value = row.id
  try {
    const res = await testNyPanel(row.id)
    testResult.value = res
    testDialogVisible.value = true
  } catch { /* handled */ }
  finally { testingId.value = null }
}

async function handleSync(row) {
  syncingId.value = row.id
  try {
    const res = await syncNyDeviceGroups(row.id)
    ElMessage.success(`已同步 ${res?.synced || 0} 个设备组`)
    fetchData()
  } catch { /* handled */ }
  finally { syncingId.value = null }
}

// Test result dialog
const testDialogVisible = ref(false)
const testResult = ref(null)

function formatBytes(n) {
  if (!n) return '-'
  const gb = n / 1024 / 1024 / 1024
  if (gb >= 1) return gb.toFixed(2) + ' GB'
  const mb = n / 1024 / 1024
  return mb.toFixed(2) + ' MB'
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.ny-panels-page {
  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; color: #4A5568; }
  .field-hint { font-size: 12px; color: #909399; margin-top: 4px; }
  .sync-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    .sync-hint { font-size: 13px; color: #909399; }
  }
}
</style>
