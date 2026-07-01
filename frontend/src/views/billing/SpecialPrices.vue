<template>
  <div class="special-prices-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">客户特批价</h2>
        <p class="page-desc">为特定客户设置协商后的特别价格。客户在面板下单时自动显示特批价。</p>
      </div>
      <el-button type="primary" @click="openDialog()"><el-icon><Plus /></el-icon> 添加特批价</el-button>
    </div>

    <el-card class="search-card">
      <el-form :inline="true">
        <el-form-item label="客户">
          <el-select v-model="filterCustomerId" filterable remote reserve-keyword clearable
            placeholder="客户名 / 手机 / 用户名" :remote-method="searchCustomers" :loading="customerLoading" style="width: 220px">
            <el-option v-for="c in customerOptions" :key="c.id"
              :label="`#${c.id} ${c.customer_name}${c.phone ? ' (' + c.phone + ')' : ''}`" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="国家">
          <el-select v-model="filterCountry" filterable clearable placeholder="全部国家" style="width: 180px">
            <el-option v-for="c in countryList" :key="c.code" :value="c.code">
              <img :src="getFlagUrl(c.iso2, 20)" :alt="c.cn" style="width:18px;height:12px;object-fit:cover;border-radius:2px;vertical-align:middle;margin-right:6px" />
              {{ c.cn }} ({{ c.code }})
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="fetchData">搜索</el-button>
          <el-button @click="filterCustomerId = null; filterCountry = ''; fetchData()">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 按客户分组展示 -->
    <template v-for="group in groupedData" :key="group.customer_id">
      <el-card class="customer-group-card">
        <template #header>
          <div class="group-header">
            <span class="group-header__name">{{ group.customer_name }}</span>
            <el-tag size="small" effect="plain" style="margin-left: 8px">{{ group.items.length }} 条规则</el-tag>
            <span v-if="group.phone" style="font-size:12px;color:#909399;margin-left:8px">{{ group.phone }}</span>
          </div>
        </template>
        <el-table :data="group.items" stripe size="small">
          <el-table-column label="国家/地区" min-width="160">
            <template #default="{ row }">
              <div style="display:flex;align-items:center;gap:6px">
                <img v-if="getIso2(row.country_code)" :src="getFlagUrl(getIso2(row.country_code), 20)"
                  style="width:20px;height:14px;object-fit:cover;border-radius:2px;box-shadow:0 1px 2px rgba(0,0,0,0.1)" />
                <span>{{ getCountryCn(row.country_code) || '全部国家' }}</span>
                <span v-if="row.area_code || row.city_code" style="font-size:11px;color:#909399">
                  {{ [row.area_code, row.city_code].filter(Boolean).join(' / ') }}
                </span>
              </div>
              <div v-if="row.product_id" style="font-size:11px;color:#409EFF;margin-top:2px">
                产品: {{ row.product_id }}
              </div>
            </template>
          </el-table-column>
          <el-table-column label="IP特批价" width="90" align="right">
            <template #default="{ row }">
              <span v-if="row.special_price != null" style="font-weight:700;color:#E8913A">¥{{ row.special_price }}</span>
              <span v-else style="color:#C0C4CC">--</span>
            </template>
          </el-table-column>
          <el-table-column label="视频专线" width="90" align="right">
            <template #default="{ row }">
              <span v-if="row.forward_price_video != null" style="font-weight:600;color:#2196F3">¥{{ row.forward_price_video }}</span>
              <span v-else style="color:#C0C4CC">--</span>
            </template>
          </el-table-column>
          <el-table-column label="直播手机" width="90" align="right">
            <template #default="{ row }">
              <span v-if="row.forward_price_live_mobile != null" style="font-weight:600;color:#9C27B0">¥{{ row.forward_price_live_mobile }}</span>
              <span v-else style="color:#C0C4CC">--</span>
            </template>
          </el-table-column>
          <el-table-column label="直播电脑" width="90" align="right">
            <template #default="{ row }">
              <span v-if="row.forward_price_live_pc != null" style="font-weight:600;color:#FF5722">¥{{ row.forward_price_live_pc }}</span>
              <span v-else style="color:#C0C4CC">--</span>
            </template>
          </el-table-column>
          <el-table-column label="IP折扣" width="80" align="right">
            <template #default="{ row }">
              <span v-if="row.discount_percent_static != null" style="font-weight:700;color:#67C23A">{{ row.discount_percent_static }}折</span>
              <span v-else style="color:#C0C4CC">--</span>
            </template>
          </el-table-column>
          <el-table-column label="视频折扣" width="80" align="right">
            <template #default="{ row }">
              <span v-if="row.discount_percent_video != null" style="font-weight:700;color:#2196F3">{{ row.discount_percent_video }}折</span>
              <span v-else style="color:#C0C4CC">--</span>
            </template>
          </el-table-column>
          <el-table-column label="备注" min-width="100" show-overflow-tooltip prop="remark" />
          <el-table-column label="状态" width="60" align="center">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启' : '停' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column v-if="!isSalesDiscountMode" label="操作" width="120" align="center" fixed="right">
            <template #default="{ row }">
              <el-button type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
              <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </template>
    <el-empty v-if="!loading && !groupedData.length" description="暂无特批价数据" />

    <!-- ===== 创建/编辑弹窗（Wizard 风格）===== -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑特批价' : '添加特批价'" width="900px" :close-on-click-modal="false" class="wizard-dialog">
      <!-- 步骤条 -->
      <div v-if="!editing" class="wizard-steps">
        <div class="ws-item" :class="{ active: wizardStep === 1, done: wizardStep > 1 }" @click="wizardStep > 1 && (wizardStep = 1)">
          <span class="ws-num">1</span><span class="ws-label">选客户 & 类型</span>
        </div>
        <div class="ws-line" :class="{ done: wizardStep > 1 }"></div>
        <div class="ws-item" :class="{ active: wizardStep === 2, done: wizardStep > 2 }" @click="wizardStep > 2 && (wizardStep = 2)">
          <span class="ws-num">2</span><span class="ws-label">选择产品</span>
        </div>
        <div class="ws-line" :class="{ done: wizardStep > 2 }"></div>
        <div class="ws-item" :class="{ active: wizardStep === 3 }">
          <span class="ws-num">3</span><span class="ws-label">设置价格</span>
        </div>
      </div>

      <!-- Step 1: 客户 + 定价类型 -->
      <div v-show="wizardStep === 1 || editing">
        <el-form :model="form" label-width="100px">
          <el-form-item label="客户" required>
            <el-select v-model="form.customer_id" filterable remote reserve-keyword
              placeholder="客户名 / 手机 / 用户名" :remote-method="searchCustomers" :loading="customerLoading" style="width: 100%"
              :disabled="!!editing">
              <el-option v-for="c in customerOptions" :key="c.id"
                :label="`#${c.id} ${c.customer_name}${c.phone ? ' (' + c.phone + ')' : ''}`" :value="c.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="定价范围">
            <el-radio-group v-model="scopeMode" @change="onScopeModeChange" :disabled="!!editing">
              <el-radio-button value="global">全局 (该客户所有)</el-radio-button>
              <el-radio-button value="country">按国家</el-radio-button>
              <el-radio-button value="product">按具体产品</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="scopeMode !== 'global' && scopeMode !== 'product'" label="国家">
            <el-select v-model="form.country_code" filterable placeholder="选择国家" style="width: 100%" @change="onCountryChange">
              <el-option v-for="c in countryList" :key="c.code" :label="`${c.cn} (${c.code})`" :value="c.code">
                <div style="display:flex;align-items:center;gap:8px">
                  <img :src="getFlagUrl(c.iso2, 20)" style="width:20px;height:14px;object-fit:cover;border-radius:2px" />
                  <span>{{ c.cn }}</span>
                  <span style="color:#909399;font-size:12px">{{ c.code }}</span>
                </div>
              </el-option>
            </el-select>
          </el-form-item>
        </el-form>
      </div>

      <!-- Step 2: 可视化产品选择（仅按产品模式） -->
      <div v-if="wizardStep === 2 && !editing && scopeMode === 'product'" class="product-picker">
        <div class="picker-toolbar">
          <!-- 来源切换 -->
          <div class="source-tabs">
            <button class="src-tab" :class="{ active: pickerSource === 'all' }" @click="pickerSource = 'all'">
              全部 <span class="src-count">{{ sourceStats.total }}</span>
            </button>
            <button class="src-tab spark" :class="{ active: pickerSource === 'spark' }" @click="pickerSource = 'spark'">
              Spark <span class="src-count">{{ sourceStats.spark }}</span>
            </button>
            <button class="src-tab ipipv" :class="{ active: pickerSource === 'ipipv' }" @click="pickerSource = 'ipipv'">
              IPIPV <span class="src-count">{{ sourceStats.ipipv }}</span>
            </button>
          </div>
          <el-input v-model="productSearch" placeholder="搜索国家/产品名" clearable :prefix-icon="Search" style="width: 200px" />
          <span class="picker-count">已选 <strong>{{ form.product_ids.length }}</strong> 个</span>
          <el-button v-if="form.product_ids.length" link type="danger" @click="form.product_ids = []">清空</el-button>
        </div>
        <div v-loading="productsLoading" class="picker-body">
          <template v-for="group in pickerGrouped" :key="group.continent">
            <div class="picker-section">
              <div class="picker-section-head">
                <span class="psh-title">{{ group.continent }}</span>
                <span class="psh-count">{{ group.items.length }} 个产品</span>
              </div>
              <div class="picker-grid">
                <div
                  v-for="p in group.items"
                  :key="p.product_id"
                  class="pick-card"
                  :class="{ selected: form.product_ids.includes(p.product_id) }"
                  @click="toggleProduct(p.product_id)"
                >
                  <div class="pick-check" v-if="form.product_ids.includes(p.product_id)"><el-icon :size="11"><Check /></el-icon></div>
                  <span class="pick-source-badge" :class="p.source">{{ p.source === 'spark' ? 'S' : 'I' }}</span>
                  <div class="pick-top">
                    <img v-if="p.iso2" :src="getFlagUrl(p.iso2, 20)" class="pick-flag" />
                    <span class="pick-name">{{ p.country_name || getCountryCn(p.country_code) }}</span>
                  </div>
                  <div class="pick-sub" v-if="p.city_name || p.area_name">{{ [p.area_name, p.city_name].filter(Boolean).join(' · ') }}</div>
                  <div class="pick-bottom">
                    <span class="pick-stock" :class="(p.inventory || 0) > 0 ? 'has' : 'empty'">{{ (p.inventory || 0) > 0 ? `库存${p.inventory}` : '无库存' }}</span>
                    <span class="pick-price" v-if="p.sale_price">¥{{ Number(p.sale_price).toFixed(0) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </template>
          <el-empty v-if="!productsLoading && !pickerGrouped.length" description="无匹配产品" :image-size="48" />
        </div>
      </div>

      <!-- Step 3 / 编辑模式: 价格设置 -->
      <div v-if="wizardStep === 3 || editing">
        <!-- 编辑模式下的产品显示 -->
        <div v-if="editing && form.product_id" class="edit-product-tag">
          <el-tag effect="plain" size="large">产品: {{ form.product_id }}</el-tag>
        </div>
        <!-- 创建模式已选产品展示 -->
        <div v-if="!editing && scopeMode === 'product' && form.product_ids.length" class="selected-products-summary">
          <span class="sps-label">已选 {{ form.product_ids.length }} 个产品：</span>
          <div class="sps-tags">
            <el-tag v-for="pid in form.product_ids.slice(0, 10)" :key="pid" size="small" closable @close="removeProduct(pid)">
              {{ getProductName(pid) }}
            </el-tag>
            <el-tag v-if="form.product_ids.length > 10" size="small" type="info">+{{ form.product_ids.length - 10 }}</el-tag>
          </div>
        </div>

        <el-divider content-position="left">
          <el-icon><Coin /></el-icon> 价格设置
        </el-divider>

        <el-form :model="form" label-width="110px">
          <el-alert v-if="isSalesDiscountMode" type="info" :closable="false" show-icon style="margin-bottom: 16px">
            销售折扣模式：仅可设置折扣百分比{{ maxDiscountLimit ? `，最低 ${maxDiscountLimit} 折` : '' }}
          </el-alert>

          <!-- 静态IP 特批价 -->
          <div class="price-section">
            <div class="price-section-head">
              <span class="ps-dot" style="background:#E8913A"></span>
              <span class="ps-title">静态 IP</span>
              <span class="ps-desc">仅影响静态 IP（不含任何转发套餐）的购买价格</span>
            </div>
            <el-form-item v-if="!isSalesDiscountMode" label="IP 特批价">
              <el-input-number v-model="form.special_price" :min="0" :precision="2" :step="5" placeholder="留空=不特批" />
              <span class="field-hint">元/月/条</span>
            </el-form-item>
            <el-form-item label="IP 折扣">
              <el-input-number v-model="form.discount_percent_static" :min="maxDiscountLimit || 1" :max="99" :precision="0" :step="5" placeholder="留空=不打折" />
              <span class="field-hint">如 85 = 八五折{{ maxDiscountLimit ? `（最低 ${maxDiscountLimit} 折）` : '' }}，IP 特批价为空时生效</span>
            </el-form-item>
          </div>

          <!-- 视频专线 -->
          <div class="price-section">
            <div class="price-section-head">
              <span class="ps-dot" style="background:#2196F3"></span>
              <span class="ps-title">视频专线</span>
              <span class="ps-desc">组合价 = IP 特批价 + 此处中转价（叠加模式）</span>
            </div>
            <el-form-item v-if="!isSalesDiscountMode" label="中转特批价">
              <el-input-number v-model="form.forward_price_video" :min="0" :precision="2" :step="5" placeholder="留空=按默认" />
              <span class="field-hint">元/月/条（仅中转加价部分）</span>
            </el-form-item>
            <el-form-item label="视频折扣">
              <el-input-number v-model="form.discount_percent_video" :min="maxDiscountLimit || 1" :max="99" :precision="0" :step="5" placeholder="留空=不打折" />
              <span class="field-hint">如 85 = 八五折{{ maxDiscountLimit ? `（最低 ${maxDiscountLimit} 折）` : '' }}，对视频专线组合价打折</span>
            </el-form-item>
          </div>

          <!-- 直播专线 -->
          <div v-if="!isSalesDiscountMode" class="price-section">
            <div class="price-section-head">
              <span class="ps-dot" style="background:#9C27B0"></span>
              <span class="ps-title">直播专线</span>
              <span class="ps-desc">独立定价，与静态 IP 价格无关，固定总价逻辑</span>
            </div>
            <el-form-item label="手机-特批价">
              <el-input-number v-model="form.forward_price_live_mobile" :min="0" :precision="2" :step="5" placeholder="留空=按默认" />
              <span class="field-hint">元/月/条（独立固定总价）</span>
            </el-form-item>
            <el-form-item label="电脑-特批价">
              <el-input-number v-model="form.forward_price_live_pc" :min="0" :precision="2" :step="5" placeholder="留空=按默认" />
              <span class="field-hint">元/月/条（独立固定总价）</span>
            </el-form-item>
          </div>

          <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="2" placeholder="如：销售谈价，长期合作优惠" /></el-form-item>
          <el-form-item label="启用"><el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" /></el-form-item>
        </el-form>
      </div>

      <template #footer>
        <div class="wizard-footer">
          <el-button v-if="!editing && wizardStep > 1" @click="wizardStep--">上一步</el-button>
          <span v-else></span>
          <div>
            <el-button @click="dialogVisible = false">取消</el-button>
            <el-button v-if="!editing && wizardStep < 3" type="primary" @click="nextStep" :disabled="!canNextStep">
              下一步
            </el-button>
            <el-button v-if="editing || wizardStep === 3" type="primary" :loading="submitting" @click="handleSubmit">
              {{ editing ? '保存' : '确认创建' }}
            </el-button>
          </div>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Check, Coin, Search } from '@element-plus/icons-vue'
import { getCustomerSpecialPrices, createCustomerSpecialPrice, updateCustomerSpecialPrice, deleteCustomerSpecialPrice } from '@/api/customerSpecialPrices'
import { getCustomers } from '@/api/customers'
import { getSparkProducts } from '@/api/spark'
import { getIpipvProducts } from '@/api/ipipv'
import { COUNTRIES, getFlagUrl, getCountryInfo } from '@/utils/countries'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const isSalesDiscountMode = computed(() =>
  authStore.hasPermission('pricing.set_discount') && !authStore.hasPermission('pricing.manage')
)
const maxDiscountLimit = computed(() => authStore.user?.max_discount_percent || null)

const loading = ref(false)
const rawData = ref([])
const filterCustomerId = ref(null)
const filterCountry = ref('')
const customerOptions = ref([])
const customerLoading = ref(false)
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const scopeMode = ref('product')
const productsLoading = ref(false)
const allProducts = ref([])
const productSearch = ref('')
const pickerSource = ref('all') // 'all' | 'spark' | 'ipipv'
const wizardStep = ref(1)

const form = reactive({
  customer_id: null, country_code: '', area_code: '', city_code: '',
  product_id: '', product_ids: [],
  special_price: null,
  forward_price_video: null, forward_price_live_mobile: null, forward_price_live_pc: null,
  discount_percent_static: null, discount_percent_video: null,
  remark: '', is_active: 1,
})

const countryList = computed(() =>
  Object.entries(COUNTRIES).map(([code, info]) => ({ code, ...info }))
    .sort((a, b) => a.cn.localeCompare(b.cn, 'zh'))
)

function getIso2(code) {
  if (!code) return ''
  return getCountryInfo(code).iso2 || ''
}
function getCountryCn(code) {
  if (!code) return ''
  return getCountryInfo(code).cn || code
}

const groupedData = computed(() => {
  const map = {}
  for (const item of rawData.value) {
    const cid = item.customer_id
    if (!map[cid]) {
      map[cid] = {
        customer_id: cid,
        customer_name: item.customer?.customer_name || `#${cid}`,
        phone: item.customer?.phone || '',
        items: [],
      }
    }
    map[cid].items.push(item)
  }
  return Object.values(map)
})

// 产品选择器：按来源 + 搜索词过滤，再按大洲分组
const pickerFilteredProducts = computed(() => {
  let list = allProducts.value
  if (pickerSource.value !== 'all') {
    list = list.filter(p => p.source === pickerSource.value)
  }
  const kw = productSearch.value.toLowerCase().trim()
  if (kw) {
    list = list.filter(p =>
      (p.display_name || '').toLowerCase().includes(kw) ||
      (p.country_name || '').toLowerCase().includes(kw) ||
      (p.product_id || '').toLowerCase().includes(kw) ||
      (p.city_name || '').toLowerCase().includes(kw) ||
      (p.area_name || '').toLowerCase().includes(kw)
    )
  }
  // 按库存降序
  return [...list].sort((a, b) => (b.inventory || 0) - (a.inventory || 0))
})

const pickerGrouped = computed(() => {
  const order = ['北美洲', '欧洲', '亚洲', '大洋洲', '南美洲', '非洲', '其他']
  const groups = {}
  for (const p of pickerFilteredProducts.value) {
    const cont = p.continent || '其他'
    if (!groups[cont]) groups[cont] = { continent: cont, items: [] }
    groups[cont].items.push(p)
  }
  return order.filter(c => groups[c]).map(c => groups[c])
})

const sourceStats = computed(() => {
  const spark = allProducts.value.filter(p => p.source === 'spark').length
  const ipipv = allProducts.value.filter(p => p.source === 'ipipv').length
  return { spark, ipipv, total: spark + ipipv }
})

function getProductName(pid) {
  const p = allProducts.value.find(x => x.product_id === pid)
  return p ? (p.display_name || p.product_name || pid) : pid
}

function toggleProduct(pid) {
  const idx = form.product_ids.indexOf(pid)
  if (idx >= 0) form.product_ids.splice(idx, 1)
  else form.product_ids.push(pid)
}

function removeProduct(pid) {
  const idx = form.product_ids.indexOf(pid)
  if (idx >= 0) form.product_ids.splice(idx, 1)
}

// Wizard 步骤控制
const canNextStep = computed(() => {
  if (wizardStep.value === 1) {
    if (!form.customer_id) return false
    if (scopeMode.value === 'country' && !form.country_code) return false
    return true
  }
  if (wizardStep.value === 2) {
    return form.product_ids.length > 0
  }
  return true
})

function nextStep() {
  if (wizardStep.value === 1) {
    if (scopeMode.value === 'product') {
      loadProducts()
      wizardStep.value = 2
    } else {
      wizardStep.value = 3
    }
  } else if (wizardStep.value === 2) {
    wizardStep.value = 3
  }
}

async function searchCustomers(kw) {
  customerLoading.value = true
  try {
    const params = { per_page: 30 }
    if (kw) params['filter[keyword]'] = kw
    customerOptions.value = (await getCustomers(params))?.items || []
  } catch {} finally { customerLoading.value = false }
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (filterCustomerId.value) params.customer_id = filterCustomerId.value
    if (filterCountry.value) params.country_code = filterCountry.value
    rawData.value = (await getCustomerSpecialPrices(params)) || []
  } catch {} finally { loading.value = false }
}

