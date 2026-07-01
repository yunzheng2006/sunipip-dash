<template>
  <div class="my-subs-page">
    <div class="page-head">
      <div>
        <h1 class="page-title">我的订阅</h1>
        <p class="page-sub">共 <strong>{{ pagination.total }}</strong> 条订阅</p>
      </div>
      <el-button type="primary" plain @click="$router.push('/subscriptions/batch-renew')">
        <el-icon><List /></el-icon> 批量续费
      </el-button>
    </div>

    <el-card class="filter-card" shadow="never">
      <div class="filter-row">
        <el-input
          v-model="searchForm.keyword"
          placeholder="搜索 资产名 / IP"
          clearable
          :prefix-icon="Search"
          style="width: 200px"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <el-input
          v-model="searchForm.country"
          placeholder="地区"
          clearable
          style="width: 120px"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <el-radio-group v-model="searchForm.status" @change="handleSearch">
          <el-radio-button value="">全部</el-radio-button>
          <el-radio-button value="active">活跃</el-radio-button>
          <el-radio-button value="expired">已过期</el-radio-button>
          <el-radio-button value="refunded">已退款</el-radio-button>
        </el-radio-group>
        <el-select
          v-model="searchForm.expiring_soon"
          placeholder="即将到期"
          clearable
          style="width: 130px"
          @change="handleSearch"
        >
          <el-option label="3 天内" :value="3" />
          <el-option label="7 天内" :value="7" />
          <el-option label="15 天内" :value="15" />
        </el-select>
        <el-select
          v-model="searchForm.auto_renew"
          placeholder="自动续费"
          clearable
          style="width: 130px"
          @change="handleSearch"
        >
          <el-option label="已开启" :value="1" />
          <el-option label="未开启" :value="0" />
        </el-select>
        <el-select
          v-model="searchForm.sort"
          placeholder="排序方式"
          style="width: 150px"
          @change="handleSearch"
        >
          <el-option label="默认排序" value="" />
          <el-option label="快到期优先" value="expires_asc" />
          <el-option label="到期远先" value="expires_desc" />
          <el-option label="最新开通" value="created_desc" />
          <el-option label="最早开通" value="created_asc" />
          <el-option label="价格由高" value="price_desc" />
          <el-option label="价格由低" value="price_asc" />
        </el-select>
        <div style="margin-left:auto">
          <SmsNotifyToggle />
        </div>
      </div>
    </el-card>

    <transition name="el-fade-in">
      <div v-if="selectedIds.length" class="batch-bar">
        <span>已选 <strong>{{ selectedIds.length }}</strong> 条</span>
        <el-button size="small" type="success" @click="batchAutoRenew(true)" :loading="batchLoading">
          批量开启自动续费
        </el-button>
        <el-button size="small" type="warning" @click="batchAutoRenew(false)" :loading="batchLoading">
          批量关闭自动续费
        </el-button>
        <el-button size="small" link @click="clearSelection">取消选择</el-button>
      </div>
    </transition>

    <!-- Desktop: Table -->
    <el-card shadow="never" class="desktop-table">
      <el-table ref="tableRef" :data="tableData" v-loading="loading" stripe @selection-change="onSelectionChange">
        <el-table-column type="selection" width="36" :selectable="row => canRenew(row)" />
        <el-table-column label="资产" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <strong>{{ row.proxy_ip?.asset_name || '-' }}</strong>
            <div class="ip-mono">{{ row.proxy_ip?.ip_address }}:{{ row.proxy_ip?.port }}</div>
          </template>
        </el-table-column>
        <el-table-column label="产品类型" width="120">
          <template #default="{ row }">
            <el-tag v-if="subModule(row) === 'video'" size="small" type="success" effect="plain">IPLC视频专线</el-tag>
            <el-tag v-else-if="subModule(row) === 'live_mobile'" size="small" type="warning" effect="plain">IPLC直播专线</el-tag>
            <el-tag v-else-if="subModule(row) === 'live_pc'" size="small" type="danger" effect="plain">IPLC直播专线</el-tag>
            <el-tag v-else size="small" effect="plain">静态IP</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="地区" width="70">
          <template #default="{ row }">{{ row.proxy_ip?.country_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="续费价" width="75" align="right">
          <template #default="{ row }">¥{{ getRenewalMonthlyPrice(row).toFixed(0) }}</template>
        </el-table-column>
        <el-table-column label="到期时间" width="170">
          <template #default="{ row }">
            <span :class="daysToExpire(row.expires_at) <= 3 ? 'expiry-urgent' : 'expiry-normal'" class="expiry-inline">
              {{ formatDate(row.expires_at) }}
              <span class="expiry-days">剩{{ daysToExpire(row.expires_at) }}天</span>
            </span>
          </template>
        </el-table-column>
        <el-table-column label="自动续费" width="75" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="!!row.auto_renew"
              :disabled="!canRenew(row) || row._toggling"
              size="small"
              @change="onToggleAutoRenew(row, $event)"
            />
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.is_test" type="warning" size="small">测试</el-tag>
            <el-tag v-else :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center">
          <template #default="{ row }">
            <el-button v-if="canRenew(row)" type="primary" link size="small" @click="openRenew(row)">
              {{ row.is_test ? '转正' : (row.status === 'expired' ? '续费激活' : '续费') }}
            </el-button>
            <el-button v-if="row.status === 'active' && canRefund(row)" type="warning" link size="small" @click="openRefund(row)">
              退款
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- Mobile: Card List -->
    <div class="mobile-list" v-loading="loading">
      <div v-if="!tableData.length && !loading" class="mobile-empty">暂无订阅</div>
      <div v-for="row in tableData" :key="row.id" class="sub-card" :class="{ 'sub-card--expired': row.status === 'expired' }">
        <div class="sub-card__head">
          <div class="sub-card__name">{{ row.proxy_ip?.asset_name || '-' }}</div>
          <el-tag v-if="row.is_test" type="warning" size="small">测试</el-tag>
          <el-tag v-else :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </div>
        <div class="sub-card__ip">{{ row.proxy_ip?.ip_address }}:{{ row.proxy_ip?.port }}</div>
        <div class="sub-card__info">
          <div class="sub-card__row">
            <span class="sub-card__label">地区</span>
            <span>{{ row.proxy_ip?.country_name || '-' }}</span>
          </div>
          <div class="sub-card__row">
            <span class="sub-card__label">续费价</span>
            <span class="sub-card__price">¥{{ Number(row.renewal_price || row.price || 0).toFixed(0) }}/月</span>
          </div>
          <div class="sub-card__row">
            <span class="sub-card__label">到期</span>
            <span :class="daysToExpire(row.expires_at) <= 3 ? 'expiry-urgent' : 'expiry-normal'">
              {{ dayjs(row.expires_at).format('YYYY-MM-DD') }}
              <span style="margin-left:4px;font-size:11px;opacity:.8">剩{{ daysToExpire(row.expires_at) }}天</span>
            </span>
          </div>
          <div class="sub-card__row">
            <span class="sub-card__label">自动续费</span>
            <el-switch
              :model-value="!!row.auto_renew"
              :disabled="!canRenew(row) || row._toggling"
              size="small"
              @change="onToggleAutoRenew(row, $event)"
            />
          </div>
        </div>
        <div class="sub-card__actions">
          <el-button v-if="canRenew(row)" type="primary" size="small" round @click="openRenew(row)">
            {{ row.is_test ? '转正' : (row.status === 'expired' ? '续费激活' : '续费') }}
          </el-button>
          <el-button v-if="row.status === 'active' && canRefund(row)" type="warning" size="small" round plain @click="openRefund(row)">
            退款
          </el-button>
        </div>
      </div>
      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          :total="pagination.total"
          :page-size="pagination.per_page"
          small
          layout="prev, pager, next"
          @current-change="fetchData"
        />
      </div>
    </div>

    <!-- Renew Dialog -->
    <el-dialog v-model="renewVisible" :title="renewTarget?.is_test ? '测试转正' : '续费订阅'" width="480px">
      <el-form :model="renewForm" label-width="100px">
        <el-form-item label="资产">
          <el-input :value="renewTarget?.proxy_ip?.asset_name" disabled />
        </el-form-item>
        <el-form-item :label="renewTarget?.is_test ? '转正单价' : '续费单价'">
          <el-input :value="`¥${getRenewalMonthlyPrice(renewTarget || {}).toFixed(2)} / 月`" disabled />
        </el-form-item>
        <el-form-item :label="renewTarget?.is_test ? '购买时长' : '续费时长'">
          <el-radio-group v-model="renewForm.duration">
            <el-radio-button :value="1">1 月</el-radio-button>
            <el-radio-button :value="3">3 月</el-radio-button>
            <el-radio-button :value="6">6 月</el-radio-button>
            <el-radio-button :value="12">12 月</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="合计">
          <span class="total-amount">¥{{ renewTotal.toFixed(2) }}</span>
        </el-form-item>
        <el-form-item v-if="balance < renewTotal" label="余额">
          <span style="color:#F56C6C;font-weight:600">¥{{ balance.toFixed(2) }}（差 ¥{{ (renewTotal - balance).toFixed(2) }}）</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="renewVisible = false">取消</el-button>
        <el-button v-if="balance >= renewTotal" type="primary" :loading="renewLoading" @click="submitRenew">
          {{ renewTarget?.is_test ? '确认转正' : '确认续费' }}
        </el-button>
        <el-button v-else type="warning" @click="showTopup = true">
          余额不足，去充值
        </el-button>
      </template>
    </el-dialog>

    <!-- Topup Dialog -->
    <TopupDialog v-model="showTopup" :need-amount="renewTotal" @paid="onTopupPaid" />

    <!-- Refund Dialog -->
    <el-dialog v-model="refundVisible" title="自助退款" width="480px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        <template #title>
          退款后 IP 将被释放并退还给上游。开通后 12 小时内可退款。
        </template>
      </el-alert>
      <el-form :model="refundForm" label-width="100px">
        <el-form-item label="资产">
          <el-input :value="refundTarget?.proxy_ip?.asset_name" disabled />
        </el-form-item>
        <el-form-item label="购买金额">
          <span style="font-weight:600">¥{{ Number(refundTarget?.price || 0).toFixed(2) }}</span>
        </el-form-item>
        <el-form-item label="释放手续费">
          <span style="color:#F56C6C;font-weight:600">- ¥1.00</span>
          <span style="color:#94A3B8;font-size:12px;margin-left:8px">(上游释放费用)</span>
        </el-form-item>
        <el-form-item label="实际退款">
          <span style="color:#48BB78;font-weight:700;font-size:16px">
            ¥{{ Math.max(0, Number(refundTarget?.price || 0) - 1).toFixed(2) }}
          </span>
        </el-form-item>
        <el-form-item label="退款原因">
          <el-input v-model="refundForm.reason" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="refundVisible = false">取消</el-button>
        <el-button type="warning" :loading="refundLoading" @click="submitRefund">
          确认退款（退 ¥{{ Math.max(0, Number(refundTarget?.price || 0) - 1).toFixed(2) }}）
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, List } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import {
  getSubscriptions,
  renewSubscription,
  refundSubscription,
  toggleAutoRenew,
  batchToggleAutoRenew,
} from '@/api/subscriptions'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import SmsNotifyToggle from '@/components/SmsNotifyToggle.vue'
import TopupDialog from '@/components/TopupDialog.vue'

