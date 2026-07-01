<template>
  <div class="topup-page">
    <div class="page-head">
      <el-button link @click="$router.push('/billing/balance')">
        <el-icon><ArrowLeft /></el-icon> 返回
      </el-button>
      <h1 class="page-title">账户充值</h1>
    </div>

    <el-card class="main-card" shadow="never">
      <!-- 当前余额 -->
      <div class="balance-header">
        <div>
          <div class="label">当前余额</div>
          <div class="value">¥{{ balance.toFixed(2) }}</div>
        </div>
      </div>

      <!-- 金额选择 -->
      <div class="form-section">
        <div class="section-label">选择充值金额 (CNY)</div>
        <div class="preset-grid">
          <div
            v-for="preset in amountPresets"
            :key="preset"
            class="preset-card"
            :class="{ active: form.amount === preset }"
            @click="form.amount = preset"
          >
            <div class="preset-value">¥{{ preset }}</div>
          </div>
          <div
            class="preset-card custom-card"
            :class="{ active: isCustomAmount }"
            @click="focusCustom"
          >
            <div class="preset-value">自定义</div>
          </div>
        </div>
        <el-input-number
          v-if="isCustomAmount"
          v-model="form.amount"
          :min="1"
          :max="50000"
          :precision="2"
          placeholder="请输入金额"
          style="width: 100%; margin-top: 12px"
          size="large"
        />
      </div>

      <!-- 网关选择 -->
      <div class="form-section" v-loading="gatewaysLoading">
        <div class="section-label">选择支付网关</div>
        <el-empty v-if="!gateways.length && !gatewaysLoading" description="暂无可用的支付网关，请联系客服" :image-size="80" />
        <div v-else class="gateway-list">
          <div
            v-for="g in gateways"
            :key="g.id"
            class="gateway-card"
            :class="{ active: form.gateway_id === g.id }"
            @click="selectGateway(g)"
          >
            <div class="gateway-icon">
              <el-icon :size="28"><CreditCard /></el-icon>
            </div>
            <div class="gateway-info">
              <div class="gateway-name">{{ g.name }}</div>
              <div class="gateway-methods">
                <el-tag
                  v-for="m in g.methods"
                  :key="m"
                  size="small"
                  type="info"
                  effect="plain"
                  style="margin-right: 4px"
                >
                  {{ methodLabel(m) }}
                </el-tag>
                <span v-if="!g.methods?.length" style="font-size: 12px; color: #909399">全部支付方式</span>
              </div>
            </div>
            <el-icon v-if="form.gateway_id === g.id" class="check-icon"><CircleCheckFilled /></el-icon>
          </div>
        </div>
      </div>

      <!-- 支付方式选择（仅当网关支持多个） -->
      <div v-if="selectedGateway?.methods?.length > 1" class="form-section">
        <div class="section-label">选择支付方式</div>
        <el-radio-group v-model="form.method" size="large">
          <el-radio-button v-for="m in selectedGateway.methods" :key="m" :value="m">
            {{ methodLabel(m) }}
          </el-radio-button>
        </el-radio-group>
      </div>

      <!-- 提交 -->
      <div class="submit-section">
        <el-button
          type="primary"
          size="large"
          :loading="submitting"
          :disabled="!canSubmit"
          @click="handleSubmit"
          style="width: 100%"
        >
          <el-icon><Wallet /></el-icon>
          去支付 ¥{{ Number(form.amount || 0).toFixed(2) }}
        </el-button>
        <el-alert type="info" :closable="false" show-icon style="margin-top: 12px">
          点击支付后将在页面内打开收银台完成支付。支付成功后余额会自动到账。
        </el-alert>
      </div>
    </el-card>

    <!-- 支付 iframe 弹窗 -->
    <el-dialog
      v-model="payDialogVisible"
      title="正在支付..."
      width="480px"
      :close-on-click-modal="false"
      class="pay-iframe-dialog"
      @close="onPayDialogClose"
    >
      <div class="pay-iframe-wrap">
        <iframe
          v-if="payIframeUrl"
          :src="payIframeUrl"
          class="pay-iframe"
          frameborder="0"
          allowpaymentrequest
          allow="payment"
        />
        <iframe
          v-else-if="payIframeHtml"
          ref="payIframeRef"
          class="pay-iframe"
          frameborder="0"
        />
      </div>
      <div class="pay-iframe-hint">支付完成后请点击下方按钮</div>
      <template #footer>
        <el-button @click="payDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="checkPayResult">我已支付完成</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import { ArrowLeft, CreditCard, CircleCheckFilled, Wallet } from '@element-plus/icons-vue'
