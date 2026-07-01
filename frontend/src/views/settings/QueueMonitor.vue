<template>
  <div class="queue-monitor-page">
    <div class="page-header">
      <h2 class="page-title">队列任务监控</h2>
      <div>
        <el-button @click="refresh" :loading="loading">
          <el-icon><Refresh /></el-icon> 刷新
        </el-button>
        <el-switch
          v-model="autoRefresh"
          active-text="自动刷新"
          style="margin-left: 12px"
        />
      </div>
    </div>

    <!-- 统计卡片 -->
    <div class="stats-grid" v-loading="loading">
      <el-card shadow="never" class="stat-card">
        <div class="stat-label">队列等待</div>
        <div class="stat-value" :class="{ warn: stats.jobs_pending > 50 }">
          {{ stats.jobs_pending || 0 }}
        </div>
      </el-card>
      <el-card shadow="never" class="stat-card">
        <div class="stat-label">正在处理</div>
        <div class="stat-value processing">{{ stats.jobs_processing || 0 }}</div>
      </el-card>
      <el-card shadow="never" class="stat-card">
        <div class="stat-label">失败任务</div>
        <div class="stat-value" :class="{ danger: (stats.failed_total || 0) > 0 }">
          {{ stats.failed_total || 0 }}
        </div>
      </el-card>
    </div>

    <!-- 转发状态分布 -->
    <el-row :gutter="16" style="margin-bottom: 16px">
      <el-col :span="12">
        <el-card shadow="never">
          <template #header>NY 转发规则状态</template>
          <div class="status-tags">
            <el-tag v-for="(n, s) in stats.forward_rules" :key="s" :type="fwTag(s)" size="small" style="margin: 2px">
              {{ fwLabel(s) }}: {{ n }}
            </el-tag>
            <span v-if="!Object.keys(stats.forward_rules || {}).length" class="muted">无数据</span>
          </div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card shadow="never">
          <template #header>3x-ui 中转状态</template>
          <div class="status-tags">
            <el-tag v-for="(n, s) in stats.xui_inbounds" :key="s" :type="fwTag(s)" size="small" style="margin: 2px">
              {{ fwLabel(s) }}: {{ n }}
            </el-tag>
            <span v-if="!Object.keys(stats.xui_inbounds || {}).length" class="muted">无数据</span>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 失败任务列表 -->
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>失败任务（最近 50 条）</span>
          <div>
            <el-button size="small" type="warning" :disabled="!failedJobs.length" @click="handleRetryAll">
              全部重试
            </el-button>
            <el-button size="small" type="danger" :disabled="!failedJobs.length" @click="handleFlush">
              清空
            </el-button>
          </div>
        </div>
      </template>
      <el-table :data="failedJobs" size="small" stripe max-height="500">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="任务" min-width="200">
          <template #default="{ row }">
            <strong>{{ row.job_class }}</strong>
          </template>
        </el-table-column>
        <el-table-column prop="queue" label="队列" width="100" />
        <el-table-column label="失败时间" width="170">
          <template #default="{ row }">{{ row.failed_at }}</template>
        </el-table-column>
        <el-table-column label="错误摘要" min-width="350" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono" style="font-size: 11px; color: #F56C6C">{{ row.exception_short }}</span>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!failedJobs.length" description="无失败任务" :image-size="60" />
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { getQueueStats, getFailedJobs, retryAllFailed, flushFailed } from '@/api/queueMonitor'

const loading = ref(false)
const autoRefresh = ref(true)
const stats = reactive({
  jobs_pending: 0,
  jobs_processing: 0,
  jobs_total: 0,
  failed_total: 0,
  by_queue: [],
  forward_rules: {},
  xui_inbounds: {},
})
const failedJobs = ref([])

let timer = null

function fwTag(s) {
  return { active: 'success', pending: 'warning', processing: '', failed: 'danger', deleted: 'info' }[s] || 'info'
}
function fwLabel(s) {
  return { active: '活跃', pending: '待处理', processing: '处理中', failed: '失败', deleted: '已删除' }[s] || s
}

async function refresh() {
  loading.value = true
  try {
    const [s, f] = await Promise.all([getQueueStats(), getFailedJobs(50)])
    Object.assign(stats, s || {})
    failedJobs.value = f || []
  } catch { /* handled */ }
  finally { loading.value = false }
}

async function handleRetryAll() {
  try {
    await ElMessageBox.confirm('重试所有失败任务？', '确认', { type: 'warning' })
    const res = await retryAllFailed()
    ElMessage.success(`已重试 ${res?.retried || 0} 条`)
    refresh()
  } catch { /* cancelled */ }
}

async function handleFlush() {
  try {
    await ElMessageBox.confirm('清空所有失败任务记录？此操作不可恢复。', '确认清空', { type: 'warning' })
    await flushFailed()
    ElMessage.success('已清空')
    refresh()
  } catch { /* cancelled */ }
}

function startAutoRefresh() {
  stopAutoRefresh()
  timer = setInterval(() => {
    if (autoRefresh.value) refresh()
  }, 5000)
}

function stopAutoRefresh() {
  if (timer) { clearInterval(timer); timer = null }
}

onMounted(() => {
  refresh()
  startAutoRefresh()
})
onBeforeUnmount(stopAutoRefresh)
</script>

<style lang="scss" scoped>
.queue-monitor-page {
  .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}

.stat-card {
  border-radius: 12px;
  border: 1px solid #EADFD2;
  :deep(.el-card__body) { padding: 20px 24px; }
  .stat-label { font-size: 13px; color: #909399; margin-bottom: 6px; }
  .stat-value {
    font-size: 36px;
    font-weight: 800;
    color: #2C3E50;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
    &.warn { color: #E6A23C; }
    &.danger { color: #F56C6C; }
    &.processing { color: #409EFF; }
  }
}

.status-tags { line-height: 2; }
.muted { font-size: 12px; color: #C0C4CC; }
.mono { font-family: 'SF Mono', Consolas, Monaco, monospace; }

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  color: #2C3E50;
}
</style>
