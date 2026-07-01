<template>
  <div class="test-pool-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">测试 IP 池</h2>
        <p class="page-desc">客户不再使用但未到期的 IP，可临时分配给其他客户测试（含转发）。测试结束回收，到期自动作废。</p>
      </div>
    </div>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe @selection-change="onSelect">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="IP:端口" min-width="170">
          <template #default="{ row }">
            <span class="mono">{{ row.ip_address }}:{{ row.port }}</span>
          </template>
        </el-table-column>
        <el-table-column label="资产名" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.asset_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="地区" width="80">
          <template #default="{ row }">{{ row.country_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="当前状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.status === 'assigned'" type="warning" size="small">测试中</el-tag>
            <el-tag v-else type="success" size="small">可分配</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="当前客户" width="120">
          <template #default="{ row }">
            <span v-if="row.assigned_customer" style="color:#E8913A;font-weight:500">{{ row.assigned_customer.customer_name }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="上游到期" width="110">
          <template #default="{ row }">
            <span :style="{ color: isExpired(row.upstream_expires_at) ? '#F56C6C' : '' }">
              {{ row.upstream_expires_at ? dayjs(row.upstream_expires_at).format('YYYY-MM-DD') : '-' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="加入时间" width="110">
          <template #default="{ row }">{{ row.test_pool_added_at ? dayjs(row.test_pool_added_at).format('MM-DD HH:mm') : '-' }}</template>
        </el-table-column>
        <el-table-column label="原因" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.test_pool_reason || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="180" align="center" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status === 'available'" type="success" link size="small" @click="openAssign(row)">分配测试</el-button>
            <el-button v-if="row.status === 'assigned'" type="warning" link size="small" @click="handleUnassign(row)">回收</el-button>
            <el-button type="danger" link size="small" @click="handleRemoveSingle(row)">作废</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="selected.length" class="batch-bar">
        <span>已选 <strong>{{ selected.length }}</strong> 条</span>
        <el-button type="danger" size="small" @click="handleBatchRemove">批量作废</el-button>
      </div>

      <div class="pagination-wrap">
        <el-pagination v-model:current-page="pagination.page" v-model:page-size="pagination.per_page"
          :total="pagination.total" :page-sizes="[20, 50]" layout="total, sizes, prev, pager, next"
          @size-change="fetchData" @current-change="fetchData" />
      </div>
    </el-card>

    <!-- Assign Dialog -->
    <el-dialog v-model="assignVisible" title="分配测试 IP" width="520px" :close-on-click-modal="false">
      <el-form :model="assignForm" label-width="100px">
        <el-form-item label="IP">
          <el-input :value="assignTarget ? `${assignTarget.asset_name} (${assignTarget.ip_address}:${assignTarget.port})` : ''" disabled />
        </el-form-item>
        <el-form-item label="测试客户" required>
          <el-select v-model="assignForm.customer_id" filterable remote reserve-keyword
            placeholder="搜索客户" :remote-method="searchCustomers" :loading="customerLoading" style="width: 100%">
            <el-option v-for="c in customerOptions" :key="c.id" :label="`#${c.id} ${c.customer_name}`" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="测试天数">
          <el-input-number v-model="assignForm.duration_days" :min="1" :max="30" />
          <span style="margin-left:8px;font-size:12px;color:#909399">天（免费测试，到期自动回收）</span>
        </el-form-item>
        <el-form-item label="创建转发">
          <el-switch v-model="assignForm.need_forward" />
        </el-form-item>
        <template v-if="assignForm.need_forward">
          <el-form-item label="转发设备组">
            <el-select v-model="assignForm.forward.device_group_id" filterable placeholder="选择设备组" style="width: 100%">
              <el-option v-for="g in deviceGroups" :key="g.id" :label="g.name" :value="g.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="限速(Mbps)">
            <el-input-number v-model="assignForm.forward.speed_limit_mbps" :min="0" :max="10000" />
            <span style="margin-left:8px;font-size:12px;color:#909399">0 = 不限速</span>
          </el-form-item>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="assignVisible = false">取消</el-button>
        <el-button type="primary" :loading="assignLoading" :disabled="!assignForm.customer_id" @click="submitAssign">确认分配</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import { getTestPoolIps, batchRemoveFromTestPool, testPoolAssign, testPoolUnassign } from '@/api/proxyIps'
import { getCustomers } from '@/api/customers'
import { getNyEnabledDeviceGroups } from '@/api/nyPanels'

const loading = ref(false)
const tableData = ref([])
const selected = ref([])
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

function isExpired(d) { return d && dayjs(d).isBefore(dayjs()) }
function onSelect(rows) { selected.value = rows }

async function fetchData() {
  loading.value = true
  try {
    const res = await getTestPoolIps({ page: pagination.page, per_page: pagination.per_page })
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch {} finally { loading.value = false }
}

// ===== Assign =====
const assignVisible = ref(false)
const assignLoading = ref(false)
const assignTarget = ref(null)
const assignForm = reactive({
  customer_id: null,
  duration_days: 7,
  need_forward: false,
  forward: { device_group_id: null, speed_limit_mbps: 0 },
})
const customerOptions = ref([])
const customerLoading = ref(false)
const deviceGroups = ref([])

function openAssign(row) {
  assignTarget.value = row
  assignForm.customer_id = null
  assignForm.duration_days = 7
  assignForm.need_forward = false
  assignForm.forward = { device_group_id: null, speed_limit_mbps: 0 }
  searchCustomers('')
  loadDeviceGroups()
  assignVisible.value = true
}

async function searchCustomers(kw) {
  customerLoading.value = true
  try {
    const params = { per_page: 30 }
    if (kw) params['filter[keyword]'] = kw
    customerOptions.value = (await getCustomers(params))?.items || []
  } catch {} finally { customerLoading.value = false }
}

async function loadDeviceGroups() {
  try { deviceGroups.value = (await getNyEnabledDeviceGroups()) || [] } catch {}
}

async function submitAssign() {
  if (!assignForm.customer_id) return
  assignLoading.value = true
  try {
    const payload = {
      proxy_ip_id: assignTarget.value.id,
      customer_id: assignForm.customer_id,
      duration_days: assignForm.duration_days,
    }
    if (assignForm.need_forward && assignForm.forward.device_group_id) {
      payload.forward = { ...assignForm.forward }
    }
    await testPoolAssign(payload)
    ElMessage.success('测试 IP 已分配')
    assignVisible.value = false
    fetchData()
  } catch {} finally { assignLoading.value = false }
}

// ===== Unassign =====
async function handleUnassign(row) {
  try {
    await ElMessageBox.confirm(`回收测试 IP「${row.ip_address}」？将解绑客户并删除转发。`, '回收确认', { type: 'warning' })
    await testPoolUnassign({ proxy_ip_id: row.id })
    ElMessage.success('已回收')
    fetchData()
  } catch {}
}

// ===== Remove (invalidate) =====
async function handleRemoveSingle(row) {
  try {
    await ElMessageBox.confirm(`作废 IP「${row.ip_address}」？将从测试池移除并标记失效。`, '确认', { type: 'warning' })
    await batchRemoveFromTestPool({ ids: [row.id] })
    ElMessage.success('已作废')
    fetchData()
  } catch {}
}

async function handleBatchRemove() {
  if (!selected.value.length) return
  try {
    await ElMessageBox.confirm(`批量作废 ${selected.value.length} 条 IP？`, '确认', { type: 'warning' })
    await batchRemoveFromTestPool({ ids: selected.value.map(r => r.id) })
    ElMessage.success('已作废')
    selected.value = []
    fetchData()
  } catch {}
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.test-pool-page {
  .page-header { margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .mono { font-family: 'SF Mono', Consolas, monospace; font-size: 13px; color: #4A5568; }
  .batch-bar {
    display: flex; align-items: center; gap: 12px; margin-top: 12px;
    padding: 10px 14px; background: #FEF2F2; border: 1px solid #FECACA; border-radius: 6px;
    font-size: 13px; strong { color: #DC2626; }
  }
}
</style>
