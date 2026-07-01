<template>
  <div class="page-container">
    <div class="page-header">
      <div>
        <h2>产品目录</h2>
        <p class="text-muted">管理路由器型号、AP 型号与套餐搭配</p>
      </div>
    </div>

    <el-tabs v-model="activeTab" @tab-change="onTabChange">
      <!-- ===== 路由器型号 ===== -->
      <el-tab-pane label="路由器型号" name="router_models">
        <div class="tab-toolbar">
          <el-button type="primary" size="small" @click="openRouterModelDialog()">添加型号</el-button>
        </div>
        <el-table :data="routerModels" v-loading="rmLoading" stripe>
          <el-table-column prop="id" label="ID" width="60" />
          <el-table-column prop="name" label="名称" min-width="140" />
          <el-table-column prop="cpu" label="CPU" width="120" />
          <el-table-column prop="ram" label="内存" width="80" />
          <el-table-column prop="storage" label="存储" width="80" />
          <el-table-column prop="ports" label="网口数" width="80" align="center" />
          <el-table-column label="进货价" width="90" align="right">
            <template #default="{ row }">{{ row.cost_price != null ? `¥${row.cost_price}` : '-' }}</template>
          </el-table-column>
          <el-table-column label="售价" width="90" align="right">
            <template #default="{ row }">{{ row.sale_price != null ? `¥${row.sale_price}` : '-' }}</template>
          </el-table-column>
          <el-table-column label="设备数" width="80" align="center">
            <template #default="{ row }">
              <el-tag size="small">{{ row.devices_count ?? 0 }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="80" align="center">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="150" fixed="right">
            <template #default="{ row }">
              <el-button size="small" link @click="openRouterModelDialog(row)">编辑</el-button>
              <el-button size="small" link type="danger" @click="handleDeleteRouterModel(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <!-- ===== AP 型号 ===== -->
      <el-tab-pane label="AP 型号" name="ap_models">
        <div class="tab-toolbar">
          <el-button type="primary" size="small" @click="openApModelDialog()">添加型号</el-button>
        </div>
        <el-table :data="apModels" v-loading="apLoading" stripe>
          <el-table-column prop="id" label="ID" width="60" />
          <el-table-column prop="name" label="名称" min-width="160" />
          <el-table-column prop="band" label="频段" width="120" />
          <el-table-column label="进货价" width="90" align="right">
            <template #default="{ row }">{{ row.cost_price != null ? `¥${row.cost_price}` : '-' }}</template>
          </el-table-column>
          <el-table-column label="售价" width="90" align="right">
            <template #default="{ row }">{{ row.sale_price != null ? `¥${row.sale_price}` : '-' }}</template>
          </el-table-column>
          <el-table-column label="设备数" width="80" align="center">
            <template #default="{ row }">
              <el-tag size="small">{{ row.devices_count ?? 0 }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="80" align="center">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="150" fixed="right">
            <template #default="{ row }">
              <el-button size="small" link @click="openApModelDialog(row)">编辑</el-button>
              <el-button size="small" link type="danger" @click="handleDeleteApModel(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <!-- ===== 套餐搭配 ===== -->
      <el-tab-pane label="套餐搭配" name="bundles">
        <div class="tab-toolbar">
          <el-button type="primary" size="small" @click="openBundleDialog()">添加套餐</el-button>
        </div>
        <el-table :data="bundles" v-loading="bundleLoading" stripe>
          <el-table-column prop="id" label="ID" width="60" />
          <el-table-column prop="name" label="名称" min-width="160" />
          <el-table-column label="路由器型号" width="160">
            <template #default="{ row }">{{ row.router_model?.name || '-' }}</template>
          </el-table-column>
          <el-table-column label="AP 型号" width="160">
            <template #default="{ row }">{{ row.ap_model?.name || '-' }}</template>
          </el-table-column>
          <el-table-column label="套餐价格" width="100" align="right">
            <template #default="{ row }">{{ row.bundle_price != null ? `¥${row.bundle_price}` : '-' }}</template>
          </el-table-column>
          <el-table-column label="设备数" width="80" align="center">
            <template #default="{ row }">
              <el-tag size="small">{{ row.devices_count ?? 0 }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="80" align="center">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="150" fixed="right">
            <template #default="{ row }">
              <el-button size="small" link @click="openBundleDialog(row)">编辑</el-button>
              <el-button size="small" link type="danger" @click="handleDeleteBundle(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <!-- 路由器型号对话框 -->
    <el-dialog :title="rmEditing ? '编辑路由器型号' : '添加路由器型号'" v-model="rmDialogVisible" width="520px" destroy-on-close>
      <el-form :model="rmForm" label-width="80px">
        <el-form-item label="名称" required>
          <el-input v-model="rmForm.name" placeholder="如 N100 四网口" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="CPU">
              <el-input v-model="rmForm.cpu" placeholder="如 N100" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="内存">
              <el-input v-model="rmForm.ram" placeholder="如 8GB" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="存储">
              <el-input v-model="rmForm.storage" placeholder="如 128GB SSD" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="网口数">
              <el-input-number v-model="rmForm.ports" :min="1" :max="12" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="进货价">
              <el-input-number v-model="rmForm.cost_price" :min="0" :precision="2" :step="10" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="售价">
              <el-input-number v-model="rmForm.sale_price" :min="0" :precision="2" :step="10" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="状态">
          <el-switch v-model="rmForm.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="rmForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="rmDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleRmSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- AP 型号对话框 -->
    <el-dialog :title="apEditing ? '编辑 AP 型号' : '添加 AP 型号'" v-model="apDialogVisible" width="520px" destroy-on-close>
      <el-form :model="apForm" label-width="80px">
        <el-form-item label="名称" required>
          <el-input v-model="apForm.name" placeholder="如 AX3000 双频" />
        </el-form-item>
        <el-form-item label="频段">
          <el-input v-model="apForm.band" placeholder="如 2.4GHz/5GHz" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="进货价">
              <el-input-number v-model="apForm.cost_price" :min="0" :precision="2" :step="10" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="售价">
              <el-input-number v-model="apForm.sale_price" :min="0" :precision="2" :step="10" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="状态">
          <el-switch v-model="apForm.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="apForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="apDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleApSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 套餐搭配对话框 -->
    <el-dialog :title="bundleEditing ? '编辑套餐' : '添加套餐'" v-model="bundleDialogVisible" width="520px" destroy-on-close>
      <el-form :model="bundleForm" label-width="90px">
        <el-form-item label="名称" required>
          <el-input v-model="bundleForm.name" placeholder="如 标准视频套餐" />
        </el-form-item>
        <el-form-item label="路由器型号">
          <el-select v-model="bundleForm.router_model_id" clearable placeholder="选择路由器型号" style="width: 100%">
            <el-option v-for="m in catalogOptions.router_models || []" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="AP 型号">
          <el-select v-model="bundleForm.ap_model_id" clearable placeholder="选择 AP 型号" style="width: 100%">
            <el-option v-for="m in catalogOptions.ap_models || []" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="套餐价格">
          <el-input-number v-model="bundleForm.bundle_price" :min="0" :precision="2" :step="10" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="bundleForm.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="bundleForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bundleDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleBundleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getRouterModels, createRouterModel, updateRouterModel, deleteRouterModel,
  getApModels, createApModel, updateApModel, deleteApModel,
  getBundles, createBundle, updateBundle, deleteBundle,
  getRouterCatalogOptions,
} from '@/api/routerCatalog'

console.log('OEM Contact edward.sun@as204921.net')

const activeTab = ref('router_models')
const submitting = ref(false)
const catalogOptions = ref({})

// ===== Router Models =====
const routerModels = ref([])
const rmLoading = ref(false)
const rmDialogVisible = ref(false)
const rmEditing = ref(null)
const rmForm = reactive({
  name: '', cpu: '', ram: '', storage: '', ports: 4,
  cost_price: null, sale_price: null, is_active: 1, remark: '',
})

// ===== AP Models =====
const apModels = ref([])
const apLoading = ref(false)
const apDialogVisible = ref(false)
const apEditing = ref(null)
const apForm = reactive({
  name: '', band: '', cost_price: null, sale_price: null,
  is_active: 1, remark: '',
})

// ===== Bundles =====
const bundles = ref([])
const bundleLoading = ref(false)
const bundleDialogVisible = ref(false)
const bundleEditing = ref(null)
const bundleForm = reactive({
  name: '', router_model_id: null, ap_model_id: null,
  bundle_price: null, is_active: 1, remark: '',
})

onMounted(() => {
  fetchRouterModels()
  fetchCatalogOptions()
})

function onTabChange(tab) {
  if (tab === 'router_models' && routerModels.value.length === 0) fetchRouterModels()
  if (tab === 'ap_models' && apModels.value.length === 0) fetchApModels()
  if (tab === 'bundles' && bundles.value.length === 0) fetchBundles()
}

async function fetchCatalogOptions() {
  try {
    catalogOptions.value = await getRouterCatalogOptions() || {}
  } catch { /* handled */ }
}

// ===== Router Models CRUD =====
async function fetchRouterModels() {
  rmLoading.value = true
  try {
    const res = await getRouterModels({ per_page: 50 })
    routerModels.value = res?.items || []
  } catch { /* handled */ }
  finally { rmLoading.value = false }
}

function openRouterModelDialog(row = null) {
  rmEditing.value = row
  if (row) {
    Object.assign(rmForm, {
      name: row.name, cpu: row.cpu || '', ram: row.ram || '', storage: row.storage || '',
      ports: row.ports ?? 4, cost_price: row.cost_price, sale_price: row.sale_price,
      is_active: row.is_active, remark: row.remark || '',
    })
  } else {
    Object.assign(rmForm, {
      name: '', cpu: '', ram: '', storage: '', ports: 4,
      cost_price: null, sale_price: null, is_active: 1, remark: '',
    })
  }
  rmDialogVisible.value = true
}

async function handleRmSubmit() {
  submitting.value = true
  try {
    if (rmEditing.value) {
      await updateRouterModel(rmEditing.value.id, { ...rmForm })
      ElMessage.success('已更新')
    } else {
      await createRouterModel({ ...rmForm })
      ElMessage.success('已创建')
    }
    rmDialogVisible.value = false
    fetchRouterModels()
    fetchCatalogOptions()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDeleteRouterModel(row) {
  await ElMessageBox.confirm(`确认删除路由器型号「${row.name}」？`, '确认删除', { type: 'warning' })
  try {
    await deleteRouterModel(row.id)
    ElMessage.success('已删除')
    fetchRouterModels()
    fetchCatalogOptions()
  } catch { /* handled */ }
}

// ===== AP Models CRUD =====
async function fetchApModels() {
  apLoading.value = true
  try {
    const res = await getApModels({ per_page: 50 })
    apModels.value = res?.items || []
  } catch { /* handled */ }
  finally { apLoading.value = false }
}

function openApModelDialog(row = null) {
  apEditing.value = row
  if (row) {
    Object.assign(apForm, {
      name: row.name, band: row.band || '',
      cost_price: row.cost_price, sale_price: row.sale_price,
      is_active: row.is_active, remark: row.remark || '',
    })
  } else {
    Object.assign(apForm, {
      name: '', band: '', cost_price: null, sale_price: null,
      is_active: 1, remark: '',
    })
  }
  apDialogVisible.value = true
}

async function handleApSubmit() {
  submitting.value = true
  try {
    if (apEditing.value) {
      await updateApModel(apEditing.value.id, { ...apForm })
      ElMessage.success('已更新')
    } else {
      await createApModel({ ...apForm })
      ElMessage.success('已创建')
    }
    apDialogVisible.value = false
    fetchApModels()
    fetchCatalogOptions()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDeleteApModel(row) {
  await ElMessageBox.confirm(`确认删除 AP 型号「${row.name}」？`, '确认删除', { type: 'warning' })
  try {
    await deleteApModel(row.id)
    ElMessage.success('已删除')
    fetchApModels()
    fetchCatalogOptions()
  } catch { /* handled */ }
}

// ===== Bundles CRUD =====
async function fetchBundles() {
  bundleLoading.value = true
  try {
    const res = await getBundles({ per_page: 50 })
    bundles.value = res?.items || []
  } catch { /* handled */ }
  finally { bundleLoading.value = false }
}

function openBundleDialog(row = null) {
  bundleEditing.value = row
  if (row) {
    Object.assign(bundleForm, {
      name: row.name, router_model_id: row.router_model_id, ap_model_id: row.ap_model_id,
      bundle_price: row.bundle_price, is_active: row.is_active, remark: row.remark || '',
    })
  } else {
    Object.assign(bundleForm, {
      name: '', router_model_id: null, ap_model_id: null,
      bundle_price: null, is_active: 1, remark: '',
    })
  }
  bundleDialogVisible.value = true
}

async function handleBundleSubmit() {
  submitting.value = true
  try {
    if (bundleEditing.value) {
      await updateBundle(bundleEditing.value.id, { ...bundleForm })
      ElMessage.success('已更新')
    } else {
      await createBundle({ ...bundleForm })
      ElMessage.success('已创建')
    }
    bundleDialogVisible.value = false
    fetchBundles()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDeleteBundle(row) {
  await ElMessageBox.confirm(`确认删除套餐「${row.name}」？`, '确认删除', { type: 'warning' })
  try {
    await deleteBundle(row.id)
    ElMessage.success('已删除')
    fetchBundles()
  } catch { /* handled */ }
}
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.page-header h2 { margin: 0 0 4px; }
.text-muted { color: #909399; font-size: 13px; margin: 0; }
.tab-toolbar { margin-bottom: 12px; }
</style>