const route = useRoute()
const authStore = useAuthStore()
const appStore = useAppStore()

const loading = ref(false)
const tableRef = ref(null)
const tableData = ref([])
const selectedIds = ref([])
const batchLoading = ref(false)

function onSelectionChange(rows) { selectedIds.value = rows.map(r => r.id) }
function clearSelection() { tableRef.value?.clearSelection(); selectedIds.value = [] }
async function batchAutoRenew(enabled) {
  if (!selectedIds.value.length) return
  batchLoading.value = true
  try {
    const res = await batchToggleAutoRenew(selectedIds.value, enabled)
    ElMessage.success(res?.message || '操作成功')
    clearSelection(); fetchData()
  } catch {} finally { batchLoading.value = false }
}
const searchForm = reactive({ status: '', expiring_soon: null, keyword: '', country: '', auto_renew: null, sort: '' })
const pagination = reactive({ page: 1, per_page: 20, total: 0 })

const balance = computed(() => authStore.balance)

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function daysToExpire(d) { return d ? Math.max(0, dayjs(d).diff(dayjs(), 'day')) : 0 }
function getMonthlyPrice(row) {
  const d = Number(row.duration || 1), u = Number(row.unit || 3)
  let m = d
  if (u === 1) m = Math.max(1, Math.ceil(d / 30))
  else if (u === 2) m = Math.max(1, Math.ceil(d * 7 / 30))
  else if (u === 4) m = d * 12
  return Number(row.price || 0) / Math.max(m, 1)
}
function getRenewalMonthlyPrice(row) {
  return Number(row.renewal_price) || getMonthlyPrice(row)
}
function statusTag(s) { return { active: 'success', expired: 'danger', cancelled: 'info', refunded: 'warning' }[s] || 'info' }
function statusLabel(s) { return { active: '活跃', expired: '已过期', cancelled: '已取消', refunded: '已退款' }[s] || s }

