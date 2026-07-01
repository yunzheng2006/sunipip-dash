<template>
  <div class="user-list">
    <h2 class="page-title">用户管理</h2>

    <el-card>
      <div class="toolbar">
        <el-button v-if="hasPerm('user.create')" type="primary" @click="openDialog()">
          <el-icon><Plus /></el-icon>新建用户
        </el-button>
      </div>

      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="username" label="用户名" min-width="120" />
        <el-table-column prop="name" label="姓名" min-width="120" />
        <el-table-column label="角色" width="120">
          <template #default="{ row }">
            <el-tag size="small" v-for="r in (row.roles_list || row.roles || [])" :key="r">{{ roleLabel(r) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="直属上级" width="120">
          <template #default="{ row }">{{ row.supervisor?.name || '-' }}</template>
        </el-table-column>
        <el-table-column v-if="hasPerm('user.set_auto_approve')" label="独立权限" width="200">
          <template #default="{ row }">
            <template v-if="isSales(row)">
              <div class="auto-approve-cell">
                <el-tooltip content="独立开IP：开启后提交的开通订单自动审批通过" placement="top">
                  <el-switch
                    v-model="row.auto_approve"
                    size="small"
                    inline-prompt
                    active-text="开IP"
                    inactive-text="开IP"
                    @change="(val) => handleAutoApprove(row, 'auto_approve', val)"
                  />
                </el-tooltip>
                <el-tooltip content="独立中转：开启后提交的中转认证审批自动通过" placement="top">
                  <el-switch
                    v-model="row.auto_approve_forward"
                    size="small"
                    inline-prompt
                    active-text="中转"
                    inactive-text="中转"
                    @change="(val) => handleAutoApprove(row, 'auto_approve_forward', val)"
                  />
                </el-tooltip>
              </div>
            </template>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '正常' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" min-width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" align="center" fixed="right">
          <template #default="{ row }">
            <el-button v-if="hasPerm('user.edit')" type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
            <el-button v-if="hasPerm('user.edit')" type="warning" link size="small" @click="handleResetPassword(row)">重置密码</el-button>
            <el-button v-if="hasPerm('user.delete')" type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.page_size"
          :total="pagination.total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- Create/Edit Dialog -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑用户' : '新建用户'" width="500px">
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="80px">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" :disabled="isEdit" placeholder="请输入用户名" />
        </el-form-item>
        <el-form-item v-if="!isEdit" label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password placeholder="请输入密码" />
        </el-form-item>
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="请输入姓名" />
        </el-form-item>
        <el-form-item label="手机" prop="phone">
          <el-input v-model="form.phone" placeholder="选填" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" placeholder="选填" />
        </el-form-item>
        <el-form-item label="角色" prop="role">
          <el-select v-model="form.role" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.name" :label="r.label" :value="r.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="直属上级">
          <el-select v-model="form.supervisor_id" filterable clearable placeholder="选择上级（选填）" style="width: 100%">
            <el-option v-for="u in userOptions" :key="u.id" :label="`${u.name} (${u.username})`" :value="u.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.role === 'sales'" label="独立开IP">
          <el-switch v-model="form.auto_approve" />
          <span style="font-size: 12px; color: #909399; margin-left: 8px">开启后该销售提交的开通订单自动审批通过</span>
        </el-form-item>
        <el-form-item v-if="form.role === 'sales'" label="独立审批中转">
          <el-switch v-model="form.auto_approve_forward" />
          <span style="font-size: 12px; color: #909399; margin-left: 8px">开启后该销售提交的中转认证审批自动通过</span>
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
import dayjs from 'dayjs'
import { getUsers, createUser, updateUser, deleteUser, resetUserPassword, setUserAutoApprove } from '@/api/users'
import { getRoles } from '@/api/roles'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const hasPerm = (p) => auth.hasPermission(p)

const loading = ref(false)
const tableData = ref([])
const pagination = reactive({ page: 1, page_size: 20, total: 0 })

const dialogVisible = ref(false)
const isEdit = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formRef = ref(null)

const userOptions = ref([])
const roleOptions = ref([])

const form = reactive({
  username: '',
  password: '',
  name: '',
  phone: '',
  email: '',
  role: 'sales',
  supervisor_id: null,
  auto_approve: false,
  auto_approve_forward: false,
})

const formRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  role: [{ required: true, message: '请选择角色', trigger: 'change' }],
}

function formatDate(date) {
  return date ? dayjs(date).format('YYYY-MM-DD HH:mm') : '-'
}

function roleLabel(role) {
  const found = roleOptions.value.find(r => r.name === role)
  return found?.label || role
}

function isSales(row) {
  const roles = row.roles_list || row.roles || []
  return roles.includes('sales')
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.page_size }
    const res = await getUsers(params)
    tableData.value = res?.items || (Array.isArray(res) ? res : [])
    pagination.total = res?.pagination?.total || 0
  } catch {
    // Error handled by interceptor
  } finally {
    loading.value = false
  }
}

