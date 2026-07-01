<template>
  <div class="verification-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">实名认证管理</h2>
        <p class="page-desc">管理实名认证接口，支持多个认证服务商，可选择启用</p>
      </div>
      <el-button type="primary" @click="openAdd"><el-icon><Plus /></el-icon> 添加接口</el-button>
    </div>

    <!-- 全局设置 -->
    <el-card class="global-settings-card">
      <template #header>
        <span style="font-weight:600">全局设置</span>
      </template>
      <el-form :inline="true" label-position="left">
        <el-form-item label="强制实名认证">
          <el-switch v-model="globalSettings['verification.required']" @change="saveGlobalSettings" />
        </el-form-item>
        <el-form-item label="允许个人认证">
          <el-switch v-model="globalSettings['verification.allow_personal']" @change="saveGlobalSettings" />
        </el-form-item>
        <el-form-item label="允许企业认证">
          <el-switch v-model="globalSettings['verification.allow_enterprise']" @change="saveGlobalSettings" />
        </el-form-item>
      </el-form>
      <div class="settings-hint">开启「强制实名认证」后，客户下单前必须完成认证</div>
    </el-card>

    <!-- Provider Cards -->
    <div v-loading="loading" class="provider-grid">
      <el-card v-for="p in providers" :key="p.id" class="provider-card" :class="{ inactive: !p.is_active }">
        <div class="card-head">
          <div class="card-title-row">
            <span class="driver-badge" :class="p.driver">{{ driverLabel(p.driver) }}</span>
            <span class="card-name">{{ p.name }}</span>
          </div>
          <div class="card-actions">
            <el-tooltip content="启用/停用" placement="top">
              <el-switch v-model="p.is_active" size="small" @change="toggleProvider(p)" />
            </el-tooltip>
            <el-button link type="primary" @click="openEdit(p)"><el-icon><Edit /></el-icon></el-button>
            <el-popconfirm title="确定删除此接口？" @confirm="remove(p.id)">
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
            </span>
          </div>
          <div class="info-row" v-if="p.description">
            <span class="info-label">说明</span>
            <span class="info-value">{{ p.description }}</span>
          </div>
          <div class="info-row" v-for="(val, key) in p.credentials_masked" :key="key">
            <span class="info-label">{{ credLabel(key) }}</span>
            <span class="info-value mono">{{ val || '—' }}</span>
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
        <el-empty description="暂无认证接口，点击右上角添加" />
      </div>
    </div>

    <!-- 添加/编辑对话框 -->
    <el-dialog v-model="dialogVisible" :title="editId ? '编辑接口' : '添加接口'" width="520px" destroy-on-close>
      <el-form :model="form" :rules="rules" ref="formRef" label-width="100px" label-position="top">
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="接口名称" prop="name">
              <el-input v-model="form.name" placeholder="如：阿里云主账号" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="服务商" prop="driver">
              <el-select v-model="form.driver" :disabled="!!editId" @change="onDriverChange" style="width:100%">
                <el-option v-for="(opt, key) in allDriverOptions" :key="key" :label="opt.label" :value="key" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="说明">
          <el-input v-model="form.description" placeholder="备注说明（可选）" />
        </el-form-item>

        <el-form-item label="启用">
          <el-switch v-model="form.is_active" />
        </el-form-item>

        <el-divider content-position="left">认证凭据</el-divider>

        <el-alert v-if="form.driver === 'aliyun'" type="info" :closable="false" style="margin-bottom: 16px" show-icon>
          使用阿里云「金融级实人认证」身份二要素核验接口（旧版）。
          请在<el-link type="primary" href="https://cloudauth.console.aliyun.com" target="_blank" style="margin: 0 4px">阿里云控制台</el-link>开通服务。
        </el-alert>

        <el-alert v-if="form.driver === 'tencent_face'" type="info" :closable="false" style="margin-bottom: 16px" show-icon>
          使用腾讯云「人脸核身」微信小程序活体验证，用于个人实名认证。
          请在<el-link type="primary" href="https://console.cloud.tencent.com/faceid" target="_blank" style="margin: 0 4px">腾讯云控制台</el-link>开通服务并创建 RuleId。
        </el-alert>

        <el-alert v-if="form.driver === 'tencent_ocr'" type="info" :closable="false" style="margin-bottom: 16px" show-icon>
          使用腾讯云「文字识别」营业执照OCR + 权威核验，用于企业认证。
          请在<el-link type="primary" href="https://console.cloud.tencent.com/ocr" target="_blank" style="margin: 0 4px">腾讯云控制台</el-link>开通服务。
        </el-alert>

        <template v-if="form.driver === 'aliyun'">
          <el-form-item label="AccessKey ID">
            <el-input v-model="form.credentials.access_key_id" placeholder="LTAI5t..." />
          </el-form-item>
          <el-form-item label="AccessKey Secret">
            <el-input v-model="form.credentials.access_key_secret" type="password" show-password
              :placeholder="editId ? '留空不修改' : '请输入'" />
          </el-form-item>
        </template>

        <template v-else-if="form.driver === 'tencent_face'">
          <el-form-item label="SecretId">
            <el-input v-model="form.credentials.secret_id" placeholder="AKIDz8krbsJ5..." />
          </el-form-item>
          <el-form-item label="SecretKey">
            <el-input v-model="form.credentials.secret_key" type="password" show-password
              :placeholder="editId ? '留空不修改' : '请输入'" />
          </el-form-item>
          <el-form-item label="RuleId">
            <el-input v-model="form.credentials.rule_id" placeholder="人脸核身业务规则 ID" />
          </el-form-item>
        </template>

        <template v-else-if="form.driver === 'tencent_ocr'">
          <el-form-item label="SecretId">
            <el-input v-model="form.credentials.secret_id" placeholder="AKIDz8krbsJ5..." />
          </el-form-item>
          <el-form-item label="SecretKey">
            <el-input v-model="form.credentials.secret_key" type="password" show-password
              :placeholder="editId ? '留空不修改' : '请输入'" />
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
import { Plus, Edit, Delete, Connection } from '@element-plus/icons-vue'
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

