<template>
  <div class="page-container">
    <div class="page-header">
      <div>
        <h2>WireGuard 服务器</h2>
        <p class="text-muted">管理软路由 VPN 隧道服务器</p>
      </div>
      <el-button type="primary" @click="openDialog()">添加服务器</el-button>
    </div>

    <el-table :data="list" v-loading="loading" stripe>
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column prop="name" label="名称" width="140" />
      <el-table-column prop="endpoint" label="Endpoint" min-width="200" />
      <el-table-column prop="server_cidr" label="网段" width="140" />
      <el-table-column prop="listen_port" label="端口" width="80" />
      <el-table-column prop="mtu" label="MTU" width="70" />
      <el-table-column label="角色" width="80" align="center">
        <template #default="{ row }">
          <el-tag :type="row.role === 'backup' ? 'warning' : 'primary'" size="small">
            {{ row.role === 'backup' ? '备用' : '主要' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="Peers" width="80" align="center">
        <template #default="{ row }">
          <el-tag size="small">{{ row.peers_count ?? 0 }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80" align="center">
        <template #default="{ row }">
          <el-tag :type="row.is_active ? 'success' : 'info'" size="small">
            {{ row.is_active ? '启用' : '停用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="280" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="openDialog(row)">编辑</el-button>
          <el-button size="small" @click="showConfig(row)">配置</el-button>
          <el-button size="small" type="success" @click="handleSyncPeers(row)" :loading="row._syncing">同步 Peers</el-button>
          <el-button size="small" type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 创建/编辑对话框 -->
    <el-dialog :title="editing ? '编辑服务器' : '添加服务器'" v-model="dialogVisible" width="560px" destroy-on-close>
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称" required>
          <el-input v-model="form.name" placeholder="如 WG-HK1" />
        </el-form-item>
        <el-form-item label="Endpoint" required>
          <el-input v-model="form.endpoint" placeholder="wg1.sunipip.com:51820" />
        </el-form-item>
        <el-form-item label="公钥" required>
          <el-input v-model="form.public_key" placeholder="WireGuard 服务端公钥" />
        </el-form-item>
        <el-form-item :label="editing ? '私钥（留空不改）' : '私钥'" :required="!editing">
          <el-input v-model="form.private_key" type="password" show-password placeholder="WireGuard 服务端私钥" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="网段" required>
              <el-input v-model="form.server_cidr" placeholder="10.10.0.0/16" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="端口">
              <el-input-number v-model="form.listen_port" :min="1" :max="65535" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="DNS">
              <el-input v-model="form.dns" placeholder="可选" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="MTU">
              <el-input-number v-model="form.mtu" :min="1280" :max="1500" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="状态">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="角色">
          <el-select v-model="form.role" style="width: 100%">
            <el-option label="主要 (Primary)" value="primary" />
            <el-option label="备用 (Backup)" value="backup" />
          </el-select>
        </el-form-item>
        <el-divider content-position="left">SSH 远程管理</el-divider>
        <el-form-item label="SSH 主机">
          <el-input v-model="form.ssh_host" placeholder="SSH 连接地址（留空则与 Endpoint 相同）" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="SSH 端口">
              <el-input-number v-model="form.ssh_port" :min="1" :max="65535" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="SSH 用户">
              <el-input v-model="form.ssh_user" placeholder="root" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item :label="editing ? 'SSH 私钥（留空不改）' : 'SSH 私钥'">
          <el-input v-model="form.ssh_private_key" type="textarea" :rows="4" placeholder="SSH 私钥（PEM 格式）" style="font-family: monospace" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 配置预览对话框 -->
    <el-dialog title="WireGuard 服务端配置" v-model="configVisible" width="600px">
      <el-input v-model="configContent" type="textarea" :rows="18" readonly style="font-family: monospace" />
      <template #footer>
        <el-button @click="copyConfig">复制</el-button>
        <el-button @click="configVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getWgServers, createWgServer, updateWgServer, deleteWgServer, getWgServerConfig, syncWgServerPeers } from '@/api/wgServers'

console.log('OEM Contact edward.sun@as204921.net')

const list = ref([])
const loading = ref(false)
const dialogVisible = ref(false)
const configVisible = ref(false)
const configContent = ref('')
const submitting = ref(false)
const editing = ref(null)

const syncing = ref(false)

const form = reactive({
  name: '', endpoint: '', public_key: '', private_key: '',
  server_cidr: '', listen_port: 51820, dns: '', mtu: 1420,
  is_active: 1, remark: '', role: 'primary',
  ssh_host: '', ssh_port: 22, ssh_user: 'root', ssh_private_key: '',
})

onMounted(() => fetchList())

async function fetchList() {
  loading.value = true
  try {
    const res = await getWgServers({ per_page: 50 })
    list.value = res?.items || []
  } catch { /* handled */ }
  finally { loading.value = false }
}

function openDialog(row = null) {
  editing.value = row
  if (row) {
    Object.assign(form, {
      name: row.name, endpoint: row.endpoint, public_key: row.public_key, private_key: '',
      server_cidr: row.server_cidr, listen_port: row.listen_port, dns: row.dns || '', mtu: row.mtu,
      is_active: row.is_active, remark: row.remark || '', role: row.role || 'primary',
      ssh_host: row.ssh_host || '', ssh_port: row.ssh_port || 22, ssh_user: row.ssh_user || 'root', ssh_private_key: '',
    })
  } else {
    Object.assign(form, {
      name: '', endpoint: '', public_key: '', private_key: '',
      server_cidr: '', listen_port: 51820, dns: '', mtu: 1420,
      is_active: 1, remark: '', role: 'primary',
      ssh_host: '', ssh_port: 22, ssh_user: 'root', ssh_private_key: '',
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  submitting.value = true
  try {
    const payload = { ...form }
    if (editing.value && !payload.private_key) delete payload.private_key
    if (editing.value && !payload.ssh_private_key) delete payload.ssh_private_key
    if (editing.value) {
      await updateWgServer(editing.value.id, payload)
      ElMessage.success('已更新')
    } else {
      await createWgServer(payload)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    fetchList()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  await ElMessageBox.confirm(`确认删除服务器「${row.name}」？`, '确认删除', { type: 'warning' })
  try {
    await deleteWgServer(row.id)
    ElMessage.success('已删除')
    fetchList()
  } catch { /* handled */ }
}

async function showConfig(row) {
  try {
    const res = await getWgServerConfig(row.id)
    configContent.value = res?.config || ''
    configVisible.value = true
  } catch { /* handled */ }
}

async function handleSyncPeers(row) {
  await ElMessageBox.confirm(
    `确认将所有活跃 Peers 同步到服务器「${row.name}」？此操作会通过 SSH 逐个推送 Peer 配置。`,
    '同步 Peers',
    { type: 'info' }
  )
  row._syncing = true
  try {
    const res = await syncWgServerPeers(row.id)
    ElMessage.success(res?.message || '同步完成')
  } catch { /* handled */ }
  finally { row._syncing = false }
}

function copyConfig() {
  navigator.clipboard.writeText(configContent.value)
  ElMessage.success('已复制')
}
</script>

<style scoped>
.page-header {
  display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
}
.page-header h2 { margin: 0 0 4px; }
.text-muted { color: #909399; font-size: 13px; margin: 0; }
</style>
