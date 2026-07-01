<template>
  <div class="payment-gateways-page">
    <div class="page-header">
      <h2 class="page-title">支付网关配置</h2>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon> 添加支付网关
      </el-button>
    </div>

    <!-- 域名配置 -->
    <el-card style="margin-bottom: 16px">
      <template #header>
        <span style="font-weight: 600">支付域名配置</span>
      </template>
      <el-form :inline="false" label-width="160px" style="max-width: 700px">
        <el-form-item label="支付回调域名">
          <el-input v-model="domainForm.callback_domain" placeholder="如 https://sunip-pay.sunip.cc" clearable>
            <template #prepend>notify_url</template>
          </el-input>
          <div class="field-hint">第三方支付网关异步通知的目标域名，需解析到 API 服务器。留空则使用 API 默认域名。</div>
        </el-form-item>
        <el-form-item label="支付完成跳转域名">
          <el-input v-model="domainForm.return_domain" placeholder="如 https://user.sunipip.com" clearable>
            <template #prepend>return_url</template>
          </el-input>
          <div class="field-hint">支付完成后用户浏览器跳转到的客户面板地址。留空则使用环境变量默认值。</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="domainSaving" @click="saveDomainSettings">保存域名配置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      <template #title>
        配置客户自助面板的在线充值渠道。支持<strong>易支付 (EPay)</strong> 聚合支付和<strong>支付宝官方</strong>直连。回调地址根据上方域名配置自动生成。
      </template>
    </el-alert>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="名称" min-width="160">
          <template #default="{ row }">
            <strong>{{ row.name }}</strong>
            <div v-if="row.description" style="font-size: 11px; color: #909399; margin-top: 2px">
              {{ row.description }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="120">
          <template #default="{ row }">
            <el-tag size="small" :type="typeTag(row.type)">{{ typeLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="标识" width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono">{{ row.type === 'alipay' ? (row.config?.app_id || '-') : (row.config?.pid || '-') }}</span>
            <div v-if="row.type !== 'alipay' && row.config?.api_url" class="mono" style="font-size:11px;color:#909399;margin-top:2px">
              {{ row.config.api_url }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="支持方式" min-width="180">
          <template #default="{ row }">
            <el-tag
              v-for="m in (row.config?.methods || [])"
              :key="m"
              size="small"
              effect="plain"
              style="margin-right: 4px"
            >
              {{ methodLabel(m) }}
            </el-tag>
            <span v-if="!(row.config?.methods || []).length" style="color: #C0C4CC">全部</span>
          </template>
        </el-table-column>
        <el-table-column label="排序" width="70" align="center">
          <template #default="{ row }">{{ row.sort }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">
              {{ row.is_active ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" @click="handleTestSign(row)">
              测试签名
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
      <el-empty v-if="!loading && !tableData.length" description="尚未配置支付网关" />
    </el-card>

    <!-- Form Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑支付网关' : '添加支付网关'"
      width="680px"
      :close-on-click-modal="false"
    >
      <el-form :model="form" :rules="rules" ref="formRef" label-width="120px">
        <el-form-item label="网关类型" prop="type">
          <el-select v-model="form.type" style="width: 100%" :disabled="!!editing" @change="onTypeChange">
            <el-option label="易支付 (EPay)" value="epay" />
            <el-option label="支付宝官方" value="alipay" />
            <el-option label="微信官方（未开发）" value="wechat" disabled />
          </el-select>
        </el-form-item>

        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：易支付-主账号" />
        </el-form-item>

        <!-- EPay Config -->
        <template v-if="form.type === 'epay'">
          <el-form-item label="商户 ID (PID)" prop="config.pid">
            <el-input v-model="form.config.pid" placeholder="网关后台获取" />
          </el-form-item>
          <el-form-item label="商户 KEY" prop="config.key">
            <el-input v-model="form.config.key" type="password" show-password
              :placeholder="editing ? '留空表示不修改' : '网关后台获取的密钥'" />
            <div class="field-hint">MD5 签名密钥</div>
          </el-form-item>
          <el-form-item label="对接地址" prop="config.api_url">
            <el-input v-model="form.config.api_url" placeholder="https://payments.nodelay.cloud" />
            <div class="field-hint">网关主域名（不含 /submit.php）</div>
          </el-form-item>
          <el-form-item label="支持的支付方式">
            <el-checkbox-group v-model="form.config.methods">
              <el-checkbox value="alipay">支付宝</el-checkbox>
              <el-checkbox value="wxpay">微信</el-checkbox>
              <el-checkbox value="qqpay">QQ 钱包</el-checkbox>
              <el-checkbox value="bank">网银</el-checkbox>
              <el-checkbox value="jdpay">京东</el-checkbox>
              <el-checkbox value="usdt">USDT</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
        </template>

        <!-- Alipay Config -->
        <template v-if="form.type === 'alipay'">
          <el-form-item label="App ID" required>
            <el-input v-model="form.config.app_id" placeholder="支付宝开放平台的应用 APPID" />
          </el-form-item>
          <el-form-item label="应用私钥" required>
            <el-input v-model="form.config.app_private_key" type="textarea" :rows="4"
              :placeholder="editing ? '留空表示不修改' : '应用私钥（RSA2, 不含 BEGIN/END 头尾）'" />
            <div class="field-hint">在支付宝开放平台生成的 RSA2 私钥，用于签名请求</div>
          </el-form-item>
          <el-form-item label="支付宝公钥" required>
            <el-input v-model="form.config.alipay_public_key" type="textarea" :rows="4"
              :placeholder="editing ? '留空表示不修改' : '支付宝公钥（不含 BEGIN/END 头尾）'" />
            <div class="field-hint">从支付宝开放平台下载，用于验证回调签名</div>
          </el-form-item>
          <el-form-item label="支持的支付方式">
            <el-checkbox-group v-model="form.config.methods">
              <el-checkbox value="alipay">支付宝</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
        </template>

        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="999" />
          <span class="field-hint" style="margin-left: 8px">数字越小越靠前</span>
        </el-form-item>

        <el-form-item label="启用状态">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>

        <el-form-item label="备注">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>

        <el-alert v-if="editing" type="info" :closable="false" show-icon style="margin-top: 8px">
          <template #title>
            <div>回调通知地址（只读，已自动生成）：</div>
            <code class="mono" style="word-break: break-all; font-size: 11px">
              {{ notifyUrlFor(editing) }}
            </code>
            <div style="margin-top: 4px">
              请在网关后台填入上面的「异步通知地址」
            </div>
          </template>
        </el-alert>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          {{ editing ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Test Sign Result Dialog -->
    <el-dialog v-model="testDialogVisible" title="签名测试结果" width="680px">
      <div v-if="testResult">
        <el-alert type="success" :closable="false" show-icon style="margin-bottom: 12px">
          使用当前配置生成的签名如下，可复制到网关后台手动核对是否一致
        </el-alert>
        <el-descriptions :column="1" border>
          <el-descriptions-item label="商户 ID">
            <span class="mono">{{ testResult.params.pid }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="订单号">
            <span class="mono">{{ testResult.params.out_trade_no }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="金额">¥{{ testResult.params.money }}</el-descriptions-item>
          <el-descriptions-item label="商品名">{{ testResult.params.name }}</el-descriptions-item>
          <el-descriptions-item label="异步通知地址">
            <span class="mono" style="word-break: break-all; font-size: 11px">
              {{ testResult.params.notify_url }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="同步返回地址">
            <span class="mono" style="word-break: break-all; font-size: 11px">
              {{ testResult.params.return_url }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="签名 (MD5)">
            <span class="mono" style="color: #E8913A; font-weight: 600">{{ testResult.sign }}</span>
            <el-button link type="primary" size="small" @click="copyText(testResult.sign)" style="margin-left: 8px">
              复制
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item label="提交 URL">
            <span class="mono" style="word-break: break-all; font-size: 11px">
              {{ testResult.api_url }}
            </span>
          </el-descriptions-item>
        </el-descriptions>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getPaymentGateways, createPaymentGateway, updatePaymentGateway, deletePaymentGateway,
  testPaymentGatewaySign, getDomainSettings, updateDomainSettings,
} from '@/api/paymentGateways'

const loading = ref(false)
const tableData = ref([])

// 域名配置
const domainForm = reactive({ callback_domain: '', return_domain: '' })
const domainSaving = ref(false)

const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formRef = ref()

const form = reactive({
  name: '',
  type: 'epay',
  config: {
    pid: '',
    key: '',
    api_url: '',
    methods: [],
  },
  is_active: 1,
  sort: 0,
  description: '',
})

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择类型', trigger: 'change' }],
}

function typeTag(t) { return { epay: 'success', wechat: 'primary', alipay: 'warning' }[t] || 'info' }
function typeLabel(t) { return { epay: '易支付', wechat: '微信', alipay: '支付宝' }[t] || t }
function methodLabel(m) {
  return { alipay: '支付宝', wxpay: '微信', qqpay: 'QQ', bank: '网银', jdpay: '京东', usdt: 'USDT' }[m] || m
}
function notifyUrlFor(row) {
  if (!row?.id) return ''
  const type = row.type === 'alipay' ? 'alipay' : 'epay'
  const base = domainForm.callback_domain || location.origin
  return `${base}/api/v1/payment/${type}/notify/${row.id}`
}

function onTypeChange(type) {
  // Reset config when switching type
  if (type === 'alipay') {
    form.config = { app_id: '', app_private_key: '', alipay_public_key: '', methods: ['alipay'] }
  } else {
    form.config = { pid: '', key: '', api_url: '', methods: ['alipay', 'wxpay'] }
  }
}

async function fetchData() {
  loading.value = true
  try {
    const res = await getPaymentGateways()
    tableData.value = Array.isArray(res) ? res : []
  } catch { /* handled */ }
  finally { loading.value = false }
}

function resetForm() {
  form.name = ''
  form.type = 'epay'
  form.config.pid = ''
  form.config.key = ''
  form.config.api_url = ''
  form.config.methods = ['alipay', 'wxpay']
  form.is_active = 1
  form.sort = 0
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
  form.type = row.type
  if (row.type === 'alipay') {
    form.config = {
      app_id: row.config?.app_id || '',
      app_private_key: '', // 留空 = 不修改
      alipay_public_key: '', // 留空 = 不修改
      methods: row.config?.methods || ['alipay'],
    }
  } else {
    form.config = {
      pid: row.config?.pid || '',
      key: '', // 留空 = 不修改
      api_url: row.config?.api_url || '',
      methods: row.config?.methods || [],
    }
  }
  form.is_active = row.is_active
  form.sort = row.sort
  form.description = row.description || ''
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    const payload = JSON.parse(JSON.stringify(form))
    if (editing.value) {
      // 编辑时空值的密钥字段不提交（保留旧值）
      if (payload.type === 'alipay') {
        if (!payload.config.app_private_key) delete payload.config.app_private_key
        if (!payload.config.alipay_public_key) delete payload.config.alipay_public_key
      } else {
        if (!payload.config.key) delete payload.config.key
      }
      await updatePaymentGateway(editing.value.id, payload)
      ElMessage.success('已保存')
    } else {
      // 新建时必须填写密钥
      if (payload.type === 'alipay') {
        if (!payload.config.app_private_key || !payload.config.alipay_public_key) {
          ElMessage.warning('请填写应用私钥和支付宝公钥')
          submitting.value = false
          return
        }
      } else if (!payload.config.key) {
        ElMessage.warning('请输入商户 KEY')
        submitting.value = false
        return
      }
      await createPaymentGateway(payload)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除支付网关「${row.name}」？`, '确认', { type: 'warning' })
    await deletePaymentGateway(row.id)
    ElMessage.success('已删除')
    fetchData()
  } catch { /* cancelled or handled */ }
}

// 测试签名
const testDialogVisible = ref(false)
const testResult = ref(null)

async function handleTestSign(row) {
  try {
    const res = await testPaymentGatewaySign(row.id)
    testResult.value = res
    testDialogVisible.value = true
  } catch { /* handled */ }
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败')
  }
}

async function fetchDomainSettings() {
  try {
    const res = await getDomainSettings()
    domainForm.callback_domain = res.callback_domain || ''
    domainForm.return_domain = res.return_domain || ''
  } catch { /* handled */ }
}

async function saveDomainSettings() {
  domainSaving.value = true
  try {
    await updateDomainSettings({
      callback_domain: domainForm.callback_domain,
      return_domain: domainForm.return_domain,
    })
    ElMessage.success('域名配置已保存')
  } catch { /* handled */ }
  finally { domainSaving.value = false }
}

onMounted(() => {
  fetchData()
  fetchDomainSettings()
})
</script>

<style lang="scss" scoped>
.payment-gateways-page {
  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; color: #4A5568; }
  .field-hint { font-size: 12px; color: #909399; margin-top: 4px; }
}
</style>