const globalSettings = reactive({
  'verification.required': false,
  'verification.allow_personal': true,
  'verification.allow_enterprise': true,
})

const form = reactive({
  name: '',
  driver: 'aliyun',
  credentials: {},
  is_active: true,
  description: '',
})

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  driver: [{ required: true, message: '请选择服务商', trigger: 'change' }],
}

async function fetchData() {
  loading.value = true
  try {
    const res = await request.get('/verification-providers')
    providers.value = res?.providers || []
    allDriverOptions.value = res?.driver_options || {}
    if (res?.global_settings) {
      Object.assign(globalSettings, res.global_settings)
    }
  } catch {} finally { loading.value = false }
}

function driverLabel(driver) {
  return allDriverOptions.value[driver]?.label || driver || '—'
}

function credLabel(key) {
  const map = {
    access_key_id: 'AccessKey ID',
    access_key_secret: 'AccessKey Secret',
    secret_id: 'SecretId',
    secret_key: 'SecretKey',
    rule_id: 'RuleId',
  }
  return map[key] || key
}

function onDriverChange() {
  form.credentials = {}
}

function openAdd() {
  editId.value = null
  form.name = ''
  form.driver = 'tencent_face'
  form.credentials = {}
  form.is_active = true
  form.description = ''
  dialogVisible.value = true
}

function openEdit(p) {
  editId.value = p.id
  form.name = p.name
  form.driver = p.driver
  form.credentials = { ...(p.credentials_masked || {}) }
  // Clear masked values so user can re-enter
  Object.keys(form.credentials).forEach(k => {
    if (form.credentials[k]?.includes('***')) form.credentials[k] = ''
  })
  form.is_active = p.is_active
  form.description = p.description || ''
  dialogVisible.value = true
}

async function saveProvider() {
  try { await formRef.value?.validate() } catch { return }
  saving.value = true
  try {
    const payload = {
      name: form.name,
      driver: form.driver,
      credentials: form.credentials,
      is_active: form.is_active,
      description: form.description,
    }
    if (editId.value) {
      await request.put(`/verification-providers/${editId.value}`, payload)
    } else {
      await request.post('/verification-providers', payload)
    }
    ElMessage.success(editId.value ? '更新成功' : '创建成功')
    dialogVisible.value = false
    fetchData()
  } catch {} finally { saving.value = false }
}

async function remove(id) {
  try {
    await request.delete(`/verification-providers/${id}`)
    ElMessage.success('已删除')
    fetchData()
  } catch {}
}

async function toggleProvider(p) {
  try {
    await request.post(`/verification-providers/${p.id}/toggle`)
  } catch { p.is_active = !p.is_active }
}

async function testConn(p) {
  testing.value = p.id
  try {
    const res = await request.post(`/verification-providers/${p.id}/test`)
    ElMessage.success(res?.message || '连接正常')
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '测试失败')
  } finally { testing.value = null }
}

async function saveGlobalSettings() {
  try {
    await request.put('/verification-providers/global-settings', { ...globalSettings })
    ElMessage.success('全局设置已保存')
  } catch {}
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.verification-page {
  .page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
  }
  .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  .page-desc { margin: 4px 0 0; font-size: 13px; color: #909399; }

  .global-settings-card {
    margin-bottom: 20px;
    :deep(.el-card__body) { padding-bottom: 8px; }
    .settings-hint { font-size: 12px; color: #909399; margin-top: -4px; }
  }

  .provider-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
    gap: 16px;
  }

  .provider-card {
    transition: box-shadow .2s, opacity .2s;
    &.inactive { opacity: 0.6; }
    &:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
  }

  .card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
  }
  .card-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .card-name { font-size: 15px; font-weight: 600; color: #303133; }
  .card-actions { display: flex; align-items: center; gap: 8px; }

  .driver-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    color: #fff;
    &.aliyun { background: #FF6A00; }
    &.tencent { background: #006EFF; }
    &.tencent_face { background: #006EFF; }
    &.tencent_ocr { background: #00A4FF; }
  }

  .card-body {
    border-top: 1px solid #EBEEF5;
    padding-top: 12px;
  }
  .info-row {
    display: flex;
    align-items: baseline;
    margin-bottom: 8px;
    font-size: 13px;
  }
  .info-label {
    width: 110px;
    flex-shrink: 0;
    color: #909399;
  }
  .info-value {
    color: #606266;
    &.mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; }
  }

  .card-foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #EBEEF5;
  }
  .updated { font-size: 12px; color: #C0C4CC; }

  .empty-state { grid-column: 1 / -1; }
}

@media (max-width: 768px) {
  .verification-page {
    .page-header { flex-direction: column; gap: 12px; }
    .provider-grid { grid-template-columns: 1fr; }
    .global-settings-card :deep(.el-form--inline .el-form-item) {
      display: block;
      margin-right: 0;
      margin-bottom: 8px;
    }
  }
}
</style>
