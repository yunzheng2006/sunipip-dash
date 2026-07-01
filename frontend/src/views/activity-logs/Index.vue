<template>
  <div class="activity-logs">
    <h2 class="page-title">操作日志</h2>

    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="用户">
          <el-input v-model="searchForm.username" placeholder="用户名/姓名" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="操作">
          <el-select v-model="searchForm.action" placeholder="全部" clearable style="width: 130px">
            <el-option label="登录" value="login" />
            <el-option label="退出" value="logout" />
            <el-option label="创建" value="create" />
            <el-option label="更新" value="update" />
            <el-option label="删除" value="delete" />
            <el-option label="充值" value="topup" />
            <el-option label="分配IP" value="assign" />
            <el-option label="续费" value="renew" />
            <el-option label="取消" value="cancel" />
            <el-option label="批量导入" value="import" />
          </el-select>
        </el-form-item>
        <el-form-item label="对象">
          <el-select v-model="searchForm.subject_type" placeholder="全部" clearable style="width: 150px">
            <el-option label="客户" value="Customer" />
            <el-option label="IP资产" value="ProxyIp" />
            <el-option label="订阅" value="Subscription" />
            <el-option label="后台用户" value="User" />
            <el-option label="资产组" value="IpAssetGroup" />
            <el-option label="IP组" value="IpGroup" />
            <el-option label="定价规则" value="PricingRule" />
            <el-option label="系统" value="System" />
          </el-select>
        </el-form-item>
        <el-form-item label="IP地址">
          <el-input v-model="searchForm.ip_address" placeholder="IP" clearable style="width: 140px" />
        </el-form-item>
        <el-form-item label="时间范围">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="searchForm.is_system" placeholder="全部" clearable style="width: 110px">
            <el-option label="用户操作" :value="0" />
            <el-option label="系统日志" :value="1" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch"><el-icon><Search /></el-icon>搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatDateTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="用户" width="120">
          <template #default="{ row }">
            <span v-if="row.user">
              <strong>{{ row.user.name }}</strong>
              <span style="color: #909399; font-size: 12px"> ({{ row.user.username }})</span>
            </span>
            <el-tag v-else size="small" type="info">系统</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100">
          <template #default="{ row }">
            <el-tag size="small" :type="actionTag(row.action)">{{ actionLabel(row.action) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="对象" width="110">
          <template #default="{ row }">
            <span>{{ subjectLabel(row.subject_type) }}</span>
            <span v-if="row.subject_id" style="color: #909399; font-size: 12px"> #{{ row.subject_id }}</span>
          </template>
        </el-table-column>
        <el-table-column label="描述" min-width="200" prop="description" />
        <el-table-column label="IP地址" width="130">
          <template #default="{ row }">
            <span class="mono">{{ row.ip_address || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="详情" width="80" align="center">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="showDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[30, 50, 100, 200]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- Detail Dialog -->
    <el-dialog v-model="detailVisible" title="日志详情" width="640px">
      <el-descriptions v-if="selectedLog" :column="1" border>
        <el-descriptions-item label="ID">{{ selectedLog.id }}</el-descriptions-item>
        <el-descriptions-item label="时间">{{ formatDateTime(selectedLog.created_at) }}</el-descriptions-item>
        <el-descriptions-item label="用户">
          {{ selectedLog.user ? `${selectedLog.user.name} (${selectedLog.user.username})` : '系统' }}
        </el-descriptions-item>
        <el-descriptions-item label="操作">{{ actionLabel(selectedLog.action) }}</el-descriptions-item>
        <el-descriptions-item label="对象">
          {{ subjectLabel(selectedLog.subject_type) }}
          <span v-if="selectedLog.subject_id"> #{{ selectedLog.subject_id }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="描述">{{ selectedLog.description }}</el-descriptions-item>
        <el-descriptions-item label="IP地址">
          <span class="mono">{{ selectedLog.ip_address }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="请求方法">
          {{ selectedLog.properties?.method }}
        </el-descriptions-item>
        <el-descriptions-item label="请求路径">
          <span class="mono">{{ selectedLog.properties?.path }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="User-Agent">
          <span style="font-size: 12px; color: #909399">{{ selectedLog.properties?.user_agent }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="请求数据">
          <pre class="json-view">{{ JSON.stringify(selectedLog.properties?.request || {}, null, 2) }}</pre>
        </el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import dayjs from 'dayjs'
import { getActivityLogs } from '@/api/activityLogs'

const loading = ref(false)
const tableData = ref([])
const searchForm = reactive({
  username: '', action: '', subject_type: '', ip_address: '',
  date_from: '', date_to: '', is_system: null,
})
const dateRange = ref([])
const pagination = reactive({ page: 1, per_page: 30, total: 0 })

watch(dateRange, (val) => {
  searchForm.date_from = val?.[0] || ''
  searchForm.date_to = val?.[1] || ''
})

function formatDateTime(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm:ss') : '-' }

function actionLabel(a) {
  return {
    login: '登录', logout: '退出', create: '创建', update: '更新', delete: '删除',
    topup: '充值', assign: '分配', renew: '续费', cancel: '取消', import: '导入',
  }[a] || a
}

function actionTag(a) {
  return {
    login: 'success', logout: 'info', create: 'primary', update: 'warning',
    delete: 'danger', topup: 'success', assign: 'primary', renew: 'success',
    cancel: 'danger', import: 'primary',
  }[a] || 'info'
}

function subjectLabel(s) {
  return {
    Customer: '客户', ProxyIp: 'IP资产', Subscription: '订阅', User: '后台用户',
    IpAssetGroup: '资产组', IpGroup: 'IP组', PricingRule: '定价规则', System: '系统',
  }[s] || s || '-'
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    Object.entries(searchForm).forEach(([k, v]) => {
      if (v !== '' && v !== null && v !== undefined) params[k] = v
    })
    const res = await getActivityLogs(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch { /* handled */ }
  finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }
function handleReset() {
  Object.keys(searchForm).forEach(k => searchForm[k] = k === 'is_system' ? null : '')
  dateRange.value = []
  pagination.page = 1
  fetchData()
}

const detailVisible = ref(false)
const selectedLog = ref(null)
function showDetail(row) { selectedLog.value = row; detailVisible.value = true }

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.activity-logs {
  .page-title { margin: 0 0 20px; font-size: 20px; font-weight: 600; color: #2C3E50; }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 13px; }
  .json-view {
    background: #FEF7F0;
    padding: 12px;
    border-radius: 6px;
    font-size: 12px;
    font-family: 'SF Mono', Consolas, monospace;
    max-height: 300px;
    overflow: auto;
    margin: 0;
  }
}
</style>
