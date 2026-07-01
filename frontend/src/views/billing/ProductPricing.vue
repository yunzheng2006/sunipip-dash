<template>
  <div class="product-pricing-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">产品定价</h2>
        <p class="page-desc">统一管理对客售价。每个国家可设通用价格，也可按 IP 类型单独定价。</p>
      </div>
      <div class="header-actions">
        <el-button @click="handleSyncCost" :loading="syncing"><el-icon><Refresh /></el-icon> 同步Spark成本</el-button>
        <el-button @click="openBatchSet"><el-icon><SetUp /></el-icon> 批量设置</el-button>
        <el-button type="primary" @click="openCountryDialog()"><el-icon><Plus /></el-icon> 添加国家定价</el-button>
      </div>
    </div>

    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm">
        <el-form-item label="国家">
          <el-input v-model="searchForm.country_name" placeholder="搜索国家" clearable style="width: 140px" />
        </el-form-item>
        <el-form-item label="IP组">
          <el-select v-model="searchForm.ip_group_id" placeholder="全部" clearable style="width: 150px">
            <el-option label="通用（不区分）" value="null" />
            <el-option v-for="g in ipGroupOptions" :key="g.id" :label="g.display_name || g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="searchForm.is_active" placeholder="全部" clearable style="width: 100px">
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column label="国家" width="180">
          <template #default="{ row }">
            <strong>{{ row.country_name || row.country_code }}</strong>
            <span class="code-tag">({{ row.country_code }})</span>
          </template>
        </el-table-column>
        <el-table-column label="IP组" width="130">
          <template #default="{ row }">
            <el-tag v-if="row.ip_group" size="small" effect="plain">{{ row.ip_group.display_name || row.ip_group.name }}</el-tag>
            <span v-else class="muted">通用</span>
          </template>
        </el-table-column>
        <el-table-column label="月售价" width="100" align="right">
          <template #default="{ row }">
            <span class="price">¥{{ row.monthly_price }}</span>
          </template>
        </el-table-column>
        <el-table-column label="Spark成本" width="100" align="right">
          <template #default="{ row }">
            <span v-if="row.spark_min_cost" class="muted">¥{{ row.spark_min_cost }}</span>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="销售价" width="90" align="right">
          <template #default="{ row }">
            <span v-if="row.sales_price" style="color:#409EFF;font-weight:600">¥{{ Number(row.sales_price).toFixed(2) }}</span>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="利润" width="90" align="right">
          <template #default="{ row }">
            <span v-if="row.spark_min_cost" :style="{ color: row.monthly_price - row.spark_min_cost > 0 ? '#67C23A' : '#F56C6C' }">
              ¥{{ (row.monthly_price - row.spark_min_cost).toFixed(2) }}
            </span>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="Spark库存" width="90" align="center">
          <template #default="{ row }">
            <span :style="{ color: row.spark_stock > 0 ? '#67C23A' : '#C0C4CC' }">{{ row.spark_stock || 0 }}</span>
          </template>
        </el-table-column>
        <el-table-column label="自有库存" width="90" align="center">
          <template #default="{ row }">
            <span :style="{ color: row.own_available_stock > 0 ? '#409EFF' : '#C0C4CC' }">{{ row.own_available_stock || 0 }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="openCountryDialog(row.country_code)">国家定价</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination-wrap">
        <el-pagination v-model:current-page="pagination.page" v-model:page-size="pagination.per_page"
          :total="pagination.total" :page-sizes="[20, 50, 100, 200]" layout="total, sizes, prev, pager, next"
          @size-change="fetchData" @current-change="fetchData" />
      </div>
    </el-card>

    <!-- Country Pricing Dialog -->
    <el-dialog v-model="countryDialogVisible" :title="countryData.country_name ? `${countryData.country_name} (${countryData.country_code}) 定价` : '添加国家定价'" width="780px" :close-on-click-modal="false" top="5vh">
      <el-form label-width="110px">
        <!-- Country Code Input (only for new) -->
        <el-form-item v-if="!countryData.country_code" label="国家代码" required>
          <el-input v-model="newCountryCode" placeholder="输入国家代码如 USA, BRA, JPN" style="width: 240px"
            @blur="loadCountryPricing(newCountryCode)" />
          <el-button type="primary" link style="margin-left: 12px" :loading="countryLoading"
            @click="loadCountryPricing(newCountryCode)">加载</el-button>
        </el-form-item>

        <!-- Country Info Header -->
        <div v-if="countryData.country_code" class="country-info-bar">
          <div class="info-left">
            <strong>{{ countryData.country_name }}</strong>
            <span class="code-tag">{{ countryData.country_code }}</span>
          </div>
          <div class="info-right">
            <span>Spark 总库存: <strong :style="{ color: countryData.total_spark_stock > 0 ? '#67C23A' : '#C0C4CC' }">{{ countryData.total_spark_stock || 0 }}</strong></span>
            <span v-if="countryData.total_min_cost">最低成本: <strong>¥{{ countryData.total_min_cost }}</strong></span>
          </div>
        </div>

        <!-- Pricing Table -->
        <div v-if="countryData.country_code" class="pricing-section">
          <div class="section-header">
            <span>定价列表</span>
            <div class="apply-all">
              <el-input-number v-model="applyAllPrice" :min="0" :precision="2" :step="5" size="small" style="width: 130px" />
              <el-button size="small" @click="applyPriceToAll">一键应用到所有</el-button>
            </div>
          </div>

          <el-table :data="pricingItems" size="small" border>
            <el-table-column label="IP类型" width="180">
              <template #default="{ row }">
                <strong v-if="!row.ip_group_id">🌐 通用（有啥开啥）</strong>
                <span v-else>{{ row.group_name }}</span>
              </template>
            </el-table-column>
            <el-table-column label="Spark库存" width="100" align="center">
              <template #default="{ row }">
                <span :style="{ color: row.spark_stock > 0 ? '#67C23A' : '#C0C4CC' }">{{ row.spark_stock || 0 }}</span>
              </template>
            </el-table-column>
            <el-table-column label="Spark成本" width="100" align="center">
              <template #default="{ row }">
                <span v-if="row.spark_min_cost" class="muted">¥{{ row.spark_min_cost }}</span>
                <span v-else class="muted">-</span>
              </template>
            </el-table-column>
            <el-table-column label="月售价 (¥)" width="150">
              <template #default="{ row }">
                <el-input-number v-model="row.monthly_price" :min="0" :precision="2" :step="5" size="small" style="width: 120px" />
              </template>
            </el-table-column>
            <el-table-column label="销售价 (¥)" width="150">
              <template #default="{ row }">
                <el-input-number v-model="row.sales_price" :min="0" :precision="2" :step="5" size="small" style="width: 120px" placeholder="选填" />
              </template>
            </el-table-column>
            <el-table-column label="利润" width="90" align="center">
              <template #default="{ row }">
                <span v-if="row.spark_min_cost && row.monthly_price" :style="{ color: row.monthly_price - row.spark_min_cost > 0 ? '#67C23A' : '#F56C6C', fontWeight: 600 }">
                  ¥{{ (row.monthly_price - row.spark_min_cost).toFixed(0) }}
                </span>
                <span v-else class="muted">-</span>
              </template>
            </el-table-column>
            <el-table-column label="启用" width="80" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.is_active" :active-value="1" :inactive-value="0" size="small" />
              </template>
            </el-table-column>
          </el-table>
        </div>
      </el-form>

      <template #footer>
        <el-button @click="countryDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="countrySaving" :disabled="!countryData.country_code" @click="saveCountry">
          保存定价
        </el-button>
      </template>
    </el-dialog>

    <!-- Batch Set Dialog -->
    <el-dialog v-model="batchVisible" title="批量设置定价" width="680px" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        为多个国家统一设置通用售价。已有定价的会更新，没有的会新建。
      </el-alert>
      <el-form :model="batchForm" label-width="100px">
        <el-form-item label="月售价" required>
          <el-input-number v-model="batchForm.monthly_price" :min="0" :precision="2" :step="5" style="width: 200px" />
        </el-form-item>
        <el-form-item label="选择国家" required>
          <div style="margin-bottom: 8px">
            <el-button size="small" @click="selectAllStock">全选有库存</el-button>
            <el-button size="small" @click="selectAllUnpriced">全选未定价</el-button>
            <el-button size="small" @click="batchForm.country_codes = []">清空</el-button>
            <el-tag style="margin-left: 8px">已选 {{ batchForm.country_codes.length }} 个</el-tag>
          </div>
          <el-checkbox-group v-model="batchForm.country_codes">
            <div v-loading="overviewLoading" class="country-grid">
              <el-checkbox v-for="c in overviewCountries" :key="c.code" :value="c.code" :label="c.code" class="country-item">
                {{ c.name }}
                <span v-if="c.spark_stock > 0" style="color:#67C23A">({{ c.spark_stock }})</span>
                <el-tag v-if="c.has_pricing" type="success" size="small" style="margin-left:2px">✓</el-tag>
              </el-checkbox>
            </div>
          </el-checkbox-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="batchVisible = false">取消</el-button>
        <el-button type="primary" :loading="batchSubmitting"
          :disabled="!batchForm.country_codes.length || !batchForm.monthly_price"
          @click="submitBatchSet">设置 {{ batchForm.country_codes.length }} 个国家</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh, SetUp } from '@element-plus/icons-vue'