import { getTopupMethods, createTopup } from '@/api/billing'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const balance = computed(() => authStore.balance)
const gatewaysLoading = ref(false)
const gateways = ref([])

const amountPresets = [50, 100, 200, 500, 1000, 2000, 5000]

const form = reactive({
  amount: 100,
  gateway_id: null,
  method: null,
})

const submitting = ref(false)
const payDialogVisible = ref(false)
const payIframeUrl = ref('')
const payIframeHtml = ref('')
const payIframeRef = ref(null)

const isCustomAmount = computed(() => !amountPresets.includes(Number(form.amount)))
const selectedGateway = computed(() => gateways.value.find(g => g.id === form.gateway_id))

const canSubmit = computed(() => {
  return form.gateway_id && form.amount > 0 && form.amount <= 50000
})

function methodLabel(m) {
  return {
    alipay: '支付宝', wxpay: '微信', qqpay: 'QQ钱包',
    bank: '网银', jdpay: '京东', usdt: 'USDT',
  }[m] || m
}

function focusCustom() {
  if (!isCustomAmount.value) form.amount = 0
}

function selectGateway(g) {
  form.gateway_id = g.id
  // 默认选第一个方式
  form.method = g.methods?.[0] || null
}

async function fetchGateways() {
  gatewaysLoading.value = true
  try {
    const res = await getTopupMethods()
    gateways.value = Array.isArray(res) ? res : []
    if (gateways.value.length) {
      selectGateway(gateways.value[0])
    }
  } catch { /* handled */ }
  finally { gatewaysLoading.value = false }
}