async function loadProducts() {
  if (allProducts.value.length) return
  productsLoading.value = true
  try {
    const [sparkRes, ipipvRes] = await Promise.all([
      getSparkProducts({}).catch(() => ({ data: { products: [] } })),
      getIpipvProducts({}).catch(() => ({ data: { products: [] } })),
    ])
    const spark = (sparkRes?.data?.products || sparkRes?.products || []).map(p => {
      const info = getCountryInfo(p.country_code)
      return {
        ...p,
        iso2: info.iso2,
        continent: info.continent || '其他',
        country_name: info.cn || p.country_code,
        display_name: `${info.cn || p.country_code} - ${p.product_name || p.product_id}`,
        area_name: p.area_name || '',
        city_name: p.city_name || '',
        sale_price: p.sale_price_ref || p.sale_price || p.price || null,
        source: 'spark',
      }
    })
    const ipipv = (ipipvRes?.data?.products || ipipvRes?.products || []).map(p => {
      const code = p.countryCode || p.country_code || ''
      const info = getCountryInfo(code)
      return {
        product_id: p.productNo || p.product_id,
        product_name: p.productName || p.product_name || p.productNo,
        country_code: code,
        iso2: info.iso2,
        continent: info.continent || '其他',
        country_name: info.cn || code,
        display_name: `${info.cn || code} - ${p.productName || p.productNo}`,
        area_name: '',
        city_name: '',
        inventory: p.inventory ?? p.stock,
        sale_price: p.sale_price || p.price || null,
        source: 'ipipv',
      }
    })
    allProducts.value = [...spark, ...ipipv]
  } catch {} finally { productsLoading.value = false }
}

