<template>
  <div class="performance-page">
    <h2 class="page-title">业绩检索</h2>

    <el-card class="search-card">
      <el-form :inline="true">
        <el-form-item label="搜索客户">
          <el-select
            v-model="selectedCustomerId"
            filterable remote reserve-keyword
            placeholder="客户名 / 手机 / 用户名"
            :remote-method="searchCustomers"
            :loading="searchLoading"
            style="width: 300px"
            @change="handleSearch"
          >
            <el-option v-for="c in customerOptions" :key="c.id"
              :label="`#${c.id} ${c.customer_name}${c.phone ? ' (' + c.phone + ')' : ''}`"
              :value="c.id" />
          </el-select>
        </el-form-item>
      </el-form>
    </el-card>

    <template v-if="data">
      <!-- 客户基础信息卡 -->
      <el-card class="info-card animate-slide-in">
        <div class="customer-header">
          <div class="customer-header__main">
            <h3>{{ data.customer.customer_name }}</h3>
            <el-tag size="small" effect="plain">{{ data.customer.username }}</el-tag>
            <el-tag v-if="data.customer.phone" size="small" type="info" effect="plain">{{ data.customer.phone }}</el-tag>
          </div>
          <div class="customer-header__stats">
            <div class="stat-item">
              <div class="stat-item__value" style="color:#E8913A">¥{{ fmt(data.customer.balance) }}</div>
              <div class="stat-item__label">账户余额</div>
            </div>
            <div class="stat-item">
              <div class="stat-item__value" style="color:#67C23A">¥{{ fmt(data.customer.commission_balance) }}</div>
              <div class="stat-item__label">推广余额</div>
            </div>
            <div class="stat-item">
              <div class="stat-item__value" style="color:#F56C6C">¥{{ fmt(data.summary.total_spent) }}</div>
              <div class="stat-item__label">累计消费</div>
            </div>
            <div class="stat-item">
              <div class="stat-item__value">{{ data.customer.active_subscriptions_count }}</div>
              <div class="stat-item__label">活跃订阅</div>
            </div>
          </div>
        </div>
      </el-card>

      <!-- 推荐链路动画 -->
      <el-card class="chain-card animate-slide-in" style="animation-delay:0.1s">
        <template #header><span style="font-weight:600">推荐/归属链路</span></template>
        <div class="flow-chain">
          <template v-if="data.invited_by_user">
            <div class="flow-node flow-node--sales animate-pop" style="animation-delay:0.2s">
              <div class="flow-node__icon">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#E6A23C" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div class="flow-node__text">
                <span class="flow-node__role">直属销售</span>
                <span class="flow-node__name">{{ data.invited_by_user.name }}</span>
              </div>
            </div>
            <div class="flow-arrow animate-pop" style="animation-delay:0.3s">
              <svg width="32" height="20" viewBox="0 0 32 20"><path d="M2 10H26M22 4l6 6-6 6" stroke="#DCDFE6" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
            </div>
          </template>
          <template v-if="data.referrer">
            <div class="flow-node flow-node--referrer animate-pop" style="animation-delay:0.35s">
              <div class="flow-node__icon">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#67C23A" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </div>
              <div class="flow-node__text">
                <span class="flow-node__role">推荐人</span>
                <span class="flow-node__name">{{ data.referrer.customer_name }} (#{{ data.referrer.id }})</span>
              </div>
            </div>
            <div class="flow-arrow animate-pop" style="animation-delay:0.45s">
              <svg width="32" height="20" viewBox="0 0 32 20"><path d="M2 10H26M22 4l6 6-6 6" stroke="#DCDFE6" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
            </div>
          </template>
          <div class="flow-node flow-node--current animate-pop" style="animation-delay:0.5s">
            <div class="flow-node__icon">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#409EFF" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="flow-node__text">
              <span class="flow-node__role">当前客户</span>
              <span class="flow-node__name">{{ data.customer.customer_name }}</span>
            </div>
          </div>
          <template v-if="data.referrals?.length">
            <div class="flow-arrow animate-pop" style="animation-delay:0.6s">
              <svg width="32" height="20" viewBox="0 0 32 20"><path d="M2 10H26M22 4l6 6-6 6" stroke="#DCDFE6" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
            </div>
            <div class="flow-node flow-node--downstream animate-pop" style="animation-delay:0.65s">
              <div class="flow-node__icon">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#909399" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </div>
              <div class="flow-node__text">
                <span class="flow-node__role">推荐了</span>
                <span class="flow-node__name">{{ data.referrals.length }} 位客户</span>
              </div>
            </div>
          </template>
        </div>
        <div v-if="data.referrals?.length" class="referrals-list">
          <el-tag v-for="r in data.referrals" :key="r.id" size="small" effect="plain" style="margin:2px 4px;cursor:pointer" @click="selectedCustomerId = r.id; handleSearch()">
            {{ r.customer_name }} (#{{ r.id }})
          </el-tag>
        </div>
      </el-card>

      <!-- 佣金/提成流向动画 -->
      <el-card v-if="data.summary.total_referral_commission > 0" class="commission-flow-card animate-slide-in" style="animation-delay:0.2s">
        <template #header><span style="font-weight:600">佣金流向</span></template>
        <div class="commission-flow">
          <!-- 消费来源 -->
          <div class="cf-block cf-block--source animate-pop" style="animation-delay:0.3s">
            <div class="cf-block__label">客户消费</div>
            <div class="cf-block__value">¥{{ fmt(data.summary.total_spent) }}</div>
          </div>
          <!-- 箭头 -->
          <div class="cf-arrows">
            <template v-if="data.summary.total_referral_commission > 0">
              <div class="cf-arrow-row animate-pop" style="animation-delay:0.4s">
                <svg width="60" height="24" viewBox="0 0 60 24"><path d="M2 12H50M46 6l6 6-6 6" stroke="#67C23A" stroke-width="2" fill="none" stroke-linecap="round"><animate attributeName="stroke-dashoffset" from="60" to="0" dur="0.8s" fill="freeze"/></path></svg>
                <div class="cf-target">
                  <div class="cf-target__label">推荐佣金 → {{ data.commissions_as_referee[0]?.referrer_name || '推荐人' }}</div>
                  <div class="cf-target__value" style="color:#67C23A">¥{{ fmt(data.summary.total_referral_commission) }}</div>
                </div>
              </div>
            </template>
          </div>
        </div>
        <div v-if="data.summary.referral_earned > 0" class="earned-highlight animate-pop" style="animation-delay:0.6s">
          该客户作为推荐人已赚取: <strong style="color:#67C23A">¥{{ fmt(data.summary.referral_earned) }}</strong>
        </div>
      </el-card>

      <!-- 明细 Tabs -->
      <el-card class="detail-card animate-slide-in" style="animation-delay:0.3s">
        <el-tabs v-model="activeTab">
          <el-tab-pane label="订阅明细" name="subs">
            <el-table :data="data.subscriptions" stripe size="small" max-height="500">
              <el-table-column prop="id" label="ID" width="60" />
              <el-table-column label="资产" min-width="180">
                <template #default="{ row }">
                  {{ row.proxy_ip?.asset_name || '-' }}
                  <div v-if="row.proxy_ip?.country_name" style="font-size:11px;color:#909399">{{ row.proxy_ip.country_name }}</div>
                </template>
              </el-table-column>
              <el-table-column label="月单价" width="90" align="right">
                <template #default="{ row }">¥{{ fmt(getMonthlyPrice(row)) }}</template>
              </el-table-column>
              <el-table-column label="时长" width="70" align="center">
                <template #default="{ row }">{{ row.duration }}{{ unitLabel(row.unit) }}</template>
              </el-table-column>
              <el-table-column label="开始" width="100">
                <template #default="{ row }">{{ fmtDate(row.started_at) }}</template>
              </el-table-column>
              <el-table-column label="到期" width="100">
                <template #default="{ row }">{{ fmtDate(row.expires_at) }}</template>
              </el-table-column>
              <el-table-column label="续费" width="60" align="center" prop="renewed_count" />
              <el-table-column label="状态" width="80" align="center">
                <template #default="{ row }">
                  <el-tag :type="subTag(row.status)" size="small">{{ subLabel(row.status) }}</el-tag>
                </template>
              </el-table-column>
            </el-table>
          </el-tab-pane>

          <el-tab-pane label="交易流水" name="tx">
            <el-table :data="data.transactions" stripe size="small" max-height="500">
              <el-table-column label="时间" width="150">
                <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
              </el-table-column>
              <el-table-column label="类型" width="100">
                <template #default="{ row }">
                  <el-tag :type="txTag(row.type)" size="small">{{ txLabel(row.type) }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="金额" width="120" align="right">
                <template #default="{ row }">
                  <span :style="{ color: row.amount >= 0 ? '#67C23A' : '#F56C6C', fontWeight: 600 }">
                    {{ row.amount >= 0 ? '+' : '' }}¥{{ fmt(row.amount) }}
                  </span>
                </template>
              </el-table-column>
              <el-table-column label="余额" width="100" align="right">
                <template #default="{ row }">¥{{ fmt(row.balance_after) }}</template>
              </el-table-column>
              <el-table-column label="描述" min-width="250" prop="description" show-overflow-tooltip />
            </el-table>
          </el-tab-pane>

          <el-tab-pane :label="`推荐佣金 (${(data.commissions_as_referee?.length || 0) + (data.commissions_as_referrer?.length || 0)})`" name="referral">
            <h4 v-if="data.commissions_as_referee?.length" style="margin:0 0 8px">该客户消费产生的佣金 (付给推荐人)</h4>
            <el-table v-if="data.commissions_as_referee?.length" :data="data.commissions_as_referee" stripe size="small" style="margin-bottom:16px">
              <el-table-column label="时间" width="150">
                <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
              </el-table-column>
              <el-table-column label="推荐人" width="120" prop="referrer_name" />
              <el-table-column label="触发" width="80">
                <template #default="{ row }">
                  <el-tag size="small" effect="plain">{{ triggerLabel(row.trigger_type) }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="消费金额" width="100" align="right">
                <template #default="{ row }">¥{{ fmt(row.trigger_amount) }}</template>
              </el-table-column>
              <el-table-column label="比例" width="70" align="center">
                <template #default="{ row }">{{ row.commission_rate }}%</template>
              </el-table-column>
              <el-table-column label="佣金" width="100" align="right">
                <template #default="{ row }">
                  <span style="color:#67C23A;font-weight:600">¥{{ fmt(row.commission_amount) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="状态" width="80" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.status === 'credited' ? 'success' : 'warning'" size="small">{{ row.status === 'credited' ? '已发放' : '待发放' }}</el-tag>
                </template>
              </el-table-column>
            </el-table>

            <h4 v-if="data.commissions_as_referrer?.length" style="margin:0 0 8px">该客户作为推荐人收到的佣金</h4>
            <el-table v-if="data.commissions_as_referrer?.length" :data="data.commissions_as_referrer" stripe size="small">
              <el-table-column label="时间" width="150">
                <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
              </el-table-column>
              <el-table-column label="被推荐客户" width="120" prop="referee_name" />
              <el-table-column label="触发" width="80">
                <template #default="{ row }">
                  <el-tag size="small" effect="plain">{{ triggerLabel(row.trigger_type) }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="消费金额" width="100" align="right">
                <template #default="{ row }">¥{{ fmt(row.trigger_amount) }}</template>
              </el-table-column>
              <el-table-column label="比例" width="70" align="center">
                <template #default="{ row }">{{ row.commission_rate }}%</template>
              </el-table-column>
              <el-table-column label="佣金" width="100" align="right">
                <template #default="{ row }">
                  <span style="color:#67C23A;font-weight:600">¥{{ fmt(row.commission_amount) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="状态" width="80" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.status === 'credited' ? 'success' : 'warning'" size="small">{{ row.status === 'credited' ? '已发放' : '待发放' }}</el-tag>
                </template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!data.commissions_as_referee?.length && !data.commissions_as_referrer?.length" description="无佣金记录" />
          </el-tab-pane>

        </el-tabs>
      </el-card>
    </template>

    <el-empty v-else-if="!loading" description="搜索客户查看业绩明细" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { getCustomers } from '@/api/customers'
import request from '@/utils/request'
import dayjs from 'dayjs'

const selectedCustomerId = ref(null)
const searchLoading = ref(false)
const loading = ref(false)
const customerOptions = ref([])
const data = ref(null)
const activeTab = ref('subs')

async function searchCustomers(kw) {
  if (!kw) return
  searchLoading.value = true
  try {
    const params = { per_page: 30 }
    if (kw) params['filter[keyword]'] = kw
    const res = await getCustomers(params)
    customerOptions.value = res?.items || []
  } catch {}
  finally { searchLoading.value = false }
}

async function handleSearch() {
  if (!selectedCustomerId.value) return
  loading.value = true
  try {
    const res = await request.get('/performance/search', { params: { customer_id: selectedCustomerId.value } })
    data.value = res.data || res
  } catch {}
  finally { loading.value = false }
}

function fmt(v) { return Number(v || 0).toFixed(2) }
function fmtDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function fmtTime(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function unitLabel(u) { return { 1: '天', 2: '周', 3: '月', 4: '年' }[u] || '' }
function getMonthlyPrice(row) {
  const d = Number(row.duration || 1), u = Number(row.unit || 3)
  let m = d
  if (u === 1) m = Math.max(1, Math.ceil(d / 30))
  else if (u === 2) m = Math.max(1, Math.ceil(d * 7 / 30))
  else if (u === 4) m = d * 12
  return Number(row.price || 0) / Math.max(m, 1)
}
function subTag(s) { return { active: 'success', expired: 'danger', cancelled: 'info' }[s] || 'info' }
function subLabel(s) { return { active: '活跃', expired: '已过期', cancelled: '已取消' }[s] || s }
function txTag(t) { return { topup: 'success', deduction: 'danger', refund: 'warning', adjustment_in: 'success', adjustment_out: 'danger', withdrawal: 'warning', purchase: 'danger', subscription_renew: 'danger' }[t] || 'info' }
function txLabel(t) { return { topup: '充值', deduction: '扣费', refund: '退款', adjustment: '调整', adjustment_in: '增加', adjustment_out: '扣除', withdrawal: '私下退款', purchase: '购买', subscription_renew: '续费', subscription_purchase: '购买' }[t] || t }
function triggerLabel(t) { return { purchase: '购买', renew: '续费', forward: '中转', topup: '充值' }[t] || t }
</script>

<style lang="scss" scoped>
.performance-page {
  .page-title { margin: 0 0 20px; font-size: 20px; font-weight: 600; color: #2C3E50; }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }

  .info-card, .chain-card, .commission-flow-card, .detail-card {
    margin-bottom: 16px;
  }

  .customer-header {
    display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;

    &__main {
      display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
      h3 { margin: 0; font-size: 18px; color: #303133; }
    }

    &__stats {
      display: flex; gap: 24px;
    }
  }

  .stat-item {
    text-align: center;
    &__value { font-size: 18px; font-weight: 700; }
    &__label { font-size: 12px; color: #909399; margin-top: 2px; }
  }

  // 链路图
  .flow-chain {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 8px 0;
  }
  .flow-node {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; border-radius: 8px; border: 1.5px solid; background: #fff;

    &--sales { border-color: #E6A23C; }
    &--referrer { border-color: #67C23A; }
    &--current { border-color: #409EFF; background: #ECF5FF; }
    &--downstream { border-color: #909399; border-style: dashed; }

    &__icon { flex-shrink: 0; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #F5F7FA; }
    &__text { display: flex; flex-direction: column; }
    &__role { font-size: 10px; color: #909399; }
    &__name { font-size: 13px; font-weight: 600; color: #303133; white-space: nowrap; }
  }
  .flow-arrow { flex-shrink: 0; }

  .referrals-list {
    margin-top: 10px; padding-top: 10px; border-top: 1px dashed #DCDFE6;
  }

  // 佣金流向
  .commission-flow {
    display: flex; align-items: flex-start; gap: 16px; padding: 8px 0;
  }
  .cf-block {
    padding: 12px 20px; border-radius: 8px; text-align: center;
    &--source { background: #FDF6EC; border: 1px solid #FAECD8; }
    &__label { font-size: 12px; color: #909399; }
    &__value { font-size: 20px; font-weight: 700; color: #303133; margin-top: 4px; }
  }
  .cf-arrows { display: flex; flex-direction: column; gap: 8px; }
  .cf-arrow-row { display: flex; align-items: center; gap: 8px; }
  .cf-target {
    &__label { font-size: 12px; color: #606266; }
    &__value { font-size: 16px; font-weight: 700; }
  }
  .earned-highlight {
    margin-top: 12px; padding: 8px 14px; border-radius: 6px;
    background: #F0F9EB; border: 1px solid #E1F3D8; font-size: 13px; color: #606266;
  }

  // 动画
  .animate-slide-in {
    animation: slideIn 0.4s ease-out both;
  }
  .animate-pop {
    animation: popIn 0.3s ease-out both;
  }
}

@keyframes slideIn {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes popIn {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: scale(1); }
}
</style>
