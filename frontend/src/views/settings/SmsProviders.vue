<template>
  <div class="sms-providers-page">
    <div class="page-header">
      <h2 class="page-title">短信验证码配置</h2>
      <el-button type="primary" @click="openCreate"><el-icon><Plus /></el-icon> 添加短信服务</el-button>
    </div>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      配置短信服务用于客户注册手机号验证和IP到期提醒。目前支持阿里云短信。
    </el-alert>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="名称" min-width="140">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag size="small">{{ row.type === 'aliyun' ? '阿里云' : row.type }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="签名" width="120">
          <template #default="{ row }">{{ row.config_display?.sign_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="验证码模板" width="160">
          <template #default="{ row }"><span class="mono">{{ row.config_display?.template_code || '-' }}</span></template>
        </el-table-column>
        <el-table-column label="到期提醒模板" width="160">
          <template #default="{ row }"><span class="mono">{{ row.expiry_template_code || '-' }}</span></template>
        </el-table-column>
        <el-table-column label="AccessKey" width="140">
          <template #default="{ row }"><span class="mono">{{ row.config_display?.access_key_id || '-' }}</span></template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" @click="openTest(row)">测试验证码</el-button>
            <el-button v-if="row.expiry_template_code" type="warning" link size="small" @click="openExpiryTest(row)">测试到期提醒</el-button>
            <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Create/Edit Dialog -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑短信服务' : '添加短信服务'" width="600px" :close-on-click-modal="false">
      <el-form :model="form" ref="formRef" label-width="120px">
        <el-form-item label="名称" required><el-input v-model="form.name" placeholder="如：阿里云短信" /></el-form-item>
        <el-form-item label="服务类型" required>
          <el-select v-model="form.type" style="width:100%" disabled><el-option label="阿里云" value="aliyun" /></el-select>
        </el-form-item>
        <el-form-item label="AccessKey ID" required>
          <el-input v-model="form.config.access_key_id" :placeholder="editing ? '留空不修改' : 'LTAI5t...'" />
          <div v-if="editing" class="field-hint">当前：{{ editing.config_display?.access_key_id || '-' }}</div>
        </el-form-item>
        <el-form-item label="AccessKey Secret" required>
          <el-input v-model="form.config.access_key_secret" type="password" show-password :placeholder="editing ? '留空不修改' : '必填'" />
        </el-form-item>
        <el-form-item label="签名名称" required>
          <el-input v-model="form.config.sign_name" placeholder="如：SuniPIP" />
          <div class="field-hint">需在阿里云短信控制台审核通过</div>
        </el-form-item>
        <el-form-item label="验证码模板" required>
          <el-input v-model="form.config.template_code" placeholder="如：SMS_123456789" />
          <div class="field-hint">验证码短信模板，需包含 ${code} 变量</div>
        </el-form-item>
        <el-form-item label="到期提醒模板">
          <el-input v-model="form.expiry_template_code" placeholder="如：SMS_987654321" />
          <div class="field-hint">IP到期前1天提醒，模板需包含 ${X} 变量（到期IP数量）。客户可在面板自行开关。</div>
        </el-form-item>
        <el-form-item label="启用"><el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.description" type="textarea" :rows="2" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">{{ editing ? '保存' : '创建' }}</el-button>
      </template>
    </el-dialog>

    <!-- Test Dialog -->
    <el-dialog v-model="testVisible" title="测试验证码短信" width="400px">
      <el-form label-width="80px">
        <el-form-item label="手机号"><el-input v-model="testPhone" placeholder="输入测试手机号" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="testVisible = false">取消</el-button>
        <el-button type="primary" :loading="testLoading" @click="submitTest">发送测试</el-button>
      </template>
    </el-dialog>

    <!-- Expiry Test Dialog -->
    <el-dialog v-model="expiryTestVisible" title="测试到期提醒短信" width="420px">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        将使用到期提醒模板发送测试短信，模板变量 ${X} = IP数量
      </el-alert>
      <el-form label-width="80px">
        <el-form-item label="手机号"><el-input v-model="expiryTestPhone" placeholder="输入测试手机号" /></el-form-item>
        <el-form-item label="IP数量"><el-input-number v-model="expiryTestCount" :min="1" :max="999" style="width: 100%" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="expiryTestVisible = false">取消</el-button>
        <el-button type="primary" :loading="expiryTestLoading" @click="submitExpiryTest">发送测试</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getSmsProviders, createSmsProvider, updateSmsProvider, deleteSmsProvider, testSmsProvider, testExpirySms } from '@/api/smsProviders'

