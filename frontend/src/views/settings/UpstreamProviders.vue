<template>
  <div class="upstream-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">API 管理</h2>
        <p class="page-desc">管理上游代理服务商 API 插件，每个插件有独立的回调地址</p>
      </div>
      <el-button type="primary" @click="openAdd"><el-icon><Plus /></el-icon> 添加插件</el-button>
    </div>

    <div v-loading="loading" class="provider-grid">
      <el-card v-for="p in providers" :key="p.id" class="provider-card" :class="{ inactive: !p.is_active }">
        <div class="card-head">
          <div class="card-title-row">
            <span class="driver-badge" :class="p.driver">{{ driverLabel(p.driver) }}</span>
            <span class="card-name">{{ p.name }}</span>
            <span v-if="p.remark" class="card-remark">{{ p.remark }}</span>
          </div>
          <div class="card-actions">
            <el-tooltip content="总开关" placement="top">
              <el-switch v-model="p.is_active" size="small" @change="toggleField(p, 'is_active')" />
            </el-tooltip>
            <el-tooltip content="客户自助下单" placement="top">
              <el-switch v-model="p.public_sale" size="small" active-color="#67C23A" :disabled="!p.is_active" @change="toggleField(p, 'public_sale')" />
            </el-tooltip>
            <el-button link type="primary" @click="openEdit(p)"><el-icon><Edit /></el-icon></el-button>
            <el-popconfirm title="确定删除此插件？" @confirm="remove(p.id)">
              <template #reference>
                <el-button link type="danger"><el-icon><Delete /></el-icon></el-button>
              </template>
            </el-popconfirm>
          </div>
        </div>

        <div class="card-body">
          <div class="info-row">
            <span class="info-label">状态</span>
            <span class="info-value">
              <el-tag :type="p.is_active ? 'success' : 'info'" size="small" round>{{ p.is_active ? '已启用' : '已停用' }}</el-tag>
              <el-tag v-if="p.is_active" :type="p.public_sale ? 'success' : 'warning'" size="small" round style="margin-left:6px">{{ p.public_sale ? '客户可下单' : '仅后台' }}</el-tag>
            </span>
          </div>
          <div class="info-row">
            <span class="info-label">API 地址</span>
            <span class="info-value url">{{ p.api_url }}</span>
          </div>
          <div class="info-row" v-for="(val, key) in p.credentials" :key="key">
            <span class="info-label">{{ credLabel(key) }}</span>
            <span class="info-value mono">{{ val || '—' }}</span>
          </div>
        </div>

        <div class="callback-row">
          <span class="info-label">回调地址</span>
          <div class="callback-val">
            <span class="info-value url">{{ p.callback_url }}</span>
            <el-button link size="small" @click="copy(p.callback_url)"><el-icon><CopyDocument /></el-icon></el-button>
          </div>
        </div>

        <div class="card-foot">
          <el-button size="small" :loading="testing === p.id" @click="testConn(p)">
            <el-icon><Connection /></el-icon> 测试连接
          </el-button>
          <span class="updated">{{ p.updated_at ? `更新于 ${dayjs(p.updated_at).format('MM-DD HH:mm')}` : '' }}</span>
        </div>
      </el-card>

      <div v-if="!loading && !providers.length" class="empty-state">
        <el-empty description="暂无 API 插件，点击右上角添加" />
      </div>
    </div>

    <!-- 添加/编辑对话框 -->
    <el-dialog v-model="dialogVisible" :title="editId ? '编辑插件' : '添加插件'" width="520px" destroy-on-close>
      <el-form :model="form" :rules="rules" ref="formRef" label-width="100px" label-position="top">
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="插件名称" prop="name">
              <el-input v-model="form.name" placeholder="如：Spark 代理" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="插件类型" prop="driver">
              <el-select v-model="form.driver" :disabled="!!editId" @change="onDriverChange" style="width:100%">
                <el-option v-for="(opt, key) in allDriverOptions" :key="key" :label="opt.label" :value="key" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="标识 (slug)" prop="slug" v-if="!editId">
          <el-input v-model="form.slug" placeholder="如：ipipv（小写英文，全局唯一）" />
        </el-form-item>

        <el-form-item label="备注/别名">
          <el-input v-model="form.remark" placeholder="如：资源池1（销售创建订单时显示此名称）" />
        </el-form-item>

        <el-form-item label="API 地址" prop="api_url">
          <el-input v-model="form.api_url" placeholder="https://api.example.com" />
        </el-form-item>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="启用">
              <el-switch v-model="form.is_active" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="允许客户自助下单">
              <el-switch v-model="form.public_sale" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-divider content-position="left">认证凭据</el-divider>

        <template v-if="form.driver === 'spark'">
          <el-form-item label="Supplier No">
            <el-input v-model="form.credentials.supplier_no" placeholder="供应商编号" />
          </el-form-item>
          <el-form-item label="AES Key">
            <el-input v-model="form.credentials.aes_key" placeholder="AES 加密密钥" show-password />
          </el-form-item>
          <el-form-item label="Version">
            <el-input v-model="form.credentials.version" placeholder="2.0" />
          </el-form-item>
        </template>

        <template v-else-if="form.driver === 'ipipv'">
          <el-form-item label="App Key">
            <el-input v-model="form.credentials.app_key" placeholder="appKey" />
          </el-form-item>
          <el-form-item label="App Secret">
            <el-input v-model="form.credentials.app_secret" placeholder="appSecret (即 AES 密钥)" show-password />
          </el-form-item>
        </template>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveProvider">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Edit, Delete, CopyDocument, Connection } from '@element-plus/icons-vue'
