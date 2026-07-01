<template>
  <div class="dns-monitor-page">
    <h2 class="page-title">DNS 容灾监控</h2>

    <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
      <template #title>
        配置中国大陆 Agent + 监控目标。Agent 定期探测 vless 节点 TLS 可达性，
        连续失败后自动通过 Cloudflare API 切换 DNS 到备机 IP。
      </template>
    </el-alert>

    <el-tabs v-model="activeTab">
      <!-- =========================== -->
      <!-- Tab 1: 监控目标  -->
      <!-- =========================== -->
      <el-tab-pane label="监控目标" name="targets">
        <div class="toolbar">
          <el-button type="primary" @click="openCreateTarget">
            <el-icon><Plus /></el-icon> 添加目标
          </el-button>
          <el-button @click="loadTargets" :loading="targetsLoading">刷新</el-button>
        </div>

        <el-table :data="targets" v-loading="targetsLoading" stripe>
          <el-table-column prop="id" label="ID" width="60" />
          <el-table-column label="名称" min-width="150">
            <template #default="{ row }">
              <strong>{{ row.name }}</strong>
              <div v-if="row.xui_panel" class="hint">
                <el-icon :size="11"><Connection /></el-icon>
                关联：{{ row.xui_panel.name }}
              </div>
            </template>
          </el-table-column>
          <el-table-column label="DNS 记录" min-width="180">
            <template #default="{ row }">
              <div class="mono">{{ row.cf_record_name }}</div>
              <div class="hint">当前 → {{ currentIp(row) }}</div>
            </template>
          </el-table-column>
          <el-table-column label="主备 IP" min-width="200">
            <template #default="{ row }">
              <div>
                <el-tag size="small" :type="row.current_active === 'primary' ? 'success' : 'info'">主</el-tag>
                <span class="mono" style="margin-left: 4px">{{ row.primary_ip }}</span>
              </div>
              <div style="margin-top: 2px">
                <el-tag size="small" :type="row.current_active === 'backup' ? 'warning' : 'info'">备</el-tag>
                <span class="mono" style="margin-left: 4px">{{ row.backup_ip }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="探测端口" width="90">
            <template #default="{ row }">
              <span class="mono">{{ row.probe_port }}</span>
            </template>
          </el-table-column>
          <el-table-column label="健康度" width="110" align="center">
            <template #default="{ row }">
              <el-tag :type="statusTag(row.status)" size="small">
                {{ statusLabel(row.status) }}
              </el-tag>
              <div v-if="row.consecutive_failures > 0" class="hint" style="color: #F56C6C">
                连续失败 {{ row.consecutive_failures }}/{{ row.failure_threshold }}
              </div>
            </template>
          </el-table-column>
          <el-table-column label="上次探测" width="130">
            <template #default="{ row }">
              {{ row.last_probe_at ? formatRelative(row.last_probe_at) : '-' }}
            </template>
          </el-table-column>
          <el-table-column label="操作" width="320" align="center" fixed="right">
            <template #default="{ row }">
              <el-button
                v-if="row.current_active === 'primary'"
                type="danger"
                link
                size="small"
                @click="handleFailover(row)"
              >
                手动切到备机
              </el-button>
              <el-button
                v-if="row.current_active === 'backup'"
                type="success"
                link
                size="small"
                @click="handleFailback(row)"
              >
                切回主机
              </el-button>
              <el-button type="primary" link size="small" @click="openHistory(row)">
                历史
              </el-button>
              <el-button type="primary" link size="small" @click="openEditTarget(row)">
                编辑
              </el-button>
              <el-button type="danger" link size="small" @click="handleDeleteTarget(row)">
                删除
              </el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!targetsLoading && !targets.length" description="尚未配置监控目标" />
      </el-tab-pane>

      <!-- =========================== -->
      <!-- Tab 2: Agent 管理  -->
      <!-- =========================== -->
      <el-tab-pane label="Agent 管理" name="agents">
        <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 14px">
          Agent 是部署在 <strong>中国大陆 VPS</strong> 上的探测程序。添加后请将 agent_key
          配置到 Agent 的环境变量 <code>SUNIPIP_AGENT_KEY</code>。
        </el-alert>

        <div class="toolbar">
          <el-button type="primary" @click="openCreateAgent">
            <el-icon><Plus /></el-icon> 添加 Agent
          </el-button>
          <el-button @click="loadAgents" :loading="agentsLoading">刷新</el-button>
        </div>

        <el-table :data="agents" v-loading="agentsLoading" stripe>
          <el-table-column prop="id" label="ID" width="60" />
          <el-table-column prop="name" label="名称" min-width="140" />
          <el-table-column prop="location" label="位置" min-width="140" />
          <el-table-column label="最近心跳" min-width="160">
            <template #default="{ row }">
              <span v-if="row.last_heartbeat_at" :style="{ color: isHeartbeatFresh(row) ? '#67C23A' : '#F56C6C' }">
                {{ formatRelative(row.last_heartbeat_at) }}
              </span>
              <span v-else class="hint">从未</span>
            </template>
          </el-table-column>
          <el-table-column prop="last_ip" label="最近 IP" min-width="120">
            <template #default="{ row }">
              <span class="mono">{{ row.last_ip || '-' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="80">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">
                {{ row.is_active ? '启用' : '停用' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180" align="center">
            <template #default="{ row }">
              <el-button type="warning" link size="small" @click="handleRegenerateKey(row)">
                重置 Key
              </el-button>
              <el-button type="danger" link size="small" @click="handleDeleteAgent(row)">
                删除
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <!-- Create Agent Dialog -->
    <el-dialog v-model="agentDialogVisible" title="添加 Agent" width="520px">
      <el-form :model="agentForm" label-width="100px">
        <el-form-item label="名称">
          <el-input v-model="agentForm.name" placeholder="如：上海-联通" />
        </el-form-item>
        <el-form-item label="位置">
          <el-input v-model="agentForm.location" placeholder="如：中国-上海-联通" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="agentForm.description" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="agentDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="agentSubmitting" @click="submitAgent">创建</el-button>
      </template>
    </el-dialog>

    <!-- Agent key display (one-time) -->
    <el-dialog v-model="newAgentKeyVisible" title="Agent Key" width="560px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 12px">
        <strong>请立即保存 agent_key，此后将不再显示</strong>。需要配置到 Agent 的环境变量里。
      </el-alert>
      <el-input
        :value="newAgentKey"
        readonly
        type="textarea"
        :rows="2"
        style="font-family: monospace"
      />
      <div style="margin-top: 14px">
        <pre class="env-block">SUNIPIP_ADMIN_URL={{ adminUrl }}
SUNIPIP_AGENT_KEY={{ newAgentKey }}</pre>
      </div>
      <template #footer>
        <el-button type="primary" @click="copyText(newAgentKey); newAgentKeyVisible = false">
          复制并关闭
        </el-button>
      </template>
    </el-dialog>

    <!-- Target Dialog -->
    <el-dialog
      v-model="targetDialogVisible"
      :title="editingTarget ? '编辑监控目标' : '添加监控目标'"
      width="720px"
      :close-on-click-modal="false"
    >
      <el-form :model="targetForm" label-width="140px">
        <el-form-item label="名称" required>
          <el-input v-model="targetForm.name" placeholder="如：主机房-香港" />
        </el-form-item>
        <el-form-item label="关联 3x-ui 面板">
          <el-select v-model="targetForm.xui_panel_id" clearable placeholder="可选" style="width: 100%">
            <el-option
              v-for="p in xuiPanels"
              :key="p.id"
              :label="`#${p.id} ${p.name}`"
              :value="p.id"
            />
          </el-select>
        </el-form-item>

        <el-divider content-position="left">Cloudflare DNS</el-divider>
        <el-form-item label="Zone ID" required>
          <el-input v-model="targetForm.cf_zone_id" placeholder="CF 控制台右侧 → API → Zone ID" />
        </el-form-item>
        <el-form-item label="Record ID" required>
          <el-input v-model="targetForm.cf_record_id" placeholder="单条 DNS 记录的 id" />
        </el-form-item>
        <el-form-item label="Record Name" required>
          <el-input v-model="targetForm.cf_record_name" placeholder="hr.sunipip.com" />
        </el-form-item>
        <el-form-item label="CF API Token">
          <el-input
            v-model="targetForm.cf_api_token"
            type="password"
            show-password
            :placeholder="editingTarget ? '留空表示不修改' : 'Zone.DNS.Edit 权限的 API Token'"
          />
          <div class="hint">建议使用 Scoped API Token，而不是 Global API Key</div>
        </el-form-item>

        <el-divider content-position="left">主备 IP</el-divider>
        <el-form-item label="主机 IP" required>
          <el-input v-model="targetForm.primary_ip" placeholder="1.1.1.1" />
        </el-form-item>
        <el-form-item label="备机 IP" required>
          <el-input v-model="targetForm.backup_ip" placeholder="2.2.2.2" />
        </el-form-item>

        <el-divider content-position="left">探测配置</el-divider>
        <el-form-item label="探测端口" required>
          <el-input-number v-model="targetForm.probe_port" :min="1" :max="65535" />
          <span class="hint">vless 监听端口（任选一条有代表性的）</span>
        </el-form-item>
        <el-form-item label="探测 Host (可选)">
          <el-input v-model="targetForm.probe_host" placeholder="留空则使用 cf_record_name" />
        </el-form-item>
        <el-form-item label="探测周期 (分钟)">
          <el-input-number v-model="targetForm.probe_interval_minutes" :min="5" :max="120" />
        </el-form-item>
        <el-form-item label="失败阈值">
          <el-input-number v-model="targetForm.failure_threshold" :min="1" :max="10" />
          <span class="hint">连续失败次数达此阈值才切换</span>
        </el-form-item>
        <el-form-item label="超时 (秒)">
          <el-input-number v-model="targetForm.probe_timeout_seconds" :min="3" :max="60" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="targetForm.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="targetDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="targetSubmitting" @click="submitTarget">
          {{ editingTarget ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- History Dialog -->
    <el-dialog v-model="historyVisible" :title="`历史 - ${historyTarget?.name}`" width="900px" top="4vh">
      <el-tabs v-model="historyTab">
        <el-tab-pane label="探测记录" name="probes">
          <el-table :data="probes" size="small" max-height="500">
            <el-table-column label="时间" width="150">
              <template #default="{ row }">{{ formatTime(row.probed_at) }}</template>
            </el-table-column>
            <el-table-column label="Agent" width="130">
              <template #default="{ row }">{{ row.agent?.name || '-' }}</template>
            </el-table-column>
            <el-table-column label="目标" width="180">
              <template #default="{ row }">
                <span class="mono">{{ row.probed_host }}:{{ row.probed_port }}</span>
              </template>
            </el-table-column>
            <el-table-column label="结果" width="80" align="center">
              <template #default="{ row }">
                <el-tag :type="row.success ? 'success' : 'danger'" size="small">
                  {{ row.success ? '成功' : '失败' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="延迟" width="80" align="right">
              <template #default="{ row }">{{ row.latency_ms != null ? row.latency_ms + ' ms' : '-' }}</template>
            </el-table-column>
            <el-table-column label="错误" min-width="260" show-overflow-tooltip>
              <template #default="{ row }">{{ row.error_message || '-' }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
        <el-tab-pane label="切换事件" name="events">
          <el-table :data="events" size="small">
            <el-table-column label="时间" width="150">
              <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
            </el-table-column>
            <el-table-column label="动作" width="100">
              <template #default="{ row }">
                <el-tag :type="row.action === 'failover' ? 'danger' : 'success'" size="small">
                  {{ row.action === 'failover' ? '切到备' : '切回主' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="IP 变更" min-width="180">
              <template #default="{ row }">
                <span class="mono">{{ row.from_ip }}</span> → <span class="mono">{{ row.to_ip }}</span>
              </template>
            </el-table-column>
            <el-table-column label="触发" width="100">
              <template #default="{ row }">
                <el-tag size="small" :type="row.trigger === 'auto' ? 'warning' : 'info'">
                  {{ row.trigger === 'auto' ? '自动' : '手动' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作人" width="100">
              <template #default="{ row }">{{ row.user?.name || '系统' }}</template>
            </el-table-column>
            <el-table-column label="原因" min-width="240" show-overflow-tooltip>
              <template #default="{ row }">{{ row.reason }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Connection } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import {
  getDnsAgents, createDnsAgent, deleteDnsAgent, regenerateAgentKey,
  getDnsTargets, createDnsTarget, updateDnsTarget, deleteDnsTarget,
  getDnsTargetProbes, getDnsTargetEvents,
  manualFailover, manualFailback,
} from '@/api/dnsMonitor'
import { getXuiPanels } from '@/api/xuiPanels'

dayjs.extend(relativeTime)

const activeTab = ref('targets')
const adminUrl = window.location.origin

// ========== helpers ==========
function formatTime(t) { return t ? dayjs(t).format('YYYY-MM-DD HH:mm:ss') : '-' }
function formatRelative(t) { return t ? dayjs(t).fromNow() : '-' }
function statusTag(s) {
  return { healthy: 'success', degraded: 'warning', failed: 'danger', switched: 'danger' }[s] || 'info'
}
function statusLabel(s) {
  return { healthy: '正常', degraded: '异常', failed: '失败', switched: '已切换' }[s] || s
}
function currentIp(row) {
  return row.current_active === 'backup' ? row.backup_ip : row.primary_ip
}
function isHeartbeatFresh(agent) {
  if (!agent.last_heartbeat_at) return false
  return dayjs().diff(dayjs(agent.last_heartbeat_at), 'minute') < 60
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch { ElMessage.warning('复制失败') }
}

// ========== Targets ==========
const targets = ref([])
const targetsLoading = ref(false)
const xuiPanels = ref([])

async function loadTargets() {
  targetsLoading.value = true
  try {
    targets.value = (await getDnsTargets()) || []
  } catch { /* handled */ }
  finally { targetsLoading.value = false }
}

async function loadXuiPanels() {
  try {
    xuiPanels.value = (await getXuiPanels()) || []
  } catch { /* ignore */ }
}

const targetDialogVisible = ref(false)
const editingTarget = ref(null)
const targetSubmitting = ref(false)
const targetForm = reactive({
  name: '',
  xui_panel_id: null,
  cf_zone_id: '',
  cf_record_id: '',
  cf_record_name: '',
  cf_api_token: '',
  primary_ip: '',
  backup_ip: '',
  probe_port: 443,
  probe_host: '',
  probe_interval_minutes: 25,
  failure_threshold: 3,
  probe_timeout_seconds: 8,
  is_active: 1,
})

function resetTargetForm() {
  Object.assign(targetForm, {
    name: '', xui_panel_id: null,
    cf_zone_id: '', cf_record_id: '', cf_record_name: '', cf_api_token: '',
    primary_ip: '', backup_ip: '',
    probe_port: 443, probe_host: '',
    probe_interval_minutes: 25, failure_threshold: 3, probe_timeout_seconds: 8,
    is_active: 1,
  })
}

function openCreateTarget() {
  editingTarget.value = null
  resetTargetForm()
  targetDialogVisible.value = true
}

function openEditTarget(row) {
  editingTarget.value = row
  Object.assign(targetForm, {
    name: row.name,
    xui_panel_id: row.xui_panel_id || null,
    cf_zone_id: row.cf_zone_id,
    cf_record_id: row.cf_record_id,
    cf_record_name: row.cf_record_name,
    cf_api_token: '',
    primary_ip: row.primary_ip,
    backup_ip: row.backup_ip,
    probe_port: row.probe_port,
    probe_host: row.probe_host || '',
    probe_interval_minutes: row.probe_interval_minutes,
    failure_threshold: row.failure_threshold,
    probe_timeout_seconds: row.probe_timeout_seconds,
    is_active: row.is_active,
  })
  targetDialogVisible.value = true
}

async function submitTarget() {
  targetSubmitting.value = true
  try {
    const payload = { ...targetForm }
    if (editingTarget.value && !payload.cf_api_token) {
      delete payload.cf_api_token
    }
    if (editingTarget.value) {
      await updateDnsTarget(editingTarget.value.id, payload)
      ElMessage.success('已保存')
    } else {
      await createDnsTarget(payload)
      ElMessage.success('已创建')
    }
    targetDialogVisible.value = false
    loadTargets()
  } catch { /* handled */ }
  finally { targetSubmitting.value = false }
}

async function handleDeleteTarget(row) {
  try {
    await ElMessageBox.confirm(`删除监控目标「${row.name}」？`, '确认', { type: 'warning' })
    await deleteDnsTarget(row.id)
    ElMessage.success('已删除')
    loadTargets()
  } catch { /* cancelled */ }
}

async function handleFailover(row) {
  try {
    await ElMessageBox.confirm(
      `确认把 DNS ${row.cf_record_name} 从主机 ${row.primary_ip} 切换到备机 ${row.backup_ip}？`,
      '手动切换到备机',
      { type: 'warning' }
    )
  } catch { return }

  try {
    await manualFailover(row.id, '管理员手动切换')
    ElMessage.success('已切换到备机')
    loadTargets()
  } catch { /* handled */ }
}

async function handleFailback(row) {
  try {
    await ElMessageBox.confirm(
      `确认把 DNS ${row.cf_record_name} 从备机 ${row.backup_ip} 切回主机 ${row.primary_ip}？请先确认主机已恢复。`,
      '切回主机',
      { type: 'warning' }
    )
  } catch { return }

  try {
    await manualFailback(row.id, '管理员手动切回')
    ElMessage.success('已切回主机')
    loadTargets()
  } catch { /* handled */ }
}

// ========== History ==========
const historyVisible = ref(false)
const historyTarget = ref(null)
const historyTab = ref('probes')
const probes = ref([])
const events = ref([])

async function openHistory(row) {
  historyTarget.value = row
  historyTab.value = 'probes'
  historyVisible.value = true
  try {
    const [p, e] = await Promise.all([
      getDnsTargetProbes(row.id, 100),
      getDnsTargetEvents(row.id),
    ])
    probes.value = p || []
    events.value = e || []
  } catch { /* handled */ }
}

// ========== Agents ==========
const agents = ref([])
const agentsLoading = ref(false)
const agentDialogVisible = ref(false)
const agentSubmitting = ref(false)
const agentForm = reactive({ name: '', location: '', description: '' })

const newAgentKeyVisible = ref(false)
const newAgentKey = ref('')

async function loadAgents() {
  agentsLoading.value = true
  try {
    agents.value = (await getDnsAgents()) || []
  } catch { /* handled */ }
  finally { agentsLoading.value = false }
}

function openCreateAgent() {
  agentForm.name = ''
  agentForm.location = ''
  agentForm.description = ''
  agentDialogVisible.value = true
}

async function submitAgent() {
  if (!agentForm.name) {
    ElMessage.warning('请填写名称')
    return
  }
  agentSubmitting.value = true
  try {
    const res = await createDnsAgent({ ...agentForm })
    newAgentKey.value = res?.agent_key || ''
    agentDialogVisible.value = false
    newAgentKeyVisible.value = true
    loadAgents()
  } catch { /* handled */ }
  finally { agentSubmitting.value = false }
}

async function handleDeleteAgent(row) {
  try {
    await ElMessageBox.confirm(`删除 Agent「${row.name}」？`, '确认', { type: 'warning' })
    await deleteDnsAgent(row.id)
    ElMessage.success('已删除')
    loadAgents()
  } catch { /* cancelled */ }
}

async function handleRegenerateKey(row) {
  try {
    await ElMessageBox.confirm(
      `重置 Agent 「${row.name}」的 key？旧 key 立即失效，需要更新 Agent 环境变量后才能继续上报。`,
      '确认重置',
      { type: 'warning' }
    )
  } catch { return }

  try {
    const res = await regenerateAgentKey(row.id)
    newAgentKey.value = res?.agent_key || ''
    newAgentKeyVisible.value = true
    loadAgents()
  } catch { /* handled */ }
}

onMounted(() => {
  loadTargets()
  loadAgents()
  loadXuiPanels()
})
</script>

<style lang="scss" scoped>
.dns-monitor-page {
  .page-title {
    margin: 0 0 20px;
    font-size: 20px;
    font-weight: 600;
    color: #2C3E50;
  }
  .toolbar {
    margin-bottom: 14px;
    display: flex;
    gap: 8px;
  }
  .mono {
    font-family: 'SF Mono', Consolas, Monaco, monospace;
    font-size: 12px;
    color: #4A5568;
  }
  .hint {
    font-size: 11px;
    color: #909399;
    margin-top: 2px;
  }
  .env-block {
    padding: 10px 12px;
    background: #1e293b;
    color: #f1f5f9;
    border-radius: 6px;
    font-size: 12px;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
    margin: 0;
    white-space: pre;
    overflow-x: auto;
  }
}
</style>
