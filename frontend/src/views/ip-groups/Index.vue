<template>
  <div class="ip-groups">
    <h2 class="page-title">IP组管理</h2>
    <p style="color: #909399; margin: -12px 0 20px; font-size: 13px;">
      IP组用于分类定价，如"双Cogent"、"原生ISP"等。批量导入IP时可按IP组ID归类。
    </p>

    <el-card>
      <div class="toolbar">
        <div class="toolbar-left">
          <el-input v-model="keyword" placeholder="搜索IP组名称" clearable style="width: 220px" @keyup.enter="fetchData" />
          <el-button type="primary" @click="fetchData">搜索</el-button>
        </div>
        <el-button type="primary" @click="openDialog()">
          <el-icon><Plus /></el-icon>新建IP组
        </el-button>
      </div>

      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="IP组名称" min-width="140">
          <template #default="{ row }">
            <span style="font-weight: 500; color: #E8913A">{{ row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="slug" label="标识符" min-width="120">
          <template #default="{ row }">
            <el-tag size="small" type="info" effect="plain">{{ row.slug }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="country_name" label="国家" width="100" />
        <el-table-column prop="city" label="城市" width="100" />
        <el-table-column prop="isp_type" label="ISP类型" min-width="120" />
        <el-table-column prop="net_type" label="网络类型" width="100">
          <template #default="{ row }">
            {{ netTypeLabel(row.net_type) }}
          </template>
        </el-table-column>
        <el-table-column prop="proxy_ips_count" label="IP总数" width="80" align="center" />
        <el-table-column prop="available_ips_count" label="可用" width="70" align="center">
          <template #default="{ row }">
            <span :style="{ color: row.available_ips_count > 0 ? '#67C23A' : '#909399' }">
              {{ row.available_ips_count }}
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
        <el-table-column label="操作" width="150" align="center" fixed="right">
          <template #default="{ row }">
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
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑IP组' : '新建IP组'" width="560px">
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="90px">
        <el-form-item label="组名称" prop="name">
          <el-input v-model="form.name" placeholder="如：双Cogent、原生ISP" />
        </el-form-item>
        <el-form-item label="标识符" prop="slug">
          <el-input v-model="form.slug" placeholder="英文标识，批量导入用(如 dual-cogent)" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="国家代码" prop="country_code">
              <el-input v-model="form.country_code" placeholder="如 US, TH, BR" maxlength="2" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="国家名称" prop="country_name">
              <el-input v-model="form.country_name" placeholder="如 美国, 泰国" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="城市">
              <el-input v-model="form.city" placeholder="如 西雅图, 曼谷" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="网络类型">
              <el-select v-model="form.net_type" placeholder="选择" clearable style="width: 100%">
                <el-option label="原生" value="native" />
                <el-option label="广播" value="broadcast" />
                <el-option label="未知" value="unknown" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="ISP类型">
          <el-input v-model="form.isp_type" placeholder="如 双Cogent, 单ISP, 原生ISP" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getIpGroups, createIpGroup, updateIpGroup, deleteIpGroup } from '@/api/ipGroups'

const loading = ref(false)
const tableData = ref([])
const keyword = ref('')
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

const dialogVisible = ref(false)
const isEdit = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formRef = ref(null)

const form = reactive({
  name: '', slug: '', country_code: '', country_name: '',
  city: '', isp_type: '', net_type: '', description: '',
})

const formRules = {
  name: [{ required: true, message: '请输入组名称', trigger: 'blur' }],
  slug: [{ required: true, message: '请输入标识符', trigger: 'blur' }],
}

function netTypeLabel(t) {
  const map = { native: '原生', broadcast: '广播', unknown: '未知' }
  return map[t] || t || '-'
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (keyword.value) params.keyword = keyword.value
    const res = await getIpGroups(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch { /* handled */ }
  finally { loading.value = false }
}

function openDialog(row) {
  if (row) {
    isEdit.value = true
    editingId.value = row.id
    Object.assign(form, {
      name: row.name, slug: row.slug,
      country_code: row.country_code || '', country_name: row.country_name || '',
      city: row.city || '', isp_type: row.isp_type || '',
      net_type: row.net_type || '', description: row.description || '',
    })
  } else {
    isEdit.value = false
    editingId.value = null
    Object.assign(form, {
      name: '', slug: '', country_code: '', country_name: '',
      city: '', isp_type: '', net_type: '', description: '',
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
      await updateIpGroup(editingId.value, { ...form })
      ElMessage.success('更新成功')
    } else {
      await createIpGroup({ ...form })
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定删除IP组「${row.name}」？`, '确认', { type: 'warning' })
    await deleteIpGroup(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch { /* cancelled */ }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.ip-groups {
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;

    .toolbar-left {
      display: flex;
      gap: 8px;
    }
  }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
}
</style>