import request from '@/utils/request'
import dayjs from 'dayjs'

const loading = ref(false)
const saving = ref(false)
const testing = ref(null)
const providers = ref([])
const allDriverOptions = ref({})
const dialogVisible = ref(false)
const editId = ref(null)
const formRef = ref()

const form = reactive({
  name: '',
  remark: '',
  slug: '',
  driver: 'ipipv',
  api_url: '',
  credentials: {},
  is_active: true,
  public_sale: false,
})

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  driver: [{ required: true, message: '请选择类型', trigger: 'change' }],
  slug: [
    { required: true, message: '请输入标识', trigger: 'blur' },
    { pattern: /^[a-z0-9_-]+$/, message: '仅限小写字母、数字、下划线', trigger: 'blur' },
  ],
  api_url: [{ required: true, message: '请输入 API 地址', trigger: 'blur' }],
}

async function fetchData() {
  loading.value = true
  try {
    const res = await request.get('/upstream-providers')
    providers.value = res?.providers || res || []
    allDriverOptions.value = res?.driver_options || {}
  } catch {} finally { loading.value = false }
}

function driverLabel(driver) {
  return allDriverOptions.value[driver]?.label || driver?.toUpperCase() || '—'
}

function credLabel(key) {
  const map = {
    supplier_no: 'Supplier No',
    aes_key: 'AES Key',
    version: 'Version',
    app_key: 'App Key',
    app_secret: 'App Secret',
  }
  return map[key] || key
}

function onDriverChange(driver) {
  if (driver === 'spark') {
    form.credentials = { supplier_no: '', aes_key: '', version: '2.0' }
    form.api_url = form.api_url || 'https://oapi.sparkproxy.com/v2/open/api'
  } else if (driver === 'ipipv') {
    form.credentials = { app_key: '', app_secret: '' }
    form.api_url = form.api_url || 'https://api.ipipv.com'
  }
  if (!editId.value) {
    form.slug = form.slug || driver
  }
}

function openAdd() {
  editId.value = null
  Object.assign(form, { name: '', remark: '', slug: '', driver: 'ipipv', api_url: 'https://api.ipipv.com', credentials: { app_key: '', app_secret: '' }, is_active: true, public_sale: false })
  dialogVisible.value = true
}

