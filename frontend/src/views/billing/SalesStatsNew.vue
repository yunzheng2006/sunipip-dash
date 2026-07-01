<template>
  <div class="sales-stats">
    <h2 class="page-title">业绩统计</h2>

    <!-- Summary Cards -->
    <div class="summary-cards">
      <el-card class="summary-card">
        <div class="card-label">总客户数</div>
        <div class="card-value">{{ summary.total_customers || 0 }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">单IP</div>
        <div class="card-value">{{ summary.total_ip_only || 0 }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">带中转IP</div>
        <div class="card-value" style="color:#67C23A">{{ summary.total_forward_ip || 0 }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">测试回收</div>
        <div class="card-value" style="color:#909399">{{ summary.total_test_ip || 0 }}</div>
      </el-card>
      <el-card class="summary-card" v-if="summary.total_unpaid_ip > 0">
        <div class="card-label">未扣款IP</div>
        <div class="card-value" style="color:#E6A23C">{{ summary.total_unpaid_ip }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}消费</div>
        <div class="card-value highlight">¥{{ formatNum(summary.total_spending) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}返佣</div>
        <div class="card-value" style="color:#F56C6C">¥{{ formatNum(summary.total_commission) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}净业绩</div>
        <div class="card-value" style="color:#E8913A;font-weight:700">¥{{ formatNum(summary.total_net_performance) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}销售成本</div>
        <div class="card-value" style="color:#409EFF">¥{{ formatNum(summary.total_sales_cost) }}</div>
      </el-card>
      <el-card v-if="canViewHardCost" class="summary-card">
        <div class="card-label">IP硬成本</div>
        <div class="card-value" style="color:#E6A23C">¥{{ formatNum(summary.total_ip_hard_cost) }}</div>
      </el-card>
      <el-card v-if="canViewHardCost" class="summary-card">
        <div class="card-label">中转硬成本</div>
        <div class="card-value" style="color:#E6A23C">¥{{ formatNum(summary.total_fwd_hard_cost) }}</div>
      </el-card>
      <el-card v-if="canViewHardCost" class="summary-card">
        <div class="card-label">总硬成本</div>
        <div class="card-value" style="color:#E6A23C">¥{{ formatNum(summary.total_hard_cost) }}</div>
      </el-card>
      <el-card class="summary-card">
        <div class="card-label">{{ periodLabel }}利润</div>
        <div class="card-value profit">¥{{ formatNum(summary.total_profit) }}</div>
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
          <el-button v-if="canManage" type="success" size="small" @click="openAddDialog"><el-icon><Plus /></el-icon> 添加内容</el-button>
          <el-button v-if="canManage" size="small" @click="openRecords"><el-icon><List /></el-icon> 手动记录</el-button>
        </div>
      </div>
    </el-card>

    <!-- Table -->
    <el-card>
      <el-table :data="pagedCustomers" v-loading="loading" stripe>
        <el-table-column prop="customer_name" label="客户名" min-width="150">
          <template #default="{ row }">
            {{ row.customer_name }}
            <el-tag v-if="row.has_manual" size="small" type="warning" style="margin-left:4px">手动</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sales_person" label="业务员" width="100" />
        <el-table-column label="单IP" width="70" align="center" sortable :sort-method="(a, b) => a.ip_only_count - b.ip_only_count">
          <template #default="{ row }">
            <span v-if="row.ip_only_count > 0">{{ row.ip_only_count }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="带中转IP" width="90" align="center" sortable :sort-method="(a, b) => a.forward_ip_count - b.forward_ip_count">
          <template #default="{ row }">
            <span v-if="row.forward_ip_count > 0" style="color:#67C23A;font-weight:600">{{ row.forward_ip_count }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="测试回收" width="90" align="center" sortable :sort-method="(a, b) => a.test_ip_count - b.test_ip_count">
          <template #default="{ row }">
            <span v-if="row.test_ip_count > 0" style="color:#909399">{{ row.test_ip_count }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="未扣款" width="80" align="center" sortable :sort-method="(a, b) => (a.unpaid_ip_count||0) - (b.unpaid_ip_count||0)">
          <template #default="{ row }">
            <el-tooltip v-if="row.unpaid_ip_count > 0" content="未从客户余额扣款的IP，不计入业绩和成本" placement="top">
              <span style="color:#E6A23C;font-weight:600">{{ row.unpaid_ip_count }}</span>
            </el-tooltip>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '消费'" width="130" align="right" sortable :sort-method="(a, b) => a.spending - b.spending">
          <template #default="{ row }">
            <span v-if="row.spending > 0" class="money">¥{{ formatNum(row.spending) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '返佣'" width="100" align="right" sortable :sort-method="(a, b) => a.commission - b.commission">
          <template #default="{ row }">
            <span v-if="row.commission > 0" style="color:#F56C6C">¥{{ formatNum(row.commission) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '净业绩'" width="120" align="right" sortable :sort-method="(a, b) => a.net_performance - b.net_performance">
          <template #default="{ row }">
            <span v-if="row.net_performance > 0" style="color:#E8913A;font-weight:600">¥{{ formatNum(row.net_performance) }}</span>
            <span v-else-if="row.net_performance < 0" style="color:#F56C6C">-¥{{ formatNum(Math.abs(row.net_performance)) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '销售成本'" width="130" align="right" sortable :sort-method="(a, b) => a.sales_cost - b.sales_cost">
          <template #default="{ row }">
            <span v-if="row.sales_cost > 0" style="color:#409EFF">¥{{ formatNum(row.sales_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column v-if="canViewHardCost" label="IP硬成本" width="110" align="right" sortable :sort-method="(a, b) => a.ip_hard_cost - b.ip_hard_cost">
          <template #default="{ row }">
            <span v-if="row.ip_hard_cost > 0" style="color:#E6A23C">¥{{ formatNum(row.ip_hard_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column v-if="canViewHardCost" label="中转硬成本" width="110" align="right" sortable :sort-method="(a, b) => a.fwd_hard_cost - b.fwd_hard_cost">
          <template #default="{ row }">
            <span v-if="row.fwd_hard_cost > 0" style="color:#E6A23C">¥{{ formatNum(row.fwd_hard_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column v-if="canViewHardCost" label="总硬成本" width="110" align="right" sortable :sort-method="(a, b) => a.hard_cost - b.hard_cost">
          <template #default="{ row }">
            <span v-if="row.hard_cost > 0" style="color:#E6A23C;font-weight:600">¥{{ formatNum(row.hard_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column :label="periodLabel + '利润'" width="120" align="right" sortable :sort-method="(a, b) => a.profit - b.profit">
          <template #default="{ row }">
            <span v-if="row.profit > 0" style="color:#67C23A;font-weight:600">¥{{ formatNum(row.profit) }}</span>
            <span v-else-if="row.profit < 0" style="color:#F56C6C;font-weight:600">-¥{{ formatNum(Math.abs(row.profit)) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="110" align="right">
          <template #default="{ row }">
            <span :style="{ color: row.balance > 0 ? '#67C23A' : '#909399' }">¥{{ formatNum(row.balance) }}</span>
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
      </el-form>
      <template #footer>
        <el-button @click="exportDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="exportCSV">确认导出</el-button>
      </template>
    </el-dialog>

    <!-- 添加内容 Dialog -->
    <el-dialog v-model="addDialogVisible" title="添加内容" width="480px" :close-on-click-modal="false">
      <el-form :model="addForm" label-width="90px">
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
        <el-form-item label="添加类型" required>
          <el-checkbox-group v-model="addForm.types">
            <el-checkbox value="spending" label="消费" />
            <el-checkbox value="sales_cost" label="销售成本" />
            <el-checkbox v-if="canViewHardCost" value="hard_cost" label="硬成本" />
          </el-checkbox-group>
        </el-form-item>
        <el-form-item v-if="addForm.types.includes('spending')" label="消费金额" required>
          <el-input-number v-model="addForm.spending" :precision="2" :step="100" :min="0" style="width:100%" />
        </el-form-item>
        <el-form-item v-if="addForm.types.includes('sales_cost')" label="销售成本" required>
          <el-input-number v-model="addForm.sales_cost" :precision="2" :step="10" :min="0" style="width:100%" />
        </el-form-item>
        <el-form-item v-if="canViewHardCost && addForm.types.includes('hard_cost')" label="硬成本" required>
          <el-input-number v-model="addForm.hard_cost" :precision="2" :step="10" :min="0" style="width:100%" />
        </el-form-item>
        <el-form-item label="日期" required>
          <el-date-picker v-model="addForm.entry_date" type="date" value-format="YYYY-MM-DD" style="width:100%" />
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

    <!-- 手动记录 Drawer -->
    <el-drawer v-model="recordsVisible" title="手动记录" size="700px">
      <el-table :data="manualRecords" v-loading="recordsLoading" stripe size="small">
        <el-table-column prop="customer.customer_name" label="客户" width="120" />
        <el-table-column prop="sales_person" label="业务员" width="80" />
        <el-table-column label="消费" width="100" align="right">
          <template #default="{ row }">
            <span v-if="Number(row.spending) > 0" class="money">¥{{ formatNum(row.spending) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="销售成本" width="100" align="right">
          <template #default="{ row }">
            <span v-if="Number(row.sales_cost) > 0" style="color:#409EFF">¥{{ formatNum(row.sales_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column v-if="canViewHardCost" label="硬成本" width="100" align="right">
          <template #default="{ row }">
            <span v-if="Number(row.hard_cost) > 0" style="color:#E6A23C">¥{{ formatNum(row.hard_cost) }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="entry_date" label="日期" width="110" />
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
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, Plus, List } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { getSalesStatsNew, getManualStatEntries, addManualStatEntry, deleteManualStatEntry } from '@/api/finance'
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

const canManage = computed(() => authStore.hasPermission('performance.manage'))
const canViewHardCost = computed(() => authStore.hasPermission('performance.view_hard_cost'))
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
  if (!periodInfo.value) return '今日'
  const s = dayjs(periodInfo.value.start)
  const e = dayjs(periodInfo.value.end)
  if (s.isSame(e, 'day')) return s.format('M/D')
  if (s.isSame(dayjs().startOf('month'), 'day') && e.isSame(dayjs(), 'day')) return '本月'
  return `${s.format('M/D')}-${e.format('M/D')}`
})

function formatNum(n) { return Number(n || 0).toFixed(2) }

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
    const res = await getSalesStatsNew(params)
    customers.value = res?.customers || []
    currentPage.value = 1
    summary.value = res?.summary || {}
    salesPersons.value = res?.sales_persons || []
    periodInfo.value = res?.period || null
  } catch { /* handled */ }
  finally { loading.value = false }
}

const exportDialogVisible = ref(false)
const exportOnlyActive = ref(true)

function escapeCSV(val) {
  const s = String(val ?? '')
  return s.includes(',') || s.includes('"') || s.includes('\n') ? `"${s.replace(/"/g, '""')}"` : s
}

function exportCSV() {
  if (!customers.value.length) { ElMessage.warning('无数据可导出'); return }
  const pL = periodLabel.value
  let data = customers.value
  if (exportOnlyActive.value) {
    data = data.filter(c => c.spending > 0)
    if (!data.length) { ElMessage.warning('所选时段无消费客户'); return }
  }
  const baseHeaders = ['客户名', '业务员', '单IP', '带中转IP', '测试回收', '未扣款', `${pL}消费`, `${pL}返佣`, `${pL}净业绩`, `${pL}销售成本`]
  const hardCostHeaders = canViewHardCost.value ? ['IP硬成本', '中转硬成本', '总硬成本'] : []
  const tailHeaders = [`${pL}利润`, '余额']
  const headers = [...baseHeaders, ...hardCostHeaders, ...tailHeaders]
  const rows = data.map(c => {
    const base = [
      escapeCSV(c.customer_name), escapeCSV(c.sales_person || ''),
      c.ip_only_count || 0, c.forward_ip_count || 0, c.test_ip_count || 0, c.unpaid_ip_count || 0,
      c.spending, c.commission || 0, c.net_performance || 0, c.sales_cost,
    ]
    const hc = canViewHardCost.value ? [c.ip_hard_cost || 0, c.fwd_hard_cost || 0, c.hard_cost || 0] : []
    return [...base, ...hc, c.profit, c.balance]
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

// ── 添加内容 ──
const addDialogVisible = ref(false)
const addSubmitting = ref(false)
const customerSearching = ref(false)
const customerOptions = ref([])
const addForm = ref(defaultAddForm())

function defaultAddForm() {
  return {
    customer_id: null,
    sales_person: '',
    types: ['spending'],
    spending: 0,
    sales_cost: 0,
    hard_cost: 0,
    entry_date: dayjs().format('YYYY-MM-DD'),
    note: '',
  }
}

function openAddDialog() {
  addForm.value = defaultAddForm()
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
  if (!addForm.value.entry_date) { ElMessage.warning('请选择日期'); return }

  const payload = {
    customer_id: addForm.value.customer_id,
    entry_date: addForm.value.entry_date,
    note: addForm.value.note || undefined,
  }

  if (addForm.value.types.includes('spending')) {
    payload.spending = addForm.value.spending
  }
  if (addForm.value.types.includes('sales_cost')) {
    payload.sales_cost = addForm.value.sales_cost
  }
  if (addForm.value.types.includes('hard_cost')) {
    payload.hard_cost = addForm.value.hard_cost
  }

  if (!payload.spending && !payload.sales_cost && !payload.hard_cost) {
    ElMessage.warning('请填写金额')
    return
  }

  addSubmitting.value = true
  try {
    await addManualStatEntry(payload)
    ElMessage.success('添加成功')
    addDialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { addSubmitting.value = false }
}

// ── 手动记录 ──
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
    const res = await getManualStatEntries(params)
    manualRecords.value = res || []
  } catch { manualRecords.value = [] }
  finally { recordsLoading.value = false }
}

async function deleteRecord(id) {
  try {
    await deleteManualStatEntry(id)
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