function canRenew(row) {
  if (row.status === 'active') return true
  if (row.status === 'expired' && row.expires_at) {
    return dayjs().diff(dayjs(row.expires_at), 'day') <= 3
  }
  return false
}

function canRefund(row) {
  if (!appStore.selfRefundEnabled) return false
  if (!row.started_at) return false
  if (row.created_by && row.created_by !== 1) return false
  const hours = dayjs().diff(dayjs(row.started_at), 'hour')
  return hours <= 12
}

function subModule(row) {
  return row.purchased_module || row.forward_rule?.forward_plan?.module
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.status) params.status = searchForm.status
    if (searchForm.expiring_soon) params.expiring_soon = searchForm.expiring_soon
    if (searchForm.keyword) params.keyword = searchForm.keyword
    if (searchForm.country) params.country = searchForm.country
    if (searchForm.auto_renew !== null && searchForm.auto_renew !== '') params.auto_renew = searchForm.auto_renew
    if (searchForm.sort) params.sort = searchForm.sort
    const res = await getSubscriptions(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch {} finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }

async function onToggleAutoRenew(row, enabled) {
  row._toggling = true
  try {
    await toggleAutoRenew(row.id, enabled)
    row.auto_renew = enabled ? 1 : 0
    ElMessage.success(enabled ? '已开启自动续费' : '已关闭自动续费')
  } catch {} finally { row._toggling = false }
}

