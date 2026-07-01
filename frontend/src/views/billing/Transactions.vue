<template>
  <div class="transaction-list">
    <h2 class="page-title">交易流水</h2>

    <!-- Filters -->
    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="客户">
          <el-input v-model="searchForm.customer_name" placeholder="客户名称" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="searchForm.type" placeholder="全部" clearable style="width: 130px">
            <el-option label="充值" value="topup" />
            <el-option label="消费" value="consume" />
            <el-option label="退款" value="refund" />
            <el-option label="调整" value="adjust" />
          </el-select>
        </el-form-item>
        <el-form-item label="时间范围">
          <el-date-picker
            v-model="searchForm.date_range"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            format="YYYY-MM-DD"
            value-format="YYYY-MM-DD"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">
            <el-icon><Search /></el-icon>搜索
          </el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Table -->
    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="customer_name" label="客户" min-width="130" />
        <el-table-column prop="type" label="类型" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="typeTagType(row.type)" size="small">{{ typeLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="amount" label="金额" width="120" align="right">
          <template #default="{ row }">
            <span :style="{ color: row.amount >= 0 ? '#67c23a' : '#f56c6c', fontWeight: 600 }">
              {{ row.amount >= 0 ? '+' : '' }}¥{{ Number(row.amount || 0).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="balance_after" label="交易后余额" width="120" align="right">
          <template #default="{ row }">¥{{ Number(row.balance_after || 0).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="description" label="描述" min-width="200">
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column prop="operator" label="操作人" width="100">
          <template #default="{ row }">{{ row.operator || '-' }}</template>
        </el-table-column>
        <el-table-column prop="created_at" label="交易时间" min-width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.page_size"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
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
import { getTransactions } from '@/api/transactions'

const loading = ref(false)
const tableData = ref([])
const pagination = reactive({ page: 1, page_size: 20, total: 0 })

const searchForm = reactive({
  customer_name: '',
  type: '',
  date_range: null,
})

function formatDate(date) {
  return date ? dayjs(date).format('YYYY-MM-DD HH:mm:ss') : '-'
}

function typeTagType(type) {
  const map = { topup: 'success', consume: 'warning', refund: '', adjust: 'info' }
  return map[type] || 'info'
}

function typeLabel(type) {
  const map = { topup: '充值', consume: '消费', refund: '退款', adjust: '调整' }
  return map[type] || type
}

async function fetchData() {
  loading.value = true
  try {
    const params = {
      page: pagination.page,
      page_size: pagination.page_size,
      customer_name: searchForm.customer_name || undefined,
      type: searchForm.type || undefined,
      start_date: searchForm.date_range?.[0] || undefined,
      end_date: searchForm.date_range?.[1] || undefined,
    }
    Object.keys(params).forEach((k) => {
      if (params[k] === undefined) delete params[k]
    })
    const res = await getTransactions(params)
    tableData.value = res.items || res.data || []
    pagination.total = res.total || 0
  } catch {
    // Error handled by interceptor
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  pagination.page = 1
  fetchData()
}

function handleReset() {
  searchForm.customer_name = ''
  searchForm.type = ''
  searchForm.date_range = null
  pagination.page = 1
  fetchData()
}

onMounted(() => {
  fetchData()
})
</script>

<style lang="scss" scoped>
.transaction-list {
  .page-title {
    margin: 0 0 20px 0;
    font-size: 20px;
    color: #303133;
  }

  .search-card {
    margin-bottom: 16px;

    :deep(.el-card__body) {
      padding-bottom: 2px;
    }
  }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
}

// ===== 手机端适配 =====
@media (max-width: 768px) {
  .transaction-list {
    .page-title {
      font-size: 17px;
      margin-bottom: 10px;
    }

    // 表格：隐藏次要列
    // 列顺序（无selection，无fixed）: 1-ID, 2-客户, 3-类型, 4-金额, 5-余额, 6-描述, 7-操作人, 8-交易时间
    // 手机保留: 2, 3, 4
    :deep(.el-table__body-wrapper) {
      .el-table__row > td.el-table__cell:nth-child(1),
      .el-table__row > td.el-table__cell:nth-child(5),
      .el-table__row > td.el-table__cell:nth-child(6),
      .el-table__row > td.el-table__cell:nth-child(7),
      .el-table__row > td.el-table__cell:nth-child(8) {
        display: none;
      }
    }
    :deep(.el-table__header-wrapper) {
      thead tr > th.el-table__cell:nth-child(1),
      thead tr > th.el-table__cell:nth-child(5),
      thead tr > th.el-table__cell:nth-child(6),
      thead tr > th.el-table__cell:nth-child(7),
      thead tr > th.el-table__cell:nth-child(8) {
        display: none;
      }
    }

    .pagination-wrap {
      justify-content: center;
    }
  }
}
</style>
