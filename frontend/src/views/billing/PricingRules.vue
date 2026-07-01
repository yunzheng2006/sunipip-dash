<template>
  <div class="pricing-rule-list">
    <h2 class="page-title">定价规则</h2>

    <el-tabs v-model="activeTab" class="pricing-tabs">
      <!-- =========================== -->
      <!-- TAB 1: 自有 IP 定价（IP组）  -->
      <!-- =========================== -->
      <el-tab-pane label="自有 IP 定价" name="own">
        <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
          按 <strong>IP 组</strong> 配置月单价，用于业务员创建订单时自动带出价格。
        </el-alert>

        <el-card>
          <div class="toolbar">
            <el-select
              v-model="filterIpGroupId"
              placeholder="按IP组筛选"
              clearable
              style="width: 220px"
              @change="handleFilter"
            >
              <el-option
                v-for="g in ipGroupOptions"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </el-select>
            <el-button type="primary" @click="openDialog()">
              <el-icon><Plus /></el-icon>新建规则
            </el-button>
          </div>

          <el-table :data="tableData" v-loading="loading" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column label="IP组名称" min-width="150">
              <template #default="{ row }">
                {{ row.ip_group?.name || row.ip_group_name || '-' }}
              </template>
            </el-table-column>
            <el-table-column label="国家" width="120">
              <template #default="{ row }">
                {{ row.ip_group?.country || row.country || '-' }}
              </template>
            </el-table-column>
            <el-table-column label="月价格" width="130" align="right">
              <template #default="{ row }">¥{{ Number(row.monthly_price || row.price || 0).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column label="成本价" width="130" align="right">
              <template #default="{ row }">
                {{ row.cost_price != null ? '¥' + Number(row.cost_price).toFixed(2) : '-' }}
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
                  {{ row.status === 'active' ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="150" align="center" fixed="right">
              <template #default="{ row }">
                <el-button type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
                <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-wrap">
            <el-pagination
              v-model:current-page="pagination.page"
              v-model:page-size="pagination.page_size"
              :total="pagination.total"
              :page-sizes="[10, 20, 50]"
              layout="total, sizes, prev, pager, next"
              @size-change="fetchData"
              @current-change="fetchData"
            />
          </div>
        </el-card>
      </el-tab-pane>

      <!-- =========================== -->
      <!-- TAB 2: Spark IP 定价（国家）-->
      <!-- =========================== -->
      <el-tab-pane label="Spark IP 定价" name="spark">
        <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 12px">
          <template #title>
            按<strong>国家</strong>为 Spark IP 配置对客售价。一条规则可覆盖多个国家，每个国家同时只能绑定一条启用中的规则。
            <span v-if="lastRefreshedAt" style="margin-left: 8px; font-size: 12px; color: #909399">
              （库存最后刷新：{{ formatRefreshTime(lastRefreshedAt) }}）
            </span>
          </template>
        </el-alert>

        <el-card>
          <div class="toolbar">
            <div class="stats">
              共 <strong>{{ sparkRules.length }}</strong> 条规则，覆盖 <strong>{{ priceBoundCount }}</strong> / {{ totalCountries }} 个国家，
              总库存 <strong style="color: #67C23A">{{ totalStock }}</strong> 条
            </div>
            <div>
              <el-button @click="loadSparkData" :loading="sparkLoading">刷新</el-button>
              <el-button type="primary" @click="openSparkDialog()">
                <el-icon><Plus /></el-icon>新建 Spark 定价
              </el-button>
            </div>
          </div>

          <el-table :data="sparkRules" v-loading="sparkLoading" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column label="规则名称" min-width="160">
              <template #default="{ row }">
                <strong>{{ row.name }}</strong>
                <div v-if="row.description" style="font-size: 11px; color: #909399; margin-top: 2px">
                  {{ row.description }}
                </div>
              </template>
            </el-table-column>
            <el-table-column label="月单价" width="110" align="right">
              <template #default="{ row }">
                <span style="color: #E8913A; font-weight: 700; font-size: 16px">¥{{ Number(row.monthly_price).toFixed(2) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="参考成本" width="100" align="right">
              <template #default="{ row }">
                {{ row.cost_price ? '¥' + Number(row.cost_price).toFixed(2) : '-' }}
              </template>
            </el-table-column>
            <el-table-column label="销售价" width="100" align="right">
              <template #default="{ row }">
                <span v-if="row.sales_price" style="color:#409EFF;font-weight:600">¥{{ Number(row.sales_price).toFixed(2) }}</span>
                <span v-else style="color: #C0C4CC">-</span>
              </template>
            </el-table-column>
            <el-table-column label="利润/月" width="90" align="right">
              <template #default="{ row }">
                <span v-if="row.cost_price" :style="{ color: row.monthly_price - row.cost_price >= 0 ? '#67C23A' : '#F56C6C' }">
                  ¥{{ (row.monthly_price - row.cost_price).toFixed(2) }}
                </span>
                <span v-else style="color: #C0C4CC">-</span>
              </template>
            </el-table-column>
            <el-table-column label="总库存" width="90" align="right">
              <template #default="{ row }">
                <span :style="{ color: row.total_stock > 0 ? '#67C23A' : '#C0C4CC', fontWeight: 600 }">
                  {{ row.total_stock }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="覆盖的国家" min-width="300">
              <template #default="{ row }">
                <div class="bound-countries">
                  <el-tag
                    v-for="c in (row.countries || [])"
                    :key="c.code"
                    size="small"
                    type="info"
                    effect="plain"
                    style="margin-right: 4px; margin-bottom: 2px"
                  >
                    {{ c.name }}
                    <span style="color: #E8913A; margin-left: 4px">{{ c.stock }}</span>
                  </el-tag>
                  <span v-if="!row.countries?.length" style="color: #C0C4CC">未覆盖任何国家</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="80" align="center">
              <template #default="{ row }">
                <el-tag :type="row.is_active ? 'success' : 'info'" size="small">
                  {{ row.is_active ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="140" align="center" fixed="right">
              <template #default="{ row }">
                <el-button type="primary" link size="small" @click="openSparkDialog(row)">编辑</el-button>
                <el-button type="danger" link size="small" @click="handleDeleteSpark(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-empty v-if="!sparkLoading && !sparkRules.length" description="尚未配置 Spark 定价规则" />
        </el-card>

        <!-- 未定价国家提示 -->
        <el-card v-if="unpriced.length" class="unpriced-card">
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center">
              <span style="color: #E6A23C; font-weight: 600">
                ⚠️ 还有 {{ unpriced.length }} 个有库存的国家未定价
              </span>
              <el-button size="small" type="warning" link @click="quickPriceUnpriced">一键配置</el-button>
            </div>
          </template>
          <div class="bound-countries">
            <el-tag
              v-for="c in unpriced.slice(0, 30)"
              :key="c.code"
              size="small"
              type="warning"
              style="margin-right: 4px; margin-bottom: 2px"
            >
              {{ c.name }} ({{ c.stock }})
            </el-tag>
            <span v-if="unpriced.length > 30" style="color: #909399; margin-left: 4px">
              ... 还有 {{ unpriced.length - 30 }} 个
            </span>
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- Own Pricing Dialog -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑定价规则' : '新建定价规则'" width="550px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="IP组" prop="ip_group_id">
          <el-select
            v-model="form.ip_group_id"
            placeholder="选择IP组"
            style="width: 100%"
            @change="onDialogIpGroupChange"
          >
            <el-option
              v-for="g in ipGroupOptions"
              :key="g.id"
              :label="g.name"
              :value="g.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="国家">
          <el-input v-model="form.country" disabled placeholder="选择IP组后自动填充" />
        </el-form-item>
        <el-form-item label="月价格" prop="monthly_price">
          <el-input-number v-model="form.monthly_price" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="成本价">
          <el-input-number v-model="form.cost_price" :min="0" :precision="2" style="width: 100%" placeholder="可选" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="form.status" style="width: 100%">
            <el-option label="启用" value="active" />
            <el-option label="停用" value="inactive" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- Spark Pricing Dialog -->
    <el-dialog
      v-model="sparkDialogVisible"
      :title="sparkIsEdit ? '编辑 Spark 定价' : '新建 Spark 定价'"
      width="920px"
      :close-on-click-modal="false"
      top="5vh"
    >
      <el-form ref="sparkFormRef" :model="sparkForm" :rules="sparkRules_" label-width="110px">
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="规则名称" prop="name">
              <el-input v-model="sparkForm.name" placeholder="如：北美高端住宅 / 东南亚基础套餐" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="启用">
              <el-switch v-model="sparkForm.is_active" :active-value="1" :inactive-value="0" />
              <span style="margin-left: 8px; color: #909399; font-size: 12px">
                停用后国家可被其他规则绑定
              </span>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="月单价" prop="monthly_price">
              <el-input-number v-model="sparkForm.monthly_price" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="参考成本">
              <el-input-number v-model="sparkForm.cost_price" :min="0" :precision="2" style="width: 100%" placeholder="选填" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="销售价格">
              <el-input-number v-model="sparkForm.sales_price" :min="0" :precision="2" style="width: 100%" placeholder="销售看到的成本" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="描述">
          <el-input v-model="sparkForm.description" type="textarea" :rows="2" placeholder="选填，用于内部备注" />
        </el-form-item>

        <el-divider content-position="left">覆盖的国家</el-divider>

        <!-- 一键预设 + 工具栏 -->
        <div class="picker-header">
          <div class="presets">
            <span class="presets-label">快捷选择：</span>
            <el-button
              v-for="(preset, key) in presets"
              :key="key"
              size="small"
              @click="applyPreset(key)"
            >
              {{ preset.label }}
            </el-button>
          </div>
          <div class="picker-stats">
            已选 <strong>{{ sparkForm.country_codes.length }}</strong> 个国家，
            库存 <strong style="color: #67C23A">{{ selectedStockTotal }}</strong> 条
          </div>
        </div>

        <div class="picker-toolbar">
          <el-input
            v-model="sparkCountryFilter"
            placeholder="搜索国家（中文/代码）"
            clearable
            size="default"
            :prefix-icon="Search"
            style="width: 240px"
          />
          <el-select v-model="stockOnlyFilter" size="default" style="width: 140px">
            <el-option label="全部国家" value="all" />
            <el-option label="有库存" value="has_stock" />
            <el-option label="已绑定" value="bound" />
            <el-option label="未绑定" value="unbound" />
          </el-select>
          <el-button size="default" link type="primary" @click="selectAllFiltered">选中显示的</el-button>
          <el-button size="default" link @click="sparkForm.country_codes = []">清空</el-button>
        </div>

        <!-- 国家列表按大洲分组 -->
        <el-form-item prop="country_codes" style="margin-bottom: 0">
          <div class="country-picker">
            <el-tabs v-model="activeContinent" type="card" class="continent-tabs">
              <el-tab-pane
                v-for="(group, contId) in continentGroups"
                :key="contId"
                :name="String(contId)"
              >
                <template #label>
                  <span>{{ group.label }}</span>
                  <el-badge
                    v-if="group.selectedCount > 0"
                    :value="group.selectedCount"
                    :max="99"
                    class="tab-badge"
                  />
                </template>
                <div class="country-grid">
                  <div
                    v-for="c in group.countries"
                    :key="c.code"
                    class="country-card"
                    :class="{
                      selected: sparkForm.country_codes.includes(c.code),
                      disabled: isCountryDisabled(c),
                      'has-stock': c.stock > 0
                    }"
                    @click="toggleCountry(c)"
                  >
                    <div class="card-top">
                      <span class="country-code">{{ c.code }}</span>
                      <el-icon v-if="sparkForm.country_codes.includes(c.code)" class="check-icon">
                        <CircleCheckFilled />
                      </el-icon>
                    </div>
                    <div class="country-name">{{ c.name }}</div>
                    <div class="card-bottom">
                      <span :class="['stock', c.stock > 0 ? 'has' : 'none']">
                        库存 {{ c.stock }}
                      </span>
                      <span v-if="c.min_cost" class="cost">
                        ¥{{ Number(c.min_cost).toFixed(0) }}+
                      </span>
                    </div>
                    <div v-if="isCountryDisabled(c)" class="disabled-overlay">
                      已被规则 #{{ c.bound_rule_id }} 占用
                    </div>
                  </div>
                </div>
              </el-tab-pane>
            </el-tabs>
          </div>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="sparkDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="sparkSubmitting" @click="handleSubmitSpark">
          确定（{{ sparkForm.country_codes.length }}个国家）
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, CircleCheckFilled } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import {
  getPricingRules, createPricingRule, updatePricingRule, deletePricingRule,
  getSparkPricingRules, createSparkPricingRule, updateSparkPricingRule, deleteSparkPricingRule,
  getSparkPricingCountries,
} from '@/api/pricingRules'
import { getAllIpGroups } from '@/api/ipGroups'

const activeTab = ref('own')

// ============ OWN PRICING ============
const loading = ref(false)
const tableData = ref([])
const pagination = reactive({ page: 1, page_size: 20, total: 0 })
const ipGroupOptions = ref([])
const filterIpGroupId = ref(null)

async function loadIpGroups() {
  try {
    const res = await getAllIpGroups()
    ipGroupOptions.value = Array.isArray(res) ? res : (res.items || res.data || [])
  } catch { /* handled */ }
}

const dialogVisible = ref(false)
const isEdit = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formRef = ref(null)
const form = reactive({
  ip_group_id: null,
  country: '',
  monthly_price: 0,
  cost_price: null,
  status: 'active',
})
const rules = {
  ip_group_id: [{ required: true, message: '请选择IP组', trigger: 'change' }],
  monthly_price: [{ required: true, message: '请输入月价格', trigger: 'blur' }],
}

function onDialogIpGroupChange(val) {
  const group = ipGroupOptions.value.find((g) => g.id === val)
  if (group) form.country = group.country || ''
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, page_size: pagination.page_size }
    if (filterIpGroupId.value) params.ip_group_id = filterIpGroupId.value
    const res = await getPricingRules(params)
    tableData.value = res.items || res.data || []
    pagination.total = res.pagination?.total ?? res.total ?? 0
  } catch { /* handled */ }
  finally { loading.value = false }
}

function handleFilter() { pagination.page = 1; fetchData() }

function openDialog(row) {
  if (row) {
    isEdit.value = true
    editingId.value = row.id
    form.ip_group_id = row.ip_group_id || null
    form.country = row.ip_group?.country || row.country || ''
    form.monthly_price = Number(row.monthly_price || row.price || 0)
    form.cost_price = row.cost_price != null ? Number(row.cost_price) : null
    form.status = row.status || 'active'
  } else {
    isEdit.value = false
    editingId.value = null
    form.ip_group_id = null
    form.country = ''
    form.monthly_price = 0
    form.cost_price = null
    form.status = 'active'
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    const payload = {
      ip_group_id: form.ip_group_id,
      monthly_price: form.monthly_price,
      cost_price: form.cost_price,
      status: form.status,
    }
    if (isEdit.value) {
      await updatePricingRule(editingId.value, payload)
      ElMessage.success('更新成功')
    } else {
      await createPricingRule(payload)
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { submitting.value = false }
}

async function handleDelete(row) {
  const label = row.ip_group?.name || `ID ${row.id}`
  try {
    await ElMessageBox.confirm(`确定要删除「${label}」的定价规则吗？`, '删除确认', { type: 'warning' })
    await deletePricingRule(row.id)
    ElMessage.success('删除成功')
    fetchData()
  } catch { /* cancelled */ }
}

// ============ SPARK PRICING (by country) ============
const sparkLoading = ref(false)
const sparkRules = ref([])
const countries = ref([])            // 全部国家（含库存 + 已绑定状态）
const continents = ref({})           // continent_id => {code, name}
const presets = ref({})              // key => {label, codes}
const lastRefreshedAt = ref(null)
const activeContinent = ref('6')     // 默认显示北美
const sparkCountryFilter = ref('')
const stockOnlyFilter = ref('has_stock')

const sparkDialogVisible = ref(false)
const sparkIsEdit = ref(false)
const sparkEditingId = ref(null)
const sparkSubmitting = ref(false)
const sparkFormRef = ref(null)

const sparkForm = reactive({
  name: '',
  monthly_price: 0,
  cost_price: null,
  sales_price: null,
  description: '',
  is_active: 1,
  country_codes: [],
})

const sparkRules_ = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  monthly_price: [{ required: true, message: '请输入月单价', trigger: 'blur' }],
  country_codes: [{ required: true, type: 'array', min: 1, message: '请至少选择一个国家', trigger: 'change' }],
}

// 统计信息
const totalCountries = computed(() => countries.value.length)
const totalStock = computed(() => countries.value.reduce((s, c) => s + (c.stock || 0), 0))
const priceBoundCount = computed(() => {
  const set = new Set()
  sparkRules.value.forEach(r => {
    if (r.is_active) (r.country_codes || []).forEach(c => set.add(c))
  })
  return set.size
})

// 未定价但有库存的国家
const unpriced = computed(() => {
  return countries.value
    .filter(c => c.stock > 0 && !c.bound_rule_id)
    .sort((a, b) => b.stock - a.stock)
})

// 过滤后的国家（按 tab + 搜索 + 库存过滤）
const filteredCountries = computed(() => {
  const kw = sparkCountryFilter.value.trim().toLowerCase()
  return countries.value.filter(c => {
    if (kw) {
      const match = c.name.toLowerCase().includes(kw) || c.code.toLowerCase().includes(kw)
      if (!match) return false
    }
    if (stockOnlyFilter.value === 'has_stock' && c.stock <= 0) return false
    if (stockOnlyFilter.value === 'bound' && !c.bound_rule_id) return false
    if (stockOnlyFilter.value === 'unbound' && c.bound_rule_id) return false
    return true
  })
})

// 按大洲分组
const continentGroups = computed(() => {
  const groups = {}
  Object.entries(continents.value).forEach(([id, meta]) => {
    groups[id] = {
      label: meta.name,
      countries: [],
      selectedCount: 0,
    }
  })
  // "其他" bucket
  if (!groups['other']) {
    groups['other'] = { label: '其他', countries: [], selectedCount: 0 }
  }

  filteredCountries.value.forEach(c => {
    const key = c.continent_id && groups[c.continent_id] ? c.continent_id : 'other'
    groups[key].countries.push(c)
    if (sparkForm.country_codes.includes(c.code)) {
      groups[key].selectedCount++
    }
  })

  // 各洲内按库存降序
  Object.values(groups).forEach(g => {
    g.countries.sort((a, b) => b.stock - a.stock || a.name.localeCompare(b.name))
  })

  // 只保留有内容的洲
  const result = {}
  Object.entries(groups).forEach(([id, g]) => {
    if (g.countries.length > 0) result[id] = g
  })
  return result
})

// 已选国家的库存合计（用于 dialog 统计）
const selectedStockTotal = computed(() => {
  return countries.value
    .filter(c => sparkForm.country_codes.includes(c.code))
    .reduce((s, c) => s + (c.stock || 0), 0)
})

function formatRefreshTime(t) {
  if (!t) return '-'
  return dayjs(t).format('MM-DD HH:mm:ss')
}

function isCountryDisabled(country) {
  if (!country.bound_rule_id) return false
  // 编辑模式下，当前规则已绑定的不算被占用
  if (sparkIsEdit.value && country.bound_rule_id === sparkEditingId.value) return false
  return true
}

function toggleCountry(country) {
  if (isCountryDisabled(country)) {
    ElMessage.warning(`${country.name} 已被规则 #${country.bound_rule_id} 占用`)
    return
  }
  const idx = sparkForm.country_codes.indexOf(country.code)
  if (idx >= 0) {
    sparkForm.country_codes.splice(idx, 1)
  } else {
    sparkForm.country_codes.push(country.code)
  }
}

function selectAllFiltered() {
  const available = filteredCountries.value
    .filter(c => !isCountryDisabled(c))
    .map(c => c.code)
  const set = new Set([...sparkForm.country_codes, ...available])
  sparkForm.country_codes = Array.from(set)
  ElMessage.success(`已选 ${available.length} 个国家`)
}

function applyPreset(key) {
  const preset = presets.value[key]
  if (!preset) return
  // 只加入当前库存中存在的国家
  const valid = preset.codes.filter(code => {
    const c = countries.value.find(x => x.code === code)
    return c && !isCountryDisabled(c)
  })
  const set = new Set([...sparkForm.country_codes, ...valid])
  sparkForm.country_codes = Array.from(set)
  ElMessage.success(`已加入 ${valid.length} 个国家（${preset.label}）`)
}

async function loadSparkData() {
  sparkLoading.value = true
  try {
    const [rulesRes, countriesRes] = await Promise.all([
      getSparkPricingRules(),
      getSparkPricingCountries(),
    ])
    sparkRules.value = Array.isArray(rulesRes) ? rulesRes : []
    countries.value = countriesRes?.countries || []
    continents.value = countriesRes?.continents || {}
    presets.value = countriesRes?.presets || {}
    lastRefreshedAt.value = countriesRes?.last_refreshed_at || null
  } catch { /* handled */ }
  finally { sparkLoading.value = false }
}

function openSparkDialog(row) {
  if (row) {
    sparkIsEdit.value = true
    sparkEditingId.value = row.id
    sparkForm.name = row.name
    sparkForm.monthly_price = Number(row.monthly_price)
    sparkForm.cost_price = row.cost_price != null ? Number(row.cost_price) : null
    sparkForm.sales_price = row.sales_price != null ? Number(row.sales_price) : null
    sparkForm.description = row.description || ''
    sparkForm.is_active = row.is_active
    sparkForm.country_codes = [...(row.country_codes || [])]
  } else {
    sparkIsEdit.value = false
    sparkEditingId.value = null
    sparkForm.name = ''
    sparkForm.monthly_price = 0
    sparkForm.cost_price = null
    sparkForm.sales_price = null
    sparkForm.description = ''
    sparkForm.is_active = 1
    sparkForm.country_codes = []
  }
  sparkCountryFilter.value = ''
  stockOnlyFilter.value = 'has_stock'
  activeContinent.value = Object.keys(continents.value)[0] || '6'
  sparkDialogVisible.value = true
}

function quickPriceUnpriced() {
  // 打开一个新建对话框，预填主流 / 北美预设
  openSparkDialog()
  // 默认勾选所有"未定价且有库存"的国家
  sparkForm.country_codes = unpriced.value.map(c => c.code)
  sparkForm.name = '未定价国家批量定价'
}

async function handleSubmitSpark() {
  const valid = await sparkFormRef.value.validate().catch(() => false)
  if (!valid) return
  sparkSubmitting.value = true
  try {
    const payload = { ...sparkForm }
    if (sparkIsEdit.value) {
      await updateSparkPricingRule(sparkEditingId.value, payload)
      ElMessage.success('更新成功')
    } else {
      await createSparkPricingRule(payload)
      ElMessage.success('创建成功')
    }
    sparkDialogVisible.value = false
    loadSparkData()
  } catch { /* handled */ }
  finally { sparkSubmitting.value = false }
}

async function handleDeleteSpark(row) {
  try {
    await ElMessageBox.confirm(`删除 Spark 定价规则「${row.name}」？`, '删除确认', { type: 'warning' })
    await deleteSparkPricingRule(row.id)
    ElMessage.success('已删除')
    loadSparkData()
  } catch { /* cancelled */ }
}

onMounted(() => {
  loadIpGroups()
  fetchData()
  loadSparkData()
})
</script>

<style lang="scss" scoped>
.pricing-rule-list {
  .page-title {
    margin: 0 0 16px 0;
    font-size: 20px;
    color: #2C3E50;
    font-weight: 600;
  }

  .pricing-tabs {
    :deep(.el-tabs__item) {
      font-size: 14px;
      font-weight: 500;
    }
  }

  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    .stats {
      font-size: 13px;
      color: #4A5568;
      strong { color: #E8913A; }
    }
  }

  .bound-groups { line-height: 1.8; }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }
}

.bound-countries { line-height: 1.8; }

.unpriced-card {
  margin-top: 12px;
  border: 1px solid #FEE7B8;
  :deep(.el-card__header) {
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
    padding: 12px 16px;
  }
}

.picker-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 10px;
  padding: 10px 14px;
  background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
  border: 1px solid #F5D9B5;
  border-radius: 8px;
  .presets {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    .presets-label {
      font-size: 13px;
      color: #4A5568;
      margin-right: 2px;
    }
  }
  .picker-stats {
    font-size: 13px;
    color: #4A5568;
    strong { color: #E8913A; font-size: 15px; }
  }
}

.picker-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.country-picker {
  width: 100%;
  border: 1px solid #EADFD2;
  border-radius: 8px;
  background: #FAFAFA;
  padding: 8px;
}

.continent-tabs {
  :deep(.el-tabs__item) {
    font-size: 13px;
    padding: 0 16px;
  }
  .tab-badge {
    margin-left: 6px;
    :deep(.el-badge__content) {
      background: #E8913A !important;
    }
  }
}

.country-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 8px;
  max-height: 340px;
  overflow-y: auto;
  padding: 4px;
}

.country-card {
  position: relative;
  padding: 10px 12px;
  border: 2px solid #E4E7ED;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
  transition: all 0.15s;

  &:hover:not(.disabled) {
    border-color: #E8913A;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(232, 145, 58, 0.12);
  }

  &.selected {
    border-color: #E8913A;
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
    box-shadow: 0 0 0 1px rgba(232, 145, 58, 0.25);
  }

  &.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #F5F7FA;
  }

  &.has-stock:not(.disabled) {
    border-left: 3px solid #67C23A;
  }

  .card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    .country-code {
      font-family: 'SF Mono', Consolas, Monaco, monospace;
      font-size: 11px;
      font-weight: 600;
      color: #909399;
    }
    .check-icon {
      color: #E8913A;
      font-size: 16px;
    }
  }

  .country-name {
    font-size: 14px;
    font-weight: 600;
    color: #2C3E50;
    margin-bottom: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .card-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    .stock {
      &.has { color: #67C23A; font-weight: 600; }
      &.none { color: #C0C4CC; }
    }
    .cost { color: #909399; }
  }

  .disabled-overlay {
    position: absolute;
    bottom: 2px;
    left: 10px;
    right: 10px;
    font-size: 10px;
    color: #E6A23C;
    text-align: center;
  }
}
</style>
