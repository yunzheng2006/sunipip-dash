<template>
  <div class="referral-page">
    <div class="page-header">
      <h2 class="page-title">推广与邀请</h2>
    </div>

    <!-- Settings -->
    <el-card style="margin-bottom: 20px">
      <template #header><strong>推广设置</strong></template>
      <el-form :model="settings" label-width="160px" v-loading="loadingSettings" style="max-width: 550px">
        <el-form-item label="启用客户推广">
          <el-switch v-model="settings['referral.enabled']" />
          <span class="hint">客户可生成推广码邀请新客户注册</span>
        </el-form-item>
        <el-form-item label="自动发放佣金">
          <el-switch v-model="settings['referral.auto_credit']" />
          <span class="hint">被推荐人消费后自动将佣金入账推荐人余额</span>
        </el-form-item>
        <el-form-item label="默认返佣比例">
          <el-input-number v-model="settings['referral.rate']" :min="0" :max="50" :precision="1" :step="0.5" />
          <span class="hint">%，购买和续费都未单独设置时使用此比例</span>
        </el-form-item>
        <el-form-item label="新单返佣比例">
          <el-input-number v-model="settings['referral.rate_purchase']" :min="0" :max="50" :precision="1" :step="0.5" />
          <span class="hint">%，被推荐人首次下单的返佣比例，不填则用默认</span>
        </el-form-item>
        <el-form-item label="续费返佣比例">
          <el-input-number v-model="settings['referral.rate_renew']" :min="0" :max="50" :precision="1" :step="0.5" />
          <span class="hint">%，被推荐人续费时的返佣比例，不填则用默认</span>
        </el-form-item>
        <el-form-item label="兜底返佣比例">
          <el-input-number v-model="settings['referral.floor_rate']" :min="0" :max="50" :precision="1" :step="0.5" />
          <span class="hint">%，特批价场景下差价为0或低于分水岭时的最低返佣比例（默认5%）</span>
        </el-form-item>
        <el-form-item label="分水岭折扣">
          <el-input-number v-model="settings['referral.threshold_discount']" :min="50" :max="100" :precision="0" :step="5" />
          <span class="hint">%，任一方折扣低于此值时走兜底比例（默认70，即7折）</span>
        </el-form-item>
        <el-form-item label="提现手续费">
          <el-input-number v-model="settings['referral.withdraw_fee_percent']" :min="0" :max="50" :precision="1" :step="0.5" />
          <span class="hint">%，客户提现佣金时收取的手续费比例</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingSettings" @click="saveSettings">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Per-product Commission Settings -->
    <el-card style="margin-bottom: 20px">
      <template #header><strong>按产品类型返佣设置</strong></template>
      <p style="font-size:12px; color:#909399; margin:0 0 16px">
        为空时使用上方全局默认值。公式：返佣 = max(实付 - 原价 × 阈值%, 原价 × 兜底%)
      </p>
      <el-table :data="productModules" size="small" border stripe v-loading="loadingSettings" style="max-width: 780px">
        <el-table-column label="产品类型" width="120" prop="label" />
        <el-table-column label="分水岭阈值 %">
          <template #default="{ row }">
            <el-input-number v-model="settings[`referral.${row.key}.threshold`]" :min="50" :max="100" :precision="0" :step="5" :controls="false" size="small" placeholder="默认" style="width:90px" />
          </template>
        </el-table-column>
        <el-table-column label="兜底 %">
          <template #default="{ row }">
            <el-input-number v-model="settings[`referral.${row.key}.floor_rate`]" :min="0" :max="50" :precision="1" :step="0.5" :controls="false" size="small" placeholder="默认" style="width:90px" />
          </template>
        </el-table-column>
        <el-table-column label="新购兜底 %">
          <template #default="{ row }">
            <el-input-number v-model="settings[`referral.${row.key}.floor_rate_purchase`]" :min="0" :max="50" :precision="1" :step="0.5" :controls="false" size="small" placeholder="同兜底" style="width:90px" />
          </template>
        </el-table-column>
        <el-table-column label="续费兜底 %">
          <template #default="{ row }">
            <el-input-number v-model="settings[`referral.${row.key}.floor_rate_renew`]" :min="0" :max="50" :precision="1" :step="0.5" :controls="false" size="small" placeholder="同兜底" style="width:90px" />
          </template>
        </el-table-column>
      </el-table>
      <el-button type="primary" :loading="savingSettings" @click="saveSettings" style="margin-top:12px">保存设置</el-button>
    </el-card>

    <!-- Cost Override Settings -->
    <el-card style="margin-bottom: 20px">
      <template #header><strong>成本覆盖</strong></template>
      <el-form :model="settings" label-width="200px" v-loading="loadingSettings" style="max-width: 550px">
        <el-form-item label="IPIPV IP 硬成本覆盖">
          <el-input-number v-model="settings['cost.ipipv_hard_cost_override']" :min="0" :max="500" :precision="2" :step="1" />
          <span class="hint">元/月，为空则用 IPIPV API 返回的原价</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingSettings" @click="saveSettings">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Stats -->
    <el-row :gutter="16" style="margin-bottom: 20px">
      <el-col :span="8"><el-card shadow="never"><div class="stat-num">{{ stats.total_referrals || 0 }}</div><div class="stat-label">推荐注册</div></el-card></el-col>
      <el-col :span="8"><el-card shadow="never"><div class="stat-num">¥{{ Number(stats.total_commission_paid || 0).toFixed(2) }}</div><div class="stat-label">已发放推广佣金</div></el-card></el-col>
      <el-col :span="8"><el-card shadow="never"><div class="stat-num">¥{{ Number(stats.pending_commission || 0).toFixed(2) }}</div><div class="stat-label">待发放推广佣金</div></el-card></el-col>
    </el-row>

    <el-row :gutter="16">
      <el-col :span="12">
        <el-card>
          <template #header><strong>客户推广排行</strong></template>
          <el-table :data="stats.top_referrers || []" size="small" stripe>
            <el-table-column prop="customer_name" label="客户" min-width="120" />
            <el-table-column prop="referral_code" label="推广码" width="100"><template #default="{ row }"><span class="mono">{{ row.referral_code }}</span></template></el-table-column>
            <el-table-column prop="referral_count" label="推荐数" width="80" align="center" />
            <el-table-column label="累计佣金" width="100" align="right"><template #default="{ row }">¥{{ Number(row.total_earned || 0).toFixed(2) }}</template></el-table-column>
          </el-table>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card>
          <template #header><strong>业务员邀请统计</strong></template>
          <el-table :data="stats.staff_stats || []" size="small" stripe>
            <el-table-column prop="name" label="业务员" min-width="90" />
            <el-table-column prop="invite_code" label="邀请码" width="80"><template #default="{ row }"><span class="mono">{{ row.invite_code }}</span></template></el-table-column>
            <el-table-column prop="customer_count" label="客户数" width="80" align="center" />
            <el-table-column label="客户消费" width="120" align="right"><template #default="{ row }">¥{{ Number(row.total_spent || 0).toFixed(0) }}</template></el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const loadingSettings = ref(false)
