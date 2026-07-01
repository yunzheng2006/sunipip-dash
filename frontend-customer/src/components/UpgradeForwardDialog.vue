<template>
  <el-dialog
    v-model="visible"
    title="升级到 IPLC 视频专线"
    width="480px"
    :close-on-click-modal="false"
    class="upgrade-forward-dialog"
  >
    <!-- 成功状态 -->
    <div v-if="upgradeSuccess" class="success-state">
      <div class="success-icon">
        <el-icon :size="64" color="#67C23A"><CircleCheckFilled /></el-icon>
      </div>
      <h3 class="success-title">升级成功</h3>
      <p class="success-desc">视频专线正在开通中，约 1-3 分钟后生效</p>
      <p class="success-charge">本次扣费 <strong>¥{{ result.charged }}</strong></p>
      <el-button type="primary" @click="handleSuccessClose" style="margin-top: 16px">完成</el-button>
    </div>

    <!-- 加载中 -->
    <div v-else-if="loading" class="loading-state" v-loading="true" element-loading-text="正在获取升级信息...">
      <div style="height: 200px"></div>
    </div>

    <!-- 错误 -->
    <div v-else-if="errorMsg" class="error-state">
      <el-result icon="warning" :sub-title="errorMsg">
        <template #extra>
          <el-button @click="visible = false">关闭</el-button>
        </template>
      </el-result>
    </div>

    <!-- 预览信息 -->
    <template v-else-if="preview">
      <div class="upgrade-ip-info">
        <div class="info-row">
          <span class="info-label">IP 地址</span>
          <span class="info-value mono">{{ preview.ip_address }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">资产名称</span>
          <span class="info-value">{{ preview.asset_name || '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">地区</span>
          <span class="info-value">{{ preview.country_name }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">当前类型</span>
          <span class="info-value"><el-tag size="small">静态IP</el-tag></span>
        </div>
        <div class="info-row">
          <span class="info-label">到期时间</span>
          <span class="info-value">{{ formatDate(preview.expires_at) }}（剩余 {{ preview.remaining_days }} 天）</span>
        </div>
      </div>

      <el-divider>
        <el-icon><Right /></el-icon>
        升级为
      </el-divider>

      <div class="upgrade-target">
        <div class="target-badge">
          <span class="premium-badge">IPLC 视频专线</span>
        </div>
        <p class="target-desc">享受 IPLC 专线中转加速，低延迟、高稳定性</p>
      </div>

      <el-divider />

      <div class="price-breakdown">
        <div class="price-row">
          <span>视频专线月费</span>
          <span class="price-value">¥{{ preview.monthly_fee.toFixed(2) }}/月</span>
        </div>
        <div class="price-row">
          <span>剩余周期</span>
          <span class="price-value">{{ preview.remaining_days }} 天（≈ {{ preview.remaining_months }} 月）</span>
        </div>
        <div class="price-row total">
          <span>升级费用（按剩余周期折算）</span>
          <span class="price-value total-price">¥{{ preview.total_charge.toFixed(2) }}</span>
        </div>
      </div>

      <el-divider />

      <div class="balance-info">
        <div class="balance-row">
          <span>当前余额</span>
          <span :class="{ insufficient: !preview.balance_sufficient }">¥{{ preview.customer_balance.toFixed(2) }}</span>
        </div>
        <div class="balance-row" v-if="preview.balance_sufficient">
          <span>升级后余额</span>
          <span>¥{{ (preview.customer_balance - preview.total_charge).toFixed(2) }}</span>
        </div>
      </div>

      <el-alert
        v-if="!preview.balance_sufficient"
        type="error"
        :closable="false"
        show-icon
        style="margin-top: 12px"
      >
        余额不足，请先充值后再升级
      </el-alert>

      <el-alert
        v-else
        type="warning"
        :closable="false"
        show-icon
        style="margin-top: 12px"
      >
        升级后将不可撤销，续费时将按（IP 原价 + 视频专线月费）计算
      </el-alert>

      <div class="step-footer">
        <el-button @click="visible = false">取消</el-button>
        <el-button
          type="primary"
          :loading="submitting"
          :disabled="!preview.balance_sufficient"
          @click="doUpgrade"
        >
          确认升级并扣费 ¥{{ preview.total_charge.toFixed(2) }}
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { CircleCheckFilled, Right } from '@element-plus/icons-vue'
import { getUpgradeForwardPreview, upgradeForward } from '@/api/subscriptions'
import dayjs from 'dayjs'

const props = defineProps({
  modelValue: Boolean,
  subscriptionId: { type: Number, default: null },
})
const emit = defineEmits(['update:modelValue', 'upgraded'])

const visible = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const loading = ref(false)
const errorMsg = ref('')
const preview = ref(null)
const submitting = ref(false)
const upgradeSuccess = ref(false)
const result = ref({})

watch(() => props.modelValue, (val) => {
  if (val && props.subscriptionId) {
    loadPreview()
  } else if (!val) {
    errorMsg.value = ''
    preview.value = null
    upgradeSuccess.value = false
    result.value = {}
  }
})

async function loadPreview() {
  loading.value = true
  errorMsg.value = ''
  preview.value = null
  try {
    const res = await getUpgradeForwardPreview(props.subscriptionId)
    preview.value = res?.data || res
  } catch (e) {
    errorMsg.value = e?.response?.data?.message || '获取升级信息失败'
  } finally {
    loading.value = false
  }
}

async function doUpgrade() {
  try {
    await ElMessageBox.confirm(
      `确认扣费 ¥${preview.value.total_charge.toFixed(2)} 升级为 IPLC 视频专线？`,
      '确认升级',
      { confirmButtonText: '确认', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }

  submitting.value = true
  try {
    const res = await upgradeForward(props.subscriptionId)
    const data = res?.data || res
    result.value = data
    upgradeSuccess.value = true
    ElMessage.success('升级成功')
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '升级失败')
  } finally {
    submitting.value = false
  }
}

function handleSuccessClose() {
  visible.value = false
  emit('upgraded')
}

function formatDate(d) {
  return d ? dayjs(d).format('YYYY-MM-DD') : '-'
}
</script>

<style scoped>
.success-state {
  text-align: center;
  padding: 30px 0;
}
.success-icon {
  animation: successBounce 0.6s ease;
}
.success-title {
  margin-top: 16px;
  font-size: 20px;
  color: #303133;
}
.success-desc {
  margin-top: 8px;
  color: #909399;
  font-size: 14px;
}
.success-charge {
  margin-top: 4px;
  color: #606266;
  font-size: 14px;
}
.success-charge strong {
  color: #E6A23C;
  font-size: 18px;
}
@keyframes successBounce {
  0% { transform: scale(0); opacity: 0; }
  50% { transform: scale(1.2); }
  100% { transform: scale(1); opacity: 1; }
}

.loading-state {
  min-height: 200px;
}

.upgrade-ip-info {
  background: #F7F8FC;
  border: 1px solid #E2E8F0;
  border-radius: 8px;
  padding: 14px 16px;
}
.info-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 5px 0;
}
.info-label {
  color: #94A3B8;
  font-size: 13px;
}
.info-value {
  color: #1E293B;
  font-size: 13px;
  font-weight: 500;
}
.mono {
  font-family: 'SF Mono', Consolas, Monaco, monospace;
}

.upgrade-target {
  text-align: center;
  padding: 4px 0;
}
.target-badge {
  margin-bottom: 8px;
}
.premium-badge {
  display: inline-flex;
  align-items: center;
  font-size: 14px;
  font-weight: 600;
  color: #fff;
  padding: 6px 20px;
  border-radius: 6px;
  background: linear-gradient(135deg, #4F6AF6, #7C3AED);
}
.target-desc {
  margin: 0;
  color: #94A3B8;
  font-size: 13px;
}

.price-breakdown {
  background: #FAFBFF;
  border: 1px solid #E8ECFF;
  border-radius: 8px;
  padding: 12px 16px;
}
.price-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 0;
  font-size: 13px;
  color: #475569;
}
.price-value {
  font-weight: 500;
  color: #1E293B;
}
.price-row.total {
  border-top: 1px dashed #CBD5E1;
  margin-top: 4px;
  padding-top: 10px;
  font-weight: 600;
}
.total-price {
  color: #E6A23C;
  font-size: 18px;
  font-weight: 700;
}

.balance-info {
  padding: 0 4px;
}
.balance-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0;
  font-size: 13px;
  color: #475569;
}
.balance-row span:last-child {
  font-weight: 600;
  color: #1E293B;
}
.insufficient {
  color: #F56C6C !important;
}

.step-footer {
  text-align: right;
  margin-top: 16px;
}

:deep(.el-divider__text) {
  display: flex;
  align-items: center;
  gap: 4px;
  color: #94A3B8;
  font-size: 12px;
}
</style>
