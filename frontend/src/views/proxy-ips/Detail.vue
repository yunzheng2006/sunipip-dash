<template>
  <div class="proxy-ip-detail" v-loading="loading">
    <div class="page-header">
      <el-button @click="$router.back()" :icon="ArrowLeft">返回</el-button>
      <h2 class="page-title">IP详情</h2>
    </div>

    <template v-if="ipInfo">
      <!-- Basic Info -->
      <el-card class="info-card">
        <template #header>
          <div class="card-header">
            <span><el-icon><Monitor /></el-icon> 资产信息</span>
            <div style="display: flex; gap: 8px; align-items: center">
              <el-tag :type="statusTag(ipInfo.status)" size="small">{{ statusLabel(ipInfo.status) }}</el-tag>
              <el-button v-if="ipInfo.status !== 'released'" type="danger" size="small" @click="openRelease">
                释放IP
              </el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="资产名称" :span="3">
            <strong>{{ ipInfo.asset_name || '-' }}</strong>
          </el-descriptions-item>
          <el-descriptions-item label="IP地址">
            <span class="mono">{{ ipInfo.ip_address }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="端口">{{ ipInfo.port }}</el-descriptions-item>
          <el-descriptions-item label="协议">{{ ipInfo.protocol }}</el-descriptions-item>
          <el-descriptions-item label="地区/国家">
            {{ ipInfo.country_name || ipInfo.country_code || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="城市">{{ ipInfo.city || '-' }}</el-descriptions-item>
          <el-descriptions-item label="IP类型">{{ ipTypeLabel(ipInfo.ip_type) }}</el-descriptions-item>
          <el-descriptions-item label="IP归属">
            <el-tag size="small" type="info">{{ ipInfo.source_name || '-' }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="资产组">
            {{ ipInfo.asset_group?.name || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="IP组">
            {{ ipInfo.ip_group?.name || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="认证用户名" :span="3">
            <span class="mono">{{ ipInfo.auth_username || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="认证密码" :span="3">
            <span class="mono">{{ showPassword ? ipInfo.auth_password : '••••••••' }}</span>
            <el-button link size="small" @click="showPassword = !showPassword">
              {{ showPassword ? '隐藏' : '显示' }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item label="Socks5连接串" :span="3">
            <span class="mono" style="word-break: break-all">{{ ipInfo.socks5_info || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="上游到期时间" :span="3">
            <span :style="{ color: isExpiringSoon(ipInfo.upstream_expires_at) ? '#F56C6C' : '' }">
              {{ formatDate(ipInfo.upstream_expires_at) }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item v-if="ipInfo.remark" label="备注" :span="3">{{ ipInfo.remark }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <!-- Current Assignment -->
      <el-card class="info-card" v-if="ipInfo.assigned_customer">
        <template #header>
          <div class="card-header">
            <span><el-icon><User /></el-icon> 当前归属客户</span>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="客户名称">
            <el-link type="primary" @click="$router.push(`/customers/${ipInfo.assigned_customer.id}`)">
              {{ ipInfo.assigned_customer.customer_name }}
            </el-link>
          </el-descriptions-item>
          <el-descriptions-item label="业务归属">
            {{ ipInfo.assigned_customer.sales_person || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="活跃订阅">
            <el-link v-if="ipInfo.active_subscription" type="primary" @click="$router.push(`/subscriptions/${ipInfo.active_subscription.id}`)">
              订阅 #{{ ipInfo.active_subscription.id }}
            </el-link>
            <span v-else>-</span>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <!-- Forward Rule -->
      <el-card v-if="forwardRule" class="info-card">
        <template #header>
          <div class="card-header">
            <span><el-icon><Share /></el-icon> 端口转发</span>
            <el-tag size="small" :type="forwardRule.status === 'active' ? 'success' : 'danger'">
              {{ forwardRule.status }}
            </el-tag>
          </div>
        </template>
        <el-descriptions :column="2" border>
          <el-descriptions-item label="转发规则名" :span="2">
            <span class="mono">{{ forwardRule.name }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="NY 面板">
            {{ forwardRule.panel?.name || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="设备组">
            {{ forwardRule.device_group?.name || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="对外地址">
            <span class="mono" style="color: #E8913A; font-weight: 600">
              {{ forwardRule.device_group?.custom_connect_host || forwardRule.device_group?.original_connect_host }}:{{ forwardRule.listen_port }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="转发到">
            <span class="mono">{{ forwardRule.dest_host }}:{{ forwardRule.dest_port }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="限速">
            {{ forwardRule.speed_limit_mbps ? forwardRule.speed_limit_mbps + ' Mbps' : '不限速' }}
          </el-descriptions-item>
          <el-descriptions-item label="月转发费">
            ¥{{ Number(forwardRule.forward_fee || 0).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item v-if="forwardRule.error_message" label="错误信息" :span="2">
            <span style="color: #F56C6C">{{ forwardRule.error_message }}</span>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <!-- Spark Release Status -->
      <el-card v-if="ipInfo.spark_instance_id" class="info-card">
        <template #header>
          <div class="card-header">
            <span><el-icon><Connection /></el-icon> Spark 上游状态</span>
            <div style="display: flex; gap: 8px">
              <el-button size="small" :loading="verifying" @click="handleVerifySpark">
                <el-icon><Refresh /></el-icon> 核验释放状态
              </el-button>
              <el-button
                v-if="ipInfo.spark_release_status === 'failed'"
                type="warning"
                size="small"
                :loading="retrying"
                @click="handleRetrySpark"
              >
                重试释放
              </el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="2" border>
          <el-descriptions-item label="Spark 实例 ID" :span="2">
            <span class="mono">{{ ipInfo.spark_instance_id }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="释放状态">
            <el-tag :type="sparkReleaseTag(ipInfo.spark_release_status)" size="small">
              {{ sparkReleaseLabel(ipInfo.spark_release_status) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="释放订单号">
            <span class="mono">{{ ipInfo.spark_release_order_no || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="Spark 确认时间" :span="2">
            {{ formatDateTime(ipInfo.spark_released_at) }}
          </el-descriptions-item>
          <el-descriptions-item v-if="ipInfo.spark_release_error" label="失败原因" :span="2">
            <span style="color: #F56C6C">{{ ipInfo.spark_release_error }}</span>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <!-- Subscription History -->
      <el-card class="info-card">
        <template #header>
          <span><el-icon><Calendar /></el-icon> 订阅历史</span>
        </template>
        <el-table :data="ipInfo.subscriptions || []" stripe size="small">
          <el-table-column prop="id" label="ID" width="60" />
          <el-table-column label="客户" min-width="120">
            <template #default="{ row }">{{ row.customer?.customer_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="价格" width="100" align="right">
            <template #default="{ row }">¥{{ Number(row.price || 0).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="开始时间" width="120">
            <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
          </el-table-column>
          <el-table-column label="到期时间" width="120">
            <template #default="{ row }">{{ formatDate(row.expires_at) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-tag size="small" :type="subStatusTag(row.status)">{{ subStatusLabel(row.status) }}</el-tag>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!ipInfo.subscriptions?.length" description="暂无订阅记录" :image-size="60" />
      </el-card>

      <!-- Assignment Logs -->
      <el-card class="info-card">
        <template #header>
          <span><el-icon><Document /></el-icon> 分配历史日志</span>
        </template>
        <el-table :data="ipInfo.assignment_logs || []" stripe size="small">
          <el-table-column label="时间" width="160">
            <template #default="{ row }">{{ formatDateTime(row.created_at) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="actionTag(row.action)">{{ actionLabel(row.action) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="客户" min-width="120">
            <template #default="{ row }">{{ row.customer?.customer_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作人" width="100">
            <template #default="{ row }">{{ row.operator?.name || '系统' }}</template>
          </el-table-column>
          <el-table-column label="备注" min-width="150">
            <template #default="{ row }">{{ row.remark || '-' }}</template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!ipInfo.assignment_logs?.length" description="暂无分配日志" :image-size="60" />
      </el-card>
    </template>

    <!-- Release Dialog -->
    <el-dialog v-model="releaseVisible" title="释放IP资产" width="500px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        释放后该IP将不再出现在可分配资源池，但历史记录会保留。此操作不可逆。
      </el-alert>
      <el-form :model="releaseForm" label-width="100px">
        <el-form-item label="资产名称">
          <el-input :value="ipInfo?.asset_name" disabled />
        </el-form-item>
        <el-form-item label="IP地址">
          <el-input :value="`${ipInfo?.ip_address}:${ipInfo?.port}`" disabled />
        </el-form-item>
        <el-form-item label="IP归属">
          <el-input :value="ipInfo?.source_name" disabled />
        </el-form-item>
        <el-form-item label="释放原因">
          <el-input v-model="releaseForm.reason" type="textarea" :rows="3" placeholder="选填，如IP失效、无法使用等" />
        </el-form-item>
        <el-form-item v-if="ipInfo?.source_name === '斯帕克' && ipInfo?.spark_instance_id" label="Spark释放">
          <el-switch v-model="releaseForm.auto_release_spark" />
          <span style="margin-left: 8px; font-size: 12px; color: #909399">
            同时调用 Spark API 释放（可获得上游退款）
          </span>
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
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Monitor, User, Calendar, Document, Connection, Refresh, Share } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { getProxyIp, releaseProxyIp, verifySparkRelease, retrySparkRelease } from '@/api/proxyIps'

const route = useRoute()
const loading = ref(false)
const ipInfo = ref(null)
const showPassword = ref(false)

// Forward rule (read from active subscription)
const forwardRule = computed(() => {
  return ipInfo.value?.active_subscription?.forward_rule || null
})

// Release dialog
const releaseVisible = ref(false)
const releaseLoading = ref(false)
const releaseForm = reactive({ reason: '', auto_release_spark: true })

// Spark release status
const verifying = ref(false)
const retrying = ref(false)

function sparkReleaseTag(s) {
  return { confirmed: 'success', pending: 'warning', failed: 'danger' }[s] || 'info'
}
function sparkReleaseLabel(s) {
  return {
    confirmed: '✓ 已确认释放',
    pending: '等待 Spark 确认',
    failed: '❌ 释放失败',
  }[s] || '未发起释放'
}

async function handleVerifySpark() {
  verifying.value = true
  try {
    const res = await verifySparkRelease(ipInfo.value.id)
    ipInfo.value = { ...ipInfo.value, ...(res?.proxy_ip || {}) }
    const verify = res?.verify || {}
    const type = verify.status === 'confirmed' ? 'success' : (verify.status === 'failed' ? 'error' : 'warning')
    ElMessage({ type, message: verify.message || '核验完成', duration: 5000 })
  } catch { /* handled */ }
  finally { verifying.value = false }
}

async function handleRetrySpark() {
  try {
    await ElMessageBox.confirm('重新调用 Spark DelProxy API 释放该实例？', '重试释放', { type: 'warning' })
  } catch { return }
  retrying.value = true
  try {
    const res = await retrySparkRelease(ipInfo.value.id)
    ipInfo.value = { ...ipInfo.value, ...(res?.proxy_ip || {}) }
    const result = res?.spark_release || {}
    const type = result.status === 'failed' ? 'error' : 'success'
    ElMessage({ type, message: result.message || '重试完成', duration: 5000 })
  } catch { /* handled */ }
  finally { retrying.value = false }
}

function openRelease() {
  releaseForm.reason = ''
  releaseForm.auto_release_spark = true
  releaseVisible.value = true
}

async function submitRelease() {
  releaseLoading.value = true
  try {
    await releaseProxyIp(ipInfo.value.id, { ...releaseForm })
    ElMessage.success('IP 已释放')
    releaseVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { releaseLoading.value = false }
}

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function formatDateTime(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function isExpiringSoon(d) { return d && dayjs(d).diff(dayjs(), 'day') <= 7 && dayjs(d).isAfter(dayjs()) }
function statusTag(s) { return { available: 'success', assigned: 'warning', expired: 'danger', disabled: 'info', released: 'info' }[s] || 'info' }
function statusLabel(s) { return { available: '可用', assigned: '已分配', expired: '已过期', disabled: '已停用', released: '已释放' }[s] || s }
function subStatusTag(s) { return { active: 'success', expired: 'danger', cancelled: 'info' }[s] || 'info' }
function subStatusLabel(s) { return { active: '活跃', expired: '已过期', cancelled: '已取消' }[s] || s }
function actionTag(a) { return { assign: 'success', unassign: 'warning', reassign: 'primary' }[a] || 'info' }
function actionLabel(a) { return { assign: '分配', unassign: '取消', reassign: '重新分配' }[a] || a }
function ipTypeLabel(t) { return { residential: '住宅', datacenter: '数据中心', isp: 'ISP' }[t] || t }

async function fetchData() {
  loading.value = true
  try {
    const res = await getProxyIp(route.params.id)
    ipInfo.value = res
  } catch { /* handled */ }
  finally { loading.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.proxy-ip-detail {
  .page-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .info-card {
    margin-bottom: 16px;
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 600;
      .el-icon { margin-right: 6px; vertical-align: middle; }
    }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 13px; }
}
</style>