const savingSettings = ref(false)

const productModules = [
  { key: 'static', label: '单IP' },
  { key: 'video', label: '视频专线' },
  { key: 'live_mobile', label: '直播-手机' },
  { key: 'live_pc', label: '直播-电脑' },
]

const settings = reactive({
  'referral.enabled': false,
  'referral.auto_credit': true,
  'referral.rate': 5,
  'referral.rate_purchase': null,
  'referral.rate_renew': null,
  'referral.floor_rate': 5,
  'referral.threshold_discount': 80,
  'referral.withdraw_fee_percent': 1,
  ...Object.fromEntries(productModules.flatMap(m => [
    [`referral.${m.key}.threshold`, null],
    [`referral.${m.key}.floor_rate`, null],
    [`referral.${m.key}.floor_rate_purchase`, null],
    [`referral.${m.key}.floor_rate_renew`, null],
  ])),
  'cost.ipipv_hard_cost_override': null,
})
const stats = ref({})

async function loadSettings() {
  loadingSettings.value = true
  try {
    const res = await request.get('/settings/referral')
    if (res) Object.assign(settings, res)
  } catch {} finally { loadingSettings.value = false }
}

async function saveSettings() {
  savingSettings.value = true
  try {
    await request.put('/settings/referral', { ...settings })
    ElMessage.success('设置已保存')
  } catch {} finally { savingSettings.value = false }
}

async function loadStats() {
  try { stats.value = (await request.get('/referral-stats')) || {} } catch {}
}

onMounted(() => { loadSettings(); loadStats() })
</script>

<style lang="scss" scoped>
.referral-page {
  .page-header { margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .hint { font-size: 12px; color: #909399; margin-left: 8px; }
  .stat-num { font-size: 28px; font-weight: 800; color: #E8913A; text-align: center; font-family: 'SF Mono', Consolas, monospace; }
  .stat-label { font-size: 12px; color: #909399; text-align: center; margin-top: 4px; }
  .mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; }
}
</style>
