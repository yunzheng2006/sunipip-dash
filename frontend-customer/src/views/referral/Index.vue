<template>
  <div class="referral-page">
    <h1 class="page-title">推广返佣</h1>

    <el-card v-if="!isVerified" shadow="never" style="margin-bottom:16px">
      <el-empty description="请先完成实名认证后使用推广功能" :image-size="80">
        <el-button type="primary" @click="showVerifyDialog = true">去认证</el-button>
      </el-empty>
    </el-card>

    <el-card v-else-if="!info.enabled" shadow="never" style="margin-bottom:16px">
      <el-empty description="推广功能暂未开放" :image-size="80" />
    </el-card>

    <template v-else>
      <el-card class="link-card" shadow="never">
        <div class="link-inner">
          <div class="link-info">
            <div class="link-label">我的推广码</div>
            <div class="link-code">{{ info.referral_code }}</div>
            <div class="link-desc">邀请好友注册，好友新单返佣 {{ info.commission_rate }}%<template v-if="info.commission_rate_renew != info.commission_rate">，续费返佣 {{ info.commission_rate_renew }}%</template></div>
          </div>
          <div class="link-actions">
            <el-button type="primary" @click="copyLink">复制推广链接</el-button>
          </div>
        </div>
      </el-card>

      <el-row :gutter="16" style="margin:16px 0">
        <el-col :xs="12" :sm="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-num">{{ info.referral_count || 0 }}</div>
            <div class="stat-label">推荐好友</div>
          </el-card>
        </el-col>
        <el-col :xs="12" :sm="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-num">¥{{ Number(info.total_commission || 0).toFixed(2) }}</div>
            <div class="stat-label">历史累计返佣</div>
          </el-card>
        </el-col>
        <el-col :xs="12" :sm="6">
          <el-card shadow="never" class="stat-card">
            <div class="stat-num">¥{{ Number(info.pending_commission || 0).toFixed(2) }}</div>
            <div class="stat-label">待到账佣金</div>
          </el-card>
        </el-col>
        <el-col :xs="12" :sm="6">
          <el-card shadow="never" class="stat-card stat-card--accent">
            <div class="stat-num">¥{{ Number(info.commission_balance || 0).toFixed(2) }}</div>
            <div class="stat-label">返佣余额（可提现/转入）</div>
          </el-card>
        </el-col>
      </el-row>

      <!-- 余额操作卡片 -->
      <el-card shadow="never" style="margin-bottom:16px">
        <template #header><strong>返佣余额操作</strong></template>
        <div class="balance-summary">
          <div class="balance-row">
            <div class="balance-item">
              <span class="balance-label">返佣余额</span>
              <span class="balance-value balance-value--accent">¥{{ Number(info.commission_balance || 0).toFixed(2) }}</span>
            </div>
            <div class="balance-item">
              <span class="balance-label">常规余额</span>
              <span class="balance-value">¥{{ Number(info.balance || 0).toFixed(2) }}</span>
            </div>
          </div>
          <div class="balance-actions">
            <el-button type="success" :disabled="!(info.commission_balance > 0)" @click="openTransferDialog">转入常规余额</el-button>
            <span class="balance-hint">常规余额可直接购买/续费 IP，返佣余额可提现或转入</span>
          </div>
        </div>
      </el-card>

      <!-- 提现操作区 -->
      <el-card shadow="never" style="margin-bottom:16px">
        <template #header><strong>佣金提现</strong></template>
        <div class="withdraw-section">
          <div class="withdraw-info">
            <template v-if="info.withdraw_info?.bank_name">
              <div class="bank-bound">
                <el-icon><CreditCard /></el-icon>
                <span>{{ info.withdraw_info.bank_name }} · {{ maskAccount(info.withdraw_info.bank_account) }} · {{ info.withdraw_info.account_holder }}</span>
                <el-button link type="primary" size="small" @click="showBankDialog = true">修改</el-button>
              </div>
            </template>
            <template v-else>
              <el-button type="warning" plain size="small" @click="showBankDialog = true">
                <el-icon><CreditCard /></el-icon> 绑定提现银行卡
              </el-button>
            </template>
          </div>
          <div class="withdraw-actions">
            <el-button type="primary" :disabled="!info.withdraw_info?.bank_name || !(info.available_withdraw > 0)" @click="showWithdrawDialog = true">
              申请提现
            </el-button>
            <span v-if="withdrawFeePercent > 0" class="fee-hint">提现手续费 {{ withdrawFeePercent }}%</span>
            <span v-if="info.total_withdrawn > 0" class="withdrawn-hint">已提现 ¥{{ Number(info.total_withdrawn).toFixed(2) }}</span>
          </div>
        </div>
      </el-card>

      <el-card v-if="info.recent_referrals?.length" shadow="never" style="margin-bottom:16px">
        <template #header><strong>我的好友 ({{ info.recent_referrals.length }})</strong></template>
        <el-table :data="info.recent_referrals" size="small" stripe>
          <el-table-column label="好友名称" min-width="120">
            <template #default="{ row }">{{ row.customer_name }}</template>
          </el-table-column>
          <el-table-column label="订阅数" width="80" align="center">
            <template #default="{ row }">{{ row.subscriptions_count || 0 }}</template>
          </el-table-column>
          <el-table-column label="累计贡献佣金" width="130" align="right">
            <template #default="{ row }">
              <span v-if="row.total_commission > 0" style="color:#F5A623;font-weight:600">¥{{ Number(row.total_commission).toFixed(2) }}</span>
              <span v-else style="color:#94A3B8">¥0.00</span>
            </template>
          </el-table-column>
          <el-table-column label="注册时间" width="140">
            <template #default="{ row }">{{ row.created_at ? dayjs(row.created_at).format('YYYY-MM-DD HH:mm') : '-' }}</template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card shadow="never">
        <template #header><strong>佣金明细</strong></template>
        <el-table :data="commissions" size="small" stripe v-loading="commissionsLoading">
          <el-table-column label="好友" min-width="100">
            <template #default="{ row }">{{ row.referee?.customer_name || '-' }}</template>
          </el-table-column>
          <el-table-column label="类型" width="80" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.trigger_type === 'purchase'" type="success" size="small">新购</el-tag>
              <el-tag v-else-if="row.trigger_type === 'renew' || row.trigger_type === 'subscription'" type="warning" size="small">续费</el-tag>
              <el-tag v-else size="small">{{ row.trigger_type }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="订购内容" min-width="140">
            <template #default="{ row }">
              <div v-if="row.product_desc || row.ip_address">
                <div style="font-size:13px">{{ row.product_desc || '-' }}</div>
                <div v-if="row.ip_address" style="font-size:11px;color:#94A3B8">{{ row.ip_address }}</div>
              </div>
              <span v-else style="color:#94A3B8">-</span>
            </template>
          </el-table-column>
          <el-table-column label="消费金额" width="100" align="right">
            <template #default="{ row }">¥{{ Number(row.trigger_amount || 0).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="佣金比例" width="100" align="center">
            <template #default="{ row }">
              <span>{{ row.commission_rate }}%</span>
              <el-tag v-if="row.is_special_rate" type="danger" size="small" style="margin-left:4px">特殊价格</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="佣金" width="100" align="right">
            <template #default="{ row }">
              <span style="color:#F5A623;font-weight:600">+¥{{ Number(row.commission_amount || 0).toFixed(2) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="时间" width="140">
            <template #default="{ row }">{{ row.created_at ? dayjs(row.created_at).format('YYYY-MM-DD HH:mm') : '-' }}</template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!commissionsLoading && !commissions.length" description="暂无佣金记录" :image-size="60" />
        <div v-if="commissionTotal > commissions.length" style="text-align:center;margin-top:12px">
          <el-button link type="primary" @click="loadMoreCommissions">加载更多</el-button>
        </div>
      </el-card>
    </template>

    <!-- 绑定银行卡弹窗 -->
    <el-dialog v-model="showBankDialog" title="绑定提现银行卡" width="420px" :close-on-click-modal="false">
      <el-form :model="bankForm" :rules="bankRules" ref="bankFormRef" label-width="80px" size="default">
        <el-form-item label="银行名称" prop="bank_name">
          <el-input v-model="bankForm.bank_name" placeholder="如：中国工商银行" />
        </el-form-item>
        <el-form-item label="银行卡号" prop="bank_account">
          <el-input v-model="bankForm.bank_account" placeholder="请输入银行卡号" />
        </el-form-item>
        <el-form-item label="持卡人" prop="account_holder">
          <el-input v-model="bankForm.account_holder" placeholder="持卡人姓名" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showBankDialog = false">取消</el-button>
        <el-button type="primary" :loading="savingBank" @click="saveBank">保存</el-button>
      </template>
    </el-dialog>

    <!-- 提现弹窗 -->
    <el-dialog v-model="showWithdrawDialog" title="申请提现" width="400px" :close-on-click-modal="false">
      <div style="margin-bottom:16px">
        <p style="font-size:13px;color:#475569">可提现金额（返佣余额）：<strong style="color:#F5A623;font-size:18px">¥{{ Number(info.commission_balance || 0).toFixed(2) }}</strong></p>
        <p style="font-size:12px;color:#94A3B8">提现到：{{ info.withdraw_info?.bank_name }} · {{ maskAccount(info.withdraw_info?.bank_account) }}</p>
      </div>
      <el-form :model="withdrawForm" ref="withdrawFormRef" size="default">
        <el-form-item label="提现金额" prop="amount" :rules="[{ required: true, message: '请输入金额' }, { type: 'number', min: 10, message: '最低提现 ¥10' }]">
          <el-input-number v-model="withdrawForm.amount" :min="10" :max="Number(info.commission_balance || 0)" :precision="2" :step="10" style="width:100%" />
        </el-form-item>
        <el-button link type="primary" size="small" @click="withdrawForm.amount = Number(info.commission_balance || 0)" style="margin-top:-8px;margin-bottom:8px">全部提现</el-button>
      </el-form>
      <div v-if="withdrawForm.amount > 0 && withdrawFeePercent > 0" class="withdraw-fee-info">
        <div class="fee-row"><span>提现金额</span><span>¥{{ withdrawForm.amount.toFixed(2) }}</span></div>
        <div class="fee-row fee-row--fee"><span>手续费 ({{ withdrawFeePercent }}%)</span><span>-¥{{ withdrawFee.toFixed(2) }}</span></div>
        <div class="fee-row fee-row--actual"><span>实际到账</span><span>¥{{ withdrawActual.toFixed(2) }}</span></div>
      </div>
      <template #footer>
        <el-button @click="showWithdrawDialog = false">取消</el-button>
        <el-button type="primary" :loading="submittingWithdraw" @click="submitWithdraw">提交申请</el-button>
      </template>
    </el-dialog>

    <!-- 转入常规余额弹窗 -->
    <el-dialog v-model="showTransferDialog" title="转入常规余额" width="400px" :close-on-click-modal="false">
      <div style="margin-bottom:16px">
        <p style="font-size:13px;color:#475569">可转入金额（返佣余额）：<strong style="color:#F5A623;font-size:18px">¥{{ Number(info.commission_balance || 0).toFixed(2) }}</strong></p>
        <p style="font-size:12px;color:#94A3B8">转入后将计入常规余额，可直接用于购买或续费 IP。<strong>此操作不可逆。</strong></p>
      </div>
      <el-form :model="transferForm" ref="transferFormRef" size="default">
        <el-form-item label="转入金额" prop="amount" :rules="[{ required: true, message: '请输入金额' }, { type: 'number', min: 0.01, message: '最低 ¥0.01' }]">
          <el-input-number v-model="transferForm.amount" :min="0.01" :max="Number(info.commission_balance || 0)" :precision="2" :step="10" style="width:100%" />
        </el-form-item>
        <el-button link type="primary" size="small" @click="transferForm.amount = Number(info.commission_balance || 0)" style="margin-top:-8px;margin-bottom:8px">全部转入</el-button>
      </el-form>
      <template #footer>
        <el-button @click="showTransferDialog = false">取消</el-button>
        <el-button type="success" :loading="submittingTransfer" @click="submitTransfer">确认转入</el-button>
      </template>
    </el-dialog>
    <VerificationDialog v-model="showVerifyDialog" :pending="verificationPending" @verified="onVerified" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { CreditCard } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import request from '@/utils/request'
import VerificationDialog from '@/components/VerificationDialog.vue'
import { getVerificationStatus } from '@/api/verification'

const info = ref({ enabled: false })
const commissions = ref([])
const commissionsLoading = ref(false)
const commissionPage = ref(1)
const commissionTotal = ref(0)
const isVerified = ref(true)
const showVerifyDialog = ref(false)
const verificationPending = ref(null)

const showBankDialog = ref(false)
const showWithdrawDialog = ref(false)
const showTransferDialog = ref(false)
const savingBank = ref(false)
const submittingWithdraw = ref(false)
const submittingTransfer = ref(false)
const bankFormRef = ref()
const withdrawFormRef = ref()
const transferFormRef = ref()

const bankForm = reactive({ bank_name: '', bank_account: '', account_holder: '' })
const bankRules = {
  bank_name: [{ required: true, message: '请输入银行名称', trigger: 'blur' }],
  bank_account: [{ required: true, message: '请输入银行卡号', trigger: 'blur' }],
  account_holder: [{ required: true, message: '请输入持卡人姓名', trigger: 'blur' }],
}
const withdrawForm = reactive({ amount: 0 })
const transferForm = reactive({ amount: 0 })

const withdrawFeePercent = computed(() => Number(info.value.withdraw_fee_percent || 0))
const withdrawFee = computed(() => {
  if (withdrawForm.amount <= 0 || withdrawFeePercent.value <= 0) return 0
  return Math.round(withdrawForm.amount * withdrawFeePercent.value) / 100
})
const withdrawActual = computed(() => {
  return Math.max(0, Math.round((withdrawForm.amount - withdrawFee.value) * 100) / 100)
})

function openTransferDialog() {
  transferForm.amount = 0
  showTransferDialog.value = true
}

async function submitTransfer() {
  const valid = await transferFormRef.value.validate().catch(() => false)
  if (!valid) return
  if (transferForm.amount <= 0) {
    ElMessage.warning('请输入有效金额')
    return
  }
  submittingTransfer.value = true
  try {
    await request.post('/referral/transfer-to-balance', { amount: transferForm.amount })
    ElMessage.success('转入成功')
    showTransferDialog.value = false
    await fetchInfo()
    // 同步刷新全局余额
    try {
      const { useAuthStore } = await import('@/stores/auth')
      await useAuthStore().fetchMe()
    } catch {}
  } catch {}
  finally { submittingTransfer.value = false }
}

function maskAccount(acc) {
  if (!acc) return ''
  if (acc.length <= 8) return acc
  return acc.slice(0, 4) + '****' + acc.slice(-4)
}

async function fetchInfo() {
  try {
    // Check verification status first
    try {
      const vStatus = await getVerificationStatus()
      isVerified.value = vStatus?.verified || false
      verificationPending.value = vStatus?.has_pending ? vStatus : null
    } catch {}

    if (!isVerified.value) return

    info.value = (await request.get('/referral')) || { enabled: false }
    // Pre-fill bank form if info exists
    if (info.value.withdraw_info) {
      bankForm.bank_name = info.value.withdraw_info.bank_name || ''
      bankForm.bank_account = info.value.withdraw_info.bank_account || ''
      bankForm.account_holder = info.value.withdraw_info.account_holder || ''
    }
  } catch (err) {
    // Handle 403 VERIFICATION_REQUIRED as fallback
    if (err?.response?.status === 403 && err?.response?.data?.error_code === 'VERIFICATION_REQUIRED') {
      isVerified.value = false
    }
  }
}

function onVerified() {
  isVerified.value = true
  ElMessage.success('认证完成，正在加载推广数据')
  fetchInfo()
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(info.value.referral_link)
    ElMessage.success('推广链接已复制')
  } catch { ElMessage.warning('复制失败') }
}

async function copyCode() {
  try {
    await navigator.clipboard.writeText(info.value.referral_code)
    ElMessage.success('推广码已复制')
  } catch { ElMessage.warning('复制失败') }
}

async function saveBank() {
  const valid = await bankFormRef.value.validate().catch(() => false)
  if (!valid) return

  savingBank.value = true
  try {
    await request.put('/referral/withdraw-info', bankForm)
    ElMessage.success('提现信息已保存')
    showBankDialog.value = false
    await fetchInfo()
  } catch {}
  finally { savingBank.value = false }
}

async function submitWithdraw() {
  const valid = await withdrawFormRef.value.validate().catch(() => false)
  if (!valid) return

  if (withdrawForm.amount <= 0) {
    ElMessage.warning('请输入有效的提现金额')
    return
  }

  submittingWithdraw.value = true
  try {
    await request.post('/referral/withdraw', { amount: withdrawForm.amount })
    ElMessage.success('提现申请已提交，请等待审核')
    showWithdrawDialog.value = false
    await fetchInfo()
  } catch {}
  finally { submittingWithdraw.value = false }
}

async function fetchCommissions(page = 1) {
  commissionsLoading.value = true
  try {
    const res = await request.get('/referral/commissions', { params: { page, per_page: 20 } })
    const items = res?.items || res?.data || []
    if (page === 1) { commissions.value = items } else { commissions.value.push(...items) }
    commissionTotal.value = res?.pagination?.total || res?.total || items.length
    commissionPage.value = page
  } catch {} finally { commissionsLoading.value = false }
}

function loadMoreCommissions() {
  fetchCommissions(commissionPage.value + 1)
}

onMounted(() => {
  fetchInfo()
  fetchCommissions()
})
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$accent: #F5A623;

.referral-page {
  .page-title { margin: 0 0 16px; font-size: 22px; font-weight: 700; color: #1E293B; }
}
.link-card {
  border-radius: 14px; border: 2px solid #C5CDFC;
  background: linear-gradient(135deg, #F8F9FF, #EEF1FE);
  .link-inner { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
  .link-code {
    font-size: 28px; font-weight: 800; color: $brand; letter-spacing: 3px;
    font-family: 'SF Mono', Consolas, monospace; margin: 6px 0;
  }
  .link-desc { font-size: 13px; color: #475569; }
  .link-actions { display: flex; gap: 8px; }
}
.stat-card {
  text-align: center; border-radius: 12px;
  .stat-num { font-size: 24px; font-weight: 800; color: $brand; font-family: 'SF Mono', Consolas, monospace; }
  .stat-label { font-size: 12px; color: #94A3B8; margin-top: 4px; }
  &--accent .stat-num { color: $accent; }
}

.withdraw-section {
  display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
}
.withdraw-info {
  .bank-bound {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: #475569;
    .el-icon { color: $brand; }
  }
}
.withdraw-actions {
  display: flex; align-items: center; gap: 12px;
  .fee-hint { font-size: 12px; color: #E6A23C; }
  .withdrawn-hint { font-size: 12px; color: #94A3B8; }
}
.withdraw-fee-info {
  background: #F8FAFC; border-radius: 8px; padding: 12px 14px; margin-top: 12px;
  .fee-row { display: flex; justify-content: space-between; font-size: 13px; color: #475569; line-height: 1.8; }
  .fee-row--fee { color: #E6A23C; }
  .fee-row--actual { font-weight: 700; color: #1E293B; border-top: 1px solid #E2E8F0; margin-top: 4px; padding-top: 4px; }
}

.balance-summary {
  display: flex; flex-direction: column; gap: 12px;
}
.balance-row {
  display: flex; gap: 24px; flex-wrap: wrap;
}
.balance-item {
  display: flex; flex-direction: column; gap: 4px;
  .balance-label { font-size: 12px; color: #94A3B8; }
  .balance-value { font-size: 20px; font-weight: 700; color: $brand; font-family: 'SF Mono', Consolas, monospace; }
  .balance-value--accent { color: $accent; }
}
.balance-actions {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  .balance-hint { font-size: 12px; color: #94A3B8; }
}

@media (max-width: 768px) {
  .page-title { font-size: 18px; }
  .link-card .link-inner { flex-direction: column; align-items: flex-start; gap: 10px; }
  .link-card .link-code { font-size: 18px; letter-spacing: 2px; word-break: break-all; }
  .link-card .link-desc { font-size: 12px; }
  .link-card .link-actions { width: 100%;
    .el-button { width: 100%; }
  }
  .el-row { margin-left: -4px !important; margin-right: -4px !important; }
  .el-col { padding-left: 4px !important; padding-right: 4px !important; margin-bottom: 8px; }
  .stat-card :deep(.el-card__body) { padding: 10px 8px; }
  .stat-num { font-size: 16px; }
  .stat-label { font-size: 11px; }
  .withdraw-section { flex-direction: column; align-items: flex-start; gap: 8px;
    .el-button { width: 100%; }
  }
  .withdraw-actions { width: 100%; flex-direction: column; gap: 6px; align-items: flex-start; }
  .balance-row { gap: 16px; }
  .balance-actions { flex-direction: column; align-items: flex-start; gap: 6px;
    .el-button { width: 100%; }
  }
  .bank-bound { flex-wrap: wrap; font-size: 12px; gap: 4px; }
  :deep(.el-table) { font-size: 12px; }
}
</style>
