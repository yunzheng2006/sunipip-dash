<template>
  <div class="proxy-ip-list">
    <div class="page-header">
      <h2 class="page-title">IP资产管理</h2>
      <div class="header-actions">
        <el-button type="primary" @click="openBatchAdd"><el-icon><Plus /></el-icon> 添加IP</el-button>
        <el-button @click="$router.push('/proxy-ips/import')"><el-icon><Upload /></el-icon> CSV导入</el-button>
      </div>
    </div>

    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="状态">
          <el-select v-model="searchForm.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="可用" value="available" />
            <el-option label="已分配" value="assigned" />
            <el-option label="已过期" value="expired" />
            <el-option label="已停用" value="disabled" />
            <el-option label="已释放" value="released" />
          </el-select>
        </el-form-item>
        <el-form-item label="资产组">
          <el-select v-model="searchForm.asset_group_id" placeholder="全部" clearable filterable style="width: 160px">
            <el-option v-for="g in assetGroupOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="资产名称">
          <el-input v-model="searchForm.asset_name" placeholder="模糊搜索" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="IP地址">
          <el-input v-model="searchForm.ip_address" placeholder="模糊搜索" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="IP归属">
          <el-input v-model="searchForm.source_name" placeholder="如斯帕克" clearable style="width: 120px" />
        </el-form-item>
        <el-form-item label="客户">
          <el-input v-model="searchForm.customer_name" placeholder="客户名" clearable style="width: 120px" />
        </el-form-item>
        <el-form-item label="测试池">
          <el-select v-model="searchForm.is_test_pool" placeholder="全部" clearable style="width: 100px">
            <el-option label="是" value="1" />
            <el-option label="否" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch"><el-icon><Search /></el-icon>搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Batch Action Bar -->
    <transition name="el-fade-in">
      <div v-if="selectedRows.length > 0" class="batch-bar">
        <span class="batch-info">已选 <strong>{{ selectedRows.length }}</strong> 条</span>
        <el-button type="success" size="small" @click="openBatchAssign" :disabled="selectedAvailableCount === 0">
          批量分配 ({{ selectedAvailableCount }})
        </el-button>
        <el-button type="warning" size="small" @click="handleBatchRelease">批量释放</el-button>
        <el-button size="small" @click="openBatchMoveGroup">迁移资产组</el-button>
        <el-button type="danger" size="small" @click="handleBatchDelete" :disabled="selectedAvailableCount === 0">
          批量删除 ({{ selectedAvailableCount }})
        </el-button>
        <el-button size="small" type="info" @click="handleBatchTestPool">加入测试池</el-button>
        <el-button link size="small" @click="clearSelection">取消选择</el-button>
      </div>
    </transition>

    <el-card>
      <el-table ref="tableRef" :data="tableData" v-loading="loading" stripe
        @selection-change="onSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="资产名称" min-width="180">
          <template #default="{ row }">
            <div v-if="editingAssetId === row.id" class="inline-edit">
              <el-input
                v-model="editingAssetName"
                size="small"
                @keyup.enter="saveAssetName(row)"
                @keyup.escape="editingAssetId = null"
                @blur="saveAssetName(row)"
                ref="assetNameInputRef"
                style="width: 100%"
              />
            </div>
            <div v-else class="asset-name-cell" @click="startEditAssetName(row)">
              <span style="font-weight: 500">{{ row.asset_name || '-' }}</span>
              <el-icon class="edit-icon" :size="12"><EditPen /></el-icon>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="IP:端口" min-width="200">
          <template #default="{ row }">
            <div class="mono">{{ row.ip_address }}:{{ row.port }}</div>
            <div v-if="row.active_subscription?.forward_rule?.status === 'active'" class="forward-display">
              <el-icon :size="11"><Share /></el-icon>
              <span class="mono">
                {{ row.active_subscription.forward_rule.device_group?.custom_connect_host
                   || row.active_subscription.forward_rule.device_group?.original_connect_host }}:{{ row.active_subscription.forward_rule.listen_port }}
              </span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="地区" width="100">
          <template #default="{ row }">{{ row.country_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="资产组" width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.asset_group" style="font-size:12px;color:#606266">{{ row.asset_group.name }}</span>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="IP归属" width="100">
          <template #default="{ row }">
            <el-tag size="small" type="info" effect="plain">{{ row.source_name || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="成本" width="80" align="center">
          <template #default="{ row }">
            <span v-if="row.sales_cost != null" style="color: #E6A23C; font-weight: 500">¥{{ Number(row.sales_cost).toFixed(2) }}</span>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="归属客户" min-width="120">
          <template #default="{ row }">
            <span v-if="row.assigned_customer" style="color: #E8913A; font-weight: 500">
              {{ row.assigned_customer.customer_name }}
            </span>
            <span v-else style="color: #C0C4CC">未分配</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="130" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
            <el-tag v-if="row.is_test_pool" type="info" size="small" effect="plain" style="margin-left:4px">测试池</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="到期时间" width="110">
          <template #default="{ row }">
            <span :style="{ color: isExpiringSoon(row.upstream_expires_at) ? '#F56C6C' : '' }">
              {{ formatDate(row.upstream_expires_at) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="250" align="center" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status === 'available'" type="success" link size="small" @click="openAssign(row)">分配</el-button>
            <el-button type="primary" link size="small" @click="$router.push(`/proxy-ips/${row.id}`)">详情</el-button>
            <el-button v-if="row.status !== 'released'" type="warning" link size="small" @click="openRelease(row)">释放</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[20, 50, 100, 200]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- Batch Add Dialog -->
    <el-dialog v-model="batchVisible" title="添加IP资产" width="720px" :close-on-click-modal="false">
      <el-form :model="batchForm" :rules="batchRules" ref="batchFormRef" label-width="110px">
        <el-form-item label="导入模式">
          <el-radio-group v-model="batchForm.mode">
            <el-radio value="single_region">统一地区</el-radio>
            <el-radio value="multi_region">每行带地区</el-radio>
          </el-radio-group>
          <div class="hint" v-if="batchForm.mode === 'single_region'">格式：<code>ip:port:user:pass</code></div>
          <div class="hint" v-else>格式：<code>ip:port:user:pass:US</code>，可一次导入多个地区</div>
        </el-form-item>
        <el-form-item label="资产组" prop="asset_group_id">
          <el-select v-model="batchForm.asset_group_id" filterable placeholder="选择资产组" style="width: 100%">
            <el-option v-for="g in assetGroupOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="IP组">
          <el-select v-model="batchForm.ip_group_id" filterable clearable placeholder="可选" style="width: 100%">
            <el-option v-for="g in ipGroupOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-row :gutter="16" v-if="batchForm.mode === 'single_region'">
          <el-col :span="12">
            <el-form-item label="国家代码" prop="country_code">
              <el-input v-model="batchForm.country_code" placeholder="如 US, BR, JP" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="国家名称">
              <el-input v-model="batchForm.country_name" placeholder="可自动填充" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="IP归属">
              <el-input v-model="batchForm.source_name" placeholder="如 斯帕克, 涛哥" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="上游到期">
              <el-date-picker v-model="batchForm.upstream_expires_at" type="date" value-format="YYYY-MM-DD" placeholder="可选" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="销售成本">
              <el-input-number v-model="batchForm.sales_cost" :min="0" :precision="2" :step="1" placeholder="每月成本" style="width: 100%" :disabled="!batchForm.has_sales_cost" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label=" ">
              <el-checkbox v-model="batchForm.has_sales_cost">有销售成本</el-checkbox>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="IP列表" prop="lines">
          <el-input v-model="batchForm.lines" type="textarea" :rows="10"
            :placeholder="batchForm.mode === 'single_region'
              ? '一行一个: ip:port:user:pass\n\n110.44.171.162:9553:Y4M4V7V0h9J3:f9G2k3O1o7L5'
              : '一行一个: ip:port:user:pass:CC\n\n110.44.171.162:9553:Y4M4V7V0h9J3:f9G2k3O1o7L5:BR\n103.87.29.55:8080:admin:pass123:US'" />
          <div class="hint">每行一个，最多 500 条。地址+端口+账号+密码完全相同才算重复（自动跳过）；密码不同视为独立资产。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="batchVisible = false">取消</el-button>
        <el-button type="primary" :loading="batchLoading" @click="submitBatch">添加 ({{ lineCount }} 条)</el-button>
      </template>
    </el-dialog>

    <!-- Assign Dialog (single + batch) -->
    <el-dialog v-model="assignVisible" :title="assignTarget ? '分配IP给客户' : `批量分配 ${assignBatchIds.length} 条IP`" width="520px" :close-on-click-modal="false">
      <el-form :model="assignForm" :rules="assignRules" ref="assignFormRef" label-width="90px">
        <el-form-item v-if="assignTarget" label="IP资产">
          <el-input :value="`${assignTarget.asset_name || ''} (${assignTarget.ip_address}:${assignTarget.port})`" disabled />
        </el-form-item>
        <el-form-item v-else label="选中IP">
          <el-tag>{{ assignBatchIds.length }} 条可用IP</el-tag>
        </el-form-item>
        <el-form-item label="客户" prop="customer_id">
          <el-select v-model="assignForm.customer_id" filterable remote reserve-keyword
            placeholder="搜索客户" :remote-method="searchCustomers" :loading="customerLoading" style="width: 100%">
            <el-option v-for="c in customerOptions" :key="c.id" :label="`#${c.id} ${c.customer_name}`" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="价格" prop="price">
              <el-input-number v-model="assignForm.price" :min="0" :precision="2" :step="10" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="时长" prop="duration">
              <el-input-number v-model="assignForm.duration" :min="1" :max="36" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="单位" prop="unit">
              <el-select v-model="assignForm.unit" style="width: 100%">
                <el-option label="天" :value="1" />
                <el-option label="周" :value="2" />
                <el-option label="月" :value="3" />
                <el-option label="年" :value="4" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="扣余额">
          <el-checkbox v-model="assignForm.deduct_balance">从客户余额扣款</el-checkbox>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="assignVisible = false">取消</el-button>
        <el-button type="primary" :loading="assignLoading" @click="submitAssign">确认分配</el-button>
      </template>
    </el-dialog>

    <!-- Batch Move Group Dialog -->
    <el-dialog v-model="moveGroupVisible" title="迁移资产组" width="480px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="选中IP">
          <el-tag>{{ selectedRows.length }} 条</el-tag>
        </el-form-item>
        <el-form-item label="目标资产组" required>
          <el-select v-model="moveGroupTarget" filterable placeholder="选择目标资产组" style="width: 100%">
            <el-option v-for="g in assetGroupOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="moveGroupVisible = false">取消</el-button>
        <el-button type="primary" :loading="moveGroupLoading" :disabled="!moveGroupTarget" @click="submitMoveGroup">确认迁移</el-button>
      </template>
    </el-dialog>

    <!-- Release Dialog -->
    <el-dialog v-model="releaseVisible" title="释放IP资产" width="500px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        释放后该IP将不再出现在可分配资源池，历史记录保留。此操作不可逆。
      </el-alert>
      <el-form :model="releaseForm" label-width="100px">
        <el-form-item label="资产名称"><el-input :value="releaseTarget?.asset_name" disabled /></el-form-item>
        <el-form-item label="IP地址"><el-input :value="releaseTarget ? `${releaseTarget.ip_address}:${releaseTarget.port}` : ''" disabled /></el-form-item>
        <el-form-item label="释放原因"><el-input v-model="releaseForm.reason" type="textarea" :rows="3" placeholder="选填" /></el-form-item>
        <el-form-item v-if="releaseTarget?.source_name === '斯帕克' && releaseTarget?.spark_instance_id" label="Spark释放">
          <el-switch v-model="releaseForm.auto_release_spark" />
          <span style="margin-left: 8px; font-size: 12px; color: #909399">同时调 Spark API 释放</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="releaseVisible = false">取消</el-button>
        <el-button type="danger" :loading="releaseLoading" @click="submitRelease">确认释放</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Upload, Search, Share, EditPen } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import {
  getProxyIps, deleteProxyIp, releaseProxyIp, batchCreateProxyIps, assignProxyIp,
  batchAssignProxyIps, batchReleaseProxyIps, batchDeleteProxyIps, batchMoveGroupProxyIps,
  batchAddToTestPool, updateProxyIp,
} from '@/api/proxyIps'
import { getAllAssetGroups } from '@/api/assetGroups'
import { getAllIpGroups } from '@/api/ipGroups'
import { getCustomers } from '@/api/customers'

const loading = ref(false)
const tableRef = ref()
const tableData = ref([])
const selectedRows = ref([])
const searchForm = reactive({ status: '', asset_name: '', ip_address: '', source_name: '', asset_group_id: null, customer_name: '', is_test_pool: '' })
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

const assetGroupOptions = ref([])
const ipGroupOptions = ref([])

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function isExpiringSoon(d) { return d && dayjs(d).diff(dayjs(), 'day') <= 7 && dayjs(d).isAfter(dayjs()) }
function statusTag(s) { return { available: 'success', assigned: 'warning', expired: 'danger', disabled: 'info', released: 'info' }[s] || 'info' }
function statusLabel(s) { return { available: '可用', assigned: '已分配', expired: '已过期', disabled: '已停用', released: '已释放' }[s] || s }

const selectedAvailableCount = computed(() => selectedRows.value.filter(r => r.status === 'available').length)

// ===== 资产名称行内编辑 =====
const editingAssetId = ref(null)
const editingAssetName = ref('')
const assetNameInputRef = ref()

function startEditAssetName(row) {
  editingAssetId.value = row.id
  editingAssetName.value = row.asset_name || ''
  nextTick(() => assetNameInputRef.value?.focus())
}

async function saveAssetName(row) {
  if (editingAssetId.value !== row.id) return
  const newName = editingAssetName.value.trim()
  editingAssetId.value = null
  if (newName === (row.asset_name || '')) return
  try {
    await updateProxyIp(row.id, { asset_name: newName })
    row.asset_name = newName
    ElMessage.success('资产名称已更新')
  } catch {}
}

function onSelectionChange(rows) { selectedRows.value = rows }
function clearSelection() { tableRef.value?.clearSelection() }

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.status) params['filter[status]'] = searchForm.status
    if (searchForm.asset_name) params['filter[asset_name]'] = searchForm.asset_name
    if (searchForm.ip_address) params['filter[ip_address]'] = searchForm.ip_address
    if (searchForm.source_name) params['filter[source_name]'] = searchForm.source_name
    if (searchForm.asset_group_id) params['filter[asset_group_id]'] = searchForm.asset_group_id
    if (searchForm.customer_name) params['filter[customer_name]'] = searchForm.customer_name
    if (searchForm.is_test_pool !== '') params['filter[is_test_pool]'] = searchForm.is_test_pool
    const res = await getProxyIps(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch {}
  finally { loading.value = false }
}

async function loadOptions() {
  try { assetGroupOptions.value = (await getAllAssetGroups()) || [] } catch {}
  try { ipGroupOptions.value = (await getAllIpGroups()) || [] } catch {}
}

function handleSearch() { pagination.page = 1; fetchData() }
function handleReset() {
  Object.keys(searchForm).forEach(k => searchForm[k] = k === 'asset_group_id' ? null : (k === 'is_test_pool' ? '' : ''))
  pagination.page = 1; fetchData()
}

// ===== Batch Add =====
const batchVisible = ref(false)
const batchLoading = ref(false)
const batchFormRef = ref()
const batchForm = reactive({ mode: 'single_region', lines: '', asset_group_id: null, ip_group_id: null, country_code: '', country_name: '', source_name: '', upstream_expires_at: null, sales_cost: null, has_sales_cost: false })
const batchRules = {
  asset_group_id: [{ required: true, message: '请选择资产组', trigger: 'change' }],
  country_code: [{ validator: (r, v, cb) => { (batchForm.mode === 'single_region' && !v) ? cb(new Error('统一地区模式下必填')) : cb() }, trigger: 'blur' }],
  lines: [{ required: true, message: '请输入IP列表', trigger: 'blur' }],
}
const lineCount = computed(() => batchForm.lines ? batchForm.lines.split('\n').filter(l => l.trim()).length : 0)

function openBatchAdd() {
  Object.assign(batchForm, { mode: 'single_region', lines: '', asset_group_id: null, ip_group_id: null, country_code: '', country_name: '', source_name: '', upstream_expires_at: null, sales_cost: null, has_sales_cost: false })
  batchVisible.value = true
}

async function submitBatch() {
  if (!(await batchFormRef.value.validate().catch(() => false))) return
  batchLoading.value = true
  try {
    const payload = { ...batchForm }
    if (!payload.has_sales_cost) { delete payload.sales_cost }
    delete payload.has_sales_cost
    const res = await batchCreateProxyIps(payload)
    ElMessageBox.alert(
      `成功 ${res?.created || 0} 条` + (res?.skipped ? `，跳过 ${res.skipped} 条重复` : '') + (res?.errors?.length ? `\n\n错误：\n${res.errors.join('\n')}` : ''),
      '添加结果', { type: res?.errors?.length ? 'warning' : 'success' }
    )
    batchVisible.value = false; fetchData()
  } catch (e) { ElMessage.error(e?.response?.data?.message || '添加失败') } finally { batchLoading.value = false }
}

// ===== Assign (single + batch) =====
const assignVisible = ref(false)
const assignLoading = ref(false)
const assignFormRef = ref()
const assignTarget = ref(null) // null = batch mode
const assignBatchIds = ref([])
const assignForm = reactive({ customer_id: null, price: 0, duration: 1, unit: 3, deduct_balance: true })
const assignRules = {
  customer_id: [{ required: true, message: '请选择客户', trigger: 'change' }],
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }],
  duration: [{ required: true, message: '请输入时长', trigger: 'blur' }],
  unit: [{ required: true, message: '请选择单位', trigger: 'change' }],
}
const customerOptions = ref([])
const customerLoading = ref(false)