function openDialog(row) {
  if (row) {
    isEdit.value = true
    editingId.value = row.id
    form.username = row.username
    form.password = ''
    form.name = row.name || ''
    form.phone = row.phone || ''
    form.email = row.email || ''
    form.role = row.roles_list?.[0] || row.roles?.[0] || 'sales'
    form.supervisor_id = row.supervisor_id || null
    form.auto_approve = row.auto_approve || false
    form.auto_approve_forward = row.auto_approve_forward || false
  } else {
    isEdit.value = false
    editingId.value = null
    form.username = ''
    form.password = ''
    form.name = ''
    form.phone = ''
    form.email = ''
    form.role = 'staff'
    form.supervisor_id = null
    form.auto_approve = false
    form.auto_approve_forward = false
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    if (isEdit.value) {
      const data = { name: form.name, phone: form.phone, email: form.email, role: form.role, supervisor_id: form.supervisor_id, auto_approve: form.role === 'sales' ? form.auto_approve : false, auto_approve_forward: form.role === 'sales' ? form.auto_approve_forward : false }
      await updateUser(editingId.value, data)
      ElMessage.success('更新成功')
    } else {
      await createUser({ ...form })
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch {
    // Error handled by interceptor
  } finally {
    submitting.value = false
  }
}

async function handleAutoApprove(row, field, val) {
  try {
    await setUserAutoApprove(row.id, { [field]: val })
    ElMessage.success('已更新')
  } catch {
    row[field] = !val
  }
}

async function handleResetPassword(row) {
  try {
    await ElMessageBox.confirm(`确定要重置用户「${row.name}」的密码吗？`, '重置密码', { type: 'warning' })
    const res = await resetUserPassword(row.id)
    const newPassword = res?.data?.password || res?.password || '(查看返回)'
    ElMessageBox.alert(`新密码为: ${newPassword}`, '密码已重置', { type: 'success' })
  } catch {
    // Cancelled or error
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定要删除用户「${row.name || row.username}」吗？`, '删除确认', { type: 'warning' })
    await deleteUser(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch {
    // Cancelled or error
  }
}

async function fetchRoles() {
  try {
    const res = await getRoles()
    roleOptions.value = (Array.isArray(res) ? res : []).filter(r => r.name !== 'user')
  } catch {
    // Error handled by interceptor
  }
}

async function fetchUserOptions() {
  try {
    const res = await getUsers({ per_page: 200 })
    userOptions.value = res?.items || (Array.isArray(res) ? res : [])
  } catch {
    // Error handled by interceptor
  }
}

onMounted(() => {
  fetchData()
  fetchUserOptions()
  fetchRoles()
})
</script>

<style lang="scss" scoped>
.user-list {
  .page-title {
    margin: 0 0 20px 0;
    font-size: 20px;
    font-weight: 600;
    color: #2C3E50;
  }

  .toolbar {
    margin-bottom: 16px;
  }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
}

.auto-approve-cell {
  display: flex;
  gap: 8px;
}
</style>