// Renew
const renewVisible = ref(false)
const renewLoading = ref(false)
const renewTarget = ref(null)
const renewForm = reactive({ duration: 1 })
const renewTotal = computed(() => getRenewalMonthlyPrice(renewTarget.value || {}) * renewForm.duration)

const showTopup = ref(false)
function onTopupPaid() { authStore.fetchMe() }

function openRenew(row) { renewTarget.value = row; renewForm.duration = 1; renewVisible.value = true }

async function submitRenew() {
  if (balance.value < renewTotal.value) { ElMessage.warning('余额不足'); return }
  renewLoading.value = true
  try {
    const res = await renewSubscription(renewTarget.value.id, { duration: renewForm.duration, unit: 3 })
    ElMessage.success('续费成功')
    authStore.updateBalance(res.new_balance)
    renewVisible.value = false; fetchData()
  } catch {} finally { renewLoading.value = false }
}

// Refund
const refundVisible = ref(false)
const refundLoading = ref(false)
const refundTarget = ref(null)
const refundForm = reactive({ reason: '' })

function openRefund(row) { refundTarget.value = row; refundForm.reason = ''; refundVisible.value = true }

async function submitRefund() {
  try {
    await ElMessageBox.confirm(
      `确认退款订阅 #${refundTarget.value.id}？金额将全额返还到余额。`,
      '退款确认', { type: 'warning' }
    )
  } catch { return }
  refundLoading.value = true
  try {
    const res = await refundSubscription(refundTarget.value.id, refundForm)
    ElMessage.success(`退款成功，已退 ¥${Number(res?.refund_amount || 0).toFixed(2)} 到余额`)
    authStore.updateBalance(res.new_balance)
    refundVisible.value = false; fetchData()
  } catch {} finally { refundLoading.value = false }
}

onMounted(() => {
  if (route.query.expiring) { searchForm.expiring_soon = Number(route.query.expiring); searchForm.status = 'active' }
  fetchData()
})

