<template>
  <div class="metric-card" :class="`theme-${theme}`">
    <div class="card-top">
      <div class="card-icon-wrap">
        <el-icon :size="20"><component :is="icon" /></el-icon>
      </div>
      <div class="card-info">
        <div class="card-label">{{ label }}</div>
        <div class="card-value">{{ typeof value === 'number' ? value.toLocaleString() : value }}</div>
      </div>
    </div>

    <!-- 折叠式用户列表（带分页） -->
    <div v-if="hasUsers" class="expand-section">
      <div class="expand-trigger" @click="toggleExpand">
        <span>{{ expanded ? '收起用户列表' : `查看用户 (${totalCustomers.toLocaleString()})` }}</span>
        <el-icon :size="12"><ArrowDown v-if="!expanded" /><ArrowUp v-else /></el-icon>
      </div>
      <transition name="slide">
        <div v-if="expanded" class="customer-list">
          <div
            v-for="c in pagedCustomers"
            :key="c.id"
            class="customer-row clickable"
            @click="c.id && $emit('detail', c.id)"
          >
            <div class="customer-main">
              <span class="customer-name">{{ c.name }}</span>
              <el-tag v-if="c.discount && c.discount < 100" size="small" :type="discountTagType(c.discount)">{{ c.discount }}折</el-tag>
            </div>
            <div class="customer-meta">
              <span v-if="c.total_spent" class="customer-spent">{{ formatSpent(c.total_spent) }}</span>
              <span class="customer-phone">{{ c.phone || '' }}</span>
            </div>
          </div>
          <div v-if="customerPageCount > 1" class="pager">
            <button class="pager-btn" :disabled="customerPage <= 1" @click="customerPage--">&lsaquo;</button>
            <span class="pager-info">{{ customerPage }} / {{ customerPageCount }}</span>
            <button class="pager-btn" :disabled="customerPage >= customerPageCount" @click="customerPage++">&rsaquo;</button>
          </div>
          <div v-if="totalCustomers > customers.length" class="list-hint">
            已加载 {{ customers.length }} 条，共 {{ totalCustomers.toLocaleString() }} 条
          </div>
        </div>
      </transition>
    </div>

    <!-- 折叠式 IP 列表（带分页） -->
    <div v-else-if="hasIps" class="expand-section">
      <div class="expand-trigger" @click="toggleExpand">
        <span>{{ expanded ? '收起' : `查看 IP (${totalCustomers.toLocaleString()})` }}</span>
        <el-icon :size="12"><ArrowDown v-if="!expanded" /><ArrowUp v-else /></el-icon>
      </div>
      <transition name="slide">
        <div v-if="expanded" class="customer-list">
          <div v-for="item in pagedIps" :key="item.ip" class="customer-row ip-row">
            <span class="customer-name mono">{{ item.ip }}</span>
            <span class="customer-phone">{{ formatTime(item.last_visit) }}</span>
          </div>
          <div v-if="ipPageCount > 1" class="pager">
            <button class="pager-btn" :disabled="ipPage <= 1" @click="ipPage--">&lsaquo;</button>
            <span class="pager-info">{{ ipPage }} / {{ ipPageCount }}</span>
            <button class="pager-btn" :disabled="ipPage >= ipPageCount" @click="ipPage++">&rsaquo;</button>
          </div>
        </div>
      </transition>
    </div>

    <!-- 国家柱状图（直接展示，不折叠） -->
    <div v-else-if="regions && regions.length" class="region-list">
      <div v-for="(r, i) in regions" :key="r.country_name" class="region-row">
        <span class="region-rank" :class="{ top: i < 3 }">{{ i + 1 }}</span>
        <span class="region-name">{{ r.country_name || '未知' }}</span>
        <div class="region-bar-wrap">
          <div class="region-bar" :style="{ width: barWidth(r.count) }"></div>
        </div>
        <span class="region-count">{{ r.count.toLocaleString() }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { ArrowDown, ArrowUp } from '@element-plus/icons-vue'
import dayjs from 'dayjs'

const PAGE_SIZE = 20

const props = defineProps({
  label: String,
  value: [Number, String],
  icon: { type: String, default: 'DataLine' },
  theme: { type: String, default: 'blue' },
  customers: Array,
  ipList: Array,
  regions: Array,
  totalCustomers: Number,
})

defineEmits(['detail'])

const expanded = ref(false)
const customerPage = ref(1)
const ipPage = ref(1)

const hasUsers = computed(() => props.customers?.length > 0)
const hasIps = computed(() => props.ipList?.length > 0)

const customerPageCount = computed(() => Math.ceil((props.customers?.length || 0) / PAGE_SIZE))
const pagedCustomers = computed(() => {
  const start = (customerPage.value - 1) * PAGE_SIZE
  return (props.customers || []).slice(start, start + PAGE_SIZE)
})

const ipPageCount = computed(() => Math.ceil((props.ipList?.length || 0) / PAGE_SIZE))
const pagedIps = computed(() => {
  const start = (ipPage.value - 1) * PAGE_SIZE
  return (props.ipList || []).slice(start, start + PAGE_SIZE)
})

function toggleExpand() {
  expanded.value = !expanded.value
  if (expanded.value) {
    customerPage.value = 1
    ipPage.value = 1
  }
}

watch(() => props.customers, () => { customerPage.value = 1 })
watch(() => props.ipList, () => { ipPage.value = 1 })

const maxRegion = computed(() => {
  if (!props.regions?.length) return 1
  return Math.max(...props.regions.map(r => r.count), 1)
})

function barWidth(count) {
  return Math.round((count / maxRegion.value) * 100) + '%'
}

function discountTagType(d) {
  if (d <= 40) return 'danger'
  if (d <= 50) return 'warning'
  if (d <= 60) return ''
  return 'info'
}

function formatSpent(n) {
  if (n >= 10000) return '¥' + (n / 10000).toFixed(1) + '万'
  return '¥' + Number(n).toLocaleString()
}

function formatTime(t) {
  return t ? dayjs(t).format('MM-DD HH:mm') : ''
}
</script>

<style lang="scss" scoped>
.metric-card {
  border-radius: 12px;
  padding: 20px;
  transition: all 0.25s ease;
  border: 1px solid transparent;

  &:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
  }

  &.theme-blue {
    background: linear-gradient(135deg, #F0F7FF, #E4EEFB);
    border-color: #BDD4F0;
    .card-icon-wrap { background: #4299E1; }
    .card-value { color: #2B6CB0; }
    .region-bar { background: #4299E1; }
  }
  &.theme-green {
    background: linear-gradient(135deg, #F0FFF4, #E2F5E9);
    border-color: #B8E6C8;
    .card-icon-wrap { background: #48BB78; }
    .card-value { color: #276749; }
    .region-bar { background: #48BB78; }
  }
  &.theme-amber {
    background: linear-gradient(135deg, #FFF8F0, #FBEEDE);
    border-color: #F5D9B5;
    .card-icon-wrap { background: #DD8B35; }
    .card-value { color: #B5722A; }
    .region-bar { background: #DD8B35; }
  }
  &.theme-rose {
    background: linear-gradient(135deg, #FFF5F5, #FDE2E2);
    border-color: #FECACA;
    .card-icon-wrap { background: #E55353; }
    .card-value { color: #C53030; }
    .region-bar { background: #E55353; }
  }
  &.theme-purple {
    background: linear-gradient(135deg, #FAF5FF, #EDE9FE);
    border-color: #D8B4FE;
    .card-icon-wrap { background: #8B5CF6; }
    .card-value { color: #6D28D9; }
    .region-bar { background: #8B5CF6; }
  }
  &.theme-teal {
    background: linear-gradient(135deg, #F0FDFA, #D5F5F0);
    border-color: #99F6E4;
    .card-icon-wrap { background: #0D9488; }
    .card-value { color: #0F766E; }
    .region-bar { background: #0D9488; }
  }
  &.theme-slate {
    background: linear-gradient(135deg, #F8FAFC, #EEF2F6);
    border-color: #E2E8F0;
    .card-icon-wrap { background: #64748B; }
    .card-value { color: #475569; }
    .region-bar { background: #64748B; }
  }
}

.card-top {
  display: flex; align-items: flex-start; gap: 14px;
}

.card-icon-wrap {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; flex-shrink: 0;
}

.card-info { flex: 1; min-width: 0; }
.card-label { font-size: 13px; color: #718096; font-weight: 500; margin-bottom: 6px; }
.card-value {
  font-size: 30px; font-weight: 700; line-height: 1.1;
  font-family: 'DIN Alternate', 'SF Mono', Consolas, monospace;
}

// Expandable section
.expand-section { margin-top: 12px; }

.expand-trigger {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: 6px 0; font-size: 12px; color: #909399; cursor: pointer;
  border-top: 1px dashed rgba(0, 0, 0, 0.08);
  transition: color .15s;
  &:hover { color: #4299E1; }
}

.slide-enter-active, .slide-leave-active { transition: all .2s ease; overflow: hidden; }
.slide-enter-from, .slide-leave-to { max-height: 0; opacity: 0; }
.slide-enter-to, .slide-leave-from { max-height: 800px; opacity: 1; }

.customer-list { padding-top: 6px; }

.customer-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 5px 6px; margin: 0 -6px; border-radius: 4px;
  transition: background .12s;
  &.clickable { cursor: pointer; }
  &.clickable:hover { background: rgba(0, 0, 0, 0.04); }
  &.ip-row { cursor: default; }
}

.customer-main { display: flex; align-items: center; gap: 6px; min-width: 0; }
.customer-name {
  font-size: 12px; color: #2D3748; font-weight: 500;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  &.mono { font-family: 'SF Mono', Consolas, monospace; color: #4A5568; }
}

.customer-meta { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.customer-spent { font-size: 11px; font-weight: 600; color: #DD8B35; font-family: 'SF Mono', Consolas, monospace; }
.customer-phone { font-size: 11px; color: #A0AEC0; }
.list-hint { text-align: center; font-size: 11px; color: #CBD5E0; padding: 6px 0; }

// Pager
.pager {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  padding: 6px 0; margin-top: 4px; border-top: 1px dashed rgba(0, 0, 0, 0.06);
}
.pager-btn {
  width: 24px; height: 24px; border-radius: 4px;
  border: 1px solid #E2E8F0; background: #fff;
  font-size: 14px; line-height: 1; color: #4A5568; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all .15s;
  &:hover:not(:disabled) { border-color: #4299E1; color: #4299E1; }
  &:disabled { opacity: 0.35; cursor: not-allowed; }
}
.pager-info { font-size: 11px; color: #A0AEC0; font-variant-numeric: tabular-nums; }

// Region bars
.region-list { margin-top: 14px; border-top: 1px dashed rgba(0, 0, 0, 0.08); padding-top: 10px; }
.region-row { display: flex; align-items: center; gap: 6px; padding: 3px 0; font-size: 12px; }
.region-rank {
  flex: 0 0 18px; text-align: center; font-size: 11px; font-weight: 700; color: #A0AEC0;
  &.top { color: #DD8B35; }
}
.region-name { flex: 0 0 68px; color: #4A5568; font-weight: 500; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.region-bar-wrap { flex: 1; height: 18px; background: rgba(0, 0, 0, 0.04); border-radius: 3px; overflow: hidden; }
.region-bar { height: 100%; border-radius: 3px; transition: width .4s ease; min-width: 2px; }
.region-count { flex: 0 0 44px; text-align: right; color: #2D3748; font-weight: 700; font-family: 'SF Mono', Consolas, monospace; }
</style>
