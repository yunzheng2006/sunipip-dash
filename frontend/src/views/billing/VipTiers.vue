<template>
  <div class="vip-tiers-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">VIP 会员等级</h2>
        <p class="page-desc">管理 VIP 等级体系。客户通过累计消费或单次大额充值自动升级，享受对应折扣。</p>
      </div>
      <div class="header-actions">
        <el-button @click="handleRecalculateAll" :loading="recalculating">
          <el-icon><Refresh /></el-icon> 重新计算全部
        </el-button>
        <el-button type="primary" @click="openDialog()">
          <el-icon><Plus /></el-icon> 新增等级
        </el-button>
      </div>
    </div>

    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="等级名称" min-width="120">
          <template #default="{ row }">
            <div style="display: flex; align-items: center; gap: 8px">
              <span
                class="badge-dot"
                :style="{ background: row.badge_color || '#909399' }"
              ></span>
              <strong>{{ row.name }}</strong>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="累计消费门槛" width="140" align="right">
          <template #default="{ row }">
            <span style="font-weight: 600">{{ Number(row.spending_threshold).toFixed(0) }}</span> 元
          </template>
        </el-table-column>
        <el-table-column label="单次充值门槛" width="140" align="right">
          <template #default="{ row }">
            <template v-if="row.topup_threshold !== null && row.topup_threshold !== undefined">
              <span style="font-weight: 600">{{ Number(row.topup_threshold).toFixed(0) }}</span> 元
            </template>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="折扣" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.discount_percent < 80 ? 'danger' : (row.discount_percent < 100 ? 'warning' : 'info')" effect="dark" round>
              {{ row.discount_percent }}%
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sort_order" label="排序" width="80" align="center" />
        <el-table-column label="徽章颜色" width="100" align="center">
          <template #default="{ row }">
            <div v-if="row.badge_color" style="display: flex; align-items: center; justify-content: center; gap: 6px">
              <span class="color-preview" :style="{ background: row.badge_color }"></span>
              <span style="font-size: 12px; color: #909399">{{ row.badge_color }}</span>
            </div>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="说明" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="openDialog(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑等级' : '新增等级'" width="560px" :close-on-click-modal="false">
      <el-form :model="form" label-width="110px">
        <el-alert type="info" :closable="false" style="margin-bottom: 16px">
          满足以下<strong>任一</strong>条件即可达到该等级：累计消费（购买+续费扣款）达到门槛，<strong>或</strong>单次充值达到门槛。
        </el-alert>
        <el-form-item label="等级名称" required>
          <el-input v-model="form.name" placeholder="如: 银牌, 金牌, 钻石" maxlength="50" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="累计消费门槛" required>
              <el-input-number v-model="form.spending_threshold" :min="0" :precision="0" :step="100" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="单次充值门槛">
              <el-input-number v-model="form.topup_threshold" :min="0" :precision="0" :step="100" style="width: 100%" clearable />
              <div style="font-size: 11px; color: #909399; margin-top: 2px">0 或留空 = 不支持充值达标</div>
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="折扣百分比" required>
              <el-input-number v-model="form.discount_percent" :min="1" :max="100" :step="5" style="width: 100%" />
              <div style="font-size: 11px; color: #909399; margin-top: 2px">70 = 7折 (原价的70%)</div>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="排序值">
              <el-input-number v-model="form.sort_order" :min="0" :step="1" style="width: 100%" />
              <div style="font-size: 11px; color: #909399; margin-top: 2px">越大越高级</div>
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="徽章颜色">
          <div style="display: flex; align-items: center; gap: 12px">
            <el-color-picker v-model="form.badge_color" />
            <el-input v-model="form.badge_color" placeholder="#8B5CF6" style="width: 140px" />
            <span class="color-preview-large" :style="{ background: form.badge_color || '#909399' }">
              {{ form.name || 'VIP' }}
            </span>
          </div>
        </el-form-item>
        <el-form-item label="说明">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="等级说明（可选）" maxlength="500" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_active" :active-value="1" :inactive-value="0" />
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
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh } from '@element-plus/icons-vue'
import { getVipTiers, createVipTier, updateVipTier, deleteVipTier, recalculateAllVip } from '@/api/vipTiers'

const loading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const editing = ref(null)
const submitting = ref(false)
const recalculating = ref(false)

const form = reactive({
  name: '',
  spending_threshold: 0,
  topup_threshold: null,
  discount_percent: 90,
  sort_order: 0,
  is_active: 1,
  description: '',
  badge_color: '#8B5CF6',
})

async function fetchData() {
  loading.value = true
  try {
    tableData.value = (await getVipTiers()) || []
  } catch {} finally { loading.value = false }
}

function openDialog(row) {
  if (row) {
    editing.value = row
    Object.assign(form, {
      name: row.name,
      spending_threshold: Number(row.spending_threshold),
      topup_threshold: row.topup_threshold !== null && row.topup_threshold !== undefined ? Number(row.topup_threshold) : null,
      discount_percent: row.discount_percent,
      sort_order: row.sort_order || 0,
      is_active: row.is_active,
      description: row.description || '',
      badge_color: row.badge_color || '#8B5CF6',
    })
  } else {
    editing.value = null
    Object.assign(form, {
      name: '', spending_threshold: 0, topup_threshold: null,
      discount_percent: 90, sort_order: 0, is_active: 1, description: '', badge_color: '#8B5CF6',
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!form.name || !form.discount_percent) {
    ElMessage.warning('请填写等级名称和折扣')
    return
  }
  submitting.value = true
  try {
    const data = { ...form }
    // 0 或空 = 不支持充值达标，传 null
    if (!data.topup_threshold || data.topup_threshold <= 0) {
      data.topup_threshold = null
    }
    if (editing.value) {
      await updateVipTier(editing.value.id, data)
      ElMessage.success('已更新')
    } else {
      await createVipTier(data)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    fetchData()
  } catch {} finally { submitting.value = false }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`删除等级「${row.name}」？`, '确认', { type: 'warning' })
    await deleteVipTier(row.id)
    ElMessage.success('已删除')
    fetchData()
  } catch {}
}

async function handleRecalculateAll() {
  try {
    await ElMessageBox.confirm('将重新计算所有活跃客户的 VIP 等级，可能需要一些时间。确认执行？', '重新计算全部', { type: 'info' })
  } catch { return }
  recalculating.value = true
  try {
    const res = await recalculateAllVip()
    ElMessage.success(res?.message || `已完成重新计算`)
  } catch {} finally { recalculating.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.vip-tiers-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
    .header-actions { display: flex; gap: 8px; }
  }

  .badge-dot {
    display: inline-block;
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .color-preview {
    display: inline-block;
    width: 16px; height: 16px;
    border-radius: 4px;
    border: 1px solid rgba(0, 0, 0, 0.1);
  }

  .color-preview-large {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: 20px;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    min-width: 50px;
    text-align: center;
  }
}
</style>
