<template>
  <div class="product-blocks-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">产品 CIDR 屏蔽</h2>
        <p class="page-desc">按 CIDR 粒度屏蔽 Spark 产品的 IP 段，屏蔽后客户无法购买该段 IP，现有订阅续费不受影响</p>
      </div>
      <div class="header-actions">
        <el-button @click="loadBlocks"><el-icon><Refresh /></el-icon> 刷新</el-button>
        <el-button type="primary" @click="openAddDialog"><el-icon><Plus /></el-icon> 添加屏蔽</el-button>
      </div>
    </div>

    <!-- 已屏蔽列表 -->
    <el-card shadow="never" v-loading="blocksLoading">
      <template #header>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>已屏蔽规则 ({{ blocks.length }})</strong>
          <el-button v-if="selectedBlocks.length" type="danger" size="small" @click="bulkRemove">
            批量解除 {{ selectedBlocks.length }} 条
          </el-button>
        </div>
      </template>
      <el-table :data="blocks" size="small" stripe empty-text="暂无屏蔽规则" @selection-change="v => selectedBlocks = v">
        <el-table-column type="selection" width="40" />
        <el-table-column label="CIDR" min-width="160">
          <template #default="{ row }">
            <span class="mono">{{ row.cidr }}</span>
          </template>
        </el-table-column>
        <el-table-column label="产品" min-width="160">
          <template #default="{ row }">
            <div>{{ row.product_name }}</div>
            <div class="sub-text">{{ row.product_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="国家" width="70" align="center" prop="country_code" />
        <el-table-column label="原因" min-width="100">
          <template #default="{ row }">{{ row.reason || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="140">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="70" align="center">
          <template #default="{ row }">
            <el-popconfirm title="解除该 CIDR 屏蔽？" @confirm="removeOne(row.id)">
              <template #reference>
                <el-button link type="danger" size="small">解除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 添加屏蔽弹窗 -->
    <el-dialog v-model="addVisible" title="添加 CIDR 屏蔽" width="960px" :close-on-click-modal="false" top="3vh" destroy-on-close>
      <!-- 过滤区 -->
      <div class="filter-bar">
        <el-input v-model="filter.keyword" placeholder="搜索产品名/CIDR" clearable style="width:220px">
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
        <el-select v-model="filter.country" placeholder="国家" clearable style="width:110px">
          <el-option v-for="c in countryOptions" :key="c" :label="c" :value="c" />
        </el-select>
        <el-checkbox v-model="filter.hideBlocked" label="隐藏已屏蔽" />
        <div style="flex:1" />
        <el-button size="small" :loading="productsLoading" @click="loadProducts(true)">
          <el-icon><Refresh /></el-icon> 实时拉取 API
        </el-button>
      </div>

      <div class="ip-match-bar">
        <span class="match-label">IP 段匹配：</span>
        <el-input v-model="filter.oct1" placeholder="*" style="width:56px" />
        <span class="dot">.</span>
        <el-input v-model="filter.oct2" placeholder="*" style="width:56px" />
        <span class="dot">.</span>
        <el-input v-model="filter.oct3" placeholder="*" style="width:56px" />
        <span class="dot">.</span>
        <el-input v-model="filter.oct4" placeholder="*" style="width:56px" />
        <span class="match-hint">填入任意位来筛选匹配的 CIDR 段</span>
      </div>

      <!-- 产品列表 -->
      <div class="product-list" v-loading="productsLoading">
        <div v-for="product in filteredProducts" :key="product.product_id" class="product-group">
          <div class="product-head">
            <div class="product-info">
              <el-tag size="small" type="info">{{ product.country_code }}</el-tag>
              <span class="product-name">{{ product.product_name }}</span>
              <span class="product-inv">库存 {{ product.inventory }}</span>
              <span v-if="product.blocked_cidr_count" class="product-blocked">
                已屏蔽 {{ product.blocked_cidr_count }} 段
              </span>
            </div>
            <el-button v-if="product._visibleCidrs.length < product._matchedCidrs.length"
              link type="primary" size="small" @click="product._expanded = true">
              展开全部 ({{ product._matchedCidrs.length }})
            </el-button>
            <el-button v-else-if="product._expanded && product.cidr_blocks.length > 4"
              link size="small" @click="product._expanded = false">
              收起
            </el-button>
          </div>
          <div class="cidr-grid">
            <label
              v-for="c in product._visibleCidrs" :key="c.cidr"
              class="cidr-item" :class="{ blocked: c.is_blocked, selected: isCidrSelected(product, c), match: isCidrHighlight(c.cidr) }"
            >
              <input
                v-if="!c.is_blocked"
                type="checkbox"
                :checked="isCidrSelected(product, c)"
                @change="toggleCidr(product, c)"
              />
              <el-tag v-if="c.is_blocked" type="danger" size="small" style="margin-right:4px">已屏蔽</el-tag>
              <span class="cidr-val">{{ c.cidr }}</span>
              <span class="cidr-count">×{{ c.count }}</span>
              <span v-if="c.isp" class="cidr-isp">{{ c.isp }}</span>
            </label>
          </div>
        </div>
        <el-empty v-if="!productsLoading && !filteredProducts.length" description="无匹配产品" :image-size="60" />
      </div>

      <div v-if="selectedCidrs.length" class="selection-bar">
        已选 <strong>{{ selectedCidrs.length }}</strong> 条 CIDR
        <el-button link type="primary" size="small" @click="selectedCidrs = []" style="margin-left:8px">清空</el-button>
      </div>

      <template #footer>
        <div class="dialog-footer">
          <el-input v-model="blockReason" placeholder="屏蔽原因（可选）" style="width:220px;margin-right:12px" />
          <el-button @click="addVisible = false">取消</el-button>
          <el-button type="danger" :disabled="!selectedCidrs.length" :loading="blocking" @click="confirmBlock">
            屏蔽 {{ selectedCidrs.length }} 条 CIDR
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Refresh, Search } from '@element-plus/icons-vue'
import {
  getProductBlocks,
  getAllProductsForBlock,
  addProductBlocks,
  removeProductBlock,
  bulkRemoveProductBlocks,
} from '@/api/spark'

const blocks = ref([])
const blocksLoading = ref(false)
const selectedBlocks = ref([])

const allProducts = ref([])
const productsLoading = ref(false)
const addVisible = ref(false)
const selectedCidrs = ref([])
const blocking = ref(false)
const blockReason = ref('')

const filter = reactive({ keyword: '', country: '', hideBlocked: true, oct1: '', oct2: '', oct3: '', oct4: '' })

const countryOptions = computed(() => {
  const s = new Set(allProducts.value.map(p => p.country_code).filter(Boolean))
  return [...s].sort()
})

const hasOctetFilter = computed(() => !!(filter.oct1 || filter.oct2 || filter.oct3 || filter.oct4))

function matchOctet(cidr) {
  if (!cidr) return false
  const ip = cidr.split('/')[0].split('.')
  if (filter.oct1 && !ip[0]?.startsWith(filter.oct1)) return false
  if (filter.oct2 && !ip[1]?.startsWith(filter.oct2)) return false
  if (filter.oct3 && !ip[2]?.startsWith(filter.oct3)) return false
  if (filter.oct4 && !ip[3]?.startsWith(filter.oct4)) return false
  return true
}

function isCidrHighlight(cidr) {
  return hasOctetFilter.value && matchOctet(cidr)
}

const COLLAPSE_LIMIT = 4

const filteredProducts = computed(() => {
  return allProducts.value
    .map(p => {
      let cidrs = p.cidr_blocks || []
      if (filter.hideBlocked) cidrs = cidrs.filter(c => !c.is_blocked)
      if (filter.keyword) {
        const kw = filter.keyword.toLowerCase()
        const nameMatch = (p.product_name || '').toLowerCase().includes(kw)
        if (!nameMatch) cidrs = cidrs.filter(c => (c.cidr || '').includes(kw))
        if (!nameMatch && !cidrs.length) return null
      }
      if (hasOctetFilter.value) {
        cidrs = cidrs.filter(c => matchOctet(c.cidr))
        if (!cidrs.length) return null
      }
      if (filter.country && p.country_code !== filter.country) return null
      if (!cidrs.length && !p.cidr_blocks?.length) return null

      const expanded = p._expanded || false
      return {
        ...p,
        _matchedCidrs: cidrs,
        _visibleCidrs: expanded ? cidrs : cidrs.slice(0, COLLAPSE_LIMIT),
        _expanded: expanded,
      }
    })
    .filter(Boolean)
})

function isCidrSelected(product, cidr) {
  return selectedCidrs.value.some(s => s.product_id === product.product_id && s.cidr === cidr.cidr)
}

function toggleCidr(product, cidr) {
  const idx = selectedCidrs.value.findIndex(s => s.product_id === product.product_id && s.cidr === cidr.cidr)
  if (idx >= 0) {
    selectedCidrs.value.splice(idx, 1)
  } else {
    selectedCidrs.value.push({
      product_id: product.product_id,
      product_name: product.product_name,
      country_code: product.country_code,
      cidr: cidr.cidr,
    })
  }
}

function fmtTime(t) {
  return t ? t.slice(0, 16).replace('T', ' ') : '-'
}

async function loadBlocks() {
  blocksLoading.value = true
  try { blocks.value = await getProductBlocks() || [] } catch {} finally { blocksLoading.value = false }
}

async function loadProducts(force = false) {
  productsLoading.value = true
  try {
    const res = await getAllProductsForBlock({ force: force ? 1 : 0 })
    allProducts.value = (res?.products || []).map(p => ({ ...p, _expanded: false }))
  } catch {} finally { productsLoading.value = false }
}

async function openAddDialog() {
  addVisible.value = true
  blockReason.value = ''
  selectedCidrs.value = []
  Object.assign(filter, { keyword: '', country: '', hideBlocked: true, oct1: '', oct2: '', oct3: '', oct4: '' })
  if (!allProducts.value.length) await loadProducts()
}

async function confirmBlock() {
  if (!selectedCidrs.value.length) return
  blocking.value = true
  try {
    await addProductBlocks({ items: selectedCidrs.value, reason: blockReason.value || null })
    ElMessage.success(`已屏蔽 ${selectedCidrs.value.length} 条 CIDR`)
    addVisible.value = false
    await Promise.all([loadBlocks(), loadProducts()])
  } catch {} finally { blocking.value = false }
}

async function removeOne(id) {
  try {
    await removeProductBlock(id)
    ElMessage.success('已解除屏蔽')
    await Promise.all([loadBlocks(), loadProducts()])
  } catch {}
}

async function bulkRemove() {
  if (!selectedBlocks.value.length) return
  try {
    await bulkRemoveProductBlocks(selectedBlocks.value.map(b => b.id))
    ElMessage.success(`已解除 ${selectedBlocks.value.length} 条屏蔽`)
    selectedBlocks.value = []
    await Promise.all([loadBlocks(), loadProducts()])
  } catch {}
}

onMounted(() => { loadBlocks() })
</script>

<style lang="scss" scoped>
.product-blocks-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;
    .page-title { margin: 0; font-size: 20px; font-weight: 700; color: #1E293B; }
    .page-desc { margin: 4px 0 0; font-size: 13px; color: #94A3B8; }
    .header-actions { display: flex; gap: 8px; }
  }
}

.mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; }
.sub-text { font-size: 11px; color: #94A3B8; font-family: monospace; }

.filter-bar {
  display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;
}

.ip-match-bar {
  display: flex; align-items: center; gap: 4px; margin-bottom: 14px; font-size: 13px;
  .match-label { color: #475569; font-weight: 500; white-space: nowrap; }
  .dot { color: #94A3B8; font-weight: 700; }
  .el-input { :deep(.el-input__inner) { text-align: center; font-family: monospace; } }
  .match-hint { color: #94A3B8; font-size: 12px; margin-left: 8px; white-space: nowrap; }
}

.product-list {
  max-height: 420px; overflow-y: auto; border: 1px solid #E2E8F0; border-radius: 8px; padding: 4px 0;
}

.product-group {
  padding: 8px 14px; border-bottom: 1px solid #F1F5F9;
  &:last-child { border-bottom: none; }
}

.product-head {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;
  .product-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .product-name { font-size: 13px; font-weight: 600; color: #1E293B; }
  .product-inv { font-size: 12px; color: #94A3B8; }
  .product-blocked { font-size: 12px; color: #E6A23C; }
}

.cidr-grid {
  display: flex; flex-wrap: wrap; gap: 6px;
}

.cidr-item {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 5px; border: 1px solid #E2E8F0;
  font-size: 12px; font-family: 'SF Mono', Consolas, monospace;
  cursor: pointer; user-select: none; transition: all .15s;

  input[type="checkbox"] { margin: 0; cursor: pointer; }

  &:hover:not(.blocked) { border-color: #93C5FD; background: #F0F7FF; }
  &.selected { border-color: #3B82F6; background: #EFF6FF; }
  &.blocked { opacity: .5; cursor: default; border-style: dashed; }
  &.match { background: #FFF8E1; border-color: #F5A623; }
  &.match.selected { background: #FFF3CD; }

  .cidr-val { color: #1E293B; }
  .cidr-count { color: #94A3B8; font-size: 11px; }
  .cidr-isp { color: #94A3B8; font-size: 11px; }
}

.selection-bar {
  margin-top: 10px; padding: 8px 12px; background: #FEF2F2; border-radius: 6px;
  font-size: 13px; color: #DC2626;
}

.dialog-footer { display: flex; align-items: center; justify-content: flex-end; }
</style>