function onScopeModeChange(mode) {
  if (mode === 'global') {
    form.country_code = ''
    form.area_code = ''
    form.city_code = ''
    form.product_id = ''
    form.product_ids = []
  } else if (mode === 'country') {
    form.product_id = ''
    form.product_ids = []
  }
}

function onCountryChange() {
  form.product_id = ''
  form.product_ids = []
}

function openDialog(row) {
  if (row) {
    editing.value = row
    wizardStep.value = 3
    Object.assign(form, {
      customer_id: row.customer_id,
      country_code: row.country_code || '',
      area_code: row.area_code || '',
      city_code: row.city_code || '',
      product_id: row.product_id || '',
      product_ids: [],
      special_price: row.special_price != null ? Number(row.special_price) : null,
      forward_price_video: row.forward_price_video != null ? Number(row.forward_price_video) : null,
      forward_price_live_mobile: row.forward_price_live_mobile != null ? Number(row.forward_price_live_mobile) : null,
      forward_price_live_pc: row.forward_price_live_pc != null ? Number(row.forward_price_live_pc) : null,
      discount_percent_static: row.discount_percent_static != null ? Number(row.discount_percent_static) : null,
      discount_percent_video: row.discount_percent_video != null ? Number(row.discount_percent_video) : null,
      remark: row.remark || '',
      is_active: row.is_active,
    })
    scopeMode.value = row.product_id ? 'product' : (row.country_code ? 'country' : 'global')
  } else {
    editing.value = null
    wizardStep.value = 1
    Object.assign(form, {
      customer_id: null, country_code: '', area_code: '', city_code: '',
      product_id: '', product_ids: [],
      special_price: null,
      forward_price_video: null, forward_price_live_mobile: null, forward_price_live_pc: null,
      discount_percent_static: null, discount_percent_video: null, remark: '', is_active: 1,
    })
    scopeMode.value = 'product'
    productSearch.value = ''
  }
  searchCustomers('')
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!form.customer_id) { ElMessage.warning('请选择客户'); return }
  const hasIp = form.special_price != null && form.special_price !== ''
  const hasFwdVideo = form.forward_price_video != null && form.forward_price_video !== ''
  const hasFwdLiveMobile = form.forward_price_live_mobile != null && form.forward_price_live_mobile !== ''
  const hasFwdLivePc = form.forward_price_live_pc != null && form.forward_price_live_pc !== ''
  const hasDiscStatic = form.discount_percent_static != null && form.discount_percent_static !== ''
  const hasDiscVideo = form.discount_percent_video != null && form.discount_percent_video !== ''
  if (!hasIp && !hasFwdVideo && !hasFwdLiveMobile && !hasFwdLivePc && !hasDiscStatic && !hasDiscVideo) {
    ElMessage.warning('请至少填写一项价格或折扣')
    return
  }

  const payload = { ...form }
  if (scopeMode.value === 'global') {
    payload.country_code = ''
    payload.area_code = ''
    payload.city_code = ''
    payload.product_id = ''
    payload.product_ids = []
  }

  submitting.value = true
  try {
    if (editing.value) {
      delete payload.product_ids
      await updateCustomerSpecialPrice(editing.value.id, payload)
      ElMessage.success('已更新')
    } else {
      if (scopeMode.value === 'product' && payload.product_ids?.length) {
        delete payload.product_id
      } else {
        delete payload.product_ids
      }
      await createCustomerSpecialPrice(payload)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    fetchData()
  } catch {} finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm('删除该特批价？', '确认', { type: 'warning' })
    await deleteCustomerSpecialPrice(row.id)
    ElMessage.success('已删除')
    fetchData()
  } catch {}
}