function openAssign(row) {
  assignTarget.value = row
  assignBatchIds.value = []
  Object.assign(assignForm, { customer_id: null, price: 0, duration: 1, unit: 3, deduct_balance: true })
  searchCustomers('')
  assignVisible.value = true
}

function openBatchAssign() {
  const ids = selectedRows.value.filter(r => r.status === 'available').map(r => r.id)
  if (!ids.length) { ElMessage.warning('没有可分配的IP（仅可用状态可分配）'); return }
  assignTarget.value = null
  assignBatchIds.value = ids
  Object.assign(assignForm, { customer_id: null, price: 0, duration: 1, unit: 3, deduct_balance: true })
  searchCustomers('')
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

async function submitAssign() {
  if (!(await assignFormRef.value.validate().catch(() => false))) return
  assignLoading.value = true
  try {
    if (assignTarget.value) {
      await assignProxyIp(assignTarget.value.id, { ...assignForm })
      ElMessage.success('分配成功')
    } else {
      const res = await batchAssignProxyIps({ ids: assignBatchIds.value, ...assignForm })
      const msg = `成功 ${res?.succeeded || 0} 条` + (res?.failed?.length ? `，失败 ${res.failed.length} 条` : '')
      if (res?.failed?.length) {
        ElMessageBox.alert(msg + '\n\n' + res.failed.join('\n'), '批量分配结果', { type: 'warning' })
      } else {
        ElMessage.success(msg)
      }
    }
    assignVisible.value = false; clearSelection(); fetchData()
  } catch {} finally { assignLoading.value = false }
}

// ===== Batch Release =====
async function handleBatchRelease() {
  const ids = selectedRows.value.filter(r => r.status !== 'released').map(r => r.id)
  if (!ids.length) { ElMessage.warning('没有可释放的IP'); return }
  try {
    await ElMessageBox.confirm(`确定释放选中的 ${ids.length} 条IP？此操作不可逆。`, '批量释放', { type: 'warning' })
  } catch { return }
  try {
    const res = await batchReleaseProxyIps({ ids, reason: '批量释放' })
    ElMessage.success(`成功释放 ${res?.succeeded || 0} 条`)
    clearSelection(); fetchData()
  } catch {}
}

// ===== Batch Delete =====
async function handleBatchDelete() {
  const ids = selectedRows.value.filter(r => r.status === 'available').map(r => r.id)
  if (!ids.length) { ElMessage.warning('没有可删除的IP（仅可用状态可删除）'); return }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条可用IP？`, '批量删除', { type: 'warning' })
  } catch { return }
  try {
    const res = await batchDeleteProxyIps({ ids })
    ElMessage.success(`成功删除 ${res?.succeeded || 0} 条`)
    clearSelection(); fetchData()
  } catch {}
}

// ===== Batch Add to Test Pool =====
async function handleBatchTestPool() {
  const ids = selectedRows.value.filter(r => r.status === 'assigned').map(r => r.id)
  if (!ids.length) { ElMessage.warning('请选择已分配的IP'); return }

  let reason = ''
  try {
    const { value } = await ElMessageBox.prompt('加入测试池原因（选填）', '加入测试池', {
      confirmButtonText: '确认',
      cancelButtonText: '取消',
      inputPlaceholder: '如：客户不再使用',
    })
    reason = value || ''
  } catch { return }

  try {
    const res = await batchAddToTestPool({ ids, reason })
    ElMessage.success(res?.message || '已加入测试池')
    clearSelection(); fetchData()
  } catch {}
}

// ===== Batch Move Group =====
const moveGroupVisible = ref(false)
const moveGroupLoading = ref(false)
const moveGroupTarget = ref(null)

function openBatchMoveGroup() {
  moveGroupTarget.value = null
  moveGroupVisible.value = true
}

async function submitMoveGroup() {
  if (!moveGroupTarget.value) return
  moveGroupLoading.value = true
  try {
    const ids = selectedRows.value.map(r => r.id)
    const res = await batchMoveGroupProxyIps({ ids, asset_group_id: moveGroupTarget.value })
    ElMessage.success(res?.message || '迁移成功')
    moveGroupVisible.value = false; clearSelection(); fetchData()
  } catch {} finally { moveGroupLoading.value = false }
}

// ===== Single Release =====
const releaseVisible = ref(false)
const releaseLoading = ref(false)
const releaseTarget = ref(null)
const releaseForm = reactive({ reason: '', auto_release_spark: true })

function openRelease(row) {
  releaseTarget.value = row; releaseForm.reason = ''; releaseForm.auto_release_spark = true
  releaseVisible.value = true
}

async function submitRelease() {
  releaseLoading.value = true
  try {
    await releaseProxyIp(releaseTarget.value.id, { ...releaseForm })
    ElMessage.success('IP 已释放'); releaseVisible.value = false; fetchData()
  } catch (e) { ElMessage.error(e?.response?.data?.message || '释放失败') } finally { releaseLoading.value = false }
}

// ===== Single Delete =====
async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除IP「${row.ip_address}」？`, '确认', { type: 'warning' })
    await deleteProxyIp(row.id); ElMessage.success('已删除'); fetchData()
  } catch {}
}

