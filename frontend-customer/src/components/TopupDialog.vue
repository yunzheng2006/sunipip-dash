<template>
  <el-dialog
    v-model="visible"
    title="余额充值"
    width="480px"
    :close-on-click-modal="false"
    class="topup-dialog"
  >
    <div class="topup-body">
      <!-- 提示 -->
      <div class="balance-hint">
        当前余额 <strong>¥{{ currentBalance.toFixed(2) }}</strong>
      </div>

      <!-- 金额选择 -->
      <div class="amount-label">选择充值金额</div>
      <div class="preset-grid">
        <div
          v-for="p in presets"
          :key="p"
          class="preset-item"
          :class="{ active: amount === p, recommended: p >= minRecommended && !recommendedMarked && markRecommended(p) }"
          @click="amount = p; customMode = false"
        >
          ¥{{ p }}
          <span v-if="p === minRecommended" class="rec-badge">推荐</span>
        </div>
        <div
          class="preset-item custom"
          :class="{ active: customMode }"
          @click="customMode = true"
        >
          自定义
        </div>
      </div>
      <el-input-number
        v-if="customMode"
        v-model="amount"
        :min="1"
        :max="50000"
        :precision="0"
        size="large"
        style="width: 100%; margin-top: 10px"
        placeholder="输入金额"
      />

      <!-- 支付网关 -->
      <div class="amount-label" style="margin-top: 16px">支付网关</div>
      <div v-loading="gatewaysLoading" class="gateway-list">
        <div
          v-for="g in gateways"
          :key="g.id"
          class="gateway-item"
          :class="{ active: selectedGatewayId === g.id }"
          @click="selectGateway(g)"
        >
          <span class="gateway-name">{{ g.name }}</span>
          <el-icon v-if="selectedGatewayId === g.id" style="color: #6366F1"><CircleCheckFilled /></el-icon>
        </div>
        <div v-if="!gateways.length && !gatewaysLoading" style="color: #94A3B8; font-size: 13px; padding: 12px">
          暂无可用的支付网关
        </div>
      </div>

      <!-- 支付方式选择 -->
      <template v-if="selectedGatewayMethods.length > 1">
        <div class="amount-label" style="margin-top: 14px">支付方式</div>
        <el-radio-group v-model="selectedMethod" size="default">
          <el-radio-button v-for="m in selectedGatewayMethods" :key="m" :value="m">
            {{ methodLabel(m) }}
          </el-radio-button>
        </el-radio-group>
      </template>
    </div>

    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button
        type="primary"
        :loading="submitting"
        :disabled="!amount || !selectedGatewayId"
        @click="handlePay"
      >
        去支付 ¥{{ Number(amount || 0).toFixed(0) }}
      </el-button>
    </template>
  </el-dialog>

  <!-- 支付 iframe 弹窗 -->
  <el-dialog
    v-model="payDialogVisible"
    title="正在支付..."
    :width="payIframeUrl || payIframeHtml ? '460px' : '400px'"
    :close-on-click-modal="false"
    class="pay-iframe-dialog"
    @close="onPayDialogClose"
  >
    <!-- 有 iframe 内容（手机端或 form 模式） -->
    <template v-if="payIframeUrl || payIframeHtml">
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
    </template>
    <!-- 电脑端已打开新页面 -->
    <template v-else>
      <div class="pay-newtab-hint">
        <div class="pay-newtab-icon">🪟</div>
        <p>支付页面已在新窗口打开</p>
        <p class="pay-newtab-sub">请在新窗口完成支付，支付成功后点击下方按钮</p>
      </div>
    </template>
    <div class="pay-iframe-hint">
      支付完成后请点击下方按钮
    </div>
    <template #footer>
      <el-button @click="payDialogVisible = false">取消</el-button>
      <el-button type="primary" @click="checkPayResult">我已支付完成</el-button>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import { CircleCheckFilled } from '@element-plus/icons-vue'
import { getTopupMethods, createTopup } from '@/api/billing'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  modelValue: Boolean,
  needAmount: { type: Number, default: 0 },
})
const emit = defineEmits(['update:modelValue', 'paid'])

const authStore = useAuthStore()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const currentBalance = computed(() => authStore.balance)
const shortfall = computed(() => Math.max(0, props.needAmount - currentBalance.value))

const minRecommended = computed(() => {
  const need = Math.ceil(shortfall.value / 50) * 50
  return Math.max(50, need)
})

const presets = [50, 100, 200, 500, 1000, 2000]
const amount = ref(100)
const customMode = ref(false)
const gatewaysLoading = ref(false)
const gateways = ref([])
const selectedGatewayId = ref(null)
const selectedMethod = ref(null)
const submitting = ref(false)

const payDialogVisible = ref(false)
const payIframeUrl = ref('')
const payIframeHtml = ref('')
const payIframeRef = ref(null)

let _recommendedMarked = false
function markRecommended(p) {
  if (!_recommendedMarked && p >= minRecommended.value) {
    _recommendedMarked = true
    return true
  }
  return false
}
const recommendedMarked = ref(false)

watch(visible, (v) => {
  if (v) {
    _recommendedMarked = false
    amount.value = minRecommended.value || 100
    customMode.value = false
    fetchGateways()
  }
})

const selectedGatewayMethods = computed(() => {
  const g = gateways.value.find(g => g.id === selectedGatewayId.value)
  return g?.methods || []
})

function methodLabel(m) {
  return { alipay: '支付宝', wxpay: '微信', qqpay: 'QQ钱包', bank: '网银', usdt: 'USDT' }[m] || m
}

