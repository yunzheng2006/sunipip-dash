<template>
  <div class="payment-orders">
    <h2 class="page-title">充值订单</h2>

    <el-tabs v-model="activeTab" @tab-change="handleTabChange">
      <!-- ===== 充值订单 Tab ===== -->
      <el-tab-pane label="充值订单" name="orders">
        <el-card class="search-card">
          <el-form :inline="true" :model="orderSearch">
            <el-form-item label="客户">
              <el-input v-model="orderSearch.customer_name" placeholder="客户名称" clearable style="width: 150px" />
            </el-form-item>
            <el-form-item label="网关">
              <el-select v-model="orderSearch.gateway_type" placeholder="全部" clearable style="width: 120px">
                <el-option label="支付宝" value="alipay" />
                <el-option label="EPay" value="epay" />
              </el-select>
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="orderSearch.status" placeholder="全部" clearable style="width: 110px">
                <el-option label="待支付" value="pending" />
                <el-option label="已支付" value="paid" />
                <el-option label="已失败" value="failed" />
                <el-option label="已过期" value="expired" />
                <el-option label="已取消" value="cancelled" />
              </el-select>
            </el-form-item>
            <el-form-item label="时间">
              <el-date-picker
                v-model="orderSearch.date_range"
                type="daterange"
                range-separator="至"
                start-placeholder="开始"
                end-placeholder="结束"
                format="YYYY-MM-DD"
                value-format="YYYY-MM-DD"
                style="width: 240px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="searchOrders"><el-icon><Search /></el-icon>搜索</el-button>
              <el-button @click="resetOrderSearch">重置</el-button>
            </el-form-item>
          </el-form>
        </el-card>

        <el-card>
          <el-table :data="orders" v-loading="ordersLoading" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="order_no" label="订单号" min-width="180">
              <template #default="{ row }">
                <span style="font-family: monospace; font-size: 12px">{{ row.order_no }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="customer_name" label="客户" min-width="120" />
            <el-table-column prop="gateway_type" label="网关" width="90" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="row.gateway_type === 'alipay' ? 'primary' : 'warning'">
                  {{ row.gateway_type === 'alipay' ? '支付宝' : 'EPay' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="amount" label="金额" width="100" align="right">
              <template #default="{ row }">
                <span style="font-weight: 600">¥{{ Number(row.amount || 0).toFixed(2) }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="refunded_amount" label="已退" width="90" align="right">
              <template #default="{ row }">
                <span :style="{ color: Number(row.refunded_amount) > 0 ? '#f56c6c' : '#999' }">
                  ¥{{ Number(row.refunded_amount || 0).toFixed(2) }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="85" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="statusType(row.status)">{{ statusLabel(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="paid_at" label="支付时间" width="160">
              <template #default="{ row }">{{ formatDate(row.paid_at) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="100" fixed="right">
              <template #default="{ row }">
                <el-button
                  v-if="row.status === 'paid' && row.gateway_type === 'alipay' && row.refundable_amount > 0"
                  type="danger"
                  size="small"
                  link
                  @click="openRefundDialog(row)"
                >原路退款</el-button>
                <span v-else style="color: #c0c4cc">-</span>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-wrap">
            <el-pagination
              v-model:current-page="orderPagination.page"
              v-model:page-size="orderPagination.page_size"
              :total="orderPagination.total"
              :page-sizes="[10, 20, 50, 100]"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="fetchOrders"
              @current-change="fetchOrders"
            />
          </div>
        </el-card>
      </el-tab-pane>

      <!-- ===== 退款记录 Tab ===== -->
      <el-tab-pane label="退款记录" name="refunds">
        <el-card class="search-card">
          <el-form :inline="true" :model="refundSearch">
            <el-form-item label="客户">
              <el-input v-model="refundSearch.customer_name" placeholder="客户名称" clearable style="width: 150px" />
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="refundSearch.status" placeholder="全部" clearable style="width: 110px">
                <el-option label="处理中" value="pending" />
                <el-option label="成功" value="success" />
                <el-option label="失败" value="failed" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="searchRefunds"><el-icon><Search /></el-icon>搜索</el-button>
              <el-button @click="resetRefundSearch">重置</el-button>
            </el-form-item>
          </el-form>
        </el-card>

        <el-card>
          <el-table :data="refunds" v-loading="refundsLoading" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="refund_no" label="退款号" min-width="180">
              <template #default="{ row }">
                <span style="font-family: monospace; font-size: 12px">{{ row.refund_no }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="order_no" label="原订单" min-width="180">
              <template #default="{ row }">
                <span style="font-family: monospace; font-size: 12px">{{ row.order_no || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="customer_name" label="客户" min-width="120" />
            <el-table-column prop="amount" label="退款金额" width="110" align="right">
              <template #default="{ row }">
                <span style="color: #f56c6c; font-weight: 600">¥{{ Number(row.amount || 0).toFixed(2) }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="85" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="refundStatusType(row.status)">{{ refundStatusLabel(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="reason" label="原因" min-width="150">
              <template #default="{ row }">{{ row.reason || '-' }}</template>
            </el-table-column>
            <el-table-column prop="error_message" label="错误信息" min-width="150">
              <template #default="{ row }">
                <span v-if="row.error_message" style="color: #f56c6c">{{ row.error_message }}</span>
                <span v-else>-</span>
              </template>
            </el-table-column>
            <el-table-column prop="operator_name" label="操作人" width="90" />
            <el-table-column prop="refunded_at" label="退款时间" width="160">
              <template #default="{ row }">{{ formatDate(row.refunded_at || row.created_at) }}</template>
            </el-table-column>
          </el-table>

          <div class="pagination-wrap">
            <el-pagination
              v-model:current-page="refundPagination.page"
              v-model:page-size="refundPagination.page_size"
              :total="refundPagination.total"
              :page-sizes="[10, 20, 50, 100]"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="fetchRefunds"
              @current-change="fetchRefunds"
            />
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- ===== 退款弹窗 ===== -->
    <el-dialog v-model="refundDialog.visible" title="原路退款" width="480px" :close-on-click-modal="false">
      <el-descriptions :column="1" border size="small" style="margin-bottom: 20px">
        <el-descriptions-item label="订单号">{{ refundDialog.order?.order_no }}</el-descriptions-item>
        <el-descriptions-item label="客户">{{ refundDialog.order?.customer_name }}</el-descriptions-item>
        <el-descriptions-item label="支付金额">¥{{ Number(refundDialog.order?.amount || 0).toFixed(2) }}</el-descriptions-item>
        <el-descriptions-item label="已退金额">¥{{ Number(refundDialog.order?.refunded_amount || 0).toFixed(2) }}</el-descriptions-item>
        <el-descriptions-item label="可退金额">
          <span style="color: #e6a23c; font-weight: 600">¥{{ Number(refundDialog.order?.refundable_amount || 0).toFixed(2) }}</span>
        </el-descriptions-item>
      </el-descriptions>

      <el-form :model="refundDialog.form" label-width="80px">
        <el-form-item label="退款金额" required>
          <el-input-number
            v-model="refundDialog.form.amount"
            :min="0.01"
            :max="refundDialog.order?.refundable_amount || 0"
            :precision="2"
            :step="1"
            style="width: 200px"
          />
          <el-button link type="primary" style="margin-left: 8px" @click="refundDialog.form.amount = refundDialog.order?.refundable_amount">全额</el-button>
        </el-form-item>
        <el-form-item label="退款原因">
          <el-input v-model="refundDialog.form.reason" type="textarea" :rows="2" placeholder="可选，会显示在支付宝退款记录中" />
        </el-form-item>
      </el-form>

      <el-alert type="warning" :closable="false" style="margin-top: 8px">
        退款将直接退回客户支付宝账户，同时从客户平台余额中扣除对应金额。请确认客户余额充足。
      </el-alert>

      <template #footer>
        <el-button @click="refundDialog.visible = false">取消</el-button>
        <el-button type="danger" :loading="refundDialog.submitting" @click="submitRefund">确认退款</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import { getPaymentOrders, getPaymentRefunds, refundPaymentOrder } from '@/api/paymentOrders'

const activeTab = ref('orders')

// ── Orders ──
const ordersLoading = ref(false)
const orders = ref([])
const orderPagination = reactive({ page: 1, page_size: 20, total: 0 })
const orderSearch = reactive({ customer_name: '', gateway_type: '', status: '', date_range: null })

async function fetchOrders() {
  ordersLoading.value = true
  try {
    const params = {
      page: orderPagination.page,
      page_size: orderPagination.page_size,
      customer_name: orderSearch.customer_name || undefined,
      gateway_type: orderSearch.gateway_type || undefined,
      status: orderSearch.status || undefined,
      date_from: orderSearch.date_range?.[0] || undefined,
      date_to: orderSearch.date_range?.[1] || undefined,
    }
    Object.keys(params).forEach(k => { if (params[k] === undefined) delete params[k] })
    const res = await getPaymentOrders(params)
    orders.value = res.data || []
    orderPagination.total = res.total || 0
  } finally {
    ordersLoading.value = false
  }
}

function searchOrders() { orderPagination.page = 1; fetchOrders() }
function resetOrderSearch() {
  orderSearch.customer_name = ''
  orderSearch.gateway_type = ''
  orderSearch.status = ''
  orderSearch.date_range = null
  orderPagination.page = 1
  fetchOrders()
}

// ── Refunds ──
const refundsLoading = ref(false)
const refunds = ref([])
const refundPagination = reactive({ page: 1, page_size: 20, total: 0 })
const refundSearch = reactive({ customer_name: '', status: '' })

async function fetchRefunds() {
  refundsLoading.value = true
  try {
    const params = {
      page: refundPagination.page,
      page_size: refundPagination.page_size,
      customer_name: refundSearch.customer_name || undefined,
      status: refundSearch.status || undefined,
    }
    Object.keys(params).forEach(k => { if (params[k] === undefined) delete params[k] })
    const res = await getPaymentRefunds(params)
    refunds.value = res.data || []
    refundPagination.total = res.total || 0
  } finally {
    refundsLoading.value = false
  }
}

function searchRefunds() { refundPagination.page = 1; fetchRefunds() }
function resetRefundSearch() {
  refundSearch.customer_name = ''
  refundSearch.status = ''
  refundPagination.page = 1
  fetchRefunds()
}

function handleTabChange(tab) {
  if (tab === 'orders') fetchOrders()
  else if (tab === 'refunds') fetchRefunds()
}

// ── Refund Dialog ──
const refundDialog = reactive({
  visible: false,
  submitting: false,
  order: null,
  form: { amount: 0, reason: '' },
})

function openRefundDialog(order) {
  refundDialog.order = order
  refundDialog.form.amount = order.refundable_amount
  refundDialog.form.reason = ''
  refundDialog.visible = true
}

async function submitRefund() {
  if (!refundDialog.form.amount || refundDialog.form.amount <= 0) {
    return ElMessage.warning('请输入退款金额')
  }

  try {
    await ElMessageBox.confirm(
      `确认退款 ¥${refundDialog.form.amount.toFixed(2)} 到客户支付宝？此操作不可撤销。`,
      '确认原路退款',
      { type: 'warning', confirmButtonText: '确认退款', cancelButtonText: '取消' }
    )
  } catch { return }

  refundDialog.submitting = true
  try {
    await refundPaymentOrder(refundDialog.order.id, {
      amount: refundDialog.form.amount,
      reason: refundDialog.form.reason || undefined,
    })
    ElMessage.success('原路退款成功')
    refundDialog.visible = false
    fetchOrders()
  } catch (err) {
    ElMessage.error(err.response?.data?.message || err.message || '退款失败')
  } finally {
    refundDialog.submitting = false
  }
}

// ── Helpers ──
function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm:ss') : '-' }

function statusType(s) {
  return { pending: 'warning', paid: 'success', failed: 'danger', expired: 'info', cancelled: 'info' }[s] || 'info'
}
function statusLabel(s) {
  return { pending: '待支付', paid: '已支付', failed: '失败', expired: '已过期', cancelled: '已取消' }[s] || s
}
function refundStatusType(s) {
  return { pending: 'warning', success: 'success', failed: 'danger' }[s] || 'info'
}
function refundStatusLabel(s) {
  return { pending: '处理中', success: '成功', failed: '失败' }[s] || s
}

onMounted(() => { fetchOrders() })
</script>

<style lang="scss" scoped>
.payment-orders {
  .page-title {
    margin: 0 0 20px 0;
    font-size: 20px;
    color: #303133;
  }

  .search-card {
    margin-bottom: 16px;
    :deep(.el-card__body) { padding-bottom: 2px; }
  }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
}
</style>
