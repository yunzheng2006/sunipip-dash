<template>
  <div class="webhook-page">
    <div class="page-header">
      <h2 class="page-title">Webhook 通知</h2>
      <el-button type="primary" @click="openCreate">
        <el-icon><Plus /></el-icon> 添加 Webhook
      </el-button>
    </div>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      <template #title>
        配置 Webhook 将系统事件推送到企业微信群机器人 / 钉钉 / 自定义端点。
        <el-link type="primary" href="https://developer.work.weixin.qq.com/document/path/91770" target="_blank">企业微信群机器人文档 ↗</el-link>
      </template>
    </el-alert>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="名称" min-width="150">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="类型" width="120">
          <template #default="{ row }">
            <el-tag size="small" :type="typeTag(row.type)">{{ typeLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Webhook 地址" min-width="280" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono">{{ maskUrl(row.webhook_url) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="订阅事件" min-width="200">
          <template #default="{ row }">
            <el-tag
              v-for="key in enabledEventKeys(row.events)"
              :key="key"
              size="small"
              type="success"
              effect="plain"
              style="margin-right: 4px; margin-bottom: 2px"
            >
              {{ eventDict[key]?.label || key }}
            </el-tag>
            <span v-if="!enabledEventKeys(row.events).length" style="color: #C0C4CC">未订阅任何事件</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="row.is_active ? 'success' : 'info'">
              {{ row.is_active ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" :loading="testingId === row.id" @click="handleTest(row)">测试</el-button>
            <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!loading && !tableData.length" description="尚未配置 Webhook" />
    </el-card>

    <!-- Create / Edit Dialog -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑 Webhook' : '添加 Webhook'" width="720px" :close-on-click-modal="false">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="110px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="如：运营群机器人" />
        </el-form-item>
        <el-form-item label="类型" prop="type">
          <el-select v-model="form.type" style="width: 100%">
            <el-option label="企业微信群机器人" value="wechat_work" />
            <el-option label="钉钉群机器人" value="dingtalk" />
            <el-option label="自定义" value="custom" />
          </el-select>
        </el-form-item>
        <el-form-item label="Webhook 地址" prop="webhook_url">
          <el-input
            v-model="form.webhook_url"
            type="textarea"
            :rows="2"
            placeholder="https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxxx-xxxx-xxxx"
          />
          <div class="field-hint">
            企业微信：群设置 → 添加群机器人 → 复制 Webhook 地址
          </div>
        </el-form-item>

        <el-form-item label="启用状态">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>

        <el-divider content-position="left">订阅事件</el-divider>
        <el-alert type="warning" :closable="false" size="small" style="margin-bottom: 12px">
          勾选后，该事件发生时将推送到此 Webhook。支持配置多个 Webhook，分别订阅不同事件（例如：运营群接到期提醒，财务群接客户充值）。
        </el-alert>

        <div v-for="(meta, key) in eventDict" :key="key" class="event-item">
          <el-checkbox
            :model-value="form.events[key]?.enabled || false"
            @update:model-value="toggleEvent(key, $event)"
          >
            <span class="event-label">{{ meta.label }}</span>
            <span class="event-desc">{{ meta.desc }}</span>
          </el-checkbox>

          <!-- 到期提醒：天数多选 -->
          <div v-if="meta.has_days && form.events[key]?.enabled" class="event-config">
            <span class="config-label">提前几天提醒：</span>
            <el-checkbox-group v-model="form.events[key].days">
              <el-checkbox :value="30">30 天</el-checkbox>
              <el-checkbox :value="15">15 天</el-checkbox>
              <el-checkbox :value="7">7 天</el-checkbox>
              <el-checkbox :value="5">5 天</el-checkbox>
              <el-checkbox :value="3">3 天</el-checkbox>
              <el-checkbox :value="1">1 天</el-checkbox>
            </el-checkbox-group>
          </div>

          <!-- 低余额：阈值 -->
          <div v-if="meta.has_threshold && form.events[key]?.enabled" class="event-config">
            <span class="config-label">余额低于：</span>
            <el-input-number v-model="form.events[key].threshold" :min="0" :precision="2" size="small" />
            <span style="margin-left: 6px; color: #909399">元</span>
          </div>
        </div>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          {{ editing ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getWebhooks, getWebhookEvents, createWebhook, updateWebhook, deleteWebhook, testWebhook,
} from '@/api/webhooks'

const loading = ref(false)
const tableData = ref([])
const eventDict = ref({})

const dialogVisible = ref(false)
const editing = ref(null) // null = create, object = edit
const submitting = ref(false)
const testingId = ref(null)
const formRef = ref()

const form = reactive({
  name: '',
  type: 'wechat_work',
  webhook_url: '',
  is_active: 1,
  events: {},
})

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择类型', trigger: 'change' }],
  webhook_url: [
    { required: true, message: '请填写 Webhook 地址', trigger: 'blur' },
    { type: 'url', message: '请填写有效的 URL', trigger: 'blur' },
  ],
}

function typeTag(t) {
  return { wechat_work: 'success', dingtalk: 'primary', custom: 'info' }[t] || 'info'
}
function typeLabel(t) {
  return { wechat_work: '企业微信', dingtalk: '钉钉', custom: '自定义' }[t] || t
}
function maskUrl(url) {
  if (!url) return ''
  return url.replace(/key=([^&]+)/, (_, k) => `key=${k.slice(0, 8)}...${k.slice(-4)}`)
}
function enabledEventKeys(events) {
  if (!events || typeof events !== 'object') return []
  return Object.keys(events).filter(k => events[k]?.enabled)
}

async function fetchData() {
  loading.value = true
  try {
    const res = await getWebhooks()
    tableData.value = Array.isArray(res) ? res : (res?.items || [])
  } catch { /* handled */ }
  finally { loading.value = false }
}

async function loadEvents() {
  try {
    const res = await getWebhookEvents()
    eventDict.value = res || {}
  } catch { /* handled */ }
}

function resetForm() {
  form.name = ''
  form.type = 'wechat_work'
  form.webhook_url = ''
  form.is_active = 1
  // 初始化每个事件的默认配置
  form.events = {}
  for (const [key, meta] of Object.entries(eventDict.value)) {
    form.events[key] = { enabled: false }
    if (meta.has_days) form.events[key].days = [...(meta.default_days || [7, 3, 1])]
    if (meta.has_threshold) form.events[key].threshold = meta.default_threshold || 50
  }
}

function openCreate() {
  editing.value = null
  resetForm()
  dialogVisible.value = true
}

function openEdit(row) {
  editing.value = row
  resetForm()
  // 合并数据库中的 events 配置
  form.name = row.name
  form.type = row.type
  form.webhook_url = row.webhook_url
  form.is_active = row.is_active
  for (const [key, cfg] of Object.entries(row.events || {})) {
    if (form.events[key]) {
      Object.assign(form.events[key], cfg)
    }
  }
  dialogVisible.value = true
}

function toggleEvent(key, enabled) {
  if (!form.events[key]) {
    const meta = eventDict.value[key] || {}
    form.events[key] = { enabled }
    if (meta.has_days) form.events[key].days = [...(meta.default_days || [7, 3, 1])]
    if (meta.has_threshold) form.events[key].threshold = meta.default_threshold || 50
  } else {
    form.events[key].enabled = enabled
  }
}

async function handleSubmit() {
  await formRef.value.validate()
  submitting.value = true
  try {
    if (editing.value) {
      await updateWebhook(editing.value.id, { ...form })
      ElMessage.success('已保存')
    } else {
      await createWebhook({ ...form })
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除 webhook「${row.name}」？`, '确认删除', { type: 'warning' })
    await deleteWebhook(row.id)
    ElMessage.success('已删除')
    fetchData()
  } catch { /* cancelled */ }
}

async function handleTest(row) {
  testingId.value = row.id
  try {
    await testWebhook(row.id)
    ElMessage.success('测试消息已发送，请到群里查看')
  } catch { /* handled */ }
  finally { testingId.value = null }
}

onMounted(async () => {
  await loadEvents()
  fetchData()
})
</script>

<style lang="scss" scoped>
.webhook-page {
  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 12px; color: #4A5568; }
  .field-hint { font-size: 12px; color: #909399; margin-top: 4px; }
  .event-item {
    padding: 10px 12px;
    border-bottom: 1px dashed #F0E6DA;
    &:last-child { border-bottom: none; }
    .event-label { font-weight: 500; margin-right: 8px; }
    .event-desc { font-size: 12px; color: #909399; }
    .event-config {
      margin-left: 24px;
      margin-top: 6px;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;
      .config-label { font-size: 13px; color: #606266; margin-right: 6px; }
    }
  }
}
</style>
