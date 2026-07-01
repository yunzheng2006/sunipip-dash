<template>
  <div class="xui-panels-page">
    <div class="page-header">
      <h2 class="page-title">3x-ui 中转面板</h2>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon> 添加面板
      </el-button>
    </div>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      <template #title>
        配置 3x-ui 面板用于 <strong>VLESS + Reality</strong> 中转。创建中转时会自动：
        <br>① 生成 Reality 密钥对 → ② 创建 VLESS 入站 → ③ 添加 socks5 outbound 和匹配 user 的 routing rule。
      </template>
    </el-alert>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="名称" min-width="180">
          <template #default="{ row }">
            <strong>{{ row.name }}</strong>
            <el-tag v-if="row.is_mirror" size="small" type="info" effect="plain" style="margin-left: 6px">备机</el-tag>
            <div v-if="row.mirror" class="mirror-hint">
              <el-icon :size="11"><Switch /></el-icon>
              容灾 → {{ row.mirror.name }}
            </div>
            <div v-if="row.description" style="font-size: 11px; color: #909399; margin-top: 2px">
              {{ row.description }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="面板地址" min-width="280" show-overflow-tooltip>
          <template #default="{ row }"><span class="mono">{{ row.api_url }}</span></template>
        </el-table-column>
        <el-table-column label="用户名" width="120">
          <template #default="{ row }">{{ row.username }}</template>
        </el-table-column>
        <el-table-column label="对客连接地址" min-width="180">
          <template #default="{ row }">
            <span class="mono">{{ row.connect_host || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="活跃中转" width="100" align="center">
          <template #default="{ row }">{{ row.active_inbounds_count || 0 }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">
              {{ row.is_active ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="340" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" :loading="testingId === row.id" @click="handleTest(row)">
              测试
            </el-button>
            <el-button type="primary" link size="small" @click="openInbounds(row)">
              中转列表
            </el-button>
            <el-button
              v-if="row.mirror_panel_id"
              type="warning"
              link
              size="small"
              :loading="syncAllId === row.id"
              @click="handleSyncAll(row)"
            >
              同步备机
            </el-button>
            <el-button type="primary" link size="small" @click="openEdit(row)">
              编辑
            </el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !tableData.length" description="尚未配置 3x-ui 面板" />
    </el-card>

    <!-- Form Dialog -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑 3x-ui 面板' : '添加 3x-ui 面板'" width="640px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="130px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：中转-主号" />
        </el-form-item>
        <el-form-item label="面板地址" prop="api_url">
          <el-input v-model="form.api_url" placeholder="https://vps.example.com:54321/basePath" />
          <div class="hint">含端口和自定义 basePath，不带尾部斜杠</div>
        </el-form-item>
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="3x-ui 管理员账号" />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            :placeholder="editing ? '留空表示不修改' : '3x-ui 管理员密码'"
          />
        </el-form-item>
        <el-form-item label="对客连接地址">
          <el-input v-model="form.connect_host" placeholder="例如 hr.sunipip.com 或 1.2.3.4（可留空）" />
          <div class="hint">生成 vless:// 客户端链接时使用；留空则用面板域名</div>
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>

        <el-divider content-position="left">容灾备机</el-divider>

        <el-form-item label="作为备机">
          <el-switch v-model="form.is_mirror" :active-value="1" :inactive-value="0" />
          <div class="hint">勾选后本面板不出现在业务员选择列表，仅作为容灾备机</div>
        </el-form-item>
        <el-form-item label="备机指向">
          <el-select
            v-model="form.mirror_panel_id"
            placeholder="选择此面板的备机（可不选）"
            clearable
            filterable
            style="width: 100%"
            :disabled="!!form.is_mirror"
          >
            <el-option
              v-for="p in mirrorCandidates"
              :key="p.id"
              :label="`#${p.id} ${p.name} (${p.connect_host || p.api_url})`"
              :value="p.id"
              :disabled="editing && p.id === editing.id"
            />
          </el-select>
          <div class="hint">
            建议只选择"作为备机"的面板。主面板每次操作成功后，会异步把配置 replay 到此备机。
          </div>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.description" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          {{ editing ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Test Result Dialog -->
    <el-dialog v-model="testResultVisible" title="连接测试结果" width="560px">
      <el-alert v-if="testResult?.ok" type="success" :closable="false" show-icon>
        连接成功，面板共 <strong>{{ testResult.inbound_count }}</strong> 个入站
      </el-alert>
      <el-table v-if="testResult?.sample?.length" :data="testResult.sample" size="small" style="margin-top: 12px">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="remark" label="备注" min-width="180" />
        <el-table-column prop="port" label="端口" width="90" />
        <el-table-column prop="protocol" label="协议" width="100" />
      </el-table>
    </el-dialog>

    <!-- Inbounds List Dialog -->
    <el-dialog
      v-model="inboundsDialogVisible"
      :title="`面板中转列表 - ${currentPanel?.name}`"
      width="1100px"
      top="5vh"
    >
      <div style="margin-bottom: 12px">
        <el-button type="primary" size="small" @click="openCreateForward">
          <el-icon><Plus /></el-icon> 手动创建中转
        </el-button>
        <el-button size="small" @click="loadInbounds">刷新</el-button>
      </div>
      <el-table :data="inboundsList" v-loading="inboundsLoading" size="small" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="备注" min-width="200" show-overflow-tooltip>
          <template #default="{ row }"><strong>{{ row.remark }}</strong></template>
        </el-table-column>
        <el-table-column label="端口" width="90">
          <template #default="{ row }">
            <span class="mono">{{ row.port || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="源 IP" min-width="160">
          <template #default="{ row }">
            <span class="mono">{{ row.proxy_ip?.ip_address }}:{{ row.proxy_ip?.port }}</span>
          </template>
        </el-table-column>
        <el-table-column label="客户" min-width="120">
          <template #default="{ row }">{{ row.subscription?.customer?.customer_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备机同步" width="110" align="center">
          <template #default="{ row }">
            <el-tag
              v-if="row.mirror_sync_status"
              :type="mirrorStatusTag(row.mirror_sync_status)"
              size="small"
            >
              {{ mirrorStatusLabel(row.mirror_sync_status) }}
            </el-tag>
            <span v-else class="muted">未配置</span>
          </template>
        </el-table-column>
        <el-table-column label="vless 链接" min-width="180">
          <template #default="{ row }">
            <el-button v-if="row.vless_url" link type="primary" size="small" @click="copyText(row.vless_url)">
              复制 vless://
            </el-button>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="170" align="center" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.mirror_sync_status && row.mirror_sync_status !== 'synced' && row.status === 'active'"
              type="warning"
              link
              size="small"
              @click="handleResync(row)"
            >
              重试同步
            </el-button>
            <el-button
              v-if="row.status !== 'deleted'"
              type="danger"
              link
              size="small"
              @click="handleDeleteInbound(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- Create Forward Dialog -->
    <el-dialog v-model="createForwardVisible" title="为源 IP 创建 3x-ui 中转" width="560px">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        选择一条 socks5 源 IP，系统会自动在 3x-ui 创建 vless+reality 中转。
      </el-alert>
      <el-form :model="forwardForm" label-width="100px">
        <el-form-item label="源 IP" required>
          <el-select
            v-model="forwardForm.proxy_ip_id"
            filterable
            remote
            reserve-keyword
            placeholder="搜索资产名 / IP 地址"
            :remote-method="searchProxyIps"
            :loading="proxyIpSearchLoading"
            style="width: 100%"
          >
            <el-option
              v-for="ip in proxyIpOptions"
              :key="ip.id"
              :label="`#${ip.id} ${ip.asset_name || ip.ip_address} (${ip.ip_address}:${ip.port})`"
              :value="ip.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="forwardForm.remark" placeholder="留空则使用 IP 资产名" />
          <div class="hint">用作 inbound.remark 和 client.email</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createForwardVisible = false">取消</el-button>
        <el-button type="primary" :loading="forwardLoading" :disabled="!forwardForm.proxy_ip_id" @click="submitCreateForward">
          创建
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Switch } from '@element-plus/icons-vue'
import {
  getXuiPanels, createXuiPanel, updateXuiPanel, deleteXuiPanel,
  testXuiPanel, createXuiForward, getXuiInbounds, deleteXuiInbound,
  syncAllXuiToMirror, resyncXuiInboundToMirror,
} from '@/api/xuiPanels'
import { getProxyIps } from '@/api/proxyIps'

const loading = ref(false)
const tableData = ref([])

// ========== CRUD dialog ==========
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formRef = ref()
const form = reactive({
  name: '',
  api_url: '',
  username: '',
  password: '',
  connect_host: '',
  is_active: 1,
  mirror_panel_id: null,
  is_mirror: 0,
  description: '',
})

// 备机候选：所有已存在的面板（排除自身）
const mirrorCandidates = computed(() => tableData.value || [])
const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  api_url: [
    { required: true, message: '请输入面板地址', trigger: 'blur' },
    { type: 'url', message: '请输入有效 URL', trigger: 'blur' },
  ],
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
}

async function fetchData() {
  loading.value = true
  try {
    tableData.value = await getXuiPanels() || []
  } catch { /* handled */ }
  finally { loading.value = false }
}

function resetForm() {
  form.name = ''
  form.api_url = ''
  form.username = ''
  form.password = ''
  form.connect_host = ''
  form.is_active = 1
  form.mirror_panel_id = null
  form.is_mirror = 0
  form.description = ''
}

function openCreate() {
  editing.value = null
  resetForm()
  dialogVisible.value = true
}

function openEdit(row) {
  editing.value = row
  form.name = row.name
  form.api_url = row.api_url
  form.username = row.username
  form.password = ''
  form.connect_host = row.connect_host || ''
  form.is_active = row.is_active
  form.mirror_panel_id = row.mirror_panel_id || null
  form.is_mirror = row.is_mirror || 0
  form.description = row.description || ''
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  if (!editing.value && !form.password) {
    ElMessage.warning('请输入密码')
    return
  }
  submitting.value = true
  try {
    const payload = { ...form }
    if (editing.value) {
      if (!payload.password) delete payload.password
      await updateXuiPanel(editing.value.id, payload)
      ElMessage.success('已保存')
    } else {
      await createXuiPanel(payload)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除面板「${row.name}」？`, '确认', { type: 'warning' })
    await deleteXuiPanel(row.id)
    ElMessage.success('已删除')
    fetchData()
  } catch { /* cancelled */ }
}

// ========== Test connection ==========
const testingId = ref(null)
const testResult = ref(null)
const testResultVisible = ref(false)

async function handleTest(row) {
  testingId.value = row.id
  try {
    const res = await testXuiPanel(row.id)
    testResult.value = res
    testResultVisible.value = true
  } catch { /* handled */ }
  finally { testingId.value = null }
}

// ========== Inbounds dialog ==========
const inboundsDialogVisible = ref(false)
const inboundsLoading = ref(false)
const inboundsList = ref([])
const currentPanel = ref(null)

async function openInbounds(row) {
  currentPanel.value = row
  inboundsList.value = []
  inboundsDialogVisible.value = true
  loadInbounds()
}

async function loadInbounds() {
  if (!currentPanel.value) return
  inboundsLoading.value = true
  try {
    inboundsList.value = await getXuiInbounds(currentPanel.value.id) || []
  } catch { /* handled */ }
  finally { inboundsLoading.value = false }
}

function statusTag(s) { return { active: 'success', pending: 'warning', failed: 'danger', deleted: 'info' }[s] || 'info' }
function statusLabel(s) { return { active: '活跃', pending: '处理中', failed: '失败', deleted: '已删除' }[s] || s }

async function handleDeleteInbound(row) {
  try {
    await ElMessageBox.confirm(`删除中转 #${row.id} (${row.remark})？`, '确认', { type: 'warning' })
    await deleteXuiInbound(row.id)
    ElMessage.success('已删除')
    loadInbounds()
  } catch { /* cancelled */ }
}

// ========== Create forward ==========
const createForwardVisible = ref(false)
const forwardLoading = ref(false)
const forwardForm = reactive({
  proxy_ip_id: null,
  remark: '',
})
const proxyIpOptions = ref([])
const proxyIpSearchLoading = ref(false)

function openCreateForward() {
  forwardForm.proxy_ip_id = null
  forwardForm.remark = ''
  proxyIpOptions.value = []
  createForwardVisible.value = true
}

async function searchProxyIps(keyword) {
  proxyIpSearchLoading.value = true
  try {
    const params = { per_page: 30 }
    if (keyword) params['filter[ip_address]'] = keyword
    const res = await getProxyIps(params)
    proxyIpOptions.value = res?.items || []
  } catch { /* handled */ }
  finally { proxyIpSearchLoading.value = false }
}

async function submitCreateForward() {
  if (!currentPanel.value || !forwardForm.proxy_ip_id) return
  forwardLoading.value = true
  try {
    const res = await createXuiForward(currentPanel.value.id, {
      proxy_ip_id: forwardForm.proxy_ip_id,
      remark: forwardForm.remark || undefined,
    })
    ElMessage.success('中转创建成功')
    createForwardVisible.value = false
    loadInbounds()

    // 弹出 vless 链接方便复制
    if (res?.vless_url) {
      ElMessageBox.alert(
        `<div style="word-break: break-all; font-family: monospace; font-size: 12px; padding: 8px; background: #FAF7F2; border-radius: 4px">${res.vless_url}</div>`,
        '中转已创建 - vless 链接',
        { dangerouslyUseHTMLString: true, confirmButtonText: '复制并关闭' }
      ).then(() => copyText(res.vless_url))
    }
  } catch { /* handled */ }
  finally { forwardLoading.value = false }
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
}

// ========== Mirror Sync ==========
const syncAllId = ref(null)

function mirrorStatusTag(s) {
  return { synced: 'success', pending: 'warning', failed: 'danger' }[s] || 'info'
}
function mirrorStatusLabel(s) {
  return { synced: '已同步', pending: '待同步', failed: '同步失败' }[s] || s
}

async function handleSyncAll(row) {
  try {
    await ElMessageBox.confirm(
      `将当前面板所有未同步 / 失败的活跃中转重新入队到备机？`,
      '确认全量同步',
      { type: 'warning' }
    )
  } catch { return }

  syncAllId.value = row.id
  try {
    const res = await syncAllXuiToMirror(row.id)
    ElMessage.success(`已入队 ${res?.queued || 0} 条同步任务`)
  } catch { /* handled */ }
  finally { syncAllId.value = null }
}

async function handleResync(row) {
  try {
    await resyncXuiInboundToMirror(row.id)
    ElMessage.success('已入队重试')
    setTimeout(loadInbounds, 1500)
  } catch { /* handled */ }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.xui-panels-page {
  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 12px; color: #4A5568; }
  .hint { font-size: 12px; color: #909399; margin-top: 4px; }
  .muted { font-size: 11px; color: #C0C4CC; }
  .mirror-hint {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-top: 2px;
    font-size: 11px;
    color: #409EFF;
  }
}
</style>
