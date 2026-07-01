<template>
  <div class="asset-group-list">
    <div class="page-header">
      <div>
        <h2 class="page-title">资产组管理</h2>
        <p class="page-desc">资产组按来源管理IP，如"手动导入"、"Spark-美国"、"自有-西雅图"等。</p>
      </div>
      <div class="header-actions">
        <el-button @click="openMerge"><el-icon><Connection /></el-icon> 合并资产组</el-button>
        <el-button type="primary" @click="openDialog()"><el-icon><Plus /></el-icon> 新建资产组</el-button>
      </div>
    </div>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="组名称" min-width="160">
          <template #default="{ row }">
            <span style="font-weight: 500">{{ row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="source_type" label="来源类型" width="110">
          <template #default="{ row }">
            <el-tag :type="sourceTypeTag(row.source_type)" size="small" effect="plain">
              {{ sourceTypeLabel(row.source_type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="source_name" label="供应商" min-width="100" />
        <el-table-column prop="country_name" label="国家" width="80" />
        <el-table-column prop="city" label="城市" width="80" />
        <el-table-column prop="proxy_ips_count" label="IP总数" width="80" align="center">
          <template #default="{ row }">
            <el-link v-if="row.proxy_ips_count > 0" type="primary" :underline="false"
              @click="viewIps(row)">{{ row.proxy_ips_count }}</el-link>
            <span v-else style="color: #C0C4CC">0</span>
          </template>
        </el-table-column>
        <el-table-column prop="available_ips_count" label="可用" width="70" align="center">
          <template #default="{ row }">
            <span :style="{ color: row.available_ips_count > 0 ? '#67C23A' : '#909399' }">
              {{ row.available_ips_count ?? '-' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="success" link size="small" @click="viewIps(row)">查看IP</el-button>
            <el-button type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- Create/Edit Dialog -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑资产组' : '新建资产组'" width="560px">
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="90px">
        <el-form-item label="来源类型" prop="source_type">
          <el-select v-model="form.source_type" style="width: 100%" @change="onSourceTypeChange">
            <el-option label="手动导入" value="manual" />
            <el-option label="Spark API" value="spark_api" />
            <el-option label="第三方API" value="third_party_api" />
            <el-option label="自有资源" value="self_owned" />
          </el-select>
        </el-form-item>
        <el-form-item label="供应商名称" prop="source_name">
          <el-input v-model="form.source_name" placeholder="如 斯帕克, 自有, 涛哥" />
        </el-form-item>
        <el-form-item label="组名称" prop="name">
          <el-input v-model="form.name" placeholder="如 Spark-美国-西雅图, 手动-巴西" />
        </el-form-item>
        <template v-if="form.source_type !== 'spark_api'">
          <el-row :gutter="16">
            <el-col :span="8">
              <el-form-item label="国家代码">
                <el-input v-model="form.country_code" placeholder="US" maxlength="2" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="国家">
                <el-input v-model="form.country_name" placeholder="美国" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="城市">
                <el-input v-model="form.city" placeholder="西雅图" />
              </el-form-item>
            </el-col>
          </el-row>
        </template>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
        <el-alert v-if="form.source_type === 'spark_api'" type="info" :closable="false" show-icon style="margin-bottom: 16px">
          Spark API 资产组不需要填写国家/城市。创建订单时选择"Spark API 开通"即可实时拉取产品列表。
        </el-alert>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- Merge Dialog -->
    <el-dialog v-model="mergeVisible" title="合并资产组" width="560px" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        将选中的源资产组下所有 IP 迁移到目标资产组，迁移后源资产组将被删除。
      </el-alert>
      <el-form label-width="100px">
        <el-form-item label="源资产组" required>
          <el-select v-model="mergeForm.source_ids" multiple filterable placeholder="选择要合并的资产组（可多选）" style="width: 100%">
            <el-option v-for="g in mergeSourceOptions" :key="g.id" :disabled="g.id === mergeForm.target_id"
              :label="`${g.name} (${g.proxy_ips_count || 0} IP)`" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="目标资产组" required>
          <el-select v-model="mergeForm.target_id" filterable placeholder="合并到..." style="width: 100%">
            <el-option v-for="g in mergeTargetOptions" :key="g.id"
              :label="`${g.name} (${g.proxy_ips_count || 0} IP)`" :value="g.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <div v-if="mergeForm.source_ids.length > 0 && mergeForm.target_id" class="merge-preview">
        <el-icon><Right /></el-icon>
        将 <strong>{{ mergeForm.source_ids.length }}</strong> 个资产组合并到
        <strong>{{ mergeTargetOptions.find(g => g.id === mergeForm.target_id)?.name || '' }}</strong>
      </div>
      <template #footer>
        <el-button @click="mergeVisible = false">取消</el-button>
        <el-button type="warning" :loading="merging" :disabled="!mergeForm.source_ids.length || !mergeForm.target_id"
          @click="submitMerge">确认合并</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Connection, Right } from '@element-plus/icons-vue'
import { getAssetGroups, createAssetGroup, updateAssetGroup, deleteAssetGroup, mergeAssetGroups } from '@/api/assetGroups'

const router = useRouter()
const loading = ref(false)
const tableData = ref([])
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

const dialogVisible = ref(false)
const isEdit = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formRef = ref(null)

const form = reactive({
  name: '', source_type: 'manual', source_name: '',
  country_code: '', country_name: '', city: '', description: '',
})

const formRules = {
  name: [{ required: true, message: '请输入组名称', trigger: 'blur' }],
  source_type: [{ required: true, message: '请选择来源类型', trigger: 'change' }],
  source_name: [{ required: true, message: '请输入供应商名称', trigger: 'blur' }],
}

function sourceTypeLabel(t) {
  return { manual: '手动导入', spark_api: 'Spark API', third_party_api: '第三方API', self_owned: '自有' }[t] || t
}
function sourceTypeTag(t) {
  return { manual: '', spark_api: 'warning', third_party_api: 'success', self_owned: 'info' }[t] || ''
}
function onSourceTypeChange(val) {
  if (val === 'spark_api') form.source_name = '斯帕克'
}

async function fetchData() {
  loading.value = true
  try {
    const res = await getAssetGroups({ page: pagination.page, per_page: pagination.per_page })
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch {}
  finally { loading.value = false }
}

function viewIps(row) {
  router.push({ path: '/proxy-ips', query: { 'filter[asset_group_id]': row.id } })
}

function openDialog(row) {
  if (row) {
    isEdit.value = true
    editingId.value = row.id
    Object.assign(form, {
      name: row.name, source_type: row.source_type || 'manual',
      source_name: row.source_name || '', country_code: row.country_code || '',
      country_name: row.country_name || '', city: row.city || '', description: row.description || '',
    })
  } else {
    isEdit.value = false
    editingId.value = null
    Object.assign(form, {
      name: '', source_type: 'manual', source_name: '',
      country_code: '', country_name: '', city: '', description: '',
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    if (isEdit.value) {
      await updateAssetGroup(editingId.value, { ...form })
      ElMessage.success('更新成功')
    } else {
      await createAssetGroup({ ...form })
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch {}
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定删除资产组「${row.name}」？`, '确认', { type: 'warning' })
    await deleteAssetGroup(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch {}
}

// ===== Merge =====
const mergeVisible = ref(false)
const merging = ref(false)
const mergeForm = reactive({ source_ids: [], target_id: null })

const mergeSourceOptions = computed(() => tableData.value)
const mergeTargetOptions = computed(() =>
  tableData.value.filter(g => !mergeForm.source_ids.includes(g.id))
)

function openMerge() {
  mergeForm.source_ids = []
  mergeForm.target_id = null
  mergeVisible.value = true
}

async function submitMerge() {
  if (!mergeForm.source_ids.length || !mergeForm.target_id) return

  const sourceNames = mergeForm.source_ids.map(id => tableData.value.find(g => g.id === id)?.name || id).join('、')
  const targetName = tableData.value.find(g => g.id === mergeForm.target_id)?.name || ''

  try {
    await ElMessageBox.confirm(
      `确定将「${sourceNames}」合并到「${targetName}」？\n\n源资产组下的所有 IP 将迁移到目标组，源资产组随后被删除。此操作不可逆。`,
      '确认合并',
      { type: 'warning', confirmButtonText: '确认合并', cancelButtonText: '取消' }
    )
  } catch { return }

  merging.value = true
  try {
    const res = await mergeAssetGroups({
      source_ids: mergeForm.source_ids,
      target_id: mergeForm.target_id,
    })
    ElMessage.success(res?.message || '合并成功')
    mergeVisible.value = false
    fetchData()
  } catch {}
  finally { merging.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.asset-group-list {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
    .header-actions { display: flex; gap: 8px; flex-shrink: 0; }
  }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .merge-preview {
    background: #FDF6EC; border: 1px solid #FAECD8; border-radius: 6px;
    padding: 10px 14px; font-size: 13px; color: #E6A23C;
    display: flex; align-items: center; gap: 4px;
    strong { color: #C07A00; }
  }
}
</style>
