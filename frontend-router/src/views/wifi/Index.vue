<template>
  <div class="wifi-page">
    <div class="page-header">
      <h2>WiFi 管理</h2>
      <el-button type="primary" :icon="Plus" @click="openDialog()">
        添加账号
      </el-button>
    </div>

    <!-- 设备选择器（如果有多个设备） -->
    <el-alert
      v-if="!currentDeviceId && !loadingDevices"
      title="未找到关联设备"
      description="请先在平台上绑定设备后再管理 WiFi 账号。"
      type="warning"
      show-icon
      :closable="false"
      class="no-device-alert"
    />

    <!-- 桌面端表格 -->
    <el-card v-if="currentDeviceId" shadow="never" class="wifi-table-card">
      <el-table
        v-loading="loading"
        :data="accounts"
        stripe
        class="desktop-table"
      >
        <el-table-column prop="username" label="用户名" min-width="120" />
        <el-table-column prop="password" label="密码" min-width="100">
          <template #default="{ row }">
            <span class="mono">{{ row.password }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="label" label="备注" min-width="100">
          <template #default="{ row }">
            {{ row.label || '--' }}
          </template>
        </el-table-column>
        <el-table-column prop="vlan_id" label="VLAN" width="80" align="center">
          <template #default="{ row }">
            <el-tag size="small">{{ row.vlan_id || '--' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="proxy_mode" label="代理模式" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.proxy_mode === 'proxy' ? 'success' : 'info'" size="small">
              {{ row.proxy_mode === 'proxy' ? '代理' : '直连' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="subscription" label="绑定订阅" min-width="140">
          <template #default="{ row }">
            {{ row.subscription?.name || row.subscription_id || '--' }}
          </template>
        </el-table-column>
        <el-table-column prop="max_devices" label="最大设备数" width="100" align="center">
          <template #default="{ row }">
            {{ row.max_devices || '不限' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="openDialog(row)">编辑</el-button>
            <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 移动端卡片列表 -->
      <div class="mobile-cards">
        <div v-if="loading" class="mobile-loading">
          <el-skeleton :rows="4" animated />
        </div>
        <div v-else-if="accounts.length === 0" class="empty-state">
          <el-empty description="暂无 WiFi 账号" />
        </div>
        <el-card
          v-for="account in accounts"
          :key="account.id"
          shadow="hover"
          class="account-card"
        >
          <div class="account-card-header">
            <strong>{{ account.username }}</strong>
            <el-tag :type="account.proxy_mode === 'proxy' ? 'success' : 'info'" size="small">
              {{ account.proxy_mode === 'proxy' ? '代理' : '直连' }}
            </el-tag>
          </div>
          <div class="account-card-body">
            <div class="card-field">
              <span class="field-label">密码</span>
              <span class="mono">{{ account.password }}</span>
            </div>
            <div class="card-field" v-if="account.label">
              <span class="field-label">备注</span>
              <span>{{ account.label }}</span>
            </div>
            <div class="card-field">
              <span class="field-label">VLAN</span>
              <el-tag size="small">{{ account.vlan_id || '--' }}</el-tag>
            </div>
            <div class="card-field">
              <span class="field-label">订阅</span>
              <span>{{ account.subscription?.name || account.subscription_id || '--' }}</span>
            </div>
            <div class="card-field">
              <span class="field-label">最大设备数</span>
              <span>{{ account.max_devices || '不限' }}</span>
            </div>
          </div>
          <div class="account-card-actions">
            <el-button type="primary" size="small" @click="openDialog(account)">编辑</el-button>
            <el-button type="danger" size="small" @click="handleDelete(account)">删除</el-button>
          </div>
        </el-card>
      </div>
    </el-card>

    <!-- 添加/编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑 WiFi 账号' : '添加 WiFi 账号'"
      width="500px"
      :close-on-click-modal="false"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="100px"
      >
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="请输入 WiFi 账号用户名" />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" placeholder="请输入密码" />
        </el-form-item>
        <el-form-item label="备注" prop="label">
          <el-input v-model="form.label" placeholder="如：主人、客人、办公室" />
        </el-form-item>
        <el-form-item label="代理模式" prop="proxy_mode">
          <el-select v-model="form.proxy_mode" class="full-width">
            <el-option label="代理模式" value="proxy" />
            <el-option label="直连模式" value="direct" />
          </el-select>
        </el-form-item>
        <el-form-item label="绑定订阅" prop="subscription_id">
          <el-select
            v-model="form.subscription_id"
            placeholder="选择代理订阅（可选）"
            clearable
            class="full-width"
          >
            <el-option
              v-for="sub in subscriptions"
              :key="sub.id"
              :label="sub.name || `订阅 #${sub.id}`"
              :value="sub.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="最大设备数" prop="max_devices">
          <el-input-number v-model="form.max_devices" :min="0" :max="100" :step="1" />
          <span class="form-tip">0 表示不限制</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          {{ isEdit ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getMyDevices,
  getWifiAccounts,
  createWifiAccount,
  updateWifiAccount,
  deleteWifiAccount,
  getAvailableSubscriptions
} from '../../api/platform'

const loading = ref(true)
const loadingDevices = ref(true)
const accounts = ref([])
const subscriptions = ref([])
const currentDeviceId = ref(null)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formRef = ref(null)

const form = reactive({
  username: '',
  password: '',
  label: '',
  proxy_mode: 'proxy',
  subscription_id: null,
  max_devices: 0
})

const rules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' }
  ],
  proxy_mode: [{ required: true, message: '请选择代理模式', trigger: 'change' }]
}

function openDialog(account = null) {
  if (account) {
    isEdit.value = true
    editingId.value = account.id
    Object.assign(form, {
      username: account.username,
      password: account.password,
      label: account.label || '',
      proxy_mode: account.proxy_mode || 'proxy',
      subscription_id: account.subscription_id || null,
      max_devices: account.max_devices || 0
    })
  } else {
    isEdit.value = false
    editingId.value = null
    Object.assign(form, {
      username: '',
      password: '',
      label: '',
      proxy_mode: 'proxy',
      subscription_id: null,
      max_devices: 0
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    if (isEdit.value) {
      await updateWifiAccount(editingId.value, { ...form })
      ElMessage.success('更新成功')
    } else {
      await createWifiAccount(currentDeviceId.value, { ...form })
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    await fetchAccounts()
  } catch (err) {
    ElMessage.error(err.response?.data?.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

async function handleDelete(account) {
  try {
    await ElMessageBox.confirm(
      `确定删除 WiFi 账号「${account.username}」？删除后将无法恢复。`,
      '确认删除',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
    )
    await deleteWifiAccount(account.id)
    ElMessage.success('删除成功')
    await fetchAccounts()
  } catch (err) {
    if (err !== 'cancel') {
      ElMessage.error(err.response?.data?.message || '删除失败')
    }
  }
}

async function fetchAccounts() {
  if (!currentDeviceId.value) return
  loading.value = true
  try {
    const { data } = await getWifiAccounts(currentDeviceId.value)
    accounts.value = data.data || []
  } catch {
    accounts.value = []
  } finally {
    loading.value = false
  }
}

async function fetchSubscriptions() {
  if (!currentDeviceId.value) return
  try {
    const { data } = await getAvailableSubscriptions(currentDeviceId.value)
    subscriptions.value = data.data || []
  } catch {
    subscriptions.value = []
  }
}

async function init() {
  loadingDevices.value = true
  try {
    const { data } = await getMyDevices()
    const devices = data.data || []
    if (devices.length > 0) {
      currentDeviceId.value = devices[0].id
      await Promise.all([fetchAccounts(), fetchSubscriptions()])
    }
  } catch {
    // 静默
  } finally {
    loadingDevices.value = false
    loading.value = false
  }
}

onMounted(() => {
  init()
})
</script>

<style scoped>
.wifi-page {
  max-width: 1200px;
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.page-header h2 {
  font-size: 20px;
  color: #303133;
}

.no-device-alert {
  margin-bottom: 20px;
}

.wifi-table-card {
  border: none;
}

.full-width {
  width: 100%;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.mono {
  font-family: 'SF Mono', 'Fira Code', monospace;
  font-size: 13px;
}

/* 移动端卡片隐藏桌面表格 */
.mobile-cards {
  display: none;
}

.desktop-table {
  display: block;
}

.account-card {
  margin-bottom: 12px;
}

.account-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.account-card-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.card-field {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
}

.field-label {
  color: #909399;
}

.account-card-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #ebeef5;
}

.mobile-loading {
  padding: 20px 0;
}

.empty-state {
  padding: 40px 0;
}

@media (max-width: 767px) {
  .desktop-table {
    display: none;
  }

  .mobile-cards {
    display: block;
  }

  :deep(.el-dialog) {
    width: 92% !important;
    margin: 0 auto;
  }
}
</style>
