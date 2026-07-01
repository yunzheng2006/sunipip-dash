<template>
  <div class="customer-list">
    <h2 class="page-title">客户管理</h2>

    <!-- Search Bar -->
    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="关键字">
          <el-input
            v-model="searchForm.keyword"
            placeholder="客户名 / 用户名 / 手机 / 邮箱"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="业务归属">
          <el-select v-model="searchForm.sales_person" filterable clearable placeholder="全部" style="width: 150px"
            allow-create>
            <el-option label="无归属" value="__none__" />
            <el-option v-for="u in staffFilterOptions" :key="u.id" :label="u.name" :value="u.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="邀请码">
          <el-input
            v-model="searchForm.invite_code_used"
            placeholder="筛选使用此邀请码"
            clearable
            style="width: 140px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="searchForm.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="正常" value="active" />
            <el-option label="停用" value="inactive" />
            <el-option label="欠费" value="overdue" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">
            <el-icon><Search /></el-icon>搜索
          </el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="success" @click="$router.push('/customers/create')">
            <el-icon><Plus /></el-icon>新建客户
          </el-button>
          <el-button v-if="isSuperAdmin" type="warning" @click="openMerge">
            <el-icon><Connection /></el-icon>合并客户
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Table -->
    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="公司/名称" min-width="150">
          <template #default="{ row }">
            <strong>{{ row.customer_name }}</strong>
            <div v-if="row.company_name && row.company_name !== row.customer_name" style="font-size:12px;color:#909399">{{ row.company_name }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="username" label="账号" min-width="110" />
        <el-table-column prop="phone" label="手机" min-width="120" />
        <el-table-column prop="balance" label="余额" min-width="100" align="right">
          <template #default="{ row }">
            <span :class="{ 'text-danger': row.balance < 0 }">
              {{ formatMoney(row.balance) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="sales_person" label="业务归属" min-width="100" />
        <el-table-column label="推荐人" min-width="110">
          <template #default="{ row }">
            <template v-if="row.referrer">
              <el-link type="primary" @click="$router.push(`/customers/${row.referrer.id}`)">
                {{ row.referrer.customer_name }}
              </el-link>
            </template>
            <span v-else-if="row.invite_code_used" style="font-size:12px;color:#909399">
              码: {{ row.invite_code_used }}
            </span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="中转" width="70" align="center">
          <template #default="{ row }">
            <el-icon v-if="row.forward_certified" :size="16" style="color:#67C23A"><CircleCheck /></el-icon>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ statusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="proxy_ips_count" label="IP数量" width="80" align="center" />
        <el-table-column label="操作" width="240" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="$router.push(`/customers/${row.id}`)">
              查看
            </el-button>
            <el-button type="warning" link size="small" @click="openEdit(row)">
              编辑
            </el-button>
            <el-button type="success" link size="small" @click="openTopup(row)">
              充值
            </el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">
              删除
            </el-button>
            <el-button type="info" link size="small" @click="handleImpersonate(row)">
              模拟登录
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.page_size"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100, 200, 500]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="onSizeChange"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- Topup Dialog -->
    <el-dialog v-model="topupDialogVisible" title="客户充值" width="450px">
      <el-form ref="topupFormRef" :model="topupForm" :rules="topupRules" label-width="80px">
        <el-form-item label="客户">
          <el-input :value="topupCustomer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="充值金额" prop="amount">
          <el-input-number v-model="topupForm.amount" :min="0.01" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="备注" prop="description">
          <el-input v-model="topupForm.description" type="textarea" :rows="3" placeholder="充值备注信息" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="topupDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="topupLoading" @click="submitTopup">确认充值</el-button>
      </template>
    </el-dialog>

    <!-- Edit Dialog -->
    <el-dialog v-model="editDialogVisible" title="编辑客户信息" width="620px" :close-on-click-modal="false">
      <el-form ref="editFormRef" :model="editForm" :rules="editRules" label-width="110px">
        <el-form-item label="公司名称" prop="customer_name">
          <el-input v-model="editForm.customer_name" />
        </el-form-item>
        <el-form-item label="登录账号">
          <el-input :value="editingCustomer?.username" disabled />
          <div class="hint">账号不可修改</div>
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="手机">
              <el-input v-model="editForm.phone" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="邮箱">
              <el-input v-model="editForm.email" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="公司名">
              <el-input v-model="editForm.company_name" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="营业执照号">
              <el-input v-model="editForm.company_id" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="业务归属">
          <el-select v-model="editForm.sales_person" filterable clearable placeholder="选择业务员"
            style="width: 100%" :disabled="!canChangeSales">
            <el-option v-for="u in staffOptions" :key="u.id" :label="u.name" :value="u.name" />
          </el-select>
          <div v-if="!canChangeSales" class="hint">需要「修改业务归属人」权限</div>
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="中转认证">
              <el-switch v-model="editForm.forward_certified" />
              <span style="margin-left:8px;font-size:12px;color:#909399">{{ editForm.forward_certified ? '已认证，可自助下单中转' : '未认证' }}</span>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="状态">
              <el-radio-group v-model="editForm.status">
                <el-radio :value="1">正常</el-radio>
                <el-radio :value="0">停用</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="地址">
          <el-input v-model="editForm.address" type="textarea" :rows="2" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="editLoading" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>

    <!-- Sales Sync Confirmation Dialog -->
    <el-dialog v-model="salesSyncVisible" title="业务归属变更 · 业绩迁移确认" width="720px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        <template #title>
          将「{{ salesSyncData?.customer?.customer_name }}」及其下游客户的业务归属变更为
          <strong>「{{ salesSyncTarget }}」</strong>
        </template>
      </el-alert>

      <div style="margin-bottom: 12px; font-weight: 500; color: #303133">受影响的客户：</div>
      <el-table :data="salesSyncData?.affected_customers || []" border size="small" max-height="260" style="margin-bottom: 16px">
        <el-table-column prop="customer_name" label="客户名" min-width="120">
          <template #default="{ row }">
            {{ row.customer_name }}
            <el-tag v-if="row.is_self" size="small" type="primary" effect="plain" style="margin-left: 4px">当前</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="current_sales_person" label="原归属" width="100">
          <template #default="{ row }">
            <span :style="{ color: row.current_sales_person ? '' : '#C0C4CC' }">{{ row.current_sales_person || '无' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="subscription_count" label="订阅数" width="70" align="center" />
        <el-table-column label="历史消费" width="110" align="right">
          <template #default="{ row }">
            <span style="color: #E8913A; font-weight: 600">¥{{ Number(row.total_spent || 0).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="已有提成" width="100" align="right">
          <template #default="{ row }">
            <span>¥{{ Number(row.existing_for_user || 0).toFixed(2) }}</span>
          </template>
        </el-table-column>
      </el-table>

      <el-divider />

      <div class="sales-sync-commission">
        <el-checkbox v-model="salesSyncBackfill" :disabled="!salesSyncData?.sales_user" size="large">
          为「{{ salesSyncTarget }}」追溯历史业绩提成
        </el-checkbox>
        <div v-if="salesSyncBackfill && salesSyncData?.total_potential_commission > 0" class="commission-preview">
          <div class="commission-summary">
            提成比例 <strong>{{ salesSyncData.commission_rate }}%</strong>，
            预计追溯提成总额：
            <span class="amount">¥{{ Number(salesSyncData.total_potential_commission).toFixed(2) }}</span>
          </div>
          <el-table :data="salesSyncData.commission_breakdown" border size="small" max-height="180" style="margin-top: 8px">
            <el-table-column prop="customer_name" label="客户" min-width="120" />
            <el-table-column label="可追溯消费" width="120" align="right">
              <template #default="{ row }">¥{{ Number(row.spend_amount).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column label="提成" width="100" align="right">
              <template #default="{ row }">
                <span style="color: #67C23A; font-weight: 600">+¥{{ Number(row.commission).toFixed(2) }}</span>
              </template>
            </el-table-column>
          </el-table>
        </div>
        <div v-else-if="salesSyncBackfill && salesSyncData?.total_potential_commission === 0" style="margin-top: 8px; color: #909399; font-size: 13px">
          暂无可追溯的历史消费记录
        </div>
        <div v-if="!salesSyncData?.sales_user" style="margin-top: 4px; color: #F56C6C; font-size: 12px">
          未找到名为「{{ salesSyncTarget }}」的管理员用户，无法追溯提成
        </div>
      </div>

      <template #footer>
        <el-button @click="salesSyncVisible = false">取消</el-button>
        <el-button type="primary" :loading="salesSyncLoading" @click="executeSalesSync">确认变更</el-button>
      </template>
    </el-dialog>

    <!-- Merge Customers Dialog -->
    <el-dialog
      v-model="mergeDialogVisible"
      title="合并客户"
      width="640px"
      :close-on-click-modal="false"
    >
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        <template #title>
          <strong>不可逆操作</strong>：将「源客户」的所有 IP 资产 / 订阅 / 流水 / 余额合并到「目标客户」，源客户会被软删除。
          <br>建议先在「源客户详情」确认所有数据，再执行合并。
        </template>
      </el-alert>

      <el-form label-width="100px">
        <el-form-item label="源客户" required>
          <el-select
            v-model="mergeForm.source_id"
            filterable
            remote
            reserve-keyword
            placeholder="搜索（将被合并删除）"
            :remote-method="searchMergeCustomers"
            :loading="mergeSearchLoading"
            style="width: 100%"
          >
            <el-option
              v-for="c in mergeCustomerOptions"
              :key="c.id"
              :label="`#${c.id} ${c.customer_name} (余额 ¥${Number(c.balance || 0).toFixed(2)}, IP ${c.proxy_ips_count || 0})`"
              :value="c.id"
              :disabled="c.id === mergeForm.target_id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="目标客户" required>
          <el-select
            v-model="mergeForm.target_id"
            filterable
            remote
            reserve-keyword
            placeholder="搜索（保留的主号）"
            :remote-method="searchMergeCustomers"
            :loading="mergeSearchLoading"
            style="width: 100%"
          >
            <el-option
              v-for="c in mergeCustomerOptions"
              :key="c.id"
              :label="`#${c.id} ${c.customer_name} (余额 ¥${Number(c.balance || 0).toFixed(2)}, IP ${c.proxy_ips_count || 0})`"
              :value="c.id"
              :disabled="c.id === mergeForm.source_id"
            />
          </el-select>
        </el-form-item>
      </el-form>

      <div class="merge-preview" v-if="mergeForm.source_id && mergeForm.target_id">
        合并后：所有数据归到「目标客户」名下，源客户「{{ mergeSourceName }}」将从客户列表移除（软删除可恢复）
      </div>

      <template #footer>
        <el-button @click="mergeDialogVisible = false">取消</el-button>
        <el-button
          plain
          :disabled="!mergeForm.source_id || !mergeForm.target_id"
          @click="previewMerge"
        >
          预览影响
        </el-button>
        <el-button
          type="warning"
          :loading="mergeLoading"
          :disabled="!mergeForm.source_id || !mergeForm.target_id"
          @click="submitMerge"
        >
          确认合并
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getCustomers, deleteCustomer, updateCustomer, changeSalesPerson,
  topupCustomer as topupCustomerApi, mergeCustomers, impersonateCustomer,
} from '@/api/customers'
import { getUsers } from '@/api/users'
import { useAuthStore } from '@/stores/auth'
import request from '@/utils/request'

const authStore = useAuthStore()
const isSuperAdmin = computed(() => authStore.hasRole('super_admin'))
const canChangeSales = computed(() => {
  if (isSuperAdmin.value) return true
  const perms = authStore.user?.permissions || []
  return perms.includes('customer.change_sales')
})

const loading = ref(false)
const tableData = ref([])

const searchForm = reactive({
  keyword: '',
  sales_person: '',
  invite_code_used: '',
  status: '',
})

const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0,
})

function formatMoney(val) {
  if (val == null) return '-'
  return `¥${Number(val).toFixed(2)}`
}

function statusTagType(status) {
  const map = { active: 'success', inactive: 'info', overdue: 'danger' }
  return map[status] || 'info'
}

function statusLabel(status) {
  const map = { active: '正常', inactive: '停用', overdue: '欠费' }
  return map[status] || status
}

async function fetchData() {
  loading.value = true
  try {
    // 后端用 Spatie QueryBuilder，筛选字段需要 filter[xxx] 格式
    // 分页用 per_page（不是 page_size）
    const params = {
      page: pagination.page,
      per_page: pagination.page_size,
    }
    if (searchForm.keyword) params['filter[keyword]'] = searchForm.keyword
    if (searchForm.sales_person) params['filter[sales_person]'] = searchForm.sales_person
    if (searchForm.invite_code_used) params['filter[invite_code_used]'] = searchForm.invite_code_used
    if (searchForm.status) params['filter[status]'] = searchForm.status

    const res = await getCustomers(params)
    tableData.value = res?.items || (Array.isArray(res) ? res : [])
    pagination.total = res?.pagination?.total || 0
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

function onSizeChange(size) {
  pagination.page_size = size
  pagination.page = 1
  fetchData()
}

function handleReset() {
  searchForm.keyword = ''
  searchForm.sales_person = ''
  searchForm.invite_code_used = ''
  searchForm.status = ''
  pagination.page = 1
  fetchData()
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定要删除客户「${row.customer_name}」吗？此操作不可撤销。`, '删除确认', {
      type: 'warning',
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
    })
    await deleteCustomer(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch {
    // Cancelled or error handled by interceptor
  }
}

// Topup
const topupDialogVisible = ref(false)
const topupLoading = ref(false)
const topupFormRef = ref(null)
const topupCustomer = ref(null)
const topupForm = reactive({
  amount: 100,
  description: '',
})

const topupRules = {
  amount: [{ required: true, message: '请输入充值金额', trigger: 'blur' }],
}

function openTopup(row) {
  topupCustomer.value = row
  topupForm.amount = 100
  topupForm.description = ''
  topupDialogVisible.value = true
}

async function submitTopup() {
  const valid = await topupFormRef.value.validate().catch(() => false)
  if (!valid) return
  topupLoading.value = true
  try {
    await topupCustomerApi(topupCustomer.value.id, {
      amount: topupForm.amount,
      description: topupForm.description,
    })
    ElMessage.success('充值成功')
    topupDialogVisible.value = false
    fetchData()
  } catch {
    // Error handled by interceptor
  } finally {
    topupLoading.value = false
  }
}

// ========== Edit Dialog ==========
const editDialogVisible = ref(false)
const editLoading = ref(false)
const editFormRef = ref(null)
const editingCustomer = ref(null)
const editForm = reactive({
  customer_name: '',
  sales_person: '',
  phone: '',
  email: '',
  company_name: '',
  company_id: '',
  address: '',
  status: 1,
  remark: '',
  forward_certified: false,
})
const staffOptions = ref([])
const editRules = {
  customer_name: [
    { required: true, message: '请输入客户名称', trigger: 'blur' },
    { max: 100, message: '不超过 100 字符', trigger: 'blur' },
  ],
}

async function openEdit(row) {
  editingCustomer.value = row
  editForm.customer_name = row.customer_name || ''
  editForm.sales_person = row.sales_person || ''
  editForm.phone = row.phone || ''
  editForm.email = row.email || ''
  editForm.company_name = row.company_name || ''
  editForm.company_id = row.company_id || ''
  editForm.address = row.address || ''
  editForm.status = row.status ?? 1
  editForm.remark = row.remark || ''
  editForm.forward_certified = row.forward_certified || false
  // Load staff list for sales_person dropdown
  try {
    const res = await getUsers({ per_page: 100 })
    staffOptions.value = res?.items || res || []
  } catch (e) {
    console.warn('Failed to load staff list:', e)
  }
  editDialogVisible.value = true
}

async function submitEdit() {
  const valid = await editFormRef.value.validate().catch(() => false)
  if (!valid) return

  // 检查 sales_person 是否变更
  const oldSales = editingCustomer.value.sales_person || ''
  const newSales = editForm.sales_person || ''
  if (newSales && newSales !== oldSales && canChangeSales.value) {
    // 先保存其他字段
    editLoading.value = true
    try {
      const otherData = { ...editForm }
      delete otherData.sales_person
      await updateCustomer(editingCustomer.value.id, otherData)
    } catch { editLoading.value = false; return }
    editLoading.value = false

    // 调 preview 获取下游客户影响
    try {
      const preview = await changeSalesPerson(editingCustomer.value.id, {
        sales_person: newSales,
        preview: true,
      })
      salesSyncData.value = preview
      salesSyncTarget.value = newSales
      salesSyncCustomerId.value = editingCustomer.value.id
      salesSyncBackfill.value = false
      editDialogVisible.value = false
      salesSyncVisible.value = true
    } catch {
      // preview 失败时直接保存 sales_person
      try {
        await updateCustomer(editingCustomer.value.id, { sales_person: newSales })
        ElMessage.success('已保存')
        editDialogVisible.value = false
        fetchData()
      } catch { /* handled */ }
    }
    return
  }

  editLoading.value = true
  try {
    await updateCustomer(editingCustomer.value.id, { ...editForm })
    ElMessage.success('已保存')
    editDialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { editLoading.value = false }
}

// ========== Sales Sync ==========
const salesSyncVisible = ref(false)
const salesSyncLoading = ref(false)
const salesSyncData = ref(null)
const salesSyncTarget = ref('')
const salesSyncCustomerId = ref(null)
const salesSyncBackfill = ref(false)

async function executeSalesSync() {
  salesSyncLoading.value = true
  try {
    const res = await changeSalesPerson(salesSyncCustomerId.value, {
      sales_person: salesSyncTarget.value,
      preview: false,
      backfill_commission: salesSyncBackfill.value,
    })
    ElMessage.success(res?.message || '业务归属已更新')
    salesSyncVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { salesSyncLoading.value = false }
}

// ========== Merge Customers ==========
const mergeDialogVisible = ref(false)
const mergeLoading = ref(false)
const mergeSearchLoading = ref(false)
const mergeCustomerOptions = ref([])
const mergeForm = reactive({
  source_id: null,
  target_id: null,
})

const mergeSourceName = computed(() => {
  const c = mergeCustomerOptions.value.find(x => x.id === mergeForm.source_id)
  return c?.customer_name || '?'
})

function openMerge() {
  mergeForm.source_id = null
  mergeForm.target_id = null
  mergeCustomerOptions.value = []
  mergeDialogVisible.value = true
  // 默认拉一批最近的客户作为初始选项
  searchMergeCustomers('')
}

async function searchMergeCustomers(keyword) {
  mergeSearchLoading.value = true
  try {
    const params = { per_page: 30 }
    if (keyword) params['filter[keyword]'] = keyword
    const res = await getCustomers(params)
    mergeCustomerOptions.value = res?.items || []
  } catch { /* handled */ }
  finally { mergeSearchLoading.value = false }
}

async function submitMerge() {
  if (!mergeForm.source_id || !mergeForm.target_id) {
    ElMessage.warning('请选择源客户和目标客户')
    return
  }
  if (mergeForm.source_id === mergeForm.target_id) {
    ElMessage.warning('源客户和目标客户不能相同')
    return
  }

  const sourceCust = mergeCustomerOptions.value.find(x => x.id === mergeForm.source_id)
  const targetCust = mergeCustomerOptions.value.find(x => x.id === mergeForm.target_id)
  const msg = `确认合并？\n\n源：${sourceCust?.customer_name} (#${sourceCust?.id})\n目标：${targetCust?.customer_name} (#${targetCust?.id})\n\n源客户的所有 IP/订阅/流水/余额都会归到目标客户名下，源客户会被软删除。此操作不可逆。`

  try {
    await ElMessageBox.confirm(msg, '合并确认', {
      type: 'warning',
      confirmButtonText: '确认合并',
      cancelButtonText: '取消',
    })
  } catch { return }

  mergeLoading.value = true
  try {
    const res = await mergeCustomers({
      source_id: mergeForm.source_id,
      target_id: mergeForm.target_id,
    })
    const c = res?.counts || res?.stats || {}
    const rows = [
      ['IP 资产', c.proxy_ips],
      ['订阅', c.subscriptions],
      ['交易流水', c.transactions],
      ['开通订单', c.provision_orders],
      ['支付订单', c.payment_orders],
      ['分配日志', c.ip_assignment_logs ?? c.ip_logs],
      ['审批单', c.provision_approvals],
      ['飞书配置', c.feishu_sync_configs],
      ['特批价迁移', c.customer_special_prices],
      ['特批价冲突(已取更低价)', c.special_price_conflicts],
      ['返佣记录(作为推荐人)', c.referral_commissions_referrer],
      ['返佣记录(作为被推荐人)', c.referral_commissions_referee],
      ['被邀请的下级客户', c.customers_invited_by],
      ['被引荐的下级客户', c.customers_referred_by],
    ].filter(([_, n]) => Number(n) > 0)

    const listHtml = rows.length
      ? rows.map(([k, v]) => `<div>${k}：${v}</div>`).join('')
      : '<div style="color:#999">源客户无业务数据</div>'

    ElMessageBox.alert(
      `<div>合并完成。</div>
      <div style="margin-top:8px;font-size:13px">${listHtml}</div>
      ${res?.new_balance !== undefined
        ? `<div style="margin-top:10px;padding-top:8px;border-top:1px solid #eee;font-size:13px">
             合并后余额：<b>¥${Number(res.new_balance).toFixed(2)}</b><br>
             累计消费：¥${Number(res.new_total_spent || 0).toFixed(2)}
             ${res.new_vip_tier ? `<br>VIP等级：${res.new_vip_tier}` : ''}
           </div>` : ''}`,
      '合并成功',
      { dangerouslyUseHTMLString: true, type: 'success' }
    )
    mergeDialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { mergeLoading.value = false }
}

async function previewMerge() {
  if (!mergeForm.source_id || !mergeForm.target_id) {
    ElMessage.warning('请先选择源和目标客户')
    return
  }
  if (mergeForm.source_id === mergeForm.target_id) {
    ElMessage.warning('源和目标不能相同'); return
  }
  try {
    const { data } = await request.post('/customers/merge-preview', {
      source_id: mergeForm.source_id,
      target_id: mergeForm.target_id,
    })
    const c = data.counts
    const rows = [
      ['IP 资产', c.proxy_ips],
      ['订阅', c.subscriptions],
      ['交易流水', c.transactions],
      ['开通订单', c.provision_orders],
      ['支付订单', c.payment_orders],
      ['分配日志', c.ip_assignment_logs],
      ['审批单', c.provision_approvals],
      ['飞书配置', c.feishu_sync_configs],
      ['特批价', c.customer_special_prices],
      ['返佣记录', c.referral_commissions],
      ['被邀请下级', c.invited_customers],
      ['被引荐下级', c.referred_customers],
    ].filter(([_, n]) => Number(n) > 0)

    const listHtml = rows.length
      ? rows.map(([k, v]) => `<div>${k}：${v}</div>`).join('')
      : '<div style="color:#999">源客户无业务数据</div>'

    const conflicts = data.special_price_conflicts || []
    const conflictHtml = conflicts.length
      ? `<div style="margin-top:10px;padding:8px 10px;background:#FEF0F0;border:1px solid #FDE2E2;border-radius:6px;font-size:13px">
           <div style="color:#F56C6C;font-weight:600;margin-bottom:4px">特批价冲突（${conflicts.length} 条）— 合并时将保留更低价</div>
           ${conflicts.map(cf => {
             const loc = [cf.country_code, cf.area_code, cf.city_code, cf.product_id].filter(Boolean).join(' / ') || '通用'
             return `<div style="margin-top:2px">${loc}：源 ¥${cf.source_price.toFixed(2)} vs 目标 ¥${cf.target_price.toFixed(2)} → 保留 ¥${Math.min(cf.source_price, cf.target_price).toFixed(2)}</div>`
           }).join('')}
         </div>`
      : ''

    ElMessageBox.alert(
      `<div><b>合并预览</b></div>
      <div style="margin-top:8px;font-size:13px;padding:8px 10px;background:#fafafa;border-radius:6px">
        <div><b>源</b> #${data.source.id} ${data.source.customer_name} · 余额 ¥${data.source.balance.toFixed(2)} · 消费 ¥${data.source.total_spent.toFixed(2)}</div>
        <div style="margin-top:4px"><b>目标</b> #${data.target.id} ${data.target.customer_name} · 余额 ¥${data.target.balance.toFixed(2)} · 消费 ¥${data.target.total_spent.toFixed(2)}</div>
      </div>
      <div style="margin-top:10px;font-size:13px">将迁移：</div>
      <div style="margin-top:6px;font-size:13px">${listHtml}</div>
      ${conflictHtml}
      <div style="margin-top:10px;padding-top:8px;border-top:1px solid #eee;font-size:13px">
        合并后目标余额：<b style="color:#E8913A">¥${data.preview.new_balance.toFixed(2)}</b><br>
        合并后返佣余额：¥${data.preview.new_commission_balance.toFixed(2)}<br>
        合并后累计消费：¥${data.preview.new_total_spent.toFixed(2)}
      </div>`,
      '预览',
      { dangerouslyUseHTMLString: true, type: 'info', confirmButtonText: '关闭' }
    )
  } catch { /* handled */ }
}

// ========== Impersonate ==========
async function handleImpersonate(row) {
  try {
    await ElMessageBox.confirm(
      `以「${row.customer_name}」的身份登录客户面板？\n\n将在新标签页打开客户面板，token 有效期 2 小时。`,
      '模拟登录',
      { type: 'info', confirmButtonText: '进入客户面板', cancelButtonText: '取消' }
    )
  } catch { return }

  try {
    const res = await impersonateCustomer(row.id)
    if (!res?.token) {
      ElMessage.error('未返回 token')
      return
    }

    // 构造客户端 URL，带上 token 参数，客户端会检测并自动登录
    const customerUrl = (window.__CUSTOMER_PORTAL_URL || import.meta.env.VITE_CUSTOMER_PORTAL_URL || 'https://user.sunipip.com')
      + '/login?impersonate=' + encodeURIComponent(res.token)
      + '&name=' + encodeURIComponent(res.customer_name || '')

    window.open(customerUrl, '_blank')
    ElMessage.success(`已在新标签页打开「${res.customer_name}」的客户面板`)
  } catch { /* handled */ }
}

const staffFilterOptions = ref([])

onMounted(async () => {
  fetchData()
  try {
    const res = await getUsers({ per_page: 100 })
    staffFilterOptions.value = res?.items || res || []
  } catch {}
})
</script>

<style lang="scss" scoped>
.customer-list {
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

  .text-danger {
    color: #f56c6c;
  }
}
.hint {
  font-size: 12px;
  color: #909399;
  margin-top: 4px;
}
.merge-preview {
  margin-top: 8px;
  padding: 10px 14px;
  background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
  border: 1px solid #F5D9B5;
  border-radius: 6px;
  font-size: 13px;
  color: #4A5568;
}
.sales-sync-commission {
  .commission-preview {
    margin-top: 8px;
    padding: 12px 14px;
    background: linear-gradient(135deg, #f0f9eb, #e1f3d8);
    border: 1px solid #c2e7b0;
    border-radius: 8px;
  }
  .commission-summary {
    font-size: 14px;
    color: #4A5568;
    .amount {
      font-size: 18px;
      font-weight: 700;
      color: #67C23A;
    }
  }
}

// ===== 手机端适配 =====
@media (max-width: 768px) {
  .customer-list {
    .page-title {
      font-size: 17px;
      margin-bottom: 10px;
    }

    // 表格：隐藏次要列
    // 列顺序: 1-ID, 2-公司/名称, 3-账号, 4-手机, 5-余额, 6-业务归属, 7-使用邀请码, 8-中转, 9-状态, 10-IP数量, 11-操作(fixed)
    // 手机保留: 2, 5 + fixed 操作列
    :deep(.el-table__body-wrapper) {
      .el-table__row > td.el-table__cell:nth-child(1),
      .el-table__row > td.el-table__cell:nth-child(3),
      .el-table__row > td.el-table__cell:nth-child(4),
      .el-table__row > td.el-table__cell:nth-child(6),
      .el-table__row > td.el-table__cell:nth-child(7),
      .el-table__row > td.el-table__cell:nth-child(8),
      .el-table__row > td.el-table__cell:nth-child(9),
      .el-table__row > td.el-table__cell:nth-child(10) {
        display: none;
      }
    }
    :deep(.el-table__header-wrapper) {
      thead tr > th.el-table__cell:nth-child(1),
      thead tr > th.el-table__cell:nth-child(3),
      thead tr > th.el-table__cell:nth-child(4),
      thead tr > th.el-table__cell:nth-child(6),
      thead tr > th.el-table__cell:nth-child(7),
      thead tr > th.el-table__cell:nth-child(8),
      thead tr > th.el-table__cell:nth-child(9),
      thead tr > th.el-table__cell:nth-child(10) {
        display: none;
      }
    }

    // 操作列按钮缩小
    :deep(.el-table .el-button) {
      padding: 2px 4px !important;
      font-size: 12px !important;
    }

    // 操作列更紧凑
    :deep(.el-table__fixed-right) {
      .el-button + .el-button {
        margin-left: 2px;
      }
    }

    .pagination-wrap {
      justify-content: center;
    }
  }
}
</style>