const loading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formRef = ref()
const form = reactive({ name: '', type: 'aliyun', config: { access_key_id: '', access_key_secret: '', sign_name: '', template_code: '' }, expiry_template_code: '', is_active: 1, description: '' })

async function fetchData() {
  loading.value = true
  try { tableData.value = (await getSmsProviders()) || [] } catch {} finally { loading.value = false }
}

function openCreate() {
  editing.value = null
  Object.assign(form, { name: '', type: 'aliyun', config: { access_key_id: '', access_key_secret: '', sign_name: '', template_code: '' }, expiry_template_code: '', is_active: 1, description: '' })
  dialogVisible.value = true
}

function openEdit(row) {
  editing.value = row
  Object.assign(form, { name: row.name, type: row.type, config: { access_key_id: '', access_key_secret: '', sign_name: row.config_display?.sign_name || '', template_code: row.config_display?.template_code || '' }, expiry_template_code: row.expiry_template_code || '', is_active: row.is_active, description: row.description || '' })
  dialogVisible.value = true
}

async function handleSubmit() {
  submitting.value = true
  try {
    const payload = JSON.parse(JSON.stringify(form))
    if (editing.value && !payload.config.access_key_id) delete payload.config.access_key_id
    if (editing.value && !payload.config.access_key_secret) delete payload.config.access_key_secret
    if (editing.value) { await updateSmsProvider(editing.value.id, payload); ElMessage.success('已保存') }
    else { await createSmsProvider(payload); ElMessage.success('已创建') }
    dialogVisible.value = false; fetchData()
  } catch {} finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除短信服务「${row.name}」？`, '确认', { type: 'warning' })
    await deleteSmsProvider(row.id); ElMessage.success('已删除'); fetchData()
  } catch {}
}

const testVisible = ref(false)
const testLoading = ref(false)
const testPhone = ref('')
const testTarget = ref(null)

function openTest(row) { testTarget.value = row; testPhone.value = ''; testVisible.value = true }

async function submitTest() {
  if (!testPhone.value) { ElMessage.warning('请输入手机号'); return }
  testLoading.value = true
  try {
    await testSmsProvider(testTarget.value.id, { phone: testPhone.value })
    ElMessage.success('测试短信已发送'); testVisible.value = false
  } catch {} finally { testLoading.value = false }
}

// Expiry test
const expiryTestVisible = ref(false)
const expiryTestLoading = ref(false)
const expiryTestPhone = ref('')
const expiryTestCount = ref(3)
const expiryTestTarget = ref(null)

function openExpiryTest(row) { expiryTestTarget.value = row; expiryTestPhone.value = ''; expiryTestCount.value = 3; expiryTestVisible.value = true }

async function submitExpiryTest() {
  if (!expiryTestPhone.value) { ElMessage.warning('请输入手机号'); return }
  expiryTestLoading.value = true
  try {
    await testExpirySms(expiryTestTarget.value.id, { phone: expiryTestPhone.value, count: expiryTestCount.value })
    ElMessage.success('到期提醒测试短信已发送'); expiryTestVisible.value = false
  } catch {} finally { expiryTestLoading.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.sms-providers-page {
  .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; color: #4A5568; }
  .field-hint { font-size: 12px; color: #909399; margin-top: 4px; }
}
</style>
