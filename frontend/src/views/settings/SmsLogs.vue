<template>
  <div class="sms-logs-page">
    <div class="page-header">
      <h2 class="page-title">短信发送记录</h2>
      <el-button @click="loadData"><el-icon><Refresh /></el-icon> 刷新</el-button>
    </div>

    <!-- 今日统计 -->
    <div class="stats-row" v-if="stats">
      <div class="stat-card">
        <div class="stat-num">{{ stats.today_total }}</div>
        <div class="stat-label">今日总计</div>
      </div>
      <div class="stat-card sent">
        <div class="stat-num">{{ stats.today_sent }}</div>
        <div class="stat-label">发送成功</div>
      </div>
      <div class="stat-card fail">
        <div class="stat-num">{{ stats.today_failed }}</div>
        <div class="stat-label">发送失败</div>
      </div>
      <div class="stat-card verified">
        <div class="stat-num">{{ stats.today_verified }}</div>
        <div class="stat-label">已验证</div>
      </div>
    </div>

    <!-- 筛选 -->
    <el-card shadow="never" style="margin-bottom:16px">
      <div class="filter-bar">
        <el-input v-model="filter.phone" placeholder="手机号" clearable style="width:150px" @keyup.enter="search" />
        <el-select v-model="filter.type" placeholder="类型" clearable style="width:130px" @change="search">
          <el-option label="注册" value="register" />
          <el-option label="登录" value="login" />
          <el-option label="重置密码" value="reset" />
          <el-option label="到期提醒" value="expiry_notify" />
          <el-option label="测试" value="test" />
        </el-select>
        <el-select v-model="filter.status" placeholder="状态" clearable style="width:120px" @change="search">
          <el-option label="待发送" value="pending" />
          <el-option label="已发送" value="sent" />
          <el-option label="已验证" value="verified" />
          <el-option label="失败" value="failed" />
          <el-option label="已过期" value="expired" />
        </el-select>
        <el-date-picker v-model="dateRange" type="daterange" range-separator="~" start-placeholder="开始日期"
          end-placeholder="结束日期" value-format="YYYY-MM-DD" style="width:250px" @change="search" />
        <el-button type="primary" @click="search">查询</el-button>
        <el-button @click="resetFilter">重置</el-button>
      </div>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never">
      <el-table :data="logs" v-loading="loading" stripe size="small" empty-text="暂无记录">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="手机号" width="130">
          <template #default="{ row }"><span class="mono">{{ row.phone }}</span></template>
        </el-table-column>
        <el-table-column label="验证码" width="80" align="center">
          <template #default="{ row }"><span class="mono">{{ row.code || '-' }}</span></template>
        </el-table-column>
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag :type="typeTag(row.type)" size="small">{{ typeLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="服务商" width="80">
          <template #default="{ row }">{{ row.provider || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="业务ID" min-width="140">
          <template #default="{ row }"><span class="mono sub-text">{{ row.biz_id || '-' }}</span></template>
        </el-table-column>
        <el-table-column label="错误信息" min-width="180">
          <template #default="{ row }">
            <span v-if="row.error" class="error-text">{{ row.error }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="IP" width="120">
          <template #default="{ row }"><span class="mono sub-text">{{ row.ip || '-' }}</span></template>
        </el-table-column>
        <el-table-column label="发送时间" width="155">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="验证时间" width="155">
          <template #default="{ row }">{{ fmtTime(row.verified_at) }}</template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrap" v-if="total > 0">
        <el-pagination v-model:current-page="page" :page-size="perPage" :total="total"
          layout="total, prev, pager, next" background small @current-change="loadLogs" />
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import { getSmsLogs, getSmsLogStats } from '@/api/smsLogs'

const logs = ref([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = 30
const stats = ref(null)
const dateRange = ref(null)

const filter = ref({ phone: '', type: '', status: '' })

const TYPE_MAP = { register: '注册', login: '登录', reset: '重置密码', expiry_notify: '到期提醒', test: '测试' }
const STATUS_MAP = { pending: '待发送', sent: '已发送', verified: '已验证', failed: '失败', expired: '已过期' }

function typeLabel(t) { return TYPE_MAP[t] || t }
function typeTag(t) { return t === 'expiry_notify' ? 'warning' : t === 'test' ? 'info' : '' }
function statusLabel(s) { return STATUS_MAP[s] || s }
function statusTag(s) {
  if (s === 'sent') return 'success'
  if (s === 'verified') return ''
  if (s === 'failed') return 'danger'
  if (s === 'expired') return 'info'
  return 'warning'
}

function fmtTime(t) { return t ? t.slice(0, 19).replace('T', ' ') : '-' }

async function loadLogs() {
  loading.value = true
  try {
    const params = { page: page.value, per_page: perPage }
    if (filter.value.phone) params.phone = filter.value.phone
    if (filter.value.type) params.type = filter.value.type
    if (filter.value.status) params.status = filter.value.status
    if (dateRange.value?.[0]) params.date_from = dateRange.value[0]
    if (dateRange.value?.[1]) params.date_to = dateRange.value[1]
    const res = await getSmsLogs(params)
    logs.value = res?.data || []
    total.value = res?.total || 0
  } catch {} finally { loading.value = false }
}

async function loadStats() {
  try { stats.value = await getSmsLogStats() } catch {}
}

function search() { page.value = 1; loadLogs() }
function resetFilter() {
  filter.value = { phone: '', type: '', status: '' }
  dateRange.value = null
  search()
}

function loadData() { loadLogs(); loadStats() }

onMounted(() => { loadData() })
</script>

<style lang="scss" scoped>
.sms-logs-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
    .page-title { margin: 0; font-size: 20px; font-weight: 700; color: #1E293B; }
  }
}

.stats-row {
  display: flex; gap: 12px; margin-bottom: 16px;
  .stat-card {
    flex: 1; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px;
    padding: 12px 16px; text-align: center;
    .stat-num { font-size: 24px; font-weight: 700; color: #1E293B; }
    .stat-label { font-size: 12px; color: #94A3B8; margin-top: 2px; }
    &.sent .stat-num { color: #22C55E; }
    &.fail .stat-num { color: #EF4444; }
    &.verified .stat-num { color: #3B82F6; }
  }
}

.filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; }
.sub-text { color: #94A3B8; }
.error-text { color: #EF4444; font-size: 12px; }
.pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
