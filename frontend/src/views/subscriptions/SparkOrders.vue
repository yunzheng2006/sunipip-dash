<template>
  <div class="api-orders">
    <div class="page-header">
      <h2>API 订单</h2>
      <div class="header-actions">
        <el-button @click="fetchData" :loading="loading">
          <el-icon><Refresh /></el-icon> 刷新
        </el-button>
        <el-button type="warning" :loading="syncAllLoading" :disabled="!pendingCount" @click="syncAllPending">
          <el-icon><RefreshRight /></el-icon> 同步全部待处理 ({{ pendingCount }})
        </el-button>
      </div>
    </div>

    <el-card>
      <div class="filter-bar">
        <el-select v-model="filterProvider" placeholder="全部来源" clearable style="width: 130px" @change="onFilterChange">
          <el-option label="全部来源" value="" />
          <el-option label="Spark" value="spark" />
          <el-option label="IPIPV" value="ipipv" />
        </el-select>
        <el-select v-model="filterMethod" placeholder="全部方法" clearable style="width: 140px" @change="onFilterChange">
          <el-option label="全部方法" value="" />
          <el-option-group label="Spark">
            <el-option label="CreateProxy" value="CreateProxy" />
            <el-option label="RenewProxy" value="RenewProxy" />
            <el-option label="DelProxy" value="DelProxy" />
          </el-option-group>
          <el-option-group label="IPIPV">
            <el-option label="open (开通)" value="open" />
            <el-option label="renew (续费)" value="renew" />
            <el-option label="release (释放)" value="release" />
          </el-option-group>
        </el-select>
        <el-select v-model="filterStatus" placeholder="全部状态" clearable style="width: 130px" @change="onFilterChange">
          <el-option label="全部状态" value="" />
          <el-option label="开通中" :value="1" />
          <el-option label="已完成" :value="2" />
          <el-option label="失败" :value="3" />
        </el-select>
      </div>

      <el-table :data="orders" v-loading="loading" border size="small" row-key="_key">
        <el-table-column label="来源" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row._provider === 'spark' ? 'primary' : 'success'" size="small" effect="dark">
              {{ row._provider === 'spark' ? 'Spark' : 'IPIPV' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="订单号" min-width="200">
          <template #default="{ row }">
            <div class="mono" style="font-size: 12px">{{ row._appOrderNo }}</div>
            <div v-if="row._upstreamOrderNo" style="font-size: 11px; color: #909399">
              {{ row._provider === 'spark' ? 'Spark' : 'IPIPV' }}: {{ row._upstreamOrderNo }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="方法" width="120">
          <template #default="{ row }">
            <el-tag :type="methodTag(row.method, row._provider)" size="small" effect="plain">{{ methodLabel(row.method, row._provider) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="产品/客户" min-width="200">
          <template #default="{ row }">
            <div>
              <img v-if="row._countryIso2" :src="getFlagUrl(row._countryIso2, 20)" :alt="row._countryCn" class="flag-img-inline" />
              {{ row._countryCn || row._productId || '-' }}
            </div>
            <div v-if="row._customerName" style="font-size: 11px; color: #909399">
              客户: {{ row._customerName }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="数量" width="70" align="center">
          <template #default="{ row }">
            {{ row.amount || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="时长" width="90" align="center">
          <template #default="{ row }">
            <template v-if="row.duration">{{ row.duration }}{{ unitLabel(row.unit) }}</template>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="成本" width="90" align="right">
          <template #default="{ row }">
            <span v-if="row.cost_amount" style="color: #E6A23C">¥{{ Number(row.cost_amount).toFixed(2) }}</span>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status, row._provider)" size="small" effect="dark">{{ statusLabel(row.status, row._provider) }}</el-tag>
            <div v-if="row.status === 1" style="font-size: 11px; color: #F56C6C; margin-top: 2px">
              {{ minutesAgo(row.created_at) }}分钟前
            </div>
          </template>
        </el-table-column>
        <el-table-column label="实例" width="70" align="center">
          <template #default="{ row }">
            <el-badge v-if="row.instances?.length" :value="row.instances.length" type="success" />
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">
            {{ formatTime(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" align="center" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1"
              type="primary"
              size="small"
              link
              :loading="row._syncing"
              @click="syncOne(row)"
            >
              同步
            </el-button>
            <el-button
              v-else
              size="small"
              link
              :loading="row._syncing"
              @click="syncOne(row)"
            >
              重新同步
            </el-button>
            <el-button size="small" link @click="showDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="perPage"
          :total="total"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @current-change="fetchData"
          @size-change="() => { page = 1; fetchData() }"
        />
      </div>
    </el-card>

    <el-dialog v-model="detailVisible" title="订单详情" width="700px">
      <template v-if="detailOrder">
        <div v-if="detailOrder.status !== 3 || detailOrder._provider === 'ipipv'" style="margin-bottom: 12px">
          <el-button type="primary" size="small" :loading="detailOrder._syncing" @click="syncOne(detailOrder)">
            重新同步
          </el-button>
        </div>
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="来源">
            <el-tag :type="detailOrder._provider === 'spark' ? 'primary' : 'success'" size="small" effect="dark">
              {{ detailOrder._provider === 'spark' ? 'Spark' : 'IPIPV' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="方法">{{ methodLabel(detailOrder.method, detailOrder._provider) }}</el-descriptions-item>
          <el-descriptions-item label="我方订单号">{{ detailOrder._appOrderNo }}</el-descriptions-item>
          <el-descriptions-item :label="detailOrder._provider === 'spark' ? 'Spark 订单号' : 'IPIPV 订单号'">
            {{ detailOrder._upstreamOrderNo || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="状态">{{ statusLabel(detailOrder.status, detailOrder._provider) }}</el-descriptions-item>
          <el-descriptions-item label="数量">{{ detailOrder.amount || '-' }}</el-descriptions-item>
          <el-descriptions-item label="成本">{{ detailOrder.cost_amount ? `¥${detailOrder.cost_amount}` : '-' }}</el-descriptions-item>
          <el-descriptions-item label="时长">
            <template v-if="detailOrder.duration">{{ detailOrder.duration }}{{ unitLabel(detailOrder.unit) }}</template>
            <template v-else>-</template>
          </el-descriptions-item>
        </el-descriptions>

        <div v-if="detailOrder.instances?.length" style="margin-top: 16px">
          <h4>关联实例 ({{ detailOrder.instances.length }})</h4>
          <el-table :data="detailOrder.instances" border size="small">
            <el-table-column :prop="detailOrder._provider === 'spark' ? 'instance_id' : 'instance_no'" label="实例ID" min-width="180" />
            <el-table-column label="IP:端口" width="180">
              <template #default="{ row }">
                <template v-if="row.ip">{{ row.ip }}{{ row.port ? `:${row.port}` : '' }}</template>
                <span v-else style="color: #C0C4CC">-</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-tag :type="instanceStatusType(row.status, detailOrder._provider)" size="small">
                  {{ instanceStatusLabel(row.status, detailOrder._provider) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="到期" width="160" prop="expire_at" />
          </el-table>
        </div>

        <div style="margin-top: 16px">
          <h4>请求参数</h4>
          <pre class="json-block">{{ JSON.stringify(detailOrder.request_data, null, 2) }}</pre>
        </div>
        <div style="margin-top: 12px">
          <h4>响应数据</h4>
          <pre class="json-block">{{ JSON.stringify(detailOrder.response_data, null, 2) }}</pre>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getSparkOrders, syncSparkOrder } from '@/api/spark'
import { getIpipvOrders, syncIpipvOrder } from '@/api/ipipv'
import { getCountryInfo, getFlagUrl } from '@/utils/countries'
import dayjs from 'dayjs'

const loading = ref(false)
const syncAllLoading = ref(false)
const orders = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const filterProvider = ref('')
const filterMethod = ref('')
const filterStatus = ref('')

const detailVisible = ref(false)
const detailOrder = ref(null)

const pendingCount = computed(() => orders.value.filter(o => {
  if (o._provider === 'ipipv') return o.status === 1 || o.status === 2
  return o.status === 1
}).length)

function unitLabel(u) {
  return { 1: '天', 2: '周', 3: '月', 4: '年' }[u] || ''
}

function statusType(s, provider) {
  if (provider === 'ipipv') {
    return { 1: 'warning', 2: 'warning', 3: 'success', 4: 'danger', 5: 'danger' }[s] || 'info'
  }
  return { 1: 'warning', 2: 'success', 3: 'danger' }[s] || 'info'
}

function statusLabel(s, provider) {
  if (provider === 'ipipv') {
    return { 1: '开通中', 2: '处理中', 3: '已完成', 4: '已关闭', 5: '已失败' }[s] || `未知(${s})`
  }
  return { 1: '开通中', 2: '已完成', 3: '失败' }[s] || `未知(${s})`
}

function methodTag(m, provider) {
  if (provider === 'spark') {
    return { CreateProxy: 'primary', RenewProxy: 'success', DelProxy: 'danger' }[m] || 'info'
  }
  return { open: 'primary', renew: 'success', release: 'danger' }[m] || 'info'
}

function methodLabel(m, provider) {
  if (provider === 'ipipv') {
    return { open: '开通', renew: '续费', release: '释放' }[m] || m
  }
  return m
}

function instanceStatusType(s, provider) {
  if (provider === 'ipipv') {
    return { 1: 'warning', 2: 'warning', 3: 'success', 6: 'info', 10: 'info', 11: 'info' }[s] || 'info'
  }
  return { 1: 'warning', 2: 'success', 3: 'info', 4: 'info' }[s] || 'info'
}

function instanceStatusLabel(s, provider) {
  if (provider === 'ipipv') {
    return { 1: '待开通', 2: '创建中', 3: '运行中', 6: '已停止', 10: '已关闭', 11: '已释放' }[s] || '未知'
  }
  return { 1: '开通中', 2: '正常', 3: '释放中', 4: '已释放' }[s] || '未知'
}

function formatTime(t) {
  return t ? dayjs(t).format('YYYY-MM-DD HH:mm:ss') : '-'
}

function minutesAgo(t) {
  return t ? dayjs().diff(dayjs(t), 'minute') : 0
}

function normalizeSparkOrder(o) {
  const reqData = o.request_data || {}
  const countryCode = reqData.country_code || ''
  const info = getCountryInfo(countryCode)
  return {
    ...o,
    _key: `spark_${o.id}`,
    _provider: 'spark',
    _appOrderNo: o.req_order_no,
    _upstreamOrderNo: o.spark_order_no,
    _productId: o.product_id,
    _countryCn: reqData.country_cn || info.cn || '',
    _countryIso2: info.iso2 || '',
    _customerName: reqData.customer_name || '',
    _syncing: false,
  }
}

function normalizeIpipvOrder(o) {
  const reqData = o.request_data || {}
  const countryCode = reqData.country_code || ''
  const info = getCountryInfo(countryCode)
  return {
    ...o,
    _key: `ipipv_${o.id}`,
    _provider: 'ipipv',
    _appOrderNo: o.app_order_no,
    _upstreamOrderNo: o.ipipv_order_no,
    _productId: o.product_no,
    _countryCn: reqData.country_cn || info.cn || '',
    _countryIso2: info.iso2 || '',
    _customerName: reqData.customer_name || '',
    _syncing: false,
  }
}

function onFilterChange() {
  page.value = 1
  fetchData()
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: page.value, per_page: perPage.value }
    if (filterStatus.value) params.status = filterStatus.value

    const showSpark = !filterProvider.value || filterProvider.value === 'spark'
    const showIpipv = !filterProvider.value || filterProvider.value === 'ipipv'

    const sparkMethods = ['CreateProxy', 'RenewProxy', 'DelProxy']
    const ipipvMethods = ['open', 'renew', 'release']

    const isSparkMethod = filterMethod.value && sparkMethods.includes(filterMethod.value)
    const isIpipvMethod = filterMethod.value && ipipvMethods.includes(filterMethod.value)

    const fetchSpark = showSpark && !isIpipvMethod
    const fetchIpipv = showIpipv && !isSparkMethod

    const promises = []

    if (fetchSpark) {
      const sparkParams = { ...params }
      if (isSparkMethod) sparkParams.method = filterMethod.value
      promises.push(
        getSparkOrders(sparkParams)
          .then(res => ({ items: (res?.items || []).map(normalizeSparkOrder), total: res?.pagination?.total || 0 }))
          .catch(() => ({ items: [], total: 0 }))
      )
    } else {
      promises.push(Promise.resolve({ items: [], total: 0 }))
    }

    if (fetchIpipv) {
      const ipipvParams = { ...params }
      if (isIpipvMethod) ipipvParams.method = filterMethod.value
      promises.push(
        getIpipvOrders(ipipvParams)
          .then(res => ({ items: (res?.items || []).map(normalizeIpipvOrder), total: res?.pagination?.total || 0 }))
          .catch(() => ({ items: [], total: 0 }))
      )
    } else {
      promises.push(Promise.resolve({ items: [], total: 0 }))
    }

    const [sparkResult, ipipvResult] = await Promise.all(promises)

    const merged = [...sparkResult.items, ...ipipvResult.items]
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))

    orders.value = merged
    total.value = sparkResult.total + ipipvResult.total
  } catch {}
  finally { loading.value = false }
}

async function syncOne(row) {
  row._syncing = true
  try {
    if (row._provider === 'spark') {
      await syncSparkOrder(row.id)
    } else {
      await syncIpipvOrder(row.id)
    }
    ElMessage.success(`订单 ${row._appOrderNo} 同步完成`)
    fetchData()
  } catch {
    row._syncing = false
  }
}

async function syncAllPending() {
  const pending = orders.value.filter(o => o.status === 1)
  if (!pending.length) return
  syncAllLoading.value = true
  let success = 0
  for (const row of pending) {
    try {
      row._syncing = true
      if (row._provider === 'spark') {
        await syncSparkOrder(row.id)
      } else {
        await syncIpipvOrder(row.id)
      }
      success++
    } catch {}
    finally { row._syncing = false }
  }
  ElMessage.success(`同步完成：${success}/${pending.length} 成功`)
  syncAllLoading.value = false
  fetchData()
}

function showDetail(row) {
  detailOrder.value = row
  detailVisible.value = true
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.api-orders {
  .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;

    h2 { font-size: 20px; font-weight: 600; color: #2C3E50; margin: 0; }
    .header-actions { display: flex; gap: 8px; }
  }

  .filter-bar {
    display: flex; gap: 10px; margin-bottom: 12px;
  }

  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; }

  .flag-img-inline {
    width: 18px;
    height: 13px;
    object-fit: cover;
    border-radius: 2px;
    vertical-align: middle;
    margin-right: 4px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
  }

  .pagination-wrap {
    display: flex; justify-content: flex-end; margin-top: 16px;
  }

  .json-block {
    background: #F5F7FA;
    border: 1px solid #EBEEF5;
    border-radius: 6px;
    padding: 12px;
    font-size: 12px;
    font-family: 'SF Mono', Consolas, Monaco, monospace;
    max-height: 300px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-all;
  }
}
</style>
