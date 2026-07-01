<template>
  <div class="customer-detail" v-loading="loading">
    <div class="page-header">
      <el-button @click="$router.back()" :icon="ArrowLeft">返回</el-button>
      <h2 class="page-title">客户详情</h2>
    </div>

    <template v-if="customer">
      <!-- Basic Info -->
      <el-card class="info-card">
        <template #header>
          <div class="card-header">
            <span><el-icon><User /></el-icon> 客户信息</span>
            <div style="display: flex; gap: 8px">
              <el-button type="info" size="small" plain @click="handleImpersonate">
                <el-icon><View /></el-icon> 模拟登录
              </el-button>
              <el-button type="warning" size="small" plain @click="openResetPassword">
                <el-icon><Key /></el-icon> 重置密码
              </el-button>
              <el-button type="success" size="small" @click="openAdjustBalance('increase')">
                <el-icon><Wallet /></el-icon> 充值
              </el-button>
              <el-button type="danger" size="small" plain @click="openAdjustBalance('decrease')">
                <el-icon><Minus /></el-icon> 扣款
              </el-button>
            </div>
          </div>
        </template>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="客户名称">
            <strong>{{ customer.customer_name }}</strong>
          </el-descriptions-item>
          <el-descriptions-item label="登录用户名">
            <span class="mono">{{ customer.username }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="customer.status === 1 ? 'success' : 'info'" size="small">
              {{ customer.status === 1 ? '正常' : '停用' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="业务归属">
            <el-tag size="small" type="warning" effect="plain">{{ customer.sales_person || '-' }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="账户余额">
            <span style="color: #E8913A; font-weight: 600; font-size: 16px">
              ¥{{ Number(customer.balance || 0).toFixed(2) }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="活跃订阅">
            {{ customer.active_subscriptions_count || 0 }} / {{ customer.proxy_ips_count || 0 }}
          </el-descriptions-item>
          <el-descriptions-item label="手机">{{ customer.phone || '-' }}</el-descriptions-item>
          <el-descriptions-item label="邮箱">{{ customer.email || '-' }}</el-descriptions-item>
          <el-descriptions-item label="公司">{{ customer.company_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="中转认证">
            <el-tag v-if="customer.forward_certified" type="success" size="small" effect="dark">已认证</el-tag>
            <el-tag v-else type="info" size="small">未认证</el-tag>
            <el-button v-if="!customer.forward_certified" type="primary" link size="small" style="margin-left:8px"
              @click="submitCertification">提交认证申请</el-button>
            <el-button v-else type="warning" link size="small" style="margin-left:8px"
              @click="toggleCertification(false)">撤销认证</el-button>
          </el-descriptions-item>
          <el-descriptions-item label="推荐人" :span="2">
            <template v-if="customer.referrer_name">
              <el-tag type="success" size="small" effect="plain" style="margin-right:6px">
                {{ customer.referrer_name }} (#{{ customer.referred_by_customer }})
              </el-tag>
              <el-button type="danger" link size="small" @click="handleClearReferrer">清除</el-button>
              <el-button type="warning" link size="small" @click="openTransferReferrer">划转</el-button>
            </template>
            <template v-else>
              <span style="color:#C0C4CC;margin-right:8px">未绑定</span>
              <el-button type="primary" link size="small" @click="referrerDialogVisible = true">设置推荐人</el-button>
            </template>
          </el-descriptions-item>
          <el-descriptions-item label="推广余额">
            <span style="color: #67C23A; font-weight: 600">
              ¥{{ Number(customer.commission_balance || 0).toFixed(2) }}
            </span>
          </el-descriptions-item>
          <el-descriptions-item label="地址" :span="3">{{ customer.address || '-' }}</el-descriptions-item>
          <el-descriptions-item v-if="customer.remark" label="备注" :span="3">{{ customer.remark }}</el-descriptions-item>
        </el-descriptions>

        <!-- 推荐链路图 -->
        <div v-if="hasChainData" class="referral-chain-card">
          <div class="chain-title">推荐/归属关系链路</div>
          <div class="chain-diagram">
            <!-- 所属销售 -->
            <template v-if="effectiveSales">
              <div class="chain-node chain-node--sales animate-fade-in" :style="{ animationDelay: '0s' }">
                <div class="chain-node__icon">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#E6A23C" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div class="chain-node__info">
                  <div class="chain-node__label">{{ effectiveSales.role === 'sales_direct' ? '直属销售' : '间接销售' }}</div>
                  <div class="chain-node__name">{{ effectiveSales.name }}</div>
                </div>
              </div>
              <div class="chain-arrow animate-fade-in" :style="{ animationDelay: '0.2s' }">
                <svg viewBox="0 0 40 24" width="40" height="24"><path d="M4 12 H32 M28 6 L34 12 L28 18" stroke="#DCDFE6" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><animate attributeName="stroke-dashoffset" from="50" to="0" dur="0.6s" fill="freeze"/></path></svg>
                <span class="chain-arrow__label">{{ effectiveSales.role === 'sales_direct' ? '邀请注册' : '二级归属' }}</span>
              </div>
            </template>
            <!-- 推荐人 -->
            <template v-if="chainReferrer">
              <div class="chain-node chain-node--referrer animate-fade-in" :style="{ animationDelay: '0.3s' }">
                <div class="chain-node__icon">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#67C23A" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="chain-node__info">
                  <div class="chain-node__label">推荐人 (客户)</div>
                  <div class="chain-node__name">
                    <el-link type="primary" @click="$router.push(`/customers/${chainReferrer.id}`)">
                      {{ chainReferrer.name }}
                    </el-link>
                  </div>
                </div>
              </div>
              <div class="chain-arrow animate-fade-in" :style="{ animationDelay: '0.5s' }">
                <svg viewBox="0 0 40 24" width="40" height="24"><path d="M4 12 H32 M28 6 L34 12 L28 18" stroke="#DCDFE6" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><animate attributeName="stroke-dashoffset" from="50" to="0" dur="0.6s" fill="freeze"/></path></svg>
                <span class="chain-arrow__label">推荐注册</span>
              </div>
            </template>
            <!-- 当前客户 -->
            <div class="chain-node chain-node--current animate-fade-in" :style="{ animationDelay: chainReferrer ? '0.6s' : '0.3s' }">
              <div class="chain-node__icon">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#409EFF" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div class="chain-node__info">
                <div class="chain-node__label">当前客户</div>
                <div class="chain-node__name">{{ customer.customer_name }}</div>
              </div>
            </div>
            <!-- 下级客户 -->
            <template v-if="customer.referral_count > 0">
              <div class="chain-arrow animate-fade-in" :style="{ animationDelay: '0.8s' }">
                <svg viewBox="0 0 40 24" width="40" height="24"><path d="M4 12 H32 M28 6 L34 12 L28 18" stroke="#DCDFE6" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><animate attributeName="stroke-dashoffset" from="50" to="0" dur="0.6s" fill="freeze"/></path></svg>
                <span class="chain-arrow__label">推荐了</span>
              </div>
              <div class="chain-node chain-node--downstream animate-fade-in" :style="{ animationDelay: '1s' }">
                <div class="chain-node__icon">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#909399" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="chain-node__info">
                  <div class="chain-node__label">下级客户</div>
                  <div class="chain-node__name">{{ customer.referral_count }} 位</div>
                </div>
              </div>
            </template>
          </div>
          <!-- 下级列表 -->
          <div v-if="customer.referrals?.length" class="referral-list">
            <div class="referral-list__title">已推荐客户 ({{ customer.referral_count }})</div>
            <div class="referral-list__items">
              <el-tag v-for="r in customer.referrals" :key="r.id" size="small" effect="plain" style="margin: 2px 4px; cursor: pointer" @click="$router.push(`/customers/${r.id}`)">
                {{ r.customer_name }} (#{{ r.id }})
              </el-tag>
            </div>
          </div>
        </div>
      </el-card>

      <!-- Verification Info -->
      <el-card v-if="canViewVerification" class="info-card" style="margin-top: 16px">
        <template #header>
          <div class="card-header">
            <span><el-icon><Stamp /></el-icon> 实名认证信息</span>
            <div style="display:flex;gap:8px;align-items:center">
              <el-tag v-if="customer.verified_type === 'personal'" type="success" size="small">个人认证</el-tag>
              <el-tag v-else-if="customer.verified_type === 'enterprise'" type="success" size="small">企业认证</el-tag>
              <el-tag v-else type="info" size="small">未认证</el-tag>
              <el-button v-if="!customer.verified_type && canResetVerification" type="success" size="small" plain @click="openManualVerify">
                手动认证
              </el-button>
              <el-button v-if="customer.verified_type && canResetVerification" type="danger" size="small" plain @click="handleResetVerification">
                重置认证
              </el-button>
              <el-button v-if="!verificationLoaded" type="primary" size="small" plain @click="loadVerificationInfo">
                查看详情
              </el-button>
            </div>
          </div>
        </template>
        <template v-if="verificationLoaded && verificationInfo">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="认证类型">
              {{ verificationInfo.verified_type === 'personal' ? '个人认证' : verificationInfo.verified_type === 'enterprise' ? '企业认证' : '未认证' }}
            </el-descriptions-item>
            <el-descriptions-item label="认证时间">
              {{ verificationInfo.verified_at ? dayjs(verificationInfo.verified_at).format('YYYY-MM-DD HH:mm:ss') : '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="真实姓名">
              <strong>{{ verificationInfo.verified_name || '-' }}</strong>
            </el-descriptions-item>
            <el-descriptions-item label="身份证号">
              <span class="mono">{{ verificationInfo.verified_id_number || '-' }}</span>
            </el-descriptions-item>
            <el-descriptions-item v-if="verificationInfo.verified_enterprise_name" label="企业名称" :span="2">
              {{ verificationInfo.verified_enterprise_name }}
            </el-descriptions-item>
            <el-descriptions-item v-if="verificationInfo.verified_credit_code" label="统一社会信用代码" :span="2">
              <span class="mono">{{ verificationInfo.verified_credit_code }}</span>
            </el-descriptions-item>
          </el-descriptions>
        </template>
        <template v-else-if="verificationLoaded && !verificationInfo">
          <el-empty description="该客户尚未进行实名认证" :image-size="60" />
        </template>
        <template v-else>
          <div style="color:#909399;font-size:13px">点击「查看详情」加载完整认证信息（不打码）</div>
        </template>
      </el-card>

      <el-tabs v-model="activeTab">
        <el-tab-pane label="持有IP资产" name="ips">
          <el-card>
            <el-table :data="customer.proxy_ips || []" stripe size="small">
              <el-table-column prop="id" label="ID" width="60" />
              <el-table-column label="资产名称" min-width="200" show-overflow-tooltip>
                <template #default="{ row }">
                  <el-link type="primary" @click="$router.push(`/proxy-ips/${row.id}`)">
                    {{ row.asset_name || '-' }}
                  </el-link>
                </template>
              </el-table-column>
              <el-table-column label="IP:端口" min-width="160">
                <template #default="{ row }">
                  <span class="mono">{{ row.ip_address }}:{{ row.port }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="country_name" label="地区" width="80" />
              <el-table-column label="IP归属" width="100">
                <template #default="{ row }">
                  <el-tag size="small" type="info" effect="plain">{{ row.source_name || '-' }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="状态" width="90" align="center">
                <template #default="{ row }">
                  <el-tag :type="ipStatusTag(row.status)" size="small">{{ ipStatusLabel(row.status) }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="上游到期" width="110">
                <template #default="{ row }">{{ formatDate(row.upstream_expires_at) }}</template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!customer.proxy_ips?.length" description="暂无持有资产" />
          </el-card>
        </el-tab-pane>

        <el-tab-pane label="订阅列表" name="subs">
          <el-card>
            <el-table :data="customer.subscriptions || []" stripe size="small">
              <el-table-column prop="id" label="ID" width="60" />
              <el-table-column label="资产名称" min-width="200" show-overflow-tooltip>
                <template #default="{ row }">{{ row.proxy_ip?.asset_name || '-' }}</template>
              </el-table-column>
              <el-table-column label="地区" width="80">
                <template #default="{ row }">{{ row.proxy_ip?.country_name || '-' }}</template>
              </el-table-column>
              <el-table-column label="单价/月" width="100" align="right">
                <template #default="{ row }">¥{{ (Number(row.price || 0) / Math.max(getSubDurationMonths(row), 1)).toFixed(2) }}</template>
              </el-table-column>
              <el-table-column label="开始时间" width="110">
                <template #default="{ row }">{{ formatDate(row.started_at) }}</template>
              </el-table-column>
              <el-table-column label="到期时间" width="110">
                <template #default="{ row }">{{ formatDate(row.expires_at) }}</template>
              </el-table-column>
              <el-table-column label="状态" width="90" align="center">
                <template #default="{ row }">
                  <el-tag :type="subStatusTag(row.status)" size="small">{{ subStatusLabel(row.status) }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="" width="80" align="center">
                <template #default="{ row }">
                  <el-button type="primary" link size="small" @click="$router.push(`/subscriptions/${row.id}`)">详情</el-button>
                </template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!customer.subscriptions?.length" description="暂无订阅记录" />
          </el-card>
        </el-tab-pane>

        <el-tab-pane label="交易流水" name="tx">
          <el-card>
            <el-table :data="customer.transactions || []" stripe size="small">
              <el-table-column label="时间" width="160">
                <template #default="{ row }">{{ formatDateTime(row.created_at) }}</template>
              </el-table-column>
              <el-table-column label="类型" width="100">
                <template #default="{ row }">
                  <el-tag :type="txTag(row.type)" size="small">{{ txLabel(row.type) }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="金额" width="120" align="right">
                <template #default="{ row }">
                  <span :style="{ color: row.amount >= 0 ? '#67C23A' : '#F56C6C', fontWeight: 600 }">
                    {{ row.amount >= 0 ? '+' : '' }}¥{{ Number(row.amount).toFixed(2) }}
                  </span>
                </template>
              </el-table-column>
              <el-table-column label="余额(变更后)" width="120" align="right">
                <template #default="{ row }">¥{{ Number(row.balance_after || 0).toFixed(2) }}</template>
              </el-table-column>
              <el-table-column label="备注" min-width="200">
                <template #default="{ row }">{{ row.description || '-' }}</template>
              </el-table-column>
            </el-table>
            <el-empty v-if="!customer.transactions?.length" description="暂无交易记录" />
          </el-card>
        </el-tab-pane>
      </el-tabs>
    </template>

    <!-- Balance Adjust Dialog -->
    <el-dialog v-model="adjustVisible" :title="adjustForm.action === 'increase' ? '增加余额' : '扣除余额'" width="480px">
      <el-form :model="adjustForm" label-width="80px">
        <el-form-item label="客户">
          <el-input :value="customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="当前余额">
          <el-input :value="`¥${Number(customer?.balance || 0).toFixed(2)}`" disabled />
        </el-form-item>
        <el-form-item label="操作类型">
          <el-radio-group v-model="adjustForm.action">
            <el-radio-button value="increase">增加余额</el-radio-button>
            <el-radio-button value="decrease">扣除余额</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="金额">
          <el-input-number v-model="adjustForm.amount" :min="0.01" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="原因">
          <el-select v-model="adjustForm.reason" placeholder="选择原因" style="width: 100%">
            <template v-if="adjustForm.action === 'increase'">
              <el-option label="线下充值" value="线下充值" />
              <el-option label="退还余额" value="退还余额" />
              <el-option label="赠送余额" value="赠送余额" />
              <el-option label="补差价" value="补差价" />
              <el-option label="其他" value="其他" />
            </template>
            <template v-else>
              <el-option label="私下退款/提现（不算消费）" value="私下退款" />
              <el-option label="线下消费" value="线下消费" />
              <el-option label="违规扣款" value="违规扣款" />
              <el-option label="余额修正" value="余额修正" />
              <el-option label="其他" value="其他" />
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="adjustForm.remark" type="textarea" :rows="2" placeholder="选填，补充说明" />
        </el-form-item>
        <el-form-item v-if="adjustForm.action === 'decrease'">
          <el-alert type="warning" :closable="false" show-icon>
            扣除后余额为 ¥{{ Math.max(0, Number(customer?.balance || 0) - adjustForm.amount).toFixed(2) }}
          </el-alert>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="adjustVisible = false">取消</el-button>
        <el-button
          :type="adjustForm.action === 'increase' ? 'primary' : 'danger'"
          :loading="adjustLoading"
          @click="submitAdjust"
        >
          确认{{ adjustForm.action === 'increase' ? '增加' : '扣除' }} ¥{{ adjustForm.amount.toFixed(2) }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Set Referrer Dialog -->
    <el-dialog v-model="referrerDialogVisible" title="设置推荐人" width="520px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="当前客户">
          <el-input :value="`${customer?.customer_name} (#${customer?.id})`" disabled />
        </el-form-item>
        <el-form-item label="邀请码">
          <el-input v-model="referrerCode" placeholder="输入推荐人的邀请码" @keyup.enter="previewReferrer" />
        </el-form-item>
      </el-form>

      <!-- Preview Result -->
      <template v-if="referrerPreview">
        <el-divider content-position="left">确认信息</el-divider>
        <el-descriptions :column="1" border size="small" style="margin-bottom:16px">
          <el-descriptions-item label="推荐人">
            <strong>{{ referrerPreview.referrer.customer_name }}</strong> (#{{ referrerPreview.referrer.id }})
          </el-descriptions-item>
          <el-descriptions-item label="推荐人当前推广余额">
            ¥{{ Number(referrerPreview.referrer.commission_balance).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="该客户历史消费总额">
            <span style="font-weight:600">¥{{ Number(referrerPreview.purchase_total).toFixed(2) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="返佣比例">
            {{ referrerPreview.commission_rate }}%
          </el-descriptions-item>
          <el-descriptions-item label="追溯返佣金额">
            <span style="color:#E8913A;font-weight:700;font-size:16px">
              ¥{{ Number(referrerPreview.retro_commission).toFixed(2) }}
            </span>
          </el-descriptions-item>
        </el-descriptions>
        <el-alert type="warning" :closable="false" show-icon>
          确认后将绑定推荐关系，并将追溯返佣 ¥{{ Number(referrerPreview.retro_commission).toFixed(2) }}
          计入推荐人「{{ referrerPreview.referrer.customer_name }}」的推广余额。后续该客户的消费也会按比例返佣。
        </el-alert>
      </template>

      <template #footer>
        <el-button @click="referrerDialogVisible = false; referrerPreview = null; referrerCode = ''">取消</el-button>
        <el-button v-if="!referrerPreview" type="primary" :loading="referrerLoading" @click="previewReferrer">
          查询邀请码
        </el-button>
        <template v-else>
          <el-button @click="referrerPreview = null">重新输入</el-button>
          <el-button type="warning" :loading="referrerLoading" @click="confirmReferrer">
            确认绑定并追溯返佣
          </el-button>
        </template>
      </template>
    </el-dialog>

    <!-- Transfer Referrer Dialog -->
    <el-dialog v-model="transferReferrerVisible" title="划转推荐人" width="560px" :close-on-click-modal="false"
      @closed="resetTransferReferrer">
      <el-form label-width="100px">
        <el-form-item label="当前客户">
          <el-input :value="`${customer?.customer_name} (#${customer?.id})`" disabled />
        </el-form-item>
        <el-form-item label="当前推荐人">
          <el-input :value="`${customer?.referrer_name} (#${customer?.referred_by_customer})`" disabled />
        </el-form-item>
        <el-form-item label="新推荐人">
          <div style="display:flex;gap:8px;width:100%">
            <el-input v-model="transferSearch" placeholder="输入客户ID或客户名搜索" @keyup.enter="searchTransferTarget"
              style="flex:1" />
            <el-button type="primary" :loading="transferSearching" @click="searchTransferTarget">查询</el-button>
          </div>
        </el-form-item>
        <!-- 搜索结果选择 -->
        <el-form-item v-if="transferSearchResults.length > 0" label="选择客户">
          <el-select v-model="transferNewReferrerId" placeholder="请选择新推荐人" style="width:100%"
            @change="handleTransferTargetSelected">
            <el-option v-for="c in transferSearchResults" :key="c.id" :value="c.id"
              :label="`${c.customer_name} (#${c.id})`" />
          </el-select>
        </el-form-item>
      </el-form>

      <!-- Preview Result -->
      <template v-if="transferPreview">
        <el-divider content-position="left">划转预览</el-divider>
        <el-descriptions :column="1" border size="small" style="margin-bottom:16px">
          <el-descriptions-item label="原推荐人">
            <strong>{{ transferPreview.old_referrer.customer_name }}</strong> (#{{ transferPreview.old_referrer.id }})
            <span style="color:#909399;margin-left:8px">推广余额: ¥{{ Number(transferPreview.old_referrer.commission_balance).toFixed(2) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="新推荐人">
            <strong>{{ transferPreview.new_referrer.customer_name }}</strong> (#{{ transferPreview.new_referrer.id }})
            <span style="color:#909399;margin-left:8px">推广余额: ¥{{ Number(transferPreview.new_referrer.commission_balance).toFixed(2) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="总记录数">
            {{ transferPreview.total_records }} 条
          </el-descriptions-item>
          <el-descriptions-item label="已发放佣金">
            <span style="color:#E8913A;font-weight:700">
              ¥{{ Number(transferPreview.credited_amount).toFixed(2) }}
            </span>
            <span style="color:#909399;margin-left:4px">({{ transferPreview.credited_count }} 条，将从原推荐人推广余额转移到新推荐人)</span>
          </el-descriptions-item>
          <el-descriptions-item label="待发放佣金">
            <span style="font-weight:600">
              ¥{{ Number(transferPreview.pending_amount).toFixed(2) }}
            </span>
            <span style="color:#909399;margin-left:4px">({{ transferPreview.pending_count }} 条)</span>
          </el-descriptions-item>
        </el-descriptions>
        <el-alert v-if="transferPreview.credited_amount > 0" type="warning" :closable="false" show-icon>
          划转后将从「{{ transferPreview.old_referrer.customer_name }}」的推广余额中扣除 ¥{{ Number(transferPreview.credited_amount).toFixed(2) }}，
          并增加到「{{ transferPreview.new_referrer.customer_name }}」的推广余额中。
        </el-alert>
      </template>

      <template #footer>
        <el-button @click="transferReferrerVisible = false">取消</el-button>
        <el-button v-if="transferPreview" type="warning" :loading="transferLoading" @click="confirmTransferReferrer">
          确认划转
        </el-button>
      </template>
    </el-dialog>

    <!-- Reset Password Dialog -->
    <el-dialog v-model="resetPasswordVisible" title="重置客户密码" width="480px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        重置后该客户当前登录会话将被踢出，请务必把新密码转告客户。
      </el-alert>
      <el-form :model="resetPasswordForm" label-width="100px">
        <el-form-item label="客户">
          <el-input :value="customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="登录用户名">
          <el-input :value="customer?.username" disabled class="mono" />
        </el-form-item>
        <el-form-item label="新密码">
          <el-input
            v-model="resetPasswordForm.password"
            placeholder="留空将自动生成 10 位随机密码"
            show-password
          />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            至少 6 位字符；留空则由系统自动生成
          </div>
        </el-form-item>
      </el-form>

      <!-- 重置成功后展示生成的密码 -->
      <el-alert
        v-if="resetResult"
        type="success"
        :closable="false"
        show-icon
        style="margin-top: 12px"
      >
        <template #title>
          密码已更新：
          <span class="mono" style="font-weight: 600; color: #E8913A">{{ resetResult }}</span>
          <el-button link type="primary" size="small" @click="copyText(resetResult)" style="margin-left: 8px">
            复制
          </el-button>
        </template>
      </el-alert>

      <template #footer>
        <el-button @click="closeResetPassword">{{ resetResult ? '关闭' : '取消' }}</el-button>
        <el-button
          v-if="!resetResult"
          type="warning"
          :loading="resetPasswordLoading"
          @click="submitResetPassword"
        >
          确认重置
        </el-button>
      </template>
    </el-dialog>

    <!-- 手动认证对话框 -->
    <el-dialog v-model="manualVerifyVisible" title="手动实名认证" width="500px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        手动认证将直接标记客户为已认证状态，请确认信息真实有效。
      </el-alert>
      <el-form :model="manualVerifyForm" label-width="100px">
        <el-form-item label="认证类型">
          <el-radio-group v-model="manualVerifyForm.verified_type">
            <el-radio value="personal">个人认证</el-radio>
            <el-radio value="enterprise">企业认证</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="真实姓名" required>
          <el-input v-model="manualVerifyForm.verified_name" placeholder="身份证上的姓名" />
        </el-form-item>
        <el-form-item label="身份证号" required>
          <el-input v-model="manualVerifyForm.verified_id_number" placeholder="18位身份证号或脱敏格式" maxlength="18" />
        </el-form-item>
        <template v-if="manualVerifyForm.verified_type === 'enterprise'">
          <el-form-item label="企业名称">
            <el-input v-model="manualVerifyForm.verified_enterprise_name" />
          </el-form-item>
          <el-form-item label="信用代码">
            <el-input v-model="manualVerifyForm.verified_credit_code" maxlength="18" />
          </el-form-item>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="manualVerifyVisible = false">取消</el-button>
        <el-button type="primary" :loading="manualVerifying" @click="submitManualVerify">确认认证</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, User, Wallet, Key, View, Minus, Stamp } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import { getCustomer, topupCustomer, resetCustomerPassword, impersonateCustomer, updateCustomer, setCustomerReferrer, clearCustomerReferrer, transferCustomerReferrer, adjustCustomerBalance, getVerificationInfo, resetVerification, manualVerify } from '@/api/customers'
import { submitApproval } from '@/api/approvals'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()
const loading = ref(false)
const customer = ref(null)
const activeTab = ref('ips')

const perms = computed(() => authStore.user?.permissions || [])
const canViewVerification = computed(() => perms.value.includes('customer.view_verification'))
const canResetVerification = computed(() => perms.value.includes('customer.reset_verification'))

// Verification info
const verificationLoaded = ref(false)
const verificationInfo = ref(null)

async function loadVerificationInfo() {
  try {
    const res = await getVerificationInfo(route.params.id)
    verificationInfo.value = res?.verified_type ? res : null
    verificationLoaded.value = true
  } catch {}
}

async function handleResetVerification() {
  try {
    await ElMessageBox.confirm(
      `确认重置「${customer.value.customer_name}」的实名认证？重置后该客户需重新进行实名认证。`,
      '重置实名认证',
      { type: 'warning', confirmButtonText: '确认重置', confirmButtonClass: 'el-button--danger' }
    )
  } catch { return }
  try {
    await resetVerification(customer.value.id)
    ElMessage.success('实名认证已重置')
    verificationInfo.value = null
    verificationLoaded.value = false
    fetchData()
  } catch {}
}

// Manual verify
const manualVerifyVisible = ref(false)
const manualVerifying = ref(false)
const manualVerifyForm = reactive({
  verified_type: 'personal',
  verified_name: '',
  verified_id_number: '',
  verified_enterprise_name: '',
  verified_credit_code: '',
})

function openManualVerify() {
  manualVerifyForm.verified_type = 'personal'
  manualVerifyForm.verified_name = ''
  manualVerifyForm.verified_id_number = ''
  manualVerifyForm.verified_enterprise_name = ''
  manualVerifyForm.verified_credit_code = ''
  manualVerifyVisible.value = true
}

async function submitManualVerify() {
  if (!manualVerifyForm.verified_name || !manualVerifyForm.verified_id_number) {
    ElMessage.warning('请填写姓名和身份证号')
    return
  }
  manualVerifying.value = true
  try {
    await manualVerify(customer.value.id, { ...manualVerifyForm })
    ElMessage.success('手动认证成功')
    manualVerifyVisible.value = false
    verificationLoaded.value = false
    fetchData()
  } catch {}
  finally { manualVerifying.value = false }
}

function getSubDurationMonths(row) {
  const d = Number(row.duration || 1), u = Number(row.unit || 3)
  if (u === 1) return Math.max(1, Math.ceil(d / 30))
  if (u === 2) return Math.max(1, Math.ceil(d * 7 / 30))
  if (u === 4) return d * 12
  return d
}
function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function formatDateTime(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function ipStatusTag(s) { return { available: 'success', assigned: 'warning', expired: 'danger', disabled: 'info' }[s] || 'info' }
function ipStatusLabel(s) { return { available: '可用', assigned: '已分配', expired: '已过期', disabled: '已停用' }[s] || s }
function subStatusTag(s) { return { active: 'success', expired: 'danger', cancelled: 'info' }[s] || 'info' }
function subStatusLabel(s) { return { active: '活跃', expired: '已过期', cancelled: '已取消' }[s] || s }
function txTag(t) { return { topup: 'success', deduction: 'danger', refund: 'warning', adjustment_in: 'success', adjustment_out: 'danger', withdrawal: 'warning', purchase: 'danger', subscription_renew: 'danger' }[t] || 'info' }
function txLabel(t) { return { topup: '充值', deduction: '扣费', refund: '退款', adjustment: '调整', adjustment_in: '手动增加', adjustment_out: '手动扣除', withdrawal: '私下退款', purchase: '购买', subscription_renew: '续费' }[t] || t }

const chainReferrer = computed(() => customer.value?.referral_chain?.find(c => c.role === 'referrer'))
const effectiveSales = computed(() => customer.value?.referral_chain?.find(c => c.role === 'sales_direct' || c.role === 'sales_indirect'))
const hasChainData = computed(() => chainReferrer.value || effectiveSales.value || customer.value?.referral_count > 0)

async function fetchData() {
  loading.value = true
  try {
    const res = await getCustomer(route.params.id)
    customer.value = res
  } catch { /* handled */ }
  finally { loading.value = false }
}

// Balance Adjust
const adjustVisible = ref(false)
const adjustLoading = ref(false)
const adjustForm = reactive({ action: 'increase', amount: 100, reason: '', remark: '' })

function openAdjustBalance(action) {
  adjustForm.action = action
  adjustForm.amount = 100
  adjustForm.reason = ''
  adjustForm.remark = ''
  adjustVisible.value = true
}

async function submitAdjust() {
  if (!adjustForm.amount || adjustForm.amount <= 0) {
    ElMessage.warning('请输入金额')
    return
  }
  if (!adjustForm.reason) {
    ElMessage.warning('请选择原因')
    return
  }
  adjustLoading.value = true
  try {
    await adjustCustomerBalance(customer.value.id, { ...adjustForm })
    ElMessage.success(adjustForm.action === 'increase' ? '增加成功' : '扣除成功')
    adjustVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { adjustLoading.value = false }
}

// Reset Password
const resetPasswordVisible = ref(false)
const resetPasswordLoading = ref(false)
const resetPasswordForm = reactive({ password: '' })
const resetResult = ref('')

function openResetPassword() {
  resetPasswordForm.password = ''
  resetResult.value = ''
  resetPasswordVisible.value = true
}

function closeResetPassword() {
  resetPasswordVisible.value = false
  resetPasswordForm.password = ''
  resetResult.value = ''
}

async function submitResetPassword() {
  if (resetPasswordForm.password && resetPasswordForm.password.length < 6) {
    ElMessage.warning('密码至少 6 位')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确认重置「${customer.value.customer_name}」的登录密码？该客户的所有会话将被踢出。`,
      '重置确认',
      { type: 'warning' }
    )
  } catch { return }

  resetPasswordLoading.value = true
  try {
    const res = await resetCustomerPassword(customer.value.id, {
      password: resetPasswordForm.password || undefined,
    })
    resetResult.value = res?.password || ''
    ElMessage.success('密码重置成功')
  } catch { /* handled */ }
  finally { resetPasswordLoading.value = false }
}

async function handleImpersonate() {
  if (!customer.value) return
  try {
    const res = await impersonateCustomer(customer.value.id)
    if (!res?.token) { ElMessage.error('未返回 token'); return }
    const url = (window.__CUSTOMER_PORTAL_URL || import.meta.env.VITE_CUSTOMER_PORTAL_URL || 'https://user.sunipip.com')
      + '/login?impersonate=' + encodeURIComponent(res.token)
      + '&name=' + encodeURIComponent(res.customer_name || '')
    window.open(url, '_blank')
    ElMessage.success(`已在新标签页打开「${res.customer_name}」的客户面板`)
  } catch { /* handled */ }
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制到剪贴板')
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
}

// ===== 推荐人绑定 =====
const referrerDialogVisible = ref(false)
const referrerLoading = ref(false)
const referrerCode = ref('')
const referrerPreview = ref(null)

async function previewReferrer() {
  if (!referrerCode.value.trim()) { ElMessage.warning('请输入邀请码'); return }
  referrerLoading.value = true
  try {
    referrerPreview.value = await setCustomerReferrer(customer.value.id, {
      referral_code: referrerCode.value.trim(),
      confirm: false,
    })
  } catch { /* handled */ }
  finally { referrerLoading.value = false }
}

async function confirmReferrer() {
  referrerLoading.value = true
  try {
    await setCustomerReferrer(customer.value.id, {
      referral_code: referrerCode.value.trim(),
      confirm: true,
    })
    ElMessage.success('推荐人已绑定，追溯返佣已处理')
    referrerDialogVisible.value = false
    referrerPreview.value = null
    referrerCode.value = ''
    fetchData()
  } catch { /* handled */ }
  finally { referrerLoading.value = false }
}

async function handleClearReferrer() {
  try {
    await ElMessageBox.confirm(
      `确认清除「${customer.value.customer_name}」的推荐人绑定？已发放的返佣不会回收。`,
      '清除推荐人',
      { type: 'warning' }
    )
  } catch { return }
  try {
    await clearCustomerReferrer(customer.value.id)
    ElMessage.success('已清除推荐人')
    fetchData()
  } catch {}
}

// ===== 划转推荐人 =====
const transferReferrerVisible = ref(false)
const transferLoading = ref(false)
const transferSearching = ref(false)
const transferSearch = ref('')
const transferSearchResults = ref([])
const transferNewReferrerId = ref(null)
const transferPreview = ref(null)

function openTransferReferrer() {
  resetTransferReferrer()
  transferReferrerVisible.value = true
}

function resetTransferReferrer() {
  transferSearch.value = ''
  transferSearchResults.value = []
  transferNewReferrerId.value = null
  transferPreview.value = null
}

async function searchTransferTarget() {
  const keyword = transferSearch.value.trim()
  if (!keyword) { ElMessage.warning('请输入客户ID或客户名'); return }

  transferSearching.value = true
  transferPreview.value = null
  transferNewReferrerId.value = null
  try {
    const { getCustomers } = await import('@/api/customers')
    const res = await getCustomers({ 'filter[keyword]': keyword, per_page: 10 })
    const list = (res?.items || res?.data || res || []).filter(c =>
      c.id !== customer.value.id && c.id !== customer.value.referred_by_customer
    )
    transferSearchResults.value = list
    if (list.length === 0) {
      ElMessage.info('未找到匹配的客户')
    } else if (list.length === 1) {
      // 自动选中唯一结果
      transferNewReferrerId.value = list[0].id
      handleTransferTargetSelected(list[0].id)
    }
  } catch { /* handled */ }
  finally { transferSearching.value = false }
}

async function handleTransferTargetSelected(newReferrerId) {
  if (!newReferrerId) return
  transferLoading.value = true
  try {
    transferPreview.value = await transferCustomerReferrer(customer.value.id, {
      new_referrer_id: newReferrerId,
      confirm: false,
    })
  } catch { /* handled */ }
  finally { transferLoading.value = false }
}

async function confirmTransferReferrer() {
  if (!transferPreview.value) return
  const p = transferPreview.value
  const msg = `确认将「${p.customer.customer_name}」的推荐人从「${p.old_referrer.customer_name}」划转到「${p.new_referrer.customer_name}」？` +
    (p.credited_amount > 0 ? `\n已发放的 ¥${Number(p.credited_amount).toFixed(2)} 佣金将从「${p.old_referrer.customer_name}」的推广余额转移到「${p.new_referrer.customer_name}」。` : '')

  try {
    await ElMessageBox.confirm(msg, '确认划转', { type: 'warning', confirmButtonText: '确认划转' })
  } catch { return }

  transferLoading.value = true
  try {
    await transferCustomerReferrer(customer.value.id, {
      new_referrer_id: transferNewReferrerId.value,
      confirm: true,
    })
    ElMessage.success('推荐人划转成功')
    transferReferrerVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { transferLoading.value = false }
}

// ===== 中转认证 =====
async function submitCertification() {
  try {
    await ElMessageBox.confirm(
      `为客户「${customer.value.customer_name}」提交中转认证申请？\n审批通过后客户可自助使用中转服务。`,
      '提交中转认证',
      { type: 'info', confirmButtonText: '提交' }
    )
  } catch { return }

  try {
    await submitApproval({
      type: 'certification',
      customer_id: customer.value.id,
      order_data: {
        company_name: customer.value.company_name || '',
        business_license: customer.value.business_license || '',
        remark: '销售提交中转认证',
      },
    })
    ElMessage.success('认证申请已提交，等待审批')
  } catch {}
}

async function toggleCertification(value) {
  const action = value ? '开启' : '撤销'
  try {
    await ElMessageBox.confirm(`确认${action}「${customer.value.customer_name}」的中转认证？`, '确认', { type: 'warning' })
    await updateCustomer(customer.value.id, { forward_certified: value })
    ElMessage.success(`已${action}中转认证`)
    fetchData()
  } catch {}
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.customer-detail {
  .page-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .info-card {
    margin-bottom: 16px;
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 600;
      .el-icon { margin-right: 6px; vertical-align: middle; }
    }
  }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 13px; }

  .referral-chain-card {
    margin-top: 16px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #FAFBFC 0%, #F0F2F5 100%);
    border-radius: 8px;
    border: 1px solid #EBEEF5;

    .chain-title {
      font-size: 13px;
      font-weight: 600;
      color: #606266;
      margin-bottom: 14px;
    }

    .chain-diagram {
      display: flex;
      align-items: center;
      gap: 4px;
      flex-wrap: wrap;
    }

    .chain-node {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 8px;
      border: 1.5px solid;
      background: #fff;
      min-width: 120px;

      &--sales { border-color: #E6A23C; }
      &--referrer { border-color: #67C23A; }
      &--current { border-color: #409EFF; background: #ECF5FF; }
      &--downstream { border-color: #909399; border-style: dashed; }

      &__icon {
        flex-shrink: 0;
        width: 28px; height: 28px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 50%;
        background: #F5F7FA;
      }

      &__info { min-width: 0; }
      &__label { font-size: 11px; color: #909399; line-height: 1; margin-bottom: 3px; }
      &__name { font-size: 13px; font-weight: 600; color: #303133; white-space: nowrap; }
    }

    .chain-arrow {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0;

      &__label { font-size: 10px; color: #C0C4CC; white-space: nowrap; margin-top: -2px; }
    }

    .referral-list {
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px dashed #DCDFE6;

      &__title { font-size: 12px; color: #909399; margin-bottom: 6px; }
      &__items { display: flex; flex-wrap: wrap; }
    }
  }

  .animate-fade-in {
    animation: chainFadeIn 0.4s ease-out both;
  }
}

@keyframes chainFadeIn {
  from { opacity: 0; transform: translateX(-10px); }
  to { opacity: 1; transform: translateX(0); }
}
</style>
