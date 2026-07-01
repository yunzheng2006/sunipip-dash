<template>
  <div class="subscription-detail" v-loading="loading">
    <div class="page-header">
      <el-button @click="$router.back()" :icon="ArrowLeft">返回</el-button>
      <h2 class="page-title">订阅详情 #{{ route.params.id }}</h2>
    </div>

    <template v-if="sub">
      <el-card class="info-card">
        <template #header>
          <div class="card-header">
            <span><el-icon><Calendar /></el-icon> 订阅信息</span>
            <div>
              <el-tag :type="statusTag(sub.status)" size="default">{{ statusLabel(sub.status) }}</el-tag>
              <el-button v-if="canRenew(sub)" type="success" size="small" @click="renewVisible = true" style="margin-left: 12px">
                {{ sub.status === 'expired' ? '续费激活' : '续费' }}
              </el-button>
              <el-button v-if="sub.status === 'active'" type="danger" size="small" @click="handleCancel">取消订阅</el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="单价 /月">
            <span style="color: #E8913A; font-weight: 600; font-size: 16px">
              ¥{{ getMonthlyPrice(sub).toFixed(2) }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="时长">
            {{ sub.duration }}{{ unitLabel(sub.unit) }}
          </el-descriptions-item>
          <el-descriptions-item label="续费次数">{{ sub.renewed_count || 0 }} 次</el-descriptions-item>
          <el-descriptions-item label="开始时间">{{ formatDateTime(sub.started_at) }}</el-descriptions-item>
          <el-descriptions-item label="到期时间">
            <span :style="{ color: isExpiringSoon(sub.expires_at) ? '#F56C6C' : '' }">
              {{ formatDateTime(sub.expires_at) }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="上次续费">{{ formatDateTime(sub.last_renewed_at) || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建人">{{ sub.creator?.name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ formatDateTime(sub.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="自动续费">
            <el-tag size="small" :type="sub.auto_renew ? 'success' : 'info'">
              {{ sub.auto_renew ? '开启' : '关闭' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item v-if="sub.remark" label="备注" :span="3">{{ sub.remark }}</el-descriptions-item>
        </el-descriptions>
      </el-card>

      <!-- Customer Info -->
      <el-card class="info-card" v-if="sub.customer">
        <template #header>
          <span><el-icon><User /></el-icon> 归属客户</span>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="客户名称">
            <el-link type="primary" @click="$router.push(`/customers/${sub.customer.id}`)">
              <strong>{{ sub.customer.customer_name }}</strong>
            </el-link>
          </el-descriptions-item>
          <el-descriptions-item label="登录账号">
            <span class="mono">{{ sub.customer.username }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="业务归属">{{ sub.customer.sales_person || '-' }}</el-descriptions-item>
          <el-descriptions-item label="手机">{{ sub.customer.phone || '-' }}</el-descriptions-item>
          <el-descriptions-item label="邮箱">{{ sub.customer.email || '-' }}</el-descriptions-item>
          <el-descriptions-item label="账户余额">
            <span style="color: #E8913A; font-weight: 600">¥{{ Number(sub.customer.balance || 0).toFixed(2) }}</span>
          </el-descriptions-item>
        </el-descriptions>
      </el-card>

      <!-- Proxy IP Info -->
      <el-card class="info-card" v-if="sub.proxy_ip">
        <template #header>
          <span><el-icon><Monitor /></el-icon> IP资产详情</span>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="资产名称" :span="3">
            <el-link type="primary" @click="$router.push(`/proxy-ips/${sub.proxy_ip.id}`)">
              <strong>{{ sub.proxy_ip.asset_name || '-' }}</strong>
            </el-link>
          </el-descriptions-item>
          <el-descriptions-item label="IP:端口">
            <span class="mono">{{ sub.proxy_ip.ip_address }}:{{ sub.proxy_ip.port }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="地区">{{ sub.proxy_ip.country_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="协议">{{ sub.proxy_ip.protocol }}</el-descriptions-item>
          <el-descriptions-item label="IP归属">
            <el-tag size="small" type="info" effect="plain">{{ sub.proxy_ip.source_name || '-' }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="资产组">{{ sub.proxy_ip.asset_group?.name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="IP组">{{ sub.proxy_ip.ip_group?.name || '-' }}</el-descriptions-item>
        </el-descriptions>
      </el-card>
    </template>

    <!-- Renew Dialog -->
    <el-dialog v-model="renewVisible" title="续费订阅" width="480px">
      <el-form :model="renewForm" label-width="80px">
        <el-form-item label="客户">
          <el-input :value="sub?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="资产">
          <el-input :value="sub?.proxy_ip?.asset_name" disabled />
        </el-form-item>
        <el-form-item v-if="renewalBreakdown" label="价格明细">
          <div style="font-size: 12px; color: #606266; line-height: 1.8">
            <div>IP 底价 ¥{{ renewalBreakdown.ip_list_price }} → 折后 <strong>¥{{ renewalBreakdown.ip_price }}</strong></div>
            <div v-if="renewalBreakdown.forward_base_price > 0">
              中转底价 ¥{{ renewalBreakdown.forward_base_price }} → 折后 <strong>¥{{ renewalBreakdown.forward_price }}</strong>
            </div>
            <div style="color: #909399">
              折扣来源：{{ discountSourceLabel }}
              <template v-if="renewalBreakdown.discount_percent">（{{ renewalBreakdown.discount_percent }}%）</template>
            </div>
          </div>
        </el-form-item>
        <el-form-item label="续费时长">
          <el-input-number v-model="renewForm.duration" :min="1" :max="12" style="width: 100%" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            {{ renewForm.duration }} 个月（{{ renewForm.duration * 30 }} 天）
          </div>
        </el-form-item>
        <el-form-item label="月单价">
          <el-input-number v-model="renewForm.price" :min="0" :precision="2" style="width: 100%" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            续费总额 <strong style="color: #E6A23C">¥{{ (renewForm.price * renewForm.duration).toFixed(2) }}</strong>（{{ renewForm.duration }} 个月）
          </div>
        </el-form-item>
        <el-form-item label="扣费方式">
          <el-radio-group v-model="renewForm.skip_deduct">
            <el-radio :value="false">扣客户余额</el-radio>
            <el-radio :value="true">不扣余额（线下已付）</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="renewVisible = false">取消</el-button>
        <el-button type="primary" :loading="renewLoading" @click="submitRenew">
          {{ renewForm.skip_deduct ? '确认续费（不扣余额）' : `确认续费 ${renewForm.duration} 个月（扣 ¥${(renewForm.price * renewForm.duration).toFixed(2)}）` }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Cancel Dialog -->
    <el-dialog v-model="cancelVisible" title="取消订阅" width="420px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        确定取消此订阅？IP 将释放为可用。
      </el-alert>
      <el-form label-width="110px">
        <el-form-item label="取消销售业绩">
          <el-switch v-model="cancelForm.reverse_commission" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            默认取消关联的销售佣金和推荐返佣
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="cancelVisible = false">取消</el-button>
        <el-button type="danger" :loading="cancelLoading" @click="submitCancel">确认取消订阅</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, Calendar, User, Monitor } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import { getSubscription, renewSubscription, cancelSubscription } from '@/api/subscriptions'

const route = useRoute()
const loading = ref(false)
const sub = ref(null)

function formatDateTime(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function isExpiringSoon(d) { return d && dayjs(d).diff(dayjs(), 'day') <= 7 && dayjs(d).isAfter(dayjs()) }
function statusTag(s) { return { active: 'success', expired: 'danger', cancelled: 'info' }[s] || 'info' }
function statusLabel(s) { return { active: '活跃', expired: '已过期', cancelled: '已取消', suspended: '已暂停' }[s] || s }
function canRenew(row) {
  if (row.status === 'active') return true
  if (row.status === 'expired' && row.expires_at) {
    return dayjs().diff(dayjs(row.expires_at), 'day') <= 3
  }
  return false
}
function unitLabel(u) { return { 1: '天', 2: '周', 3: '月', 4: '年' }[u] || '' }
function getMonthlyPrice(s) {
  const d = Number(s.duration || 1), u = Number(s.unit || 3)
  let m = d
  if (u === 1) m = Math.max(1, Math.ceil(d / 30))
  else if (u === 2) m = Math.max(1, Math.ceil(d * 7 / 30))
  else if (u === 4) m = d * 12
  return Number(s.price || 0) / Math.max(m, 1)
}

async function fetchData() {
  loading.value = true
  try {
    sub.value = await getSubscription(route.params.id)
  } catch { /* handled */ }
  finally { loading.value = false }
}

const cancelVisible = ref(false)
const cancelLoading = ref(false)
const cancelForm = reactive({ reverse_commission: true })

function handleCancel() {
  cancelForm.reverse_commission = true
  cancelVisible.value = true
}

async function submitCancel() {
  cancelLoading.value = true
  try {
    await cancelSubscription(route.params.id, { reverse_commission: cancelForm.reverse_commission })
    ElMessage.success('已取消')
    cancelVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { cancelLoading.value = false }
}

const renewVisible = ref(false)
const renewLoading = ref(false)
const renewForm = reactive({ duration: 1, price: 0, skip_deduct: false })

const renewalBreakdown = computed(() => sub.value?.renewal_breakdown || null)
const discountSourceLabel = computed(() => {
  const s = renewalBreakdown.value?.discount_source
  return { special_fixed: '特批固定价', special_discount: '特批折扣', vip: 'VIP等级折扣', none: '无折扣' }[s] || s || ''
})

function openRenew() {
  renewForm.duration = 1
  renewForm.skip_deduct = false
  // 使用服务端动态计算的续费月单价
  const bd = renewalBreakdown.value
  renewForm.price = bd ? bd.monthly_price : getMonthlyPrice(sub.value || {})
  renewVisible.value = true
}

async function submitRenew() {
  renewLoading.value = true
  try {
    await renewSubscription(route.params.id, {
      duration: renewForm.duration * 30,
      unit: 1,
      price: Math.round(renewForm.price * renewForm.duration * 100) / 100,
      skip_deduct: renewForm.skip_deduct,
    })
    ElMessage.success('续费成功')
    renewVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { renewLoading.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.subscription-detail {
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
