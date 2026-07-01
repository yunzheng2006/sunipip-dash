<template>
  <div class="forward-plans-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">中转套餐管理</h2>
        <p class="page-desc">定义 NY/3x-ui 中转服务的计费套餐，包括基础费用和流量定价。</p>
      </div>
      <el-button type="primary" @click="openDialog()"><el-icon><Plus /></el-icon> 添加套餐</el-button>
    </div>

    <!-- 客户自助购买开关 -->
    <el-card style="margin-bottom: 16px">
      <div style="display: flex; align-items: center; justify-content: space-between">
        <div>
          <div style="font-weight: 600; color: #2C3E50">客户自助购买直连</div>
          <div style="font-size: 12px; color: #909399; margin-top: 2px">开启后，已通过直连认证的客户可在商店自助选购直连套餐</div>
        </div>
        <el-switch v-model="forwardEnabled" :loading="savingToggle" @change="toggleForwardEnabled" />
      </div>
    </el-card>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="套餐名" min-width="150">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="类型" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.type === 'ny' ? '' : 'warning'" size="small">{{ row.type === 'ny' ? 'NY' : '3x-ui' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="面板 / 节点" min-width="180">
          <template #default="{ row }">
            <template v-if="row.type === 'ny'">
              <div v-if="row.panel || row.device_group">
                <span style="font-weight:500">{{ row.panel?.name || '-' }}</span>
                <span v-if="row.device_group" style="color:#909399"> → {{ row.device_group.name }}</span>
              </div>
              <span v-else style="color:#F56C6C;font-size:12px">未配置面板/设备组</span>
            </template>
            <template v-else>
              <span v-if="row.panel" style="font-weight:500">{{ row.panel?.name || '-' }}</span>
              <span v-else style="color:#F56C6C;font-size:12px">未配置面板</span>
            </template>
          </template>
        </el-table-column>
        <el-table-column label="限速" width="100" align="center">
          <template #default="{ row }">{{ row.speed_limit_mbps > 0 ? row.speed_limit_mbps + ' Mbps' : '不限' }}</template>
        </el-table-column>
        <el-table-column label="设备数" width="80" align="center">
          <template #default="{ row }">{{ row.device_limit > 0 ? row.device_limit : '不限' }}</template>
        </el-table-column>
        <el-table-column label="月基础费" width="100" align="right">
          <template #default="{ row }"><span style="font-weight:600;color:#E8913A">¥{{ row.base_price }}</span></template>
        </el-table-column>
        <el-table-column label="软成本" width="90" align="right">
          <template #default="{ row }">
            <span v-if="row.cost_price != null" style="color:#409EFF">¥{{ row.cost_price }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="硬成本" width="90" align="right">
          <template #default="{ row }">
            <span v-if="row.hard_cost_price != null" style="color:#E6A23C">¥{{ row.hard_cost_price }}</span>
            <span v-else style="color:#C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="含流量" width="90" align="center">
          <template #default="{ row }">{{ row.included_traffic_gb }} GB</template>
        </el-table-column>
        <el-table-column label="超额/GB" width="90" align="right">
          <template #default="{ row }">¥{{ row.overage_price_per_gb }}</template>
        </el-table-column>
        <el-table-column label="计价" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.pricing_mode === 'fixed' ? 'warning' : ''" size="small">{{ row.pricing_mode === 'fixed' ? '总价' : '叠加' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="展示域名" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.display_host" class="mono">{{ row.display_host }}</span>
            <span v-else style="color:#C0C4CC">默认</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="应用模块" width="120" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.module === 'video'" type="success" size="small">视频专线</el-tag>
            <el-tag v-else-if="row.module === 'live_mobile'" type="warning" size="small">直播-手机</el-tag>
            <el-tag v-else-if="row.module === 'live_pc'" type="danger" size="small">直播-电脑</el-tag>
            <span v-else style="color:#C0C4CC">通用</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑套餐' : '添加套餐'" width="560px" :close-on-click-modal="false">
      <el-form :model="form" ref="formRef" label-width="110px">
        <el-form-item label="套餐名" required><el-input v-model="form.name" placeholder="如：标准NY中转" /></el-form-item>
        <el-form-item label="中转类型" required>
          <el-radio-group v-model="form.type" @change="onTypeChange">
            <el-radio value="ny">NY 转发</el-radio>
            <el-radio value="xui">3x-ui 中转</el-radio>
          </el-radio-group>
        </el-form-item>

        <!-- NY: 选面板 + 设备组 -->
        <template v-if="form.type === 'ny'">
          <el-form-item label="NY 面板" required>
            <el-select v-model="form.panel_id" placeholder="选择 NY 面板" clearable style="width: 100%" @change="onNyPanelChange">
              <el-option v-for="p in nyPanels" :key="p.id" :value="p.id" :label="p.name" />
            </el-select>
          </el-form-item>
          <el-form-item label="设备组" required>
            <el-select v-model="form.device_group_id" placeholder="选择设备组" clearable style="width: 100%">
              <el-option v-for="g in filteredDeviceGroups" :key="g.id" :value="g.id"
                :label="`${g.name} (${g.original_connect_host || '-'})`" />
            </el-select>
            <div v-if="!filteredDeviceGroups.length && form.panel_id" style="font-size:12px;color:#F56C6C;margin-top:2px">
              该面板下没有启用的设备组，请先在 NY 面板设置中同步并启用
            </div>
          </el-form-item>
        </template>

        <!-- XUI: 选面板 -->
        <template v-if="form.type === 'xui'">
          <el-form-item label="3x-ui 面板" required>
            <el-select v-model="form.panel_id" placeholder="选择 3x-ui 面板" clearable style="width: 100%">
              <el-option v-for="p in xuiPanels" :key="p.id" :value="p.id" :label="p.name" />
            </el-select>
          </el-form-item>
        </template>

        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="限速">
              <el-input-number v-model="form.speed_limit_mbps" :min="0" :max="10000" style="width:100%" />
              <div style="font-size:12px;color:#909399;margin-top:2px">Mbps, 0=不限速</div>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="设备数限制">
              <el-input-number v-model="form.device_limit" :min="0" :max="100" style="width:100%" />
              <div style="font-size:12px;color:#909399;margin-top:2px">台, 0=不限制</div>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="展示域名">
          <el-input v-model="form.display_host" placeholder="如 hr.sunipip.com，留空则用设备组默认" />
          <div style="font-size:12px;color:#909399;margin-top:2px">客户看到的转发连接域名，不同套餐可设不同域名</div>
        </el-form-item>
        <el-form-item label="计价模式">
          <el-radio-group v-model="form.pricing_mode">
            <el-radio value="addon">叠加（IP价 + 套餐费）</el-radio>
            <el-radio value="fixed">总价（套餐价即总价，含IP）</el-radio>
          </el-radio-group>
          <div style="font-size:12px;color:#909399;margin-top:2px">总价模式下，月基础费 = 客户实付总价（不再另加IP费），适用于直播专线等固定定价</div>
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item :label="form.pricing_mode === 'fixed' ? '月总价' : '月基础费'" required>
              <el-input-number v-model="form.base_price" :min="0" :precision="2" :step="5" style="width:100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="销售软成本">
              <el-input-number v-model="form.cost_price" :min="0" :precision="2" :step="5" style="width:100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="硬成本">
              <el-input-number v-model="form.hard_cost_price" :min="0" :precision="2" :step="5" style="width:100%" />
              <div style="font-size:12px;color:#909399;margin-top:2px">真实上游成本，用于计算硬成本利润</div>
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="含流量(GB)">
              <el-input-number v-model="form.included_traffic_gb" :min="0" style="width:100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="超额单价/GB">
              <el-input-number v-model="form.overage_price_per_gb" :min="0" :precision="2" :step="0.1" style="width:100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="启用"><el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" type="textarea" :rows="2" /></el-form-item>
        <el-form-item label="应用模块">
          <el-select v-model="form.module" placeholder="通用（不限模块）" clearable style="width: 100%">
            <el-option label="通用（不限模块）" :value="null" />
            <el-option label="视频专线" value="video" />
            <el-option label="直播专线 - 手机" value="live_mobile" />
            <el-option label="直播专线 - 电脑" value="live_pc" />
          </el-select>
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            指定该套餐应用于客户商店的哪个模块。视频专线/直播专线模块会自动绑定对应套餐。
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import request from '@/utils/request'
import { getForwardPlans, createForwardPlan, updateForwardPlan, deleteForwardPlan } from '@/api/forwardPlans'
import { getNyPanels, getNyEnabledDeviceGroups } from '@/api/nyPanels'
import { getXuiPanels } from '@/api/xuiPanels'

const forwardEnabled = ref(false)
const savingToggle = ref(false)

async function fetchToggle() {
  try {
    const res = await request.get('/settings/site')
    forwardEnabled.value = !!res?.['store.forward_enabled']
  } catch {}
}
async function toggleForwardEnabled(val) {
  savingToggle.value = true
  try {
    await request.put('/settings/site', { 'store.forward_enabled': val })
    ElMessage.success(val ? '已开启客户自助购买直连' : '已关闭')
  } catch { forwardEnabled.value = !val }
  finally { savingToggle.value = false }
}

const loading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formRef = ref()
const form = reactive({
  name: '', type: 'ny', panel_id: null, device_group_id: null,
  speed_limit_mbps: 0, device_limit: 0,
  display_host: '', pricing_mode: 'addon',
  base_price: 30, cost_price: null, hard_cost_price: null, included_traffic_gb: 50, overage_price_per_gb: 1.00,
  is_active: 1, description: '',
  module: null,
})

// 面板和设备组选项
const nyPanels = ref([])
const allDeviceGroups = ref([])
const xuiPanels = ref([])

const filteredDeviceGroups = computed(() => {
  if (!form.panel_id || form.type !== 'ny') return []
  return allDeviceGroups.value.filter(g => g.ny_panel_id === form.panel_id)
})

function onTypeChange() {
  form.panel_id = null
  form.device_group_id = null
}
function onNyPanelChange() {
  form.device_group_id = null
}

async function fetchOptions() {
  try {
    const [nyRes, dgRes, xuiRes] = await Promise.all([
      getNyPanels(),
      getNyEnabledDeviceGroups(),
      getXuiPanels(),
    ])
    nyPanels.value = (nyRes || []).filter(p => p.is_active)
    allDeviceGroups.value = dgRes || []
    xuiPanels.value = (xuiRes || []).filter(p => p.is_active)
  } catch {}
}

async function fetchData() {
  loading.value = true
  try { tableData.value = (await getForwardPlans()) || [] } catch {} finally { loading.value = false }
}

function openDialog(row) {
  if (row) {
    editing.value = row
    Object.assign(form, {
      name: row.name, type: row.type,
      panel_id: row.panel_id || null, device_group_id: row.device_group_id || null,
      speed_limit_mbps: row.speed_limit_mbps || 0, device_limit: row.device_limit || 0,
      display_host: row.display_host || '', pricing_mode: row.pricing_mode || 'addon',
      base_price: Number(row.base_price), cost_price: row.cost_price != null ? Number(row.cost_price) : null, hard_cost_price: row.hard_cost_price != null ? Number(row.hard_cost_price) : null,
      included_traffic_gb: row.included_traffic_gb || 0,
      overage_price_per_gb: Number(row.overage_price_per_gb), is_active: row.is_active,
      description: row.description || '',
      module: row.module || null,
    })
  } else {
    editing.value = null
    Object.assign(form, { name: '', type: 'ny', panel_id: null, device_group_id: null, speed_limit_mbps: 0, device_limit: 0, display_host: '', pricing_mode: 'addon', base_price: 30, cost_price: null, hard_cost_price: null, included_traffic_gb: 50, overage_price_per_gb: 1.00, is_active: 1, description: '', module: null })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  submitting.value = true
  try {
    if (editing.value) { await updateForwardPlan(editing.value.id, { ...form }); ElMessage.success('已更新') }
    else { await createForwardPlan({ ...form }); ElMessage.success('已创建') }
    dialogVisible.value = false; fetchData()
  } catch {} finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除套餐「${row.name}」？`, '确认', { type: 'warning' })
    await deleteForwardPlan(row.id); ElMessage.success('已删除'); fetchData()
  } catch {}
}

onMounted(() => { fetchData(); fetchToggle(); fetchOptions() })
</script>

<style lang="scss" scoped>
.forward-plans-page {
  .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
  .mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; color: #475569; }
}
</style>