async function handleSubmit() {
  if (!canSubmit.value) {
    ElMessage.warning('请选择金额和支付方式')
    return
  }

  submitting.value = true
  try {
    const res = await createTopup({
      gateway_id: form.gateway_id,
      amount: Number(form.amount),
      method: form.method || undefined,
    })
    if (res?.pay_type === 'form' && res?.pay_html) {
      payIframeUrl.value = ''
      payIframeHtml.value = res.pay_html
      payDialogVisible.value = true
      await nextTick()
      const iframe = payIframeRef.value
      if (iframe) {
        const doc = iframe.contentDocument || iframe.contentWindow?.document
        if (doc) { doc.open(); doc.write(res.pay_html); doc.close() }
      }
    } else if (res?.checkout_url || res?.pay_url) {
      payIframeHtml.value = ''
      payIframeUrl.value = res.checkout_url || res.pay_url
      payDialogVisible.value = true
    } else {
      ElMessage.error('未返回支付链接')
    }
    pollBalance()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function onPayDialogClose() {
  payIframeUrl.value = ''
  payIframeHtml.value = ''
}

async function checkPayResult() {
  const startBal = balance.value
  await authStore.fetchMe()
  if (authStore.balance > startBal) {
    clearInterval(pollTimer)
    ElMessage.success(`充值成功！余额已更新为 ¥${authStore.balance.toFixed(2)}`)
    payDialogVisible.value = false
  } else {
    ElMessage.info('暂未检测到到账，请稍后再试')
  }
}

let pollTimer = null
function pollBalance() {
  const startBalance = balance.value
  let attempts = 0
  clearInterval(pollTimer)
  pollTimer = setInterval(async () => {
    attempts++
    await authStore.fetchMe()
    if (authStore.balance > startBalance) {
      clearInterval(pollTimer)
      ElMessage.success(`充值成功！余额已更新为 ¥${authStore.balance.toFixed(2)}`)
      payDialogVisible.value = false
    }
    if (attempts >= 60) clearInterval(pollTimer)
  }, 5000)
}

onMounted(async () => {
  await authStore.fetchMe()
  fetchGateways()
})
</script>

<style lang="scss" scoped>
.topup-page {
  max-width: 760px;
  margin: 0 auto;
}

.page-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
  .page-title { margin: 0; font-size: 22px; font-weight: 700; color: #2C3E50; }
}

.main-card {
  border-radius: 16px;
  border: 1px solid #EADFD2;
  :deep(.el-card__body) { padding: 28px; }
}

.balance-header {
  padding: 20px 24px;
  background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
  border: 1px solid #F5D9B5;
  border-radius: 12px;
  margin-bottom: 24px;
  .label { font-size: 13px; color: #909399; margin-bottom: 4px; }
  .value {
    font-size: 36px;
    font-weight: 800;
    color: #E8913A;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
    line-height: 1.1;
  }
}

.form-section {
  margin-bottom: 24px;
  .section-label {
    font-size: 14px;
    font-weight: 600;
    color: #2C3E50;
    margin-bottom: 12px;
  }
}

.preset-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
}

.preset-card {
  padding: 16px 10px;
  background: #fff;
  border: 2px solid #EADFD2;
  border-radius: 10px;
  text-align: center;
  cursor: pointer;
  transition: all 0.15s;

  &:hover {
    border-color: #E8913A;
    transform: translateY(-1px);
  }
  &.active {
    border-color: #E8913A;
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
  }
  .preset-value {
    font-size: 17px;
    font-weight: 700;
    color: #2C3E50;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
  }
  &.custom-card .preset-value { color: #909399; }
  &.active .preset-value { color: #E8913A; }
}

.gateway-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.gateway-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  background: #fff;
  border: 2px solid #EADFD2;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
  position: relative;

  &:hover {
    border-color: #E8913A;
  }
  &.active {
    border-color: #E8913A;
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
  }
  .gateway-icon {
    width: 48px;
    height: 48px;
    background: #FDF0E2;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #E8913A;
  }
  .gateway-info { flex: 1; }
  .gateway-name {
    font-size: 15px;
    font-weight: 600;
    color: #2C3E50;
    margin-bottom: 4px;
  }
  .check-icon {
    color: #E8913A;
    font-size: 22px;
  }
}

.submit-section {
  margin-top: 32px;
}

.pay-iframe-wrap {
  width: 100%;
  height: 460px;
  border: 1px solid #EADFD2;
  border-radius: 8px;
  overflow: hidden;
}
.pay-iframe {
  width: 100%;
  height: 100%;
  border: none;
}
.pay-iframe-hint {
  text-align: center;
  color: #94A3B8;
  font-size: 13px;
  margin-top: 10px;
}

@media (max-width: 768px) {
  .page-head {
    margin-bottom: 12px;
    .page-title { font-size: 18px; }
  }
  .main-card :deep(.el-card__body) { padding: 16px 12px; }
  .balance-header {
    padding: 14px 16px; margin-bottom: 16px;
    .value { font-size: 28px; }
  }
  .preset-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .preset-card { padding: 12px 6px;
    .preset-value { font-size: 15px; }
  }
  .gateway-card { padding: 12px 14px; gap: 10px;
    .gateway-icon { width: 40px; height: 40px; }
    .gateway-name { font-size: 14px; }
  }
  .submit-section { margin-top: 20px; }
  .pay-iframe-wrap { height: 380px; }
}
</style>
