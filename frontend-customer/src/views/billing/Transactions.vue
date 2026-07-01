<template>
  <div class="transactions-page">
    <h1 class="page-title">交易流水</h1>

    <el-card class="filter-card" shadow="never">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="类型">
          <el-select v-model="searchForm.type" placeholder="全部" clearable style="width: 140px">
            <el-option label="充值" value="topup" />
            <el-option label="购买" value="purchase" />
            <el-option label="续费" value="subscription_renew" />
            <el-option label="退款" value="refund" />
            <el-option label="调整" value="adjustment" />
          </el-select>
        </el-form-item>
        <el-form-item label="日期">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="类型" width="80">
          <template #default="{ row }">
            <el-tag size="small" :type="txTagType(row.type)">{{ txLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="110" align="right">
          <template #default="{ row }">
            <span :style="{ color: row.amount >= 0 ? '#67C23A' : '#F56C6C', fontWeight: 700, fontSize: '14px' }">
              {{ row.amount >= 0 ? '+' : '' }}{{ Number(row.amount).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变动前" width="100" align="right">
          <template #default="{ row }">¥{{ Number(row.balance_before).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="变动后" width="100" align="right">
          <template #default="{ row }">¥{{ Number(row.balance_after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="说明" min-width="200">
          <template #default="{ row }">
            <span class="tx-desc">{{ row.description || '-' }}</span>
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
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import dayjs from 'dayjs'
import { getTransactions } from '@/api/billing'

const loading = ref(false)
const tableData = ref([])
const searchForm = reactive({ type: '' })
const dateRange = ref(null)
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

function formatTime(t) { return t ? dayjs(t).format('YYYY-MM-DD HH:mm:ss') : '-' }
function txLabel(t) {
  return {
    topup: '充值', purchase: '购买', subscription_renew: '续费',
    refund: '退款', deduction: '扣费', adjustment: '调整', withdrawal: '退款',
  }[t] || t
}
function txTagType(t) {
  if (['topup', 'refund', 'withdrawal'].includes(t)) return 'success'
  if (['purchase', 'subscription_renew', 'deduction'].includes(t)) return 'warning'
  return 'info'
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.type) params.type = searchForm.type
    if (dateRange.value?.[0]) params.date_from = dateRange.value[0]
    if (dateRange.value?.[1]) params.date_to = dateRange.value[1]
    const res = await getTransactions(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch { /* handled */ }
  finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }
function handleReset() {
  searchForm.type = ''
  dateRange.value = null
  pagination.page = 1
  fetchData()
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.transactions-page { display: flex; flex-direction: column; gap: 16px; }
.page-title { margin: 0; font-size: 22px; font-weight: 700; color: #2C3E50; }
.filter-card {
  border-radius: 10px;
  border: 1px solid #EADFD2;
  :deep(.el-card__body) { padding: 12px 18px 2px; }
}
.tx-desc {
  font-size: 13px;
  color: #475569;
  line-height: 1.5;
  word-break: break-word;
}
.pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }

@media (max-width: 768px) {
  .page-title { font-size: 18px; }
  .filter-card {
    :deep(.el-card__body) { padding: 8px 10px 4px; }
    :deep(.el-form) { display: flex; flex-wrap: wrap; gap: 0; }
    :deep(.el-form-item) { width: 100%; margin-right: 0 !important; margin-bottom: 6px; }
    :deep(.el-select), :deep(.el-date-editor) { width: 100% !important; }
    :deep(.el-form-item:last-child) {
      display: flex; gap: 8px;
      .el-button { flex: 1; }
    }
  }
  :deep(.el-card__body) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  :deep(.el-table) {
    font-size: 12px;
    min-width: 560px;
    // 隐藏 ID
    .el-table__header th:nth-child(1),
    .el-table__body td:nth-child(1) { display: none; }
    // 隐藏变动前
    .el-table__header th:nth-child(5),
    .el-table__body td:nth-child(5) { display: none; }
    // 隐藏变动后
    .el-table__header th:nth-child(6),
    .el-table__body td:nth-child(6) { display: none; }
  }
  .tx-desc { font-size: 12px; }
  .pagination-wrap { justify-content: center; }
}
</style>
