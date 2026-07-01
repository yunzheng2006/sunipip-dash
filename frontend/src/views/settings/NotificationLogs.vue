<template>
  <div class="notification-logs">
    <h2 class="page-title">通知发送记录</h2>

    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="Webhook">
          <el-select v-model="searchForm.webhook_config_id" placeholder="全部" clearable style="width: 200px">
            <el-option v-for="w in webhooks" :key="w.id" :label="w.name" :value="w.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="事件">
          <el-select v-model="searchForm.event_type" placeholder="全部" clearable style="width: 180px">
            <el-option v-for="(meta, key) in eventDict" :key="key" :label="meta.label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="searchForm.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="已发送" value="sent" />
            <el-option label="失败" value="failed" />
            <el-option label="待发送" value="pending" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="Webhook" min-width="150">
          <template #default="{ row }">{{ row.webhook_config?.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="渠道" width="100">
          <template #default="{ row }">
            <el-tag size="small">{{ channelLabel(row.channel) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="事件" width="150">
          <template #default="{ row }">{{ eventDict[row.event_type]?.label || row.event_type }}</template>
        </el-table-column>
        <el-table-column label="标题" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="viewDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <el-dialog v-model="detailVisible" title="通知详情" width="640px">
      <el-descriptions :column="1" border v-if="current">
        <el-descriptions-item label="时间">{{ formatDate(current.created_at) }}</el-descriptions-item>
        <el-descriptions-item label="Webhook">{{ current.webhook_config?.name || '-' }}</el-descriptions-item>
        <el-descriptions-item label="事件">{{ eventDict[current.event_type]?.label || current.event_type }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="statusTag(current.status)" size="small">{{ statusLabel(current.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="标题">{{ current.title }}</el-descriptions-item>
        <el-descriptions-item label="内容">
          <pre class="content-pre">{{ current.content }}</pre>
        </el-descriptions-item>
        <el-descriptions-item label="响应">
          <pre class="content-pre">{{ current.response || '-' }}</pre>
        </el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import dayjs from 'dayjs'
import { getNotificationLogs, getWebhooks, getWebhookEvents } from '@/api/webhooks'

const loading = ref(false)
const tableData = ref([])
const webhooks = ref([])
const eventDict = ref({})
const searchForm = reactive({ webhook_config_id: null, event_type: '', status: '' })
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

const detailVisible = ref(false)
const current = ref(null)

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm:ss') : '-' }
function statusTag(s) { return { sent: 'success', failed: 'danger', pending: 'warning' }[s] || 'info' }
function statusLabel(s) { return { sent: '已发送', failed: '失败', pending: '待发送' }[s] || s }
function channelLabel(c) { return { wechat_work: '企业微信', dingtalk: '钉钉', custom: '自定义' }[c] || c }

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.webhook_config_id) params.webhook_config_id = searchForm.webhook_config_id
    if (searchForm.event_type) params.event_type = searchForm.event_type
    if (searchForm.status) params.status = searchForm.status
    const res = await getNotificationLogs(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch { /* handled */ }
  finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }
function handleReset() {
  Object.assign(searchForm, { webhook_config_id: null, event_type: '', status: '' })
  pagination.page = 1
  fetchData()
}

function viewDetail(row) {
  current.value = row
  detailVisible.value = true
}

onMounted(async () => {
  try {
    const [wRes, eRes] = await Promise.all([getWebhooks(), getWebhookEvents()])
    webhooks.value = Array.isArray(wRes) ? wRes : []
    eventDict.value = eRes || {}
  } catch { /* handled */ }
  fetchData()
})
</script>

<style lang="scss" scoped>
.notification-logs {
  .page-title { margin: 0 0 20px; font-size: 20px; font-weight: 600; color: #2C3E50; }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .content-pre {
    margin: 0;
    padding: 8px;
    background: #FAFAFA;
    border-radius: 4px;
    white-space: pre-wrap;
    word-break: break-all;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
    font-size: 12px;
    color: #4A5568;
    max-height: 200px;
    overflow-y: auto;
  }
}
</style>
