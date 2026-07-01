<template>
  <div class="pricing-multipliers-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">销售倍率定价</h2>
        <p class="page-desc">客户售价 = Spark 成本 × 倍率。支持全局/国家/州/城市/产品级配置，优先级从小到大。</p>
      </div>
      <div class="header-actions">
        <el-button @click="openPreview">预览售价</el-button>
        <el-button type="primary" @click="openDialog()"><el-icon><Plus /></el-icon> 添加倍率</el-button>
      </div>
    </div>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column label="优先级" width="80" align="center" sortable :sort-method="(a, b) => (a.priority || 0) - (b.priority || 0)">
          <template #default="{ row }">
            <span :style="{ fontWeight: 700, color: row.priority > 0 ? '#E6A23C' : '#C0C4CC', fontSize: '15px' }">{{ row.priority || 0 }}</span>
          </template>
        </el-table-column>
        <el-table-column label="作用范围" width="120">
          <template #default="{ row }">
            <el-tag :type="scopeTag(row.scope)" size="small">{{ scopeLabel(row.scope) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="国家" width="100">
          <template #default="{ row }">{{ row.country_code || '-' }}</template>
        </el-table-column>
        <el-table-column label="州/城市" width="140">
          <template #default="{ row }">{{ row.area_code || row.city_code || '-' }}</template>
        </el-table-column>
        <el-table-column label="成本匹配" width="90" align="center">
          <template #default="{ row }">
            <span v-if="row.cost_match !== null && row.cost_match !== undefined" style="color:#2196F3;font-weight:600">¥{{ Number(row.cost_match).toFixed(2) }}</span>
            <span v-else style="color:#C0C4CC">不限</span>
          </template>
        </el-table-column>
        <el-table-column label="倍率" width="100" align="center">
          <template #default="{ row }">
            <span style="font-weight:700;color:#E8913A;font-size:16px">{{ row.multiplier }}x</span>
          </template>
        </el-table-column>
        <el-table-column label="最低售价" width="100" align="right">
          <template #default="{ row }">{{ row.min_price ? `¥${row.min_price}` : '-' }}</template>
        </el-table-column>
        <el-table-column label="固定售价" width="100" align="right">
          <template #default="{ row }">
            <span v-if="row.fixed_price" style="color:#67C23A;font-weight:600">¥{{ row.fixed_price }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="销售倍率" width="90" align="center">
          <template #default="{ row }">
            <span v-if="row.sales_multiplier" style="color:#409EFF;font-weight:600">{{ row.sales_multiplier }}x</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="销售固定价" width="100" align="right">
          <template #default="{ row }">
            <span v-if="row.sales_fixed_price" style="color:#409EFF;font-weight:600">¥{{ row.sales_fixed_price }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
            <el-button v-if="row.scope !== 'global'" type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Create/Edit Dialog -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑倍率' : '添加倍率'" width="520px" :close-on-click-modal="false">
      <el-form :model="form" label-width="100px">
        <el-form-item label="作用范围" required>
          <el-select v-model="form.scope" :disabled="!!editing" style="width:100%">
            <el-option label="全局默认" value="global" />
            <el-option label="按国家" value="country" />
            <el-option label="按州" value="area" />
            <el-option label="按城市" value="city" />
            <el-option label="按产品" value="product" />
          </el-select>
        </el-form-item>
        <el-form-item label="优先级">
          <el-input-number v-model="form.priority" :min="0" :max="9999" :step="10" style="width:180px" />
          <span style="margin-left:8px;font-size:12px;color:#909399">
            数值越大越优先。相同优先级时按作用范围粒度决定（产品 > 城市 > 州 > 国家 > 全局）。
          </span>
        </el-form-item>
        <el-form-item v-if="form.scope !== 'global'" label="国家">
          <el-select v-model="form.country_code" filterable clearable
            placeholder="搜索国家名称或代码" style="width: 100%"
            @change="onCountryChange">
            <el-option v-for="c in countryOptions" :key="c.code"
              :label="`${c.cname || c.name} (${c.code})`" :value="c.code" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.scope === 'area' || form.scope === 'city'" label="州/省">
          <el-select v-model="form.area_code" filterable clearable
            placeholder="请先选国家" style="width: 100%"
            :disabled="!form.country_code || stateLoading"
            :loading="stateLoading"
            @change="onStateChange">
            <el-option v-for="s in stateOptions" :key="s.code_full"
              :label="`${s.cname || s.name} (${s.code_full})`" :value="s.code_full" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.scope === 'city'" label="城市">
          <el-select v-model="form.city_code" filterable clearable
            placeholder="先选州/省" style="width: 100%"
            :disabled="!form.area_code || cityLoading"
            :loading="cityLoading">
            <el-option v-for="c in cityOptions" :key="c.code"
              :label="`${c.cname || c.name} (${c.code})`" :value="c.code" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.scope === 'product'" label="产品">
          <el-select v-model="form.product_id" filterable clearable
            placeholder="搜索产品（按名称/ID）" style="width: 100%">
            <el-option v-for="p in productOptions" :key="p.product_id"
              :label="`${p.product_name || p.product_id} · ${p.country_code}${p.isp ? ' · ' + p.isp : ''}`" :value="p.product_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="成本价匹配">
          <el-input-number v-model="form.cost_match" :min="0" :precision="2" :step="1" placeholder="留空=不限成本" style="width:180px" />
          <span style="margin-left:8px;font-size:12px;color:#909399">
            仅匹配成本为此值的产品（如"美国 cost=21 → 固定 ¥55"）。留空则该 scope 下所有产品都匹配。
          </span>
        </el-form-item>
        <div v-if="affectedProducts.length" class="affected-preview">
          <div class="ap-head">
            <el-icon><InfoFilled /></el-icon>
            将影响 <b>{{ affectedProducts.length }}</b> 个 Spark 产品
          </div>
          <div class="ap-list">
            <div v-for="p in affectedProducts.slice(0, 8)" :key="p.product_id" class="ap-row">
              <span>{{ p.country_code }}<span v-if="p.area_code"> · {{ p.area_code }}</span><span v-if="p.city_code"> · {{ p.city_code }}</span></span>
              <span class="ap-name">{{ p.product_name }}</span>
              <span class="ap-cost">成本 ¥{{ Number(p.cost_price || 0).toFixed(2) }}</span>
            </div>
            <div v-if="affectedProducts.length > 8" class="ap-more">... 及 {{ affectedProducts.length - 8 }} 个</div>
          </div>
        </div>
        <el-form-item label="销售倍率" required>
          <el-input-number v-model="form.multiplier" :min="0.1" :max="99" :step="0.1" :precision="2" style="width:180px" />
          <span style="margin-left:8px;font-size:13px;color:#909399">成本 × {{ form.multiplier }} = 售价</span>
        </el-form-item>
        <el-form-item label="最低售价">
          <el-input-number v-model="form.min_price" :min="0" :precision="2" :step="5" style="width:180px" />
          <span style="margin-left:8px;font-size:12px;color:#909399">兜底价格，倍率算出来低于此值则用此值</span>
        </el-form-item>
        <el-form-item label="固定售价">
          <el-input-number v-model="form.fixed_price" :min="0" :precision="2" :step="5" style="width:180px" />
          <span style="margin-left:8px;font-size:12px;color:#909399">设置后忽略倍率，直接用此价</span>
        </el-form-item>
        <el-divider content-position="left" style="margin:12px 0">
          <span style="font-size:13px;color:#409EFF">销售成本配置（销售人员看到的"成本"）</span>
        </el-divider>
        <el-form-item label="销售倍率">
          <el-input-number v-model="form.sales_multiplier" :min="0.1" :max="99" :step="0.1" :precision="2" style="width:180px" />
          <span style="margin-left:8px;font-size:13px;color:#909399">销售成本 = Spark成本 × {{ form.sales_multiplier || '?' }}</span>
        </el-form-item>
        <el-form-item label="销售固定价">
          <el-input-number v-model="form.sales_fixed_price" :min="0" :precision="2" :step="5" style="width:180px" />
          <span style="margin-left:8px;font-size:12px;color:#909399">设置后忽略销售倍率，销售看到此值为成本</span>
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- Preview Dialog -->
    <el-dialog v-model="previewVisible" title="售价预览" width="800px" top="5vh">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom:12px">
        基于当前倍率配置和 Spark 实时成本，预览各国家的客户售价范围。
      </el-alert>
      <el-table :data="previewData" v-loading="previewLoading" stripe size="small" max-height="500">
        <el-table-column label="国家" width="140">
          <template #default="{ row }">
            <strong>{{ row.country_name }}</strong> <span style="color:#909399">({{ row.country_code }})</span>
          </template>
        </el-table-column>
        <el-table-column label="产品数" width="80" align="center">
          <template #default="{ row }">{{ row.products }}</template>
        </el-table-column>
        <el-table-column label="库存" width="80" align="center">
          <template #default="{ row }">{{ row.total_stock }}</template>
        </el-table-column>
        <el-table-column label="成本范围" width="140">
          <template #default="{ row }">
            <span style="color:#909399">¥{{ row.min_cost }} ~ ¥{{ row.max_cost }}</span>
          </template>
        </el-table-column>
        <el-table-column label="售价范围" width="140">
          <template #default="{ row }">
            <span v-if="row.has_pricing" style="color:#E8913A;font-weight:600">¥{{ row.min_sale }} ~ ¥{{ row.max_sale }}</span>
            <el-tag v-else type="danger" size="small">未定价</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, InfoFilled } from '@element-plus/icons-vue'
import {
  getPricingMultipliers, createPricingMultiplier, updatePricingMultiplier,
  deletePricingMultiplier, previewPricing,
} from '@/api/pricingMultipliers'
import request from '@/utils/request'

const loading = ref(false)
const tableData = ref([])

// 地区 + 产品下拉数据
const countryOptions = ref([])
const stateOptions = ref([])
const cityOptions = ref([])
const productOptions = ref([])
const stateLoading = ref(false)
const cityLoading = ref(false)
const productLoading = ref(false)
// 当前国家下的全部产品（用于 affected 预览）
const countryProducts = ref([])

async function loadCountries() {
  try { countryOptions.value = await request.get('/spark/areas/countries') || [] } catch {}
}
async function loadStates(country) {
  if (!country) { stateOptions.value = []; return }
  stateLoading.value = true
  try { stateOptions.value = await request.get('/spark/areas/states', { params: { country_code: country } }) || [] }
  catch {} finally { stateLoading.value = false }
}
async function loadCities(country, state) {
  if (!country || !state) { cityOptions.value = []; return }
  cityLoading.value = true
  try { cityOptions.value = await request.get('/spark/areas/cities', { params: { country_code: country, state_code: state } }) || [] }
  catch {} finally { cityLoading.value = false }
}
async function loadCountryProducts(country) {
  if (!country) { countryProducts.value = []; productOptions.value = []; return }
  productLoading.value = true
  try {
    const res = await request.get('/pricing-multipliers/product-list', { params: { country_code: country } })
    countryProducts.value = res?.products || []
    productOptions.value = countryProducts.value
  } catch {} finally { productLoading.value = false }
}

// 根据当前作用域动态提示可影响的产品
const affectedProducts = computed(() => {
  if (!form.country_code) return []
  let list = countryProducts.value
  if (form.scope === 'area' && form.area_code) {
    list = list.filter(p => p.area_code === form.area_code)
  }
  if (form.scope === 'city' && form.city_code) {
    list = list.filter(p => p.city_code === form.city_code)
  }
  if (form.scope === 'product' && form.product_id) {
    list = list.filter(p => p.product_id === form.product_id)
  }
  return list
})

function onCountryChange(code) {
  form.area_code = ''
  form.city_code = ''
  form.product_id = ''
  stateOptions.value = []
  cityOptions.value = []
  if (code) {
    loadStates(code)
    loadCountryProducts(code)
  }
}
function onStateChange(code) {
  form.city_code = ''
  cityOptions.value = []
  if (code && form.country_code) loadCities(form.country_code, code)
}

function scopeLabel(s) { return { global: '全局', country: '国家', area: '州', city: '城市', product: '产品' }[s] || s }
function scopeTag(s) { return { global: 'danger', country: 'warning', area: '', city: 'success', product: 'info' }[s] || '' }

async function fetchData() {
  loading.value = true
  try { tableData.value = (await getPricingMultipliers()) || [] } catch {} finally { loading.value = false }
}

// Dialog
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = reactive({
  scope: 'country', priority: 0, country_code: '', area_code: '', city_code: '', product_id: '',
  cost_match: null, multiplier: 2.0, min_price: null, fixed_price: null,
  sales_multiplier: null, sales_fixed_price: null,
  is_active: 1, remark: '',
})

function openDialog(row) {
  if (row) {
    editing.value = row
    Object.assign(form, {
      scope: row.scope, priority: row.priority || 0,
      country_code: row.country_code || '', area_code: row.area_code || '',
      city_code: row.city_code || '', product_id: row.product_id || '',
      cost_match: row.cost_match !== null && row.cost_match !== undefined ? Number(row.cost_match) : null,
      multiplier: Number(row.multiplier), min_price: row.min_price ? Number(row.min_price) : null,
      fixed_price: row.fixed_price ? Number(row.fixed_price) : null,
      sales_multiplier: row.sales_multiplier ? Number(row.sales_multiplier) : null,
      sales_fixed_price: row.sales_fixed_price ? Number(row.sales_fixed_price) : null,
      is_active: row.is_active, remark: row.remark || '',
    })
    // 编辑时预加载国家相关的下拉数据
    if (row.country_code) {
      loadStates(row.country_code)
      loadCountryProducts(row.country_code)
      if (row.area_code) loadCities(row.country_code, row.area_code)
    }
  } else {
    editing.value = null
    Object.assign(form, {
      scope: 'country', priority: 0, country_code: '', area_code: '', city_code: '', product_id: '',
      cost_match: null, multiplier: 2.0, min_price: null, fixed_price: null,
      sales_multiplier: null, sales_fixed_price: null,
      is_active: 1, remark: '',
    })
    stateOptions.value = []
    cityOptions.value = []
    productOptions.value = []
    countryProducts.value = []
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  submitting.value = true
  try {
    if (editing.value) {
      await updatePricingMultiplier(editing.value.id, { ...form })
      ElMessage.success('更新成功')
    } else {
      await createPricingMultiplier({ ...form })
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false; fetchData()
  } catch {} finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除该倍率规则？`, '确认', { type: 'warning' })
    await deletePricingMultiplier(row.id)
    ElMessage.success('已删除'); fetchData()
  } catch {}
}

// Preview
const previewVisible = ref(false)
const previewLoading = ref(false)
const previewData = ref([])

async function openPreview() {
  previewVisible.value = true
  previewLoading.value = true
  try {
    const res = await previewPricing()
    previewData.value = res?.countries || []
  } catch {} finally { previewLoading.value = false }
}

onMounted(() => { fetchData(); loadCountries() })
</script>

<style lang="scss" scoped>
.pricing-multipliers-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
    .header-actions { display: flex; gap: 8px; }
  }
}
.affected-preview {
  margin: 4px 0 0 100px;
  padding: 10px 12px;
  background: #F0F9FF;
  border: 1px solid #BAE6FD;
  border-radius: 8px;
  font-size: 12px;
  color: #0C4A6E;
  .ap-head {
    display: flex; align-items: center; gap: 6px;
    font-weight: 600; margin-bottom: 8px;
    b { color: #0369A1; font-size: 14px; }
  }
  .ap-list { display: flex; flex-direction: column; gap: 4px; max-height: 180px; overflow-y: auto; }
  .ap-row {
    display: flex; gap: 10px; align-items: center; line-height: 1.5;
    .ap-name { flex: 1; color: #64748B; font-size: 11px; }
    .ap-cost { color: #0369A1; font-family: 'SF Mono', monospace; font-size: 11px; }
  }
  .ap-more { color: #94A3B8; font-size: 11px; text-align: center; padding-top: 4px; }
}
</style>