onMounted(() => {
  const route = useRoute()
  if (route.query['filter[asset_group_id]']) searchForm.asset_group_id = Number(route.query['filter[asset_group_id]'])
  fetchData(); loadOptions()
})
</script>

<style lang="scss" scoped>
.proxy-ip-list {
  .page-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .header-actions { display: flex; gap: 8px; }
  }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }
  .batch-bar {
    background: #ECF5FF; border: 1px solid #B3D8FF; border-radius: 6px;
    padding: 10px 16px; margin-bottom: 12px;
    display: flex; align-items: center; gap: 10px;
    .batch-info { font-size: 13px; color: #409EFF; margin-right: 6px; strong { font-size: 15px; } }
  }
  .asset-name-cell {
    display: flex; align-items: center; gap: 4px; cursor: pointer;
    .edit-icon { color: #C0C4CC; flex-shrink: 0; }
    &:hover .edit-icon { color: #409EFF; }
  }
  .inline-edit { width: 100%; }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 13px; color: #4A5568; }
  .hint { font-size: 12px; color: #909399; margin-top: 4px; code { background: #f4f4f5; padding: 1px 4px; border-radius: 3px; } }
  .forward-display {
    margin-top: 3px; font-size: 11px; color: #E8913A;
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 6px; background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
    border-radius: 4px; border: 1px solid #F5D9B5;
    .mono { color: #E8913A; font-weight: 600; font-size: 11px; }
  }
}

// ===== 手机端适配 =====
@media (max-width: 768px) {
  .proxy-ip-list {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      gap: 10px;
      margin-bottom: 12px;
      .page-title { font-size: 17px; }
      .header-actions {
        .el-button { flex: 1; font-size: 12px; }
      }
    }

    .batch-bar {
      flex-wrap: wrap;
      padding: 8px 10px;
      gap: 6px;
      .batch-info { flex: 1 1 100%; font-size: 12px; }
      .el-button { font-size: 11px; padding: 4px 8px; }
    }

    // 表格：隐藏次要列
    // 列顺序（有selection）: 1-sel, 2-ID, 3-资产名称, 4-IP:端口, 5-地区, 6-资产组, 7-IP归属, 8-归属客户, 9-状态, 10-到期, 11-操作(fixed)
    // 手机保留: 3, 9 + fixed 操作列
    :deep(.el-table__body-wrapper) {
      .el-table__row > td.el-table__cell:nth-child(1),
      .el-table__row > td.el-table__cell:nth-child(2),
      .el-table__row > td.el-table__cell:nth-child(4),
      .el-table__row > td.el-table__cell:nth-child(5),
      .el-table__row > td.el-table__cell:nth-child(6),
      .el-table__row > td.el-table__cell:nth-child(7),
      .el-table__row > td.el-table__cell:nth-child(8),
      .el-table__row > td.el-table__cell:nth-child(10) {
        display: none;
      }
    }
    :deep(.el-table__header-wrapper) {
      thead tr > th.el-table__cell:nth-child(1),
      thead tr > th.el-table__cell:nth-child(2),
      thead tr > th.el-table__cell:nth-child(4),
      thead tr > th.el-table__cell:nth-child(5),
      thead tr > th.el-table__cell:nth-child(6),
      thead tr > th.el-table__cell:nth-child(7),
      thead tr > th.el-table__cell:nth-child(8),
      thead tr > th.el-table__cell:nth-child(10) {
        display: none;
      }
    }

    :deep(.el-table .el-button) {
      padding: 2px 4px !important;
      font-size: 12px !important;
    }

    .pagination-wrap {
      justify-content: center;
    }
  }
}
</style>