import {
  getProductPricing, deleteProductPricing, batchSetProductPricing,
  syncSparkCost, getCountriesOverview, getCountryPricing, saveCountryPricing,
} from '@/api/productPricing'
import { getAllIpGroups } from '@/api/ipGroups'

const loading = ref(false)
const tableData = ref([])
const pagination = reactive({ page: 1, per_page: 50, total: 0 })
const searchForm = reactive({ country_name: '', ip_group_id: null, is_active: null })
const ipGroupOptions = ref([])
const syncing = ref(false)

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.country_name) params['filter[country_name]'] = searchForm.country_name
    if (searchForm.ip_group_id) {
      if (searchForm.ip_group_id === 'null') params['filter[ip_group_id]'] = ''
      else params['filter[ip_group_id]'] = searchForm.ip_group_id
    }
    if (searchForm.is_active !== null && searchForm.is_active !== '') params['filter[is_active]'] = searchForm.is_active
    const res = await getProductPricing(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch {} finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }
function handleReset() {
  Object.assign(searchForm, { country_name: '', ip_group_id: null, is_active: null })
  pagination.page = 1; fetchData()
}

async function loadOptions() {
  try { ipGroupOptions.value = (await getAllIpGroups()) || [] } catch {}
}

async function handleSyncCost() {
  syncing.value = true
  try {
    const res = await syncSparkCost()
    ElMessage.success(res?.message || '同步完成'); fetchData()
  } catch {} finally { syncing.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除「${row.country_name || row.country_code}」的「${row.ip_group?.display_name || '通用'}」定价？`, '确认', { type: 'warning' })
    await deleteProductPricing(row.id)
    ElMessage.success('已删除'); fetchData()
  } catch {}
}

// ===== Country Dialog =====
const countryDialogVisible = ref(false)
const countryLoading = ref(false)
const countrySaving = ref(false)
const newCountryCode = ref('')
const countryData = reactive({ country_code: '', country_name: '', total_spark_stock: 0, total_min_cost: null })
const pricingItems = ref([])
const applyAllPrice = ref(80)

function openCountryDialog(code) {
  newCountryCode.value = code || ''
  Object.assign(countryData, { country_code: '', country_name: '', total_spark_stock: 0, total_min_cost: null })
  pricingItems.value = []
  countryDialogVisible.value = true
  if (code) loadCountryPricing(code)
}

async function loadCountryPricing(code) {
  if (!code || code.length < 2) return
  countryLoading.value = true
  try {
    const res = await getCountryPricing(code.toUpperCase())
    Object.assign(countryData, {
      country_code: res.country_code,
      country_name: res.country_name,
      total_spark_stock: res.total_spark_stock || 0,
      total_min_cost: res.total_min_cost,
    })

    // Build pricing items: 通用 + 每个 IP 组
    const existingMap = {}
    for (const p of (res.pricings || [])) {
      existingMap[p.ip_group_id || 'default'] = p
    }

    const items = []
    // 通用价格（ip_group_id=null）
    const defaultPricing = existingMap['default']
    items.push({
      ip_group_id: null,
      group_name: '🌐 通用（有啥开啥）',
      monthly_price: defaultPricing ? Number(defaultPricing.monthly_price) : 0,
      sales_price: defaultPricing?.sales_price != null ? Number(defaultPricing.sales_price) : null,
      is_active: defaultPricing ? defaultPricing.is_active : 1,
      spark_stock: res.total_spark_stock || 0,
      spark_min_cost: res.total_min_cost,
    })

    // 每个 IP 组
    for (const g of (res.ip_groups || [])) {
      const existing = existingMap[g.id]
      const costs = res.group_costs?.[g.id]
      items.push({
        ip_group_id: g.id,
        group_name: g.display_name || g.name,
        monthly_price: existing ? Number(existing.monthly_price) : 0,
        sales_price: existing?.sales_price != null ? Number(existing.sales_price) : null,
        is_active: existing ? existing.is_active : 0,
        spark_stock: costs?.stock || 0,
        spark_min_cost: costs?.min_cost || null,
      })
    }

    pricingItems.value = items
  } catch { ElMessage.error('加载失败') }
  finally { countryLoading.value = false }
}

function applyPriceToAll() {
  for (const item of pricingItems.value) {
    item.monthly_price = applyAllPrice.value
    if (applyAllPrice.value > 0) item.is_active = 1
  }
}

async function saveCountry() {
  if (!countryData.country_code) return
  countrySaving.value = true
  try {
    await saveCountryPricing({
      country_code: countryData.country_code,
      country_name: countryData.country_name,
      access_type: 'dedicated',
      items: pricingItems.value.map(i => ({
        ip_group_id: i.ip_group_id,
        monthly_price: i.monthly_price,
        sales_price: i.sales_price,
        is_active: i.is_active,
      })),
    })
    ElMessage.success('保存成功')
    countryDialogVisible.value = false
    fetchData()
  } catch {} finally { countrySaving.value = false }
}

// ===== Batch Set =====
const batchVisible = ref(false)
const batchSubmitting = ref(false)
const overviewLoading = ref(false)
const overviewCountries = ref([])
const batchForm = reactive({ country_codes: [], monthly_price: 80 })

async function openBatchSet() {
  batchForm.country_codes = []; batchForm.monthly_price = 80
  batchVisible.value = true
  overviewLoading.value = true
  try { overviewCountries.value = (await getCountriesOverview()) || [] }
  catch {} finally { overviewLoading.value = false }
}

function selectAllStock() {
  batchForm.country_codes = overviewCountries.value.filter(c => c.spark_stock > 0).map(c => c.code)
}
function selectAllUnpriced() {
  batchForm.country_codes = overviewCountries.value.filter(c => !c.has_pricing && c.spark_stock > 0).map(c => c.code)
}

async function submitBatchSet() {
  if (!batchForm.country_codes.length || !batchForm.monthly_price) return
  batchSubmitting.value = true
  try {
    const res = await batchSetProductPricing({ ...batchForm })
    ElMessage.success(res?.message || '批量设置完成')
    batchVisible.value = false; fetchData()
  } catch {} finally { batchSubmitting.value = false }
}

onMounted(() => { fetchData(); loadOptions() })
</script>

<style lang="scss" scoped>
.product-pricing-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
    .header-actions { display: flex; gap: 8px; flex-shrink: 0; }
  }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .code-tag { color: #909399; margin-left: 4px; }
  .muted { color: #C0C4CC; }
  .price { font-weight: 600; color: #E8913A; }
}

.country-info-bar {
  display: flex; justify-content: space-between; align-items: center;
  background: linear-gradient(135deg, #FFF8F0, #FDF0E2); border: 1px solid #F5D9B5;
  border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;
  .info-left { font-size: 16px; .code-tag { color: #909399; font-size: 13px; margin-left: 8px; } }
  .info-right { display: flex; gap: 20px; font-size: 13px; color: #718096;
    strong { color: #2C3E50; }
  }
}

.pricing-section {
  .section-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
    font-size: 14px; font-weight: 600; color: #2C3E50;
    .apply-all { display: flex; gap: 8px; align-items: center; }
  }
}

.country-grid {
  max-height: 400px; overflow-y: auto; border: 1px solid #EBEEF5; border-radius: 4px; padding: 12px;
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px 16px;
  .country-item { margin-right: 0; }
}
</style>