function openEdit(p) {
  editId.value = p.id
  Object.assign(form, {
    name: p.name,
    remark: p.remark || '',
    slug: p.slug,
    driver: p.driver,
    api_url: p.api_url,
    credentials: { ...p.credentials },
    is_active: p.is_active,
    public_sale: !!p.public_sale,
  })
  dialogVisible.value = true
}

async function saveProvider() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    if (editId.value) {
      await request.put(`/upstream-providers/${editId.value}`, form)
      ElMessage.success('已更新')
    } else {
      await request.post('/upstream-providers', form)
      ElMessage.success('已添加')
    }
    dialogVisible.value = false
    fetchData()
  } catch {} finally { saving.value = false }
}

async function toggleField(p, field) {
  try {
    const payload = { [field]: p[field] }
    if (field === 'is_active' && !p[field]) {
      payload.public_sale = false
    }
    await request.put(`/upstream-providers/${p.id}`, payload)
    if (field === 'is_active') {
      ElMessage.success(p.is_active ? '已启用' : '已禁用')
      if (!p.is_active) p.public_sale = false
    } else {
      ElMessage.success(p.public_sale ? '已开启客户下单' : '已关闭客户下单')
    }
  } catch { p[field] = !p[field] }
}

async function remove(id) {
  try {
    await request.delete(`/upstream-providers/${id}`)
    ElMessage.success('已删除')
    fetchData()
  } catch {}
}

async function testConn(p) {
  testing.value = p.id
  try {
    const res = await request.post(`/upstream-providers/${p.id}/test`)
    const info = res || {}
    const detail = info.balance != null ? `余额: ${info.balance}` : (info.app_name || '成功')
    ElMessage.success(`连接成功 — ${detail}`)
  } catch {} finally { testing.value = null }
}

function copy(text) {
  navigator.clipboard.writeText(text)
  ElMessage.success('已复制')
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.upstream-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
}

.provider-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
  gap: 16px;
}

.provider-card {
  border-radius: 12px;
  transition: box-shadow 0.2s;
  &:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
  &.inactive { opacity: 0.55; }

  :deep(.el-card__body) { padding: 0; }

  .card-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px 12px;
    border-bottom: 1px solid #F2F6FC;
  }
  .card-title-row { display: flex; align-items: center; gap: 10px; }
  .card-name { font-size: 16px; font-weight: 600; color: #303133; }
  .card-remark { font-size: 12px; color: #909399; margin-left: 8px; }
  .card-actions { display: flex; align-items: center; gap: 6px; }

  .driver-badge {
    display: inline-block; padding: 2px 10px; border-radius: 10px;
    font-size: 12px; font-weight: 700; letter-spacing: 0.5px;
    &.spark { background: #FEF0E0; color: #E6832A; }
    &.ipipv { background: #E8F4FD; color: #2B85D4; }
  }

  .card-body { padding: 14px 20px 10px; }

  .info-row {
    display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px;
    .info-label { font-size: 12px; color: #909399; min-width: 72px; flex-shrink: 0; line-height: 20px; }
    .info-value {
      font-size: 13px; color: #303133; word-break: break-all; line-height: 20px;
      &.url { color: #409EFF; }
      &.mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; color: #606266; }
    }
  }

  .callback-row {
    display: flex; align-items: flex-start; gap: 8px;
    background: #F8FAFF; padding: 10px 20px 12px;
    .info-label { font-size: 12px; color: #909399; min-width: 72px; flex-shrink: 0; line-height: 20px; }
    .callback-val { display: flex; align-items: center; gap: 4px; flex: 1; min-width: 0; }
    .info-value {
      font-size: 13px; color: #409EFF; line-height: 20px;
      flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; word-break: break-all;
    }
  }

  .card-foot {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 20px; border-top: 1px solid #F2F6FC;
    .updated { font-size: 12px; color: #C0C4CC; }
  }
}

.empty-state { grid-column: 1 / -1; padding: 60px 0; }

@media (max-width: 768px) {
  .provider-grid { grid-template-columns: 1fr; }
}
</style>
