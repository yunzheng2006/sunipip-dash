<template>
  <div class="sales-stats">
    <h2 class="page-title">销售统计</h2>

    <!-- Summary Cards -->
    <div class="summary-cards">
      <el-card class="summary-card">
        <div class="card-label">总客户数</div>
        <div class="card-value">{{ summary.total_customers || 0 }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}消费</div>
        <div class="card-value highlight">¥{{ formatNum(summary.period_spent) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ compareLabel }}消费</div>
        <div class="card-value">¥{{ formatNum(summary.compare_spent) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}利润</div>
        <div class="card-value profit">¥{{ formatNum(summary.period_profit) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ compareLabel }}利润</div>
        <div class="card-value profit">¥{{ formatNum(summary.compare_profit) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">总余额</div>
        <div class="card-value balance">¥{{ formatNum(summary.total_balance) }}</div>
      </el-card>
    </div>

    <!-- Filters -->
    <el-card class="filter-card">
      <div class="filter-row">
        <div class="filter-left">
          <el-form :inline="true" style="margin:0">
            <el-form-item label="业务员" style="margin-bottom:0">
              <el-select
                v-model="selectedSales"
                placeholder="全部业务员"
                clearable
                :disabled="isSalesLocked"
                style="width: 140px"
                @change="fetchData"
              >
                <el-option v-for="sp in salesPersons" :key="sp" :label="sp" :value="sp" />
              </el-select>
            </el-form-item>
            <el-form-item label="时间段" style="margin-bottom:0">
              <el-date-picker
                v-model="dateRange"
                type="daterange"
                range-separator="至"
                start-placeholder="开始"
                end-placeholder="结束"
                value-format="YYYY-MM-DD"
                :clearable="true"
                style="width: 240px"
                @change="fetchData"
              />
            </el-form-item>
          </el-form>
        </div>
        <div class="filter-presets">
          <el-button
            v-for="p in presets" :key="p.key"
            :type="activePreset === p.key ? 'primary' : ''"
            size="small"
            @click="applyPreset(p)"
          >{{ p.label }}</el-button>
          <el-button size="small" @click="exportDialogVisible = true"><el-icon><Download /></el-icon> 导出</el-button>
          <el-button v-if="canManage" type="success" size="small" @click="openAddDialog"><el-icon><Plus /></el-icon> 添加业绩</el-button>
          <el-button v-if="canManage" size="small" @click="openRecords"><el-icon><List /></el-icon> 手动记录</el-button>
        </div>
      </div>
    </el-card>

    <!-- Table -->
    <el-card>
      <el-table :data="pagedCustomers" v-loading="loading" stripe>
        <el-table-column prop="customer_name" label="客户名" min-width="130">
          <template #default="{ row }">
            {{ row.customer_name }}
            <el-tag v-if="row.has_manual" size="small" type="warning" style="margin-left:4px">手动</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sales_person" label="业务员" width="90" />
        <el-table-column prop="active_subs" label="活跃订阅" width="80" align="center" />
        <el-table-column prop="ip_count" label="IP数" width="70" align="center" />
        <el-table-column label="国家分布" min-width="160">
          <template #default="{ row }">
            <span v-if="row.countries && row.countries.length">
              <el-tag v-for="c in row.countries.slice(0, 3)" :key="c.country_code" size="small" style="margin: 1px 2px">
                {{ c.country_name || c.country_code }}×{{ c.count }}
              </el-tag>
              <el-tag v-if="row.countries.length > 3" size="small" type="info" style="margin: 1px 2px">
                +{{ row.countries.length - 3 }}
              </el-tag>
            </span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="中转" width="70" align="center">
          <template #default="{ row }">
            <span v-if="row.forward_count > 0" style="color:#67C23A;font-weight:600">{{ row.forward_count }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '消费'" width="130" align="right" sortable :sort-method="(a, b) => a.period_spent - b.period_spent">
          <template #default="{ row }">
            <el-tooltip v-if="hasBreakdown(row)" placement="top" :show-after="300">
              <template #content>
                <div style="line-height:1.8">
                  <div v-if="row.period_breakdown?.ip">单IP: ¥{{ formatNum(row.period_breakdown.ip.amount) }} ({{ row.period_breakdown.ip.count }}笔)</div>
                  <div v-if="row.period_breakdown?.video">视频专线: ¥{{ formatNum(row.period_breakdown.video.amount) }} ({{ row.period_breakdown.video.count }}笔)</div>
                  <div v-if="row.period_breakdown?.live">直播专线: ¥{{ formatNum(row.period_breakdown.live.amount) }} ({{ row.period_breakdown.live.count }}笔)</div>
                </div>
              </template>
              <span class="money breakdown-link">¥{{ formatNum(row.period_spent) }}</span>
            </el-tooltip>
            <span v-else class="money">¥{{ formatNum(row.period_spent) }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="compareLabel + '消费'" width="110" align="right" sortable :sort-method="(a, b) => a.compare_spent - b.compare_spent">
          <template #default="{ row }">
            <span class="money">¥{{ formatNum(row.compare_spent) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="成交价" width="120" align="right" sortable :sort-method="(a, b) => a.sub_revenue - b.sub_revenue">
          <template #default="{ row }">
            <span v-if="row.sub_revenue > 0" class="money">¥{{ formatNum(row.sub_revenue) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
            <div v-if="row.referral_deduction > 0" style="font-size:11px;color:#E6A23C">佣金 -¥{{ formatNum(row.referral_deduction) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="销售成本" width="100" align="right">
          <template #default="{ row }">
            <span v-if="row.sub_sales_cost > 0" style="color:#409EFF">¥{{ formatNum(row.sub_sales_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '利润'" width="110" align="right" sortable :sort-method="(a, b) => a.period_profit - b.period_profit">
          <template #default="{ row }">
            <span v-if="row.period_profit > 0" style="color:#67C23A;font-weight:600">¥{{ formatNum(row.period_profit) }}</span>
            <span v-else-if="row.period_profit < 0" style="color:#F56C6C;font-weight:600">-¥{{ formatNum(Math.abs(row.period_profit)) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="compareLabel + '利润'" width="110" align="right" sortable :sort-method="(a, b) => a.compare_profit - b.compare_profit">
          <template #default="{ row }">
            <span v-if="row.compare_profit > 0" style="color:#67C23A;font-weight:600">¥{{ formatNum(row.compare_profit) }}</span>
            <span v-else-if="row.compare_profit < 0" style="color:#F56C6C;font-weight:600">-¥{{ formatNum(Math.abs(row.compare_profit)) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="100" align="right">
          <template #default="{ row }">
            <span :style="{ color: row.balance > 0 ? '#67C23A' : '#909399' }">¥{{ formatNum(row.balance) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="注册时间" width="110">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100, 200]"
          :total="customers.length"
          layout="total, sizes, prev, pager, next"
          background
          small
        />
      </div>
    </el-card>

    <!-- 添加手动业绩 Dialog -->
    <el-dialog v-model="addDialogVisible" title="添加手动业绩" width="480px" :close-on-click-modal="false">
      <el-form :model="addForm" label-width="80px">
        <el-form-item label="客户" required>
          <el-select
            v-model="addForm.customer_id"
            filterable
            remote
            :remote-method="searchCustomers"
            :loading="customerSearching"
            placeholder="搜索客户名称"
            style="width:100%"
            @change="onCustomerSelected"
          >
            <el-option
              v-for="c in customerOptions"
              :key="c.id"
              :label="c.customer_name"
              :value="c.id"
            >
              <span>{{ c.customer_name }}</span>
              <span style="float:right;color:#909399;font-size:12px">{{ c.sales_person || '无业务员' }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="业务员">
          <el-input v-model="addForm.sales_person" disabled />
        </el-form-item>
        <el-form-item label="金额" required>
          <el-input-number v-model="addForm.amount" :precision="2" :step="100" style="width:100%" />
        </el-form-item>
        <el-form-item label="销售成本">
          <el-input-number v-model="addForm.cost" :precision="2" :step="10" style="width:100%" />
          <div style="font-size:12px;color:#909399;margin-top:4px">填写后自动计算利润 = 金额 - 成本</div>
        </el-form-item>
        <el-form-item label="利润">
          <el-input-number v-model="addForm.profit" :precision="2" :step="100" style="width:100%" />
        </el-form-item>
        <el-form-item label="日期" required>
          <el-date-picker v-model="addForm.performance_date" type="date" value-format="YYYY-MM-DD" style="width:100%" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="addForm.note" type="textarea" :rows="2" maxlength="500" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="addDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="addSubmitting" @click="submitAdd">确认添加</el-button>
      </template>
    </el-dialog>

    <!-- 导出设置 Dialog -->
    <el-dialog v-model="exportDialogVisible" title="导出销售统计" width="420px">
      <el-form label-width="100px">
        <el-form-item label="时间段">
          <span v-if="dateRange && dateRange.length === 2">{{ dateRange[0] }} 至 {{ dateRange[1] }}</span>
          <span v-else>今天 ({{ dayjs().format('YYYY-MM-DD') }})</span>
        </el-form-item>
        <el-form-item label="导出范围">
          <el-radio-group v-model="exportOnlyActive">
            <el-radio :value="false">全部客户</el-radio>
            <el-radio :value="true">仅有消费的客户</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="消费明细">
          <el-checkbox v-model="exportBreakdown">包含产品分类（单IP / 视频专线 / 直播专线）</el-checkbox>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="exportDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="exportCSV">确认导出</el-button>
      </template>
    </el-dialog>

    <!-- 手动业绩记录 Drawer -->
    <el-drawer v-model="recordsVisible" title="手动业绩记录" size="700px">
      <el-table :data="manualRecords" v-loading="recordsLoading" stripe size="small">
        <el-table-column prop="customer.customer_name" label="客户" width="120" />
        <el-table-column prop="sales_person" label="业务员" width="80" />
        <el-table-column label="金额" width="100" align="right">
          <template #default="{ row }">
            <span class="money">¥{{ formatNum(row.amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="利润" width="100" align="right">
          <template #default="{ row }">
            <span style="color:#67C23A">¥{{ formatNum(row.profit) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="performance_date" label="日期" width="110" />
        <el-table-column prop="note" label="备注" min-width="120" show-overflow-tooltip />
        <el-table-column prop="creator.name" label="操作人" width="80" />
        <el-table-column label="操作" width="70" align="center">
          <template #default="{ row }">
            <el-popconfirm title="确认删除？" @confirm="deleteRecord(row.id)">
              <template #reference>
                <el-button type="danger" size="small" link>删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, Plus, List } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { getSalesStats, getManualPerformances, addManualPerformance, deleteManualPerformance } from '@/api/finance'
import { getCustomers } from '@/api/customers'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const loading = ref(false)
const customers = ref([])
const summary = ref({})
const salesPersons = ref([])
const selectedSales = ref('')
const isSalesLocked = ref(false)
const dateRange = ref(null)
const activePreset = ref('today')
const currentPage = ref(1)
const pageSize = ref(50)
const periodInfo = ref(null)
const compareInfo = ref(null)

const canManage = computed(() => authStore.hasPermission('performance.manage'))
const pagedCustomers = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return customers.value.slice(start, start + pageSize.value)
})

const presets = [
  { key: 'today', label: '今天', get: () => [dayjs().format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')] },
  { key: 'yesterday', label: '昨天', get: () => [dayjs().subtract(1, 'day').format('YYYY-MM-DD'), dayjs().subtract(1, 'day').format('YYYY-MM-DD')] },
  { key: 'week', label: '近7天', get: () => [dayjs().subtract(6, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')] },
  { key: 'month', label: '本月', get: () => [dayjs().startOf('month').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')] },
  { key: 'last_month', label: '上月', get: () => [dayjs().subtract(1, 'month').startOf('month').format('YYYY-MM-DD'), dayjs().subtract(1, 'month').endOf('month').format('YYYY-MM-DD')] },
  { key: '30d', label: '近30天', get: () => [dayjs().subtract(29, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')] },
  { key: '90d', label: '近90天', get: () => [dayjs().subtract(89, 'day').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')] },
]

const periodLabel = computed(() => {
  if (!periodInfo.value) return '本月'
  const s = dayjs(periodInfo.value.start)
  const e = dayjs(periodInfo.value.end)
  if (s.isSame(e, 'day')) return s.format('M/D')
  if (s.isSame(dayjs().startOf('month'), 'day') && e.isSame(dayjs(), 'day')) return '本月'
  return `${s.format('M/D')}-${e.format('M/D')}`
})

const compareLabel = computed(() => {
  if (!compareInfo.value) return '上月'
  const s = dayjs(compareInfo.value.start)
  const e = dayjs(compareInfo.value.end)
  if (s.isSame(e, 'day')) return s.format('M/D')
  if (s.isSame(dayjs().subtract(1, 'month').startOf('month'), 'day') && e.isSame(dayjs().subtract(1, 'month').endOf('month'), 'day')) return '上月'
  return `${s.format('M/D')}-${e.format('M/D')}`
})

function formatNum(n) { return Number(n || 0).toFixed(2) }
function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function hasBreakdown(row) {
  const b = row.period_breakdown
  return b && (b.ip || b.video || b.live)
}

function applyPreset(p) {
  activePreset.value = p.key
  dateRange.value = p.get()
  fetchData()
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (selectedSales.value) params.sales_person = selectedSales.value
    if (dateRange.value && dateRange.value.length === 2) {
      params.date_from = dateRange.value[0]
      params.date_to = dateRange.value[1]
    }
    const res = await getSalesStats(params)
    customers.value = res?.customers || []
    currentPage.value = 1
    summary.value = res?.summary || {}
    salesPersons.value = res?.sales_persons || []
    periodInfo.value = res?.period || null
    compareInfo.value = res?.compare || null
  } catch { /* handled */ }
  finally { loading.value = false }
}

const exportDialogVisible = ref(false)
const exportOnlyActive = ref(true)
const exportBreakdown = ref(true)

function escapeCSV(val) {
  const s = String(val ?? '')
  return s.includes(',') || s.includes('"') || s.includes('\n') ? `"${s.replace(/"/g, '""')}"` : s
}

function exportCSV() {
  if (!customers.value.length) { ElMessage.warning('无数据可导出'); return }
  const pL = periodLabel.value, cL = compareLabel.value
  let data = customers.value
  if (exportOnlyActive.value) {
    data = data.filter(c => c.period_spent > 0)
    if (!data.length) { ElMessage.warning('所选时段无消费客户'); return }
  }
  const headers = ['客户名', '业务员', '活跃订阅', 'IP数', '国家分布', '中转数', `${pL}消费`]
  if (exportBreakdown.value) headers.push('单IP消费', '视频专线消费', '直播专线消费')
  headers.push(`${cL}消费`, '成交价', '销售成本', `${pL}利润`, `${cL}利润`, '余额', '注册时间')
  const rows = data.map(c => {
    const countriesStr = (c.countries || []).map(x => `${x.country_name || x.country_code}×${x.count}`).join('/')
    const row = [
      escapeCSV(c.customer_name), escapeCSV(c.sales_person || ''), c.active_subs, c.ip_count || 0,
      escapeCSV(countriesStr || ''), c.forward_count || 0, c.period_spent,
    ]
    if (exportBreakdown.value) {
      row.push(
        c.period_breakdown?.ip?.amount || 0,
        c.period_breakdown?.video?.amount || 0,
        c.period_breakdown?.live?.amount || 0,
      )
    }
    row.push(
      c.compare_spent, c.sub_revenue || 0, c.sub_sales_cost || 0,
      c.period_profit || 0, c.compare_profit || 0, c.balance,
      c.created_at ? dayjs(c.created_at).format('YYYY-MM-DD') : '',
    )
    return row
  })
  const csv = '﻿' + [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  const suffix = exportOnlyActive.value ? '有消费' : '全部'
  link.download = `销售统计_${pL}_${suffix}_${dayjs().format('YYYYMMDD')}.csv`
  link.click()
  URL.revokeObjectURL(link.href)
  exportDialogVisible.value = false
}

// ── 手动业绩 ──
const addDialogVisible = ref(false)
const addSubmitting = ref(false)
const customerSearching = ref(false)
const customerOptions = ref([])
const addForm = ref({
  customer_id: null,
  sales_person: '',
  amount: 0,
  cost: 0,
  profit: 0,
  performance_date: dayjs().format('YYYY-MM-DD'),
  note: '',
})

watch(() => [addForm.value.amount, addForm.value.cost], ([amt, cost]) => {
  addForm.value.profit = Math.round((amt - cost) * 100) / 100
})

function openAddDialog() {
  addForm.value = {
    customer_id: null,
    sales_person: '',
    amount: 0,
    cost: 0,
    profit: 0,
    performance_date: dayjs().format('YYYY-MM-DD'),
    note: '',
  }
  customerOptions.value = []
  addDialogVisible.value = true
}

async function searchCustomers(query) {
  if (!query || query.length < 1) { customerOptions.value = []; return }
  customerSearching.value = true
  try {
    const res = await getCustomers({ 'filter[keyword]': query, per_page: 20 })
    customerOptions.value = (res?.items || res?.data || []).slice(0, 20)
  } catch { customerOptions.value = [] }
  finally { customerSearching.value = false }
}

function onCustomerSelected(id) {
  const c = customerOptions.value.find(x => x.id === id)
  addForm.value.sales_person = c?.sales_person || ''
}

async function submitAdd() {
  if (!addForm.value.customer_id) { ElMessage.warning('请选择客户'); return }
  if (!addForm.value.performance_date) { ElMessage.warning('请选择日期'); return }
  addSubmitting.value = true
  try {
    await addManualPerformance({
      customer_id: addForm.value.customer_id,
      amount: addForm.value.amount,
      profit: addForm.value.profit,
      performance_date: addForm.value.performance_date,
      note: addForm.value.note || undefined,
    })
    ElMessage.success('添加成功')
    addDialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { addSubmitting.value = false }
}

// ── 手动记录列表 ──
const recordsVisible = ref(false)
const recordsLoading = ref(false)
const manualRecords = ref([])

async function openRecords() {
  recordsVisible.value = true
  recordsLoading.value = true
  try {
    const params = {}
    if (selectedSales.value) params.sales_person = selectedSales.value
    if (dateRange.value && dateRange.value.length === 2) {
      params.date_from = dateRange.value[0]
      params.date_to = dateRange.value[1]
    }
    const res = await getManualPerformances(params)
    manualRecords.value = res || []
  } catch { manualRecords.value = [] }
  finally { recordsLoading.value = false }
}

async function deleteRecord(id) {
  try {
    await deleteManualPerformance(id)
    ElMessage.success('已删除')
    manualRecords.value = manualRecords.value.filter(r => r.id !== id)
    fetchData()
  } catch { /* handled */ }
}

onMounted(() => {
  const user = authStore.user
  if (user) {
    const roles = user.roles || []
    const isSales = roles.includes('sales') && !roles.some(r => ['super_admin', 'ops_admin', 'manager'].includes(r))
    if (isSales) {
      selectedSales.value = user.name
      isSalesLocked.value = true
    }
  }
  applyPreset(presets.find(p => p.key === 'today'))
})
</script>

<style lang="scss" scoped>
.sales-stats {
  .page-title {
    margin: 0 0 20px;
    font-size: 20px;
    font-weight: 600;
    color: #2C3E50;
  }

  .summary-cards {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
    margin-bottom: 16px;

    .summary-card {
      :deep(.el-card__body) { padding: 16px 20px; }
      .card-label { font-size: 13px; color: #909399; margin-bottom: 8px; }
      .card-value {
        font-size: 22px; font-weight: 700; color: #2C3E50;
        font-family: 'SF Mono', Consolas, monospace;
        &.highlight { color: #E8913A; }
        &.profit { color: #67C23A; }
        &.balance { color: #67C23A; }
      }
    }
  }

  .filter-card {
    margin-bottom: 16px;
    :deep(.el-card__body) { padding: 12px 16px; }
  }

  .filter-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }

  .filter-left {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .filter-presets {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
  }

  .money {
    font-family: 'SF Mono', Consolas, monospace;
    color: #E8913A;
  }

  .breakdown-link {
    cursor: help;
    border-bottom: 1px dashed #E8913A;
  }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 12px;
  }
}

@media (max-width: 768px) {
  .sales-stats {
    .page-title { font-size: 17px; margin-bottom: 10px; }
    .summary-cards {
      grid-template-columns: repeat(2, 1fr);
      gap: 8px;
      .summary-card {
        :deep(.el-card__body) { padding: 10px 12px; }
        .card-label { font-size: 11px; margin-bottom: 4px; }
        .card-value { font-size: 16px; }
      }
    }
    .filter-row { flex-direction: column; align-items: flex-start; }
    .filter-presets { width: 100%; }
  }
}
</style>