function selectGateway(g) {
  selectedGatewayId.value = g.id
  selectedMethod.value = g.methods?.[0] || null
}

async function fetchGateways() {
  gatewaysLoading.value = true
  try {
    const res = await getTopupMethods()
    gateways.value = Array.isArray(res) ? res : []
    if (gateways.value.length) selectGateway(gateways.value[0])
  } catch {} finally { gatewaysLoading.value = false }
}

function isMobile() {
  return window.innerWidth <= 768
}

async function handlePay() {
  if (!amount.value || !selectedGatewayId.value) return
  submitting.value = true
  try {
    const res = await createTopup({
      gateway_id: selectedGatewayId.value,
      amount: Number(amount.value),
      method: selectedMethod.value || undefined,
    })

    if (res?.checkout_url || res?.pay_url) {
      const url = res.checkout_url || res.pay_url
      if (isMobile()) {
        // 手机端直接跳转，避免 iframe 被风控拦截
        window.location.href = url
      } else {
        window.open(url, '_blank')
        visible.value = false
        payDialogVisible.value = true
      }
    } else if (res?.pay_type === 'form' && res?.pay_html) {
      // 兼容旧的 form 模式
      payIframeUrl.value = ''
      payIframeHtml.value = res.pay_html
      payDialogVisible.value = true
      visible.value = false
      await nextTick()
      const iframe = payIframeRef.value
      if (iframe) {
        const doc = iframe.contentDocument || iframe.contentWindow?.document
        if (doc) { doc.open(); doc.write(res.pay_html); doc.close() }
      }
    }

    pollBalance()
  } catch {} finally { submitting.value = false }
}

function onPayDialogClose() {
  payIframeUrl.value = ''
  payIframeHtml.value = ''
}

async function checkPayResult() {
  await authStore.fetchMe()
  if (authStore.balance > currentBalance.value) {
    clearInterval(pollTimer)
    ElMessage.success(`充值成功！余额已更新为 ¥${authStore.balance.toFixed(2)}`)
    payDialogVisible.value = false
    emit('paid')
  } else {
    ElMessage.info('暂未检测到到账，请稍后再试')
  }
}

let pollTimer = null
function pollBalance() {
  const startBalance = currentBalance.value
  let attempts = 0
  clearInterval(pollTimer)
  pollTimer = setInterval(async () => {
    attempts++
    await authStore.fetchMe()
    if (authStore.balance > startBalance) {
      clearInterval(pollTimer)
      ElMessage.success(`充值成功！余额已更新为 ¥${authStore.balance.toFixed(2)}`)
      payDialogVisible.value = false
      emit('paid')
    }
    if (attempts >= 60) {
      clearInterval(pollTimer)
    }
  }, 5000)
}
</script>

<style lang="scss" scoped>
$brand: #6366F1;

.topup-body {
  .balance-hint {
    padding: 12px 16px; background: #F8FAFC; border-radius: 12px;
    font-size: 13px; color: #475569; margin-bottom: 16px;
    strong { font-weight: 700; color: #1E293B; }
  }
  .amount-label { font-size: 13px; font-weight: 600; color: #1E293B; margin-bottom: 10px; }
}

.preset-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
  .preset-item {
    position: relative;
    padding: 14px 8px; text-align: center;
    border: 1.5px solid #E2E8F0; border-radius: 12px;
    font-size: 15px; font-weight: 600; color: #1E293B;
    cursor: pointer; transition: all 0.15s;
    &:hover { border-color: $brand; }
    &.active { border-color: $brand; background: #EEF2FF; color: $brand; }
    &.custom { font-size: 13px; color: #64748B; }
    .rec-badge {
      position: absolute; top: -8px; right: -4px;
      background: #DC2626; color: #fff; font-size: 10px; font-weight: 700;
      padding: 1px 6px; border-radius: 8px;
    }
  }
}

.gateway-list {
  display: flex; flex-direction: column; gap: 6px;
  .gateway-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 14px; border: 1.5px solid #E2E8F0; border-radius: 12px;
    cursor: pointer; transition: all 0.15s; font-size: 14px; color: #1E293B;
    &:hover { border-color: $brand; }
    &.active { border-color: $brand; background: #EEF2FF; }
    .gateway-name { font-weight: 500; }
  }
}

.pay-iframe-wrap {
  width: 100%;
  height: 460px;
  border: 1px solid #E2E8F0;
  border-radius: 8px;
  overflow: hidden;
}
.pay-iframe {
  width: 100%;
  height: 100%;
  border: none;
}
.pay-newtab-hint {
  text-align: center;
  padding: 30px 20px 20px;
}
.pay-newtab-icon {
  font-size: 48px;
  margin-bottom: 16px;
}
.pay-newtab-sub {
  color: #94A3B8;
  font-size: 13px;
  margin-top: 6px;
}
.pay-iframe-hint {
  text-align: center;
  color: #94A3B8;
  font-size: 13px;
  margin-top: 10px;
}

@media (max-width: 768px) {
  .topup-body {
    .balance-hint { padding: 10px 12px; font-size: 12px; }
    .amount-label { font-size: 12px; }
  }
  .preset-grid { grid-template-columns: repeat(3, 1fr); gap: 6px;
    .preset-item { padding: 10px 6px; font-size: 14px; }
  }
  .gateway-list {
    .gateway-item { padding: 10px 12px; font-size: 13px; }
  }
  .pay-iframe-wrap { height: 380px; }
}
</style>