onMounted(() => { searchCustomers(''); fetchData() })
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$accent: #F5A623;
$text-primary: #1E293B;
$text-muted: #94A3B8;
$border: #E2E8F0;

.special-prices-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: $text-primary; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }

  .customer-group-card {
    margin-bottom: 12px;
    :deep(.el-card__header) { padding: 10px 16px; background: #FAFBFC; }
    .group-header {
      display: flex; align-items: center;
      &__name { font-size: 15px; font-weight: 600; color: #303133; }
    }
  }
}

// ===== Wizard Dialog =====
.wizard-dialog {
  :deep(.el-dialog__body) { padding: 16px 24px; }
}

.wizard-steps {
  display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 20px; padding: 0 20px;
  .ws-item {
    display: flex; align-items: center; gap: 6px; cursor: default; padding: 6px 12px; border-radius: 20px;
    transition: all 0.2s;
    .ws-num {
      width: 24px; height: 24px; border-radius: 50%; background: $border; color: $text-muted;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; transition: all 0.2s;
    }
    .ws-label { font-size: 13px; color: $text-muted; font-weight: 500; }
    &.active {
      background: $brand-light;
      .ws-num { background: $brand; color: #fff; }
      .ws-label { color: $brand; font-weight: 600; }
    }
    &.done {
      cursor: pointer;
      .ws-num { background: #D1FAE5; color: #059669; }
      .ws-label { color: #059669; }
      &:hover { background: #ECFDF5; }
    }
  }
  .ws-line {
    width: 40px; height: 2px; background: $border; border-radius: 1px; transition: background 0.2s;
    &.done { background: #6EE7B7; }
  }
}

// ===== Product Picker =====
.product-picker {
  .picker-toolbar {
    display: flex; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap;
    .picker-count { font-size: 13px; color: $text-muted; margin-left: auto;
      strong { color: $brand; font-weight: 700; }
    }
  }
  .source-tabs {
    display: flex; gap: 4px; background: #F1F5F9; border-radius: 8px; padding: 3px;
    .src-tab {
      border: none; background: transparent; padding: 5px 12px; border-radius: 6px;
      font-size: 12px; font-weight: 600; color: $text-muted; cursor: pointer; transition: all 0.15s;
      .src-count { font-weight: 400; opacity: 0.7; margin-left: 2px; }
      &:hover { color: $text-primary; }
      &.active { background: #fff; color: $text-primary; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
      &.spark.active { color: #E8913A; }
      &.ipipv.active { color: #7C3AED; }
    }
  }
  .picker-body {
    max-height: 420px; overflow-y: auto; padding: 4px;
    border: 1px solid $border; border-radius: 10px; background: #FAFBFC;
  }
  .picker-section {
    margin-bottom: 12px;
    &:last-child { margin-bottom: 0; }
  }
  .picker-section-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 6px; padding: 4px 8px;
    .psh-title {
      font-size: 13px; font-weight: 700; color: $text-primary;
      padding-left: 8px; border-left: 3px solid $brand;
    }
    .psh-count { font-size: 11px; color: $text-muted; }
  }
  .picker-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px;
    padding: 0 4px;
  }
}

.pick-card {
  position: relative; padding: 9px 10px 8px; background: #fff;
  border: 1.5px solid transparent; border-radius: 10px; cursor: pointer;
  transition: all 0.15s; box-shadow: 0 1px 2px rgba(0,0,0,0.04);

  &:hover { border-color: rgba($brand, 0.3); box-shadow: 0 3px 8px rgba($brand, 0.08); }
  &.selected { border-color: $brand; background: $brand-light; }

  .pick-check {
    position: absolute; top: -4px; left: -4px; width: 18px; height: 18px;
    background: $brand; border-radius: 50%; display: flex; align-items: center;
    justify-content: center; color: #fff; font-size: 10px;
  }
  .pick-source-badge {
    position: absolute; top: 4px; right: 5px; font-size: 9px; font-weight: 800;
    padding: 1px 4px; border-radius: 4px; line-height: 1.4;
    &.spark { background: #FEF3E2; color: #E8913A; }
    &.ipipv { background: #F3E8FF; color: #7C3AED; }
  }
  .pick-top { display: flex; align-items: center; gap: 5px; margin-bottom: 2px; padding-right: 20px; }
  .pick-flag { width: 18px; height: 12px; object-fit: cover; border-radius: 2px; }
  .pick-name { font-size: 12px; font-weight: 600; color: $text-primary; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .pick-sub { font-size: 11px; color: $brand; font-weight: 500; margin-bottom: 2px; }
  .pick-bottom {
    display: flex; align-items: baseline; justify-content: space-between; margin-top: 3px;
    .pick-stock { font-size: 10px; color: $text-muted;
      &.has { color: #16A34A; }
      &.empty { color: #CBD5E1; }
    }
    .pick-price { font-size: 13px; font-weight: 800; color: $accent; font-family: 'SF Mono', Consolas, monospace; }
  }
}

// ===== Price Sections =====
.price-section {
  margin-bottom: 16px; padding: 14px 16px 8px; border-radius: 10px; border: 1px solid $border; background: #FAFBFC;
  .price-section-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
    .ps-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .ps-title { font-size: 14px; font-weight: 700; color: $text-primary; }
    .ps-desc { font-size: 11px; color: $text-muted; }
  }
}

.field-hint { margin-left: 8px; font-size: 12px; color: $text-muted; }

.selected-products-summary {
  margin-bottom: 12px; padding: 10px 14px; background: $brand-light; border-radius: 8px;
  .sps-label { font-size: 12px; color: $text-primary; font-weight: 500; margin-bottom: 6px; display: block; }
  .sps-tags { display: flex; flex-wrap: wrap; gap: 4px; }
}

.edit-product-tag { margin-bottom: 12px; }

// ===== Footer =====
.wizard-footer {
  display: flex; justify-content: space-between; align-items: center; width: 100%;
}
</style>