</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$accent: #F5A623;
$text-primary: #1E293B;
$text-muted: #94A3B8;
$border: #E2E8F0;

.my-subs-page { display: flex; flex-direction: column; gap: 16px; }
.batch-bar {
  display: flex; align-items: center; gap: 10px; padding: 10px 16px;
  background: $brand-light; border: 1px solid #C5CDFC; border-radius: 10px;
  font-size: 13px; color: #1E40AF;
  strong { color: $brand; font-size: 15px; }
}
.page-head {
  display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px;
  .page-title { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: $text-primary; }
  .page-sub { margin: 0; font-size: 13px; color: $text-muted; strong { color: $brand; } }
}
.filter-card {
  border-radius: 10px; border: 1px solid $border;
  :deep(.el-card__body) { padding: 12px 18px; }
  .filter-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
}
.ip-mono {
  font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 11px; color: $text-muted; margin-top: 2px;
}
.expiry-inline {
  display: flex; align-items: center; gap: 6px; white-space: nowrap; font-size: 12px;
  .expiry-days { font-size: 11px; color: #94A3B8; flex-shrink: 0; }
}
.expiry-normal { color: #E6A23C; }
.expiry-urgent { color: #F56C6C; font-weight: 600; .expiry-days { color: #F56C6C; } }
.total-amount {
  font-size: 22px; font-weight: 800; color: $accent;
  font-family: 'SF Mono', Consolas, Monaco, monospace;
}
.pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }

// Mobile card list — hidden on desktop
.mobile-list { display: none; }
.mobile-empty { text-align: center; padding: 40px 0; color: $text-muted; font-size: 14px; }

// Sub card styles (mobile)
.sub-card {
  background: #fff; border: 1px solid $border; border-radius: 12px; padding: 14px; margin-bottom: 10px;
  &--expired { opacity: .7; border-color: #FECACA; }
  &__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
  &__name { font-size: 14px; font-weight: 700; color: $text-primary; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  &__ip { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 11px; color: $text-muted; margin-bottom: 10px; }
  &__info { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
  &__row { display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: $text-primary; }
  &__label { color: $text-muted; font-size: 12px; flex-shrink: 0; min-width: 56px; }
  &__price { font-weight: 600; color: $accent; }
  &__actions { display: flex; gap: 8px; }
}

@media (max-width: 768px) {
  .my-subs-page { gap: 10px; }

  // Header
  .page-head {
    flex-direction: row; align-items: center; gap: 8px;
    .page-title { font-size: 18px; }
    .page-sub { display: none; }
    .el-button { width: auto; font-size: 12px; padding: 6px 12px; }
  }

  // Batch bar
  .batch-bar { flex-wrap: wrap; gap: 6px; padding: 8px 10px; font-size: 12px;
    .el-button { font-size: 12px; }
  }

  // Filters
  .filter-card {
    :deep(.el-card__body) { padding: 10px 12px; }
    .filter-row {
      gap: 8px;
      .el-input { width: 100% !important; }
      .el-select { width: calc(50% - 4px) !important; flex-shrink: 0; }
      :deep(.el-radio-group) {
        width: 100%; display: flex; flex-wrap: nowrap;
        .el-radio-button { flex: 1; min-width: 0;
          :deep(.el-radio-button__inner) { width: 100%; padding: 7px 0; font-size: 12px; text-align: center; }
        }
      }
      > div:last-child { width: 100%; margin-left: 0; }
    }
  }

  // Hide desktop table, show mobile cards
  .desktop-table { display: none; }
  .mobile-list { display: block; }

  // Pagination
  .pagination-wrap { justify-content: center; }

  // Dialogs
  :deep(.el-dialog) { width: 92vw !important; max-width: 92vw !important;
    .el-dialog__body { padding: 12px 16px; }
    .el-form-item { margin-bottom: 14px; }
    .el-form-item__label { font-size: 13px; }
    .el-radio-group { display: flex; flex-wrap: wrap; gap: 6px;
      .el-radio-button { flex: 1; min-width: 0;
        :deep(.el-radio-button__inner) { width: 100%; text-align: center; padding: 8px 0; }
      }
    }
  }
  .total-amount { font-size: 18px; }
}
</style>
