<template>
  <div class="feishu-sync-page">
    <div class="page-header">
      <h2 class="page-title">飞书多维表格同步</h2>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon> 添加配置
      </el-button>
    </div>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      关联客户到飞书多维表格。平台操作（续费、退订、改到期等）后自动推送变更到飞书，无定时轮询。
      同步时会检测飞书侧数据是否被人为修改，如有出入将通过企业微信通知。
    </el-alert>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="配置名" min-width="150">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="客户" min-width="130">
          <template #default="{ row }">{{ row.customer?.customer_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="表格 ID" min-width="200">
          <template #default="{ row }">
            <div class="mono">{{ row.app_token }} / {{ row.table_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="已同步" width="90" align="center">
          <template #default="{ row }">{{ row.synced_count }}</template>
        </el-table-column>
        <el-table-column label="上次同步" width="140">
          <template #default="{ row }">
            {{ row.last_synced_at ? formatRelative(row.last_synced_at) : '从未' }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.last_sync_error" type="danger" size="small">异常</el-tag>
            <el-tag v-else-if="row.is_active" type="success" size="small">正常</el-tag>
            <el-tag v-else type="info" size="small">停用</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="300" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" :loading="syncingId === row.id" @click="handleSync(row)">
              立即同步
            </el-button>
            <el-button type="primary" link size="small" :loading="testingId === row.id" @click="handleTest(row)">
              测试
            </el-button>
            <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !tableData.length" description="尚未配置飞书同步" />
    </el-card>

    <!-- Form Dialog -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑配置' : '添加飞书同步'" width="720px" :close-on-click-modal="false">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="130px">
        <el-form-item label="配置名" prop="name">
          <el-input v-model="form.name" placeholder="如：凯慕传媒-飞书表" />
        </el-form-item>
        <el-form-item label="关联客户" prop="customer_id">
          <el-select v-model="form.customer_id" filterable remote reserve-keyword
            placeholder="搜索客户" :remote-method="searchCustomers" :loading="customerLoading" style="width: 100%">
            <el-option v-for="c in customerOptions" :key="c.id"
              :label="`#${c.id} ${c.customer_name}`" :value="c.id" />
          </el-select>
        </el-form-item>

        <el-divider content-position="left">飞书应用凭证</el-divider>
        <el-form-item label="App ID" prop="app_id">
          <el-input v-model="form.app_id" placeholder="cli_xxxxxxxxxxxx" />
        </el-form-item>
        <el-form-item label="App Secret" prop="app_secret">
          <el-input v-model="form.app_secret" type="password" show-password
            :placeholder="editing ? '留空不修改' : 'App Secret'" />
        </el-form-item>

        <el-divider content-position="left">多维表格</el-divider>
        <el-form-item label="App Token" prop="app_token">
          <el-input v-model="form.app_token" placeholder="飞书多维表格 URL 中的 token" />
          <div class="hint">
            从飞书多维表格 URL 中复制，可以是 wiki token 或 bitable token。
          </div>
        </el-form-item>
        <el-form-item label="真实 Bitable Token">
          <el-input v-model="form.real_app_token" placeholder="留空则自动检测（点击测试连接）">
            <template #append>
              <el-tooltip content="文件上传（如二维码）必须用真实 bitable token，不能用 wiki token。点击「测试」可自动检测填入。">
                <el-icon><QuestionFilled /></el-icon>
              </el-tooltip>
            </template>
          </el-input>
          <div class="hint">
            如果表格嵌在知识库（Wiki）中，App Token 可能是 wiki token，文件上传会失败。此处填真实的 bitable token，或点「测试」自动检测。
          </div>
        </el-form-item>
        <el-form-item label="Table ID" prop="table_id">
          <el-input v-model="form.table_id" placeholder="?table=tblkc8jVTkpYHuRV 中的值" />
        </el-form-item>
        <el-form-item label="View ID">
          <el-input v-model="form.view_id" placeholder="可选" />
        </el-form-item>

        <el-form-item label="启用">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.description" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">{{ editing ? '保存' : '创建' }}</el-button>
      </template>
    </el-dialog>

    <!-- Test result -->
    <el-dialog v-model="testVisible" title="连接测试结果" width="640px">
      <el-alert v-if="testResult?.ok" type="success" :closable="false" show-icon style="margin-bottom: 12px">
        连接成功，表格共 {{ testResult.field_count }} 个字段
      </el-alert>
      <el-table v-if="testResult?.fields" :data="testResult.fields" size="small">
        <el-table-column prop="field_name" label="字段名" min-width="160" />
        <el-table-column prop="ui_type" label="类型" width="120" />
        <el-table-column prop="field_id" label="Field ID" width="140">
          <template #default="{ row }"><span class="mono">{{ row.field_id }}</span></template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, QuestionFilled } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import {
  getFeishuSyncConfigs, createFeishuSyncConfig, updateFeishuSyncConfig,
  deleteFeishuSyncConfig, testFeishuConnection, triggerFeishuSync,
} from '@/api/feishuSync'
import { getCustomers } from '@/api/customers'

dayjs.extend(relativeTime)

const loading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formRef = ref()
const testingId = ref(null)
const syncingId = ref(null)
const testVisible = ref(false)
const testResult = ref(null)
const customerOptions = ref([])
const customerLoading = ref(false)

const form = reactive({
  name: '', customer_id: null,
  app_id: '', app_secret: '',
  app_token: '', real_app_token: '', table_id: '', view_id: '',
  is_active: 1, description: '',
})

const rules = {
  name: [{ required: true, message: '请输入配置名', trigger: 'blur' }],
  customer_id: [{ required: true, message: '请选择客户', trigger: 'change' }],
  app_id: [{ required: true, message: '请输入 App ID', trigger: 'blur' }],
  app_token: [{ required: true, message: '请输入 App Token', trigger: 'blur' }],
  table_id: [{ required: true, message: '请输入 Table ID', trigger: 'blur' }],
}

function formatRelative(t) { return t ? dayjs(t).fromNow() : '-' }

async function fetchData() {
  loading.value = true
  try { tableData.value = (await getFeishuSyncConfigs()) || [] }
  catch { /* handled */ }
  finally { loading.value = false }
}

async function searchCustomers(kw) {
  customerLoading.value = true
  try {
    const params = { per_page: 30 }
    if (kw) params['filter[keyword]'] = kw
    const res = await getCustomers(params)
    customerOptions.value = res?.items || []
  } catch { /* handled */ }
  finally { customerLoading.value = false }
}

function resetForm() {
  Object.assign(form, {
    name: '', customer_id: null, app_id: '', app_secret: '',
    app_token: '', real_app_token: '', table_id: '', view_id: '', is_active: 1, description: '',
  })
}

function openCreate() { editing.value = null; resetForm(); searchCustomers(''); dialogVisible.value = true }

function openEdit(row) {
  editing.value = row
  Object.assign(form, {
    name: row.name, customer_id: row.customer_id,
    app_id: row.app_id, app_secret: '',
    app_token: row.app_token, real_app_token: row.real_app_token || '', table_id: row.table_id, view_id: row.view_id || '',
    is_active: row.is_active, description: row.description || '',
  })
  searchCustomers('')
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  if (!editing.value && !form.app_secret) { ElMessage.warning('请输入 App Secret'); return }
  submitting.value = true
  try {
    const payload = { ...form }
    if (editing.value && !payload.app_secret) delete payload.app_secret
    if (editing.value) { await updateFeishuSyncConfig(editing.value.id, payload); ElMessage.success('已保存') }
    else { await createFeishuSyncConfig(payload); ElMessage.success('已创建') }
    dialogVisible.value = false; fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除配置「${row.name}」？`, '确认', { type: 'warning' })
    await deleteFeishuSyncConfig(row.id); ElMessage.success('已删除'); fetchData()
  } catch { /* cancelled */ }
}

async function handleTest(row) {
  testingId.value = row.id
  try {
    const res = await testFeishuConnection(row.id)
    testResult.value = res; testVisible.value = true
    if (res?.real_app_token) {
      ElMessage.info(`已自动设置真实 bitable token: ${res.real_app_token}`)
      fetchData()
    }
  } catch { /* handled */ }
  finally { testingId.value = null }
}

async function handleSync(row) {
  try {
    await ElMessageBox.confirm(
      `立即同步「${row.name}」？\n将把客户的所有活跃 IP 推送到飞书多维表格。`,
      '确认同步', { type: 'warning' }
    )
  } catch { return }

  syncingId.value = row.id
  try {
    const res = await triggerFeishuSync(row.id)
    ElMessageBox.alert(
      `<div>创建 <strong style="color:#67C23A">${res?.created || 0}</strong> · ` +
      `更新 <strong style="color:#E6A23C">${res?.updated || 0}</strong> · ` +
      `未变 ${res?.unchanged || 0} · 删除 ${res?.deleted || 0}</div>` +
      (res?.errors?.length ? `<div style="margin-top:8px;color:#F56C6C;font-size:12px">${res.errors.join('<br>')}</div>` : ''),
      '同步结果',
      { dangerouslyUseHTMLString: true, type: (res?.errors?.length ? 'warning' : 'success') }
    )
    fetchData()
  } catch { /* handled */ }
  finally { syncingId.value = null }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.feishu-sync-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 12px; color: #4A5568; }
  .hint { font-size: 12px; color: #909399; margin-top: 4px; }
}
</style>
