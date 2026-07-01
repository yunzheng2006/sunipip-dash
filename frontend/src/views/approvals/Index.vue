<template>
  <div class="approval-page">
    <div class="page-header">
      <h2 class="page-title">审批中心</h2>
    </div>

    <!-- Stats -->
    <el-row :gutter="16" style="margin-bottom: 20px">
      <el-col :span="6" v-for="s in statCards" :key="s.key">
        <div class="stat-card" :class="s.theme" @click="filterByStatus(s.status)">
          <div class="stat-num">{{ stats[s.key] ?? 0 }}</div>
          <div class="stat-label">{{ s.label }}</div>
        </div>
      </el-col>
    </el-row>

    <!-- Filter -->
    <el-card class="search-card">
      <el-form :inline="true">
        <el-form-item label="状态">
          <el-select v-model="searchStatus" placeholder="全部" clearable style="width: 130px" @change="fetchData">
            <el-option label="待审批" value="pending" />
            <el-option label="已批准" value="approved" />
            <el-option label="已执行" value="executed" />
            <el-option label="已驳回" value="rejected" />
            <el-option label="已取消" value="cancelled" />
          </el-select>
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="searchType" placeholder="全部" clearable style="width: 130px" @change="fetchData">
            <el-option label="开通订单" value="provision" />
            <el-option label="中转认证" value="certification" />
            <el-option label="特批价" value="custom_price" />
            <el-option label="赎回IP" value="redeem" />
            <el-option label="提现" value="withdraw" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button @click="searchStatus = ''; searchType = ''; fetchData()">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Table -->
    <el-card>
      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="order_no" label="审批单号" width="180">
          <template #default="{ row }"><span class="mono">{{ row.order_no }}</span></template>
        </el-table-column>
        <el-table-column label="类型" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="typeTagStyle(row.type)" size="small">{{ typeLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交人" width="100">
          <template #default="{ row }">{{ row.submitter?.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="客户" width="150">
          <template #default="{ row }">
            <div class="cell-main">{{ row.customer?.customer_name || '-' }}</div>
            <div class="cell-sub" v-if="row.customer?.sales_person">归属: {{ row.customer.sales_person }}</div>
            <div class="cell-sub" v-if="row.customer?.phone">{{ row.customer.phone }}</div>
          </template>
        </el-table-column>
        <el-table-column label="详细内容" min-width="320">
          <template #default="{ row }">
            <!-- 开通订单 -->
            <template v-if="row.type === 'provision'">
              <div class="content-main">
                <span class="content-highlight">{{ row.order_data?.country_cn || row.order_data?.country_code }}</span>
                {{ row.order_data?.product_name }}
              </div>
              <div class="content-meta">
                {{ row.order_data?.quantity || 1 }}条 · {{ row.order_data?.duration }}{{ unitLabel(row.order_data?.unit) }} · ¥{{ row.order_data?.sale_price }}/条/月
                · <span class="text-red">合计 ¥{{ row.total_amount }}</span>
              </div>
              <div class="content-tags">
                <el-tag v-if="row.order_data?.auto_renew" size="small" type="info" class="mini-tag">自动续费</el-tag>
                <el-tag v-if="row.order_data?.forward" size="small" type="warning" class="mini-tag">含转发 ¥{{ row.order_data.forward.forward_fee }}/月</el-tag>
                <el-tag v-if="row.order_data?.cidr_blocks?.length" size="small" type="" class="mini-tag">指定段 {{ row.order_data.cidr_blocks.map(b => b.cidr).join(', ') }}</el-tag>
              </div>
            </template>

            <!-- 中转认证 -->
            <template v-else-if="row.type === 'certification'">
              <div class="content-main">企业中转认证申请</div>
              <div class="content-meta">
                <template v-if="row.order_data?.company_name">公司: {{ row.order_data.company_name }}</template>
                <template v-if="row.order_data?.business_license"> · 执照: {{ row.order_data.business_license }}</template>
              </div>
              <div class="content-meta" v-if="row.customer?.verified_name">实名: {{ row.customer.verified_name }}</div>
              <div class="content-extra" v-if="row.order_data?.remark">{{ row.order_data.remark }}</div>
            </template>

            <!-- 特批价 -->
            <template v-else-if="row.type === 'custom_price'">
              <div class="content-main">特批价格申请</div>
              <div class="content-meta">
                地区: {{ row.order_data?.country_code || '-' }}
                <template v-if="row.order_data?.product_id"> · 产品: {{ row.order_data.product_id }}</template>
                · <span class="text-red">特批价 ¥{{ row.order_data?.special_price }}/月</span>
              </div>
              <div class="content-extra" v-if="row.order_data?.remark">{{ row.order_data.remark }}</div>
            </template>

            <!-- 赎回IP -->
            <template v-else-if="row.type === 'redeem'">
              <div class="content-main">
                赎回过期IP
                <span class="mono" v-if="row.order_data?.ip_address">{{ row.order_data.ip_address }}</span>
              </div>
              <div class="content-meta">
                {{ row.order_data?.country_name }} · 订阅 #{{ row.order_data?.subscription_id }}
                <template v-if="row.order_data?.original_price"> · 原价 ¥{{ row.order_data.original_price }}/月</template>
              </div>
              <div class="content-meta" v-if="row.order_data?.expires_at">
                过期: {{ formatDate(row.order_data.expires_at) }}
                <template v-if="row.order_data?.grace_days_left != null">
                  · 剩余宽限 <span :class="row.order_data.grace_days_left < 1 ? 'text-red' : 'text-orange'">{{ Math.round(row.order_data.grace_days_left * 10) / 10 }}天</span>
                </template>
              </div>
            </template>

            <!-- 提现 -->
            <template v-else-if="row.type === 'withdraw'">
              <div class="content-main">返佣提现申请</div>
              <div class="content-meta">
                <span class="text-red">提现 ¥{{ row.total_amount }}</span>
                <template v-if="row.order_data?.fee"> · 手续费 ¥{{ row.order_data.fee }} · 到账 ¥{{ row.order_data.actual_amount }}</template>
              </div>
              <div class="content-meta" v-if="row.order_data?.account_holder || row.customer?.withdraw_account_holder">
                {{ row.order_data?.account_holder || row.customer?.withdraw_account_holder }}
                · {{ row.order_data?.bank_name || row.customer?.withdraw_bank_name }}
              </div>
            </template>

            <!-- 其他 -->
            <template v-else>
              <div class="content-main">{{ row.type || '未知类型' }}</div>
            </template>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="审批人" width="100">
          <template #default="{ row }">{{ row.reviewer?.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="提交时间" width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" align="center" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <el-button v-if="canReview" type="success" link size="small" @click="openReview(row, 'approve')">批准</el-button>
              <el-button v-if="canReview" type="danger" link size="small" @click="openReview(row, 'reject')">驳回</el-button>
              <el-button v-if="row.submitted_by === currentUserId" type="info" link size="small" @click="handleCancel(row)">取消</el-button>
            </template>
            <el-button type="primary" link size="small" @click="openDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination v-model:current-page="pagination.page" v-model:page-size="pagination.per_page"
          :total="pagination.total" :page-sizes="[20, 50]" layout="total, sizes, prev, pager, next"
          @size-change="fetchData" @current-change="fetchData" />
      </div>
    </el-card>

    <!-- ========== Review Dialog ========== -->
    <el-dialog v-model="reviewVisible" :title="reviewAction === 'approve' ? '批准审批' : '驳回审批'" width="680px" :close-on-click-modal="false">
      <div v-if="reviewTarget" class="review-info">

        <!-- Provision review -->
        <template v-if="reviewTarget.type === 'provision'">
          <el-descriptions :column="2" size="small" border>
            <el-descriptions-item label="客户">{{ reviewTarget.customer?.customer_name }}</el-descriptions-item>
            <el-descriptions-item label="客户余额">¥{{ reviewTarget.customer?.balance ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="产品">{{ reviewTarget.order_data?.product_name || reviewTarget.order_data?.country_cn }}</el-descriptions-item>
            <el-descriptions-item label="数量">{{ reviewTarget.order_data?.quantity || 1 }} 条</el-descriptions-item>
            <el-descriptions-item label="售价">¥{{ reviewTarget.order_data?.sale_price }}/条/月</el-descriptions-item>
            <el-descriptions-item label="时长">{{ reviewTarget.order_data?.duration }} {{ unitLabel(reviewTarget.order_data?.unit) }}</el-descriptions-item>
            <el-descriptions-item label="总金额"><span class="text-red text-bold">¥{{ reviewTarget.total_amount }}</span></el-descriptions-item>
            <el-descriptions-item label="自动续费">{{ reviewTarget.order_data?.auto_renew ? '是' : '否' }}</el-descriptions-item>
            <el-descriptions-item v-if="reviewTarget.order_data?.forward" label="转发" :span="2">
              设备组#{{ reviewTarget.order_data.forward.device_group_id }} · ¥{{ reviewTarget.order_data.forward.forward_fee }}/月 · {{ reviewTarget.order_data.forward.speed_limit_mbps }}Mbps
            </el-descriptions-item>
            <el-descriptions-item v-if="reviewTarget.order_data?.cidr_blocks?.length" label="指定段" :span="2">
              {{ reviewTarget.order_data.cidr_blocks.map(b => `${b.cidr} ×${b.count}`).join('，') }}
            </el-descriptions-item>
            <el-descriptions-item label="归属销售">{{ reviewTarget.customer?.sales_person || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提交人">{{ reviewTarget.submitter?.name }}</el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- Certification review -->
        <template v-else-if="reviewTarget.type === 'certification'">
          <el-descriptions :column="2" size="small" border>
            <el-descriptions-item label="客户">{{ reviewTarget.customer?.customer_name }}</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ reviewTarget.customer?.phone || '-' }}</el-descriptions-item>
            <el-descriptions-item label="实名认证">{{ reviewTarget.customer?.verified_name || '未认证' }}</el-descriptions-item>
            <el-descriptions-item label="当前认证"><el-tag :type="reviewTarget.customer?.forward_certified ? 'success' : 'info'" size="small">{{ reviewTarget.customer?.forward_certified ? '已认证' : '未认证' }}</el-tag></el-descriptions-item>
            <el-descriptions-item label="公司名称" :span="2">{{ reviewTarget.order_data?.company_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="营业执照" :span="2">{{ reviewTarget.order_data?.business_license || '-' }}</el-descriptions-item>
            <el-descriptions-item label="归属销售">{{ reviewTarget.customer?.sales_person || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提交人">{{ reviewTarget.submitter?.name }}</el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- Custom Price review -->
        <template v-else-if="reviewTarget.type === 'custom_price'">
          <el-descriptions :column="2" size="small" border>
            <el-descriptions-item label="客户">{{ reviewTarget.customer?.customer_name }}</el-descriptions-item>
            <el-descriptions-item label="客户余额">¥{{ reviewTarget.customer?.balance ?? '-' }}</el-descriptions-item>
            <el-descriptions-item label="地区">{{ reviewTarget.order_data?.country_code || '-' }}</el-descriptions-item>
            <el-descriptions-item label="产品ID">{{ reviewTarget.order_data?.product_id || '全部产品' }}</el-descriptions-item>
            <el-descriptions-item label="特批价格"><span class="text-red text-bold">¥{{ reviewTarget.order_data?.special_price }}/月</span></el-descriptions-item>
            <el-descriptions-item label="归属销售">{{ reviewTarget.customer?.sales_person || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提交人">{{ reviewTarget.submitter?.name }}</el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- Redeem review -->
        <template v-else-if="reviewTarget.type === 'redeem'">
          <el-descriptions :column="2" size="small" border>
            <el-descriptions-item label="客户">{{ reviewTarget.customer?.customer_name }}</el-descriptions-item>
            <el-descriptions-item label="IP地址"><span class="mono">{{ reviewTarget.order_data?.ip_address || '-' }}</span></el-descriptions-item>
            <el-descriptions-item label="国家">{{ reviewTarget.order_data?.country_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="原价">¥{{ reviewTarget.order_data?.original_price || '-' }}/月</el-descriptions-item>
            <el-descriptions-item label="过期时间">{{ formatDate(reviewTarget.order_data?.expires_at) }}</el-descriptions-item>
            <el-descriptions-item label="宽限剩余">
              <span v-if="reviewTarget.order_data?.grace_days_left != null" :class="reviewTarget.order_data.grace_days_left < 1 ? 'text-red' : 'text-orange'">
                {{ Math.round(reviewTarget.order_data.grace_days_left * 10) / 10 }}天
              </span>
              <span v-else>-</span>
            </el-descriptions-item>
            <el-descriptions-item label="归属销售">{{ reviewTarget.customer?.sales_person || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提交人">{{ reviewTarget.submitter?.name }}</el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- Withdraw review — 核心：银行信息 -->
        <template v-else-if="reviewTarget.type === 'withdraw'">
          <el-descriptions :column="2" size="small" border>
            <el-descriptions-item label="客户">{{ reviewTarget.customer?.customer_name }}</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ reviewTarget.customer?.phone || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提现金额"><span class="text-red text-bold text-lg">¥{{ reviewTarget.total_amount }}</span></el-descriptions-item>
            <el-descriptions-item label="返佣余额">¥{{ reviewTarget.customer?.commission_balance ?? '-' }}</el-descriptions-item>
          </el-descriptions>
          <div class="bank-card">
            <div class="bank-card-title">收款信息</div>
            <div class="bank-card-row"><span class="bank-label">开户人</span><span class="bank-value">{{ reviewTarget.order_data?.account_holder || reviewTarget.customer?.withdraw_account_holder || '-' }}</span></div>
            <div class="bank-card-row"><span class="bank-label">开户行</span><span class="bank-value">{{ reviewTarget.order_data?.bank_name || reviewTarget.customer?.withdraw_bank_name || '-' }}</span></div>
            <div class="bank-card-row"><span class="bank-label">银行账号</span><span class="bank-value mono">{{ formatBankAccount(reviewTarget.order_data?.bank_account || reviewTarget.customer?.withdraw_bank_account) }}</span></div>
            <div class="bank-card-row" v-if="reviewTarget.customer?.verified_name"><span class="bank-label">实名姓名</span><span class="bank-value">{{ reviewTarget.customer.verified_name }}</span></div>
            <div class="bank-card-row" v-if="reviewTarget.order_data?.commission_balance_snapshot != null"><span class="bank-label">申请时余额</span><span class="bank-value">¥{{ reviewTarget.order_data.commission_balance_snapshot }}</span></div>
          </div>
          <el-descriptions :column="2" size="small" border style="margin-top: 10px">
            <el-descriptions-item v-if="reviewTarget.order_data?.fee" label="手续费">¥{{ reviewTarget.order_data.fee }}</el-descriptions-item>
            <el-descriptions-item v-if="reviewTarget.order_data?.actual_amount" label="实际到账">¥{{ reviewTarget.order_data.actual_amount }}</el-descriptions-item>
            <el-descriptions-item label="归属销售">{{ reviewTarget.customer?.sales_person || '-' }}</el-descriptions-item>
            <el-descriptions-item label="提交人">{{ reviewTarget.submitter?.name }}</el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- Remark -->
        <div v-if="reviewTarget.order_data?.remark" class="detail-remark" style="margin-top: 10px">
          <strong>备注：</strong>{{ reviewTarget.order_data.remark }}
        </div>
      </div>
      <el-form style="margin-top: 16px">
        <el-form-item :label="reviewAction === 'reject' ? '驳回原因（必填）' : '备注（选填）'">
          <el-input v-model="reviewComment" type="textarea" :rows="3" :placeholder="reviewAction === 'reject' ? '请填写驳回原因' : '选填'" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reviewVisible = false">取消</el-button>
        <el-button :type="reviewAction === 'approve' ? 'success' : 'danger'" :loading="reviewing" @click="submitReview">
          {{ reviewAction === 'approve' ? '确认批准' : '确认驳回' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- ========== Detail Dialog ========== -->
    <el-dialog v-model="detailVisible" title="审批详情" width="780px" top="5vh">
      <div v-if="detailData" v-loading="detailLoading">

        <!-- Section: 基本信息 -->
        <div class="detail-section-title">基本信息</div>
        <el-descriptions :column="2" border size="small" class="detail-section">
          <el-descriptions-item label="审批单号"><span class="mono">{{ detailData.order_no }}</span></el-descriptions-item>
          <el-descriptions-item label="类型"><el-tag :type="typeTagStyle(detailData.type)" size="small">{{ typeLabel(detailData.type) }}</el-tag></el-descriptions-item>
          <el-descriptions-item label="状态"><el-tag :type="statusType(detailData.status)" size="small">{{ statusLabel(detailData.status) }}</el-tag></el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ formatDate(detailData.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="提交人">{{ detailData.submitter?.name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="归属销售">{{ detailData.customer?.sales_person || '-' }}</el-descriptions-item>
        </el-descriptions>

        <!-- Section: 客户信息 -->
        <div class="detail-section-title">客户信息</div>
        <el-descriptions :column="2" border size="small" class="detail-section">
          <el-descriptions-item label="客户名称">{{ detailData.customer?.customer_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="手机号">{{ detailData.customer?.phone || '-' }}</el-descriptions-item>
          <el-descriptions-item label="邮箱">{{ detailData.customer?.email || '-' }}</el-descriptions-item>
          <el-descriptions-item label="实名认证">
            <template v-if="detailData.customer?.verified_name">
              {{ detailData.customer.verified_name }}
              <el-tag size="small" type="success" style="margin-left:4px">{{ detailData.customer.verified_type === 'enterprise' ? '企业' : '个人' }}</el-tag>
            </template>
            <span v-else class="text-muted">未认证</span>
          </el-descriptions-item>
          <el-descriptions-item label="账户余额">¥{{ detailData.customer?.balance ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="返佣余额">¥{{ detailData.customer?.commission_balance ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="累计消费">¥{{ detailData.customer?.total_spent ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="注册时间">{{ formatDate(detailData.customer?.created_at) }}</el-descriptions-item>
          <template v-if="detailData.extra?.customer_stats">
            <el-descriptions-item label="活跃订阅">{{ detailData.extra.customer_stats.active_subscriptions }} 条</el-descriptions-item>
            <el-descriptions-item label="持有IP">{{ detailData.extra.customer_stats.active_ips }} 个</el-descriptions-item>
          </template>
          <el-descriptions-item v-if="detailData.extra?.referrer" label="推荐人">{{ detailData.extra.referrer.name }} (#{{ detailData.extra.referrer.id }})</el-descriptions-item>
          <el-descriptions-item v-if="detailData.customer?.referral_code" label="推荐码">{{ detailData.customer.referral_code }}</el-descriptions-item>
        </el-descriptions>

        <!-- Section: 申请内容 (type-specific) -->
        <div class="detail-section-title">申请内容</div>

        <!-- ===== Provision detail ===== -->
        <template v-if="detailData.type === 'provision'">
          <el-descriptions :column="2" border size="small" class="detail-section">
            <el-descriptions-item label="产品名称">{{ detailData.order_data?.product_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="产品ID"><span class="mono text-xs">{{ detailData.order_data?.product_id || '-' }}</span></el-descriptions-item>
            <el-descriptions-item label="地区">{{ detailData.order_data?.country_cn || detailData.order_data?.country_code || '-' }}</el-descriptions-item>
            <el-descriptions-item label="数量">{{ detailData.order_data?.quantity || 1 }} 条</el-descriptions-item>
            <el-descriptions-item label="售价">¥{{ detailData.order_data?.sale_price }}/条/月</el-descriptions-item>
            <el-descriptions-item label="时长">{{ detailData.order_data?.duration }} {{ unitLabel(detailData.order_data?.unit) }}</el-descriptions-item>
            <el-descriptions-item label="总金额"><span class="text-red text-bold">¥{{ detailData.total_amount }}</span></el-descriptions-item>
            <el-descriptions-item label="自动续费">{{ detailData.order_data?.auto_renew ? '是' : '否' }}</el-descriptions-item>
          </el-descriptions>

          <!-- 价格对比 -->
          <div v-if="detailData.extra?.product_detail" class="info-card">
            <div class="info-card-title">价格对比</div>
            <div class="price-flow">
              <div class="price-step">
                <div class="price-step-label">成本价</div>
                <div class="price-step-value">¥{{ detailData.extra.product_detail.cost_price ?? '-' }}</div>
              </div>
              <div class="price-arrow">&rarr;</div>
              <div class="price-step">
                <div class="price-step-label">系统原价</div>
                <div class="price-step-value">¥{{ detailData.extra.product_detail.list_price ?? '-' }}</div>
              </div>
              <div class="price-arrow">&rarr;</div>
              <div class="price-step highlight">
                <div class="price-step-label">本单售价</div>
                <div class="price-step-value">¥{{ detailData.order_data?.sale_price }}</div>
              </div>
            </div>
            <div class="price-note" v-if="detailData.extra.product_detail.list_price && detailData.order_data?.sale_price">
              售价为原价的 {{ Math.round(detailData.order_data.sale_price / detailData.extra.product_detail.list_price * 100) }}%
              <template v-if="detailData.order_data.sale_price < detailData.extra.product_detail.list_price">
                (低于原价 {{ Math.round((1 - detailData.order_data.sale_price / detailData.extra.product_detail.list_price) * 100) }}%)
              </template>
            </div>
            <div class="info-card-meta">
              ISP: {{ detailData.extra.product_detail.isp_type || '-' }}
              <template v-if="detailData.extra.product_detail.net_type"> · 网络: {{ detailData.extra.product_detail.net_type }}</template>
              <template v-if="detailData.extra.product_detail.stock != null"> · 库存: {{ detailData.extra.product_detail.stock }}</template>
            </div>
          </div>

          <!-- 转发配置 -->
          <div v-if="detailData.order_data?.forward" class="info-card">
            <div class="info-card-title">转发配置</div>
            <el-descriptions :column="2" size="small" border>
              <el-descriptions-item label="设备组">
                {{ detailData.extra?.forward_detail?.device_group_name || `#${detailData.order_data.forward.device_group_id}` }}
              </el-descriptions-item>
              <el-descriptions-item label="转发费">¥{{ detailData.order_data.forward.forward_fee }}/月</el-descriptions-item>
              <el-descriptions-item label="限速">{{ detailData.order_data.forward.speed_limit_mbps }}Mbps</el-descriptions-item>
              <el-descriptions-item v-if="detailData.extra?.forward_detail?.connect_host" label="连接地址">
                <span class="mono text-xs">{{ detailData.extra.forward_detail.connect_host }}</span>
              </el-descriptions-item>
            </el-descriptions>
          </div>

          <!-- CIDR 段 -->
          <div v-if="detailData.order_data?.cidr_blocks?.length" class="info-card">
            <div class="info-card-title">指定网段</div>
            <div v-for="(block, i) in detailData.order_data.cidr_blocks" :key="i" class="cidr-item">
              <span class="mono">{{ block.cidr }}</span> × {{ block.count }}条
            </div>
          </div>
        </template>

        <!-- ===== Certification detail ===== -->
        <template v-else-if="detailData.type === 'certification'">
          <el-descriptions :column="2" border size="small" class="detail-section">
            <el-descriptions-item label="公司名称" :span="2">{{ detailData.order_data?.company_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="营业执照号" :span="2">{{ detailData.order_data?.business_license || '-' }}</el-descriptions-item>
            <el-descriptions-item label="当前中转状态">
              <el-tag :type="detailData.customer?.forward_certified ? 'success' : 'danger'" size="small">
                {{ detailData.customer?.forward_certified ? '已认证' : '未认证' }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item v-if="detailData.extra?.existing_forwards != null" label="现有转发规则">{{ detailData.extra.existing_forwards }} 条</el-descriptions-item>
          </el-descriptions>
          <div v-if="detailData.customer?.verified_name" class="info-card">
            <div class="info-card-title">实名信息</div>
            <el-descriptions :column="2" size="small" border>
              <el-descriptions-item label="姓名">{{ detailData.customer.verified_name }}</el-descriptions-item>
              <el-descriptions-item label="认证类型">{{ detailData.customer.verified_type === 'enterprise' ? '企业认证' : '个人认证' }}</el-descriptions-item>
              <el-descriptions-item v-if="detailData.customer.verified_id_number" label="证件号">{{ maskIdNumber(detailData.customer.verified_id_number) }}</el-descriptions-item>
              <el-descriptions-item v-if="detailData.customer.verified_enterprise_name" label="企业名称">{{ detailData.customer.verified_enterprise_name }}</el-descriptions-item>
              <el-descriptions-item v-if="detailData.customer.verified_credit_code" label="统一信用代码">{{ detailData.customer.verified_credit_code }}</el-descriptions-item>
            </el-descriptions>
          </div>
        </template>

        <!-- ===== Custom Price detail ===== -->
        <template v-else-if="detailData.type === 'custom_price'">
          <el-descriptions :column="2" border size="small" class="detail-section">
            <el-descriptions-item label="国家/地区">{{ detailData.order_data?.country_code || '-' }}</el-descriptions-item>
            <el-descriptions-item label="地区代码">{{ detailData.order_data?.area_code || '全部' }}</el-descriptions-item>
            <el-descriptions-item label="城市代码">{{ detailData.order_data?.city_code || '全部' }}</el-descriptions-item>
            <el-descriptions-item label="产品ID">{{ detailData.order_data?.product_id || '全部产品' }}</el-descriptions-item>
            <el-descriptions-item label="特批价格"><span class="text-red text-bold">¥{{ detailData.order_data?.special_price }}/月</span></el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- ===== Redeem detail ===== -->
        <template v-else-if="detailData.type === 'redeem'">
          <el-descriptions :column="2" border size="small" class="detail-section">
            <el-descriptions-item label="IP地址"><span class="mono">{{ detailData.order_data?.ip_address || '-' }}</span></el-descriptions-item>
            <el-descriptions-item label="国家">{{ detailData.order_data?.country_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="资产名">{{ detailData.order_data?.asset_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="原价">¥{{ detailData.order_data?.original_price || '-' }}/月</el-descriptions-item>
            <el-descriptions-item label="过期时间">{{ formatDate(detailData.order_data?.expires_at) }}</el-descriptions-item>
            <el-descriptions-item label="宽限剩余">
              <span v-if="detailData.order_data?.grace_days_left != null" :class="detailData.order_data.grace_days_left < 1 ? 'text-red text-bold' : 'text-orange'">
                {{ Math.round(detailData.order_data.grace_days_left * 10) / 10 }}天
              </span>
              <span v-else>-</span>
            </el-descriptions-item>
          </el-descriptions>
          <div v-if="detailData.extra?.subscription" class="info-card">
            <div class="info-card-title">订阅详情</div>
            <el-descriptions :column="2" size="small" border>
              <el-descriptions-item label="订阅ID">#{{ detailData.extra.subscription.id }}</el-descriptions-item>
              <el-descriptions-item label="状态">
                <el-tag :type="detailData.extra.subscription.status === 'active' ? 'success' : 'danger'" size="small">{{ detailData.extra.subscription.status }}</el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="订阅价格">¥{{ detailData.extra.subscription.price }}</el-descriptions-item>
              <el-descriptions-item label="销售成本">¥{{ detailData.extra.subscription.sales_cost ?? '-' }}</el-descriptions-item>
              <el-descriptions-item label="开始时间">{{ formatDate(detailData.extra.subscription.started_at) }}</el-descriptions-item>
              <el-descriptions-item label="到期时间">{{ formatDate(detailData.extra.subscription.expires_at) }}</el-descriptions-item>
              <el-descriptions-item label="已续费">{{ detailData.extra.subscription.renewed_count }}次</el-descriptions-item>
              <el-descriptions-item label="自动续费">{{ detailData.extra.subscription.auto_renew ? '是' : '否' }}</el-descriptions-item>
              <el-descriptions-item v-if="detailData.extra.subscription.proxy_ip" label="IP来源">{{ detailData.extra.subscription.proxy_ip.source_name || '-' }}</el-descriptions-item>
              <el-descriptions-item v-if="detailData.extra.subscription.proxy_ip" label="上游到期">{{ detailData.extra.subscription.proxy_ip.upstream_expires_at || '-' }}</el-descriptions-item>
            </el-descriptions>
          </div>
          <div class="info-card">
            <div class="info-card-title">Spark 实例</div>
            <el-descriptions :column="1" size="small" border>
              <el-descriptions-item label="实例ID"><span class="mono text-xs">{{ detailData.order_data?.spark_instance_id }}</span></el-descriptions-item>
            </el-descriptions>
          </div>
        </template>

        <!-- ===== Withdraw detail ===== -->
        <template v-else-if="detailData.type === 'withdraw'">
          <el-descriptions :column="2" border size="small" class="detail-section">
            <el-descriptions-item label="提现金额"><span class="text-red text-bold text-lg">¥{{ detailData.total_amount }}</span></el-descriptions-item>
            <el-descriptions-item label="当前返佣余额">¥{{ detailData.customer?.commission_balance ?? '-' }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.order_data?.commission_balance_snapshot != null" label="申请时余额">¥{{ detailData.order_data.commission_balance_snapshot }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.order_data?.fee" label="手续费">¥{{ detailData.order_data.fee }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.order_data?.actual_amount" label="实际到账">¥{{ detailData.order_data.actual_amount }}</el-descriptions-item>
          </el-descriptions>

          <!-- 收款银行信息 -->
          <div class="bank-card">
            <div class="bank-card-title">收款银行信息</div>
            <div class="bank-card-row">
              <span class="bank-label">开户人</span>
              <span class="bank-value">{{ detailData.order_data?.account_holder || detailData.customer?.withdraw_account_holder || '-' }}</span>
              <el-tag v-if="detailData.customer?.verified_name && (detailData.order_data?.account_holder || detailData.customer?.withdraw_account_holder) === detailData.customer.verified_name"
                size="small" type="success" style="margin-left:6px">与实名一致</el-tag>
              <el-tag v-else-if="detailData.customer?.verified_name" size="small" type="warning" style="margin-left:6px">与实名不一致 ({{ detailData.customer.verified_name }})</el-tag>
            </div>
            <div class="bank-card-row"><span class="bank-label">开户行</span><span class="bank-value">{{ detailData.order_data?.bank_name || detailData.customer?.withdraw_bank_name || '-' }}</span></div>
            <div class="bank-card-row"><span class="bank-label">银行账号</span><span class="bank-value mono">{{ formatBankAccount(detailData.order_data?.bank_account || detailData.customer?.withdraw_bank_account) }}</span></div>
          </div>

          <!-- 历史提现 -->
          <div v-if="detailData.extra?.recent_withdrawals?.length" class="info-card">
            <div class="info-card-title">历史提现记录 (已提现合计 ¥{{ detailData.extra.total_withdrawn }})</div>
            <el-table :data="detailData.extra.recent_withdrawals" size="small" border stripe>
              <el-table-column prop="id" label="ID" width="60" />
              <el-table-column label="金额" width="100">
                <template #default="{ row }">¥{{ row.total_amount }}</template>
              </el-table-column>
              <el-table-column label="状态" width="80">
                <template #default="{ row }"><el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag></template>
              </el-table-column>
              <el-table-column label="时间">
                <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
              </el-table-column>
            </el-table>
          </div>

          <!-- 近期佣金记录 -->
          <div v-if="detailData.extra?.commission_records?.length" class="info-card">
            <div class="info-card-title">近期佣金记录</div>
            <el-table :data="detailData.extra.commission_records" size="small" border stripe>
              <el-table-column prop="id" label="ID" width="60" />
              <el-table-column label="金额" width="100">
                <template #default="{ row }">¥{{ row.amount }}</template>
              </el-table-column>
              <el-table-column label="类型" width="80">
                <template #default="{ row }">{{ row.trigger_type === 'purchase' ? '购买' : row.trigger_type === 'renew' ? '续费' : row.trigger_type }}</template>
              </el-table-column>
              <el-table-column label="状态" width="80">
                <template #default="{ row }">
                  <el-tag :type="row.status === 'active' ? 'success' : row.status === 'reversed' ? 'danger' : 'info'" size="small">{{ row.status === 'active' ? '有效' : row.status === 'reversed' ? '已冲回' : row.status }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="时间">
                <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
              </el-table-column>
            </el-table>
          </div>
        </template>

        <!-- Remark -->
        <div v-if="detailData.order_data?.remark" class="detail-remark">
          <strong>备注：</strong>{{ detailData.order_data.remark }}
        </div>

        <!-- Section: 审批信息 -->
        <template v-if="detailData.reviewed_by">
          <div class="detail-section-title">审批信息</div>
          <el-descriptions :column="2" border size="small" class="detail-section">
            <el-descriptions-item label="审批人">{{ detailData.reviewer?.name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="审批时间">{{ formatDate(detailData.reviewed_at) }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.review_comment" label="审批备注" :span="2">{{ detailData.review_comment }}</el-descriptions-item>
          </el-descriptions>
        </template>

        <!-- Section: 执行结果 -->
        <template v-if="detailData.execution_result">
          <div class="detail-section-title">执行结果</div>
          <div v-if="detailData.execution_result.error" class="detail-section">
            <el-alert type="error" :closable="false">执行失败: {{ detailData.execution_result.error }}</el-alert>
          </div>
          <div v-else class="detail-section">
            <el-alert v-if="detailData.type === 'provision'" type="success" :closable="false">
              已开通，订阅 ID: {{ detailData.execution_result.subscription_ids?.join(', ') || '-' }}
            </el-alert>
            <el-alert v-else-if="detailData.type === 'certification'" type="success" :closable="false">中转认证已通过</el-alert>
            <el-alert v-else-if="detailData.type === 'custom_price'" type="success" :closable="false">特批价已生效</el-alert>
            <el-alert v-else-if="detailData.type === 'redeem'" type="success" :closable="false">
              已赎回，新到期: {{ formatDate(detailData.execution_result.new_sub_expires_at) }}
            </el-alert>
            <el-alert v-else-if="detailData.type === 'withdraw'" type="success" :closable="false">
              已提现 ¥{{ detailData.execution_result.withdrawn_amount }}
              <template v-if="detailData.execution_result.fee"> · 手续费 ¥{{ detailData.execution_result.fee }} · 到账 ¥{{ detailData.execution_result.actual_amount }}</template>
            </el-alert>
            <el-alert v-else type="success" :closable="false">已执行</el-alert>
          </div>
          <div v-if="detailData.executed_at" style="font-size: 12px; color: #9CA3AF; margin-top: 4px;">
            执行时间: {{ formatDate(detailData.executed_at) }}
          </div>
        </template>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import { getApprovals, getApprovalStats, approveApproval, rejectApproval, cancelApproval, getApproval } from '@/api/approvals'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const loading = ref(false)
const tableData = ref([])
const pagination = reactive({ page: 1, per_page: 20, total: 0 })
const searchStatus = ref('')
const searchType = ref('')
const stats = ref({})

const currentUserId = computed(() => authStore.user?.id)
const canReview = computed(() => {
  if (!authStore.user) return false
  if (authStore.user.roles?.includes('super_admin')) return true
  return (authStore.user?.permissions || []).includes('approval.review')
})

const statCards = [
  { key: 'pending', label: '待审批', status: 'pending', theme: 'theme-orange' },
  { key: 'executed', label: '已执行', status: 'executed', theme: 'theme-green' },
  { key: 'rejected', label: '已驳回', status: 'rejected', theme: 'theme-red' },
  { key: 'total', label: '全部', status: '', theme: 'theme-gray' },
]

function statusType(s) { return { pending: 'warning', approved: '', executed: 'success', rejected: 'danger', cancelled: 'info' }[s] || 'info' }
function statusLabel(s) { return { pending: '待审批', approved: '已批准', executed: '已执行', rejected: '已驳回', cancelled: '已取消' }[s] || s }
function typeLabel(t) { return { provision: '开通订单', certification: '中转认证', custom_price: '特批价', redeem: '赎回IP', withdraw: '提现' }[t] || t || '其他' }
function typeTagStyle(t) { return { provision: '', certification: 'success', custom_price: 'warning', redeem: 'info', withdraw: 'danger' }[t] || 'info' }
function unitLabel(u) { return { 1: '天', 2: '周', 3: '月', 4: '年' }[u] || '' }
function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function filterByStatus(s) { searchStatus.value = s; pagination.page = 1; fetchData() }

function formatBankAccount(account) {
  if (!account) return '-'
  return account.replace(/(.{4})/g, '$1 ').trim()
}

function maskIdNumber(id) {
  if (!id || id.length < 8) return id || '-'
  return id.slice(0, 4) + '****' + id.slice(-4)
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchStatus.value) params['filter[status]'] = searchStatus.value
    if (searchType.value) params['filter[type]'] = searchType.value
    const res = await getApprovals(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch {} finally { loading.value = false }
}

async function fetchStats() {
  try { stats.value = (await getApprovalStats()) || {} } catch {}
}

// Review
const reviewVisible = ref(false)
const reviewAction = ref('approve')
const reviewTarget = ref(null)
const reviewComment = ref('')
const reviewing = ref(false)

function openReview(row, action) {
  reviewTarget.value = row
  reviewAction.value = action
  reviewComment.value = ''
  reviewVisible.value = true
}

async function submitReview() {
  if (reviewAction.value === 'reject' && !reviewComment.value.trim()) {
    ElMessage.warning('请填写驳回原因'); return
  }
  reviewing.value = true
  try {
    if (reviewAction.value === 'approve') {
      await approveApproval(reviewTarget.value.id, { comment: reviewComment.value || undefined })
      ElMessage.success('已批准')
    } else {
      await rejectApproval(reviewTarget.value.id, { comment: reviewComment.value })
      ElMessage.success('已驳回')
    }
    reviewVisible.value = false; fetchData(); fetchStats()
  } catch {} finally { reviewing.value = false }
}

async function handleCancel(row) {
  try {
    await ElMessageBox.confirm('确定取消该审批单？', '确认', { type: 'warning' })
    await cancelApproval(row.id)
    ElMessage.success('已取消'); fetchData(); fetchStats()
  } catch {}
}

// Detail
const detailVisible = ref(false)
const detailData = ref(null)
const detailLoading = ref(false)

async function openDetail(row) {
  detailData.value = row
  detailVisible.value = true
  detailLoading.value = true
  try {
    detailData.value = await getApproval(row.id)
  } catch {} finally { detailLoading.value = false }
}

onMounted(() => { fetchData(); fetchStats() })
</script>

<style lang="scss" scoped>
.approval-page {
  .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .mono { font-family: 'SF Mono', Consolas, monospace; font-size: 12px; color: #4A5568; }
}

// Stat cards
.stat-card {
  padding: 16px 20px; border-radius: 10px; cursor: pointer; transition: all 0.15s; text-align: center;
  &:hover { transform: translateY(-2px); }
  .stat-num { font-size: 28px; font-weight: 800; font-family: 'SF Mono', Consolas, monospace; }
  .stat-label { font-size: 12px; margin-top: 4px; }
  &.theme-orange { background: #FFF7ED; border: 1px solid #FED7AA; .stat-num { color: #EA580C; } .stat-label { color: #9A3412; } }
  &.theme-green { background: #F0FDF4; border: 1px solid #BBF7D0; .stat-num { color: #16A34A; } .stat-label { color: #166534; } }
  &.theme-red { background: #FEF2F2; border: 1px solid #FECACA; .stat-num { color: #DC2626; } .stat-label { color: #991B1B; } }
  &.theme-gray { background: #F9FAFB; border: 1px solid #E5E7EB; .stat-num { color: #4B5563; } .stat-label { color: #6B7280; } }
}

// Table cells
.cell-main { font-weight: 500; }
.cell-sub { font-size: 11px; color: #9CA3AF; }
.content-main { font-weight: 500; color: #1F2937; font-size: 13px; .content-highlight { color: #2563EB; margin-right: 4px; } }
.content-meta { font-size: 12px; color: #6B7280; margin-top: 2px; }
.content-tags { margin-top: 3px; .mini-tag { margin-right: 4px; font-size: 10px; padding: 0 4px; height: 18px; line-height: 18px; } }
.content-extra { font-size: 12px; color: #9CA3AF; margin-top: 2px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

// Common text
.text-red { color: #DC2626; }
.text-orange { color: #EA580C; }
.text-bold { font-weight: 600; }
.text-lg { font-size: 15px; }
.text-xs { font-size: 11px; }
.text-muted { color: #D1D5DB; }

// Review dialog
.review-info { margin-bottom: 12px; }

// Bank card (for withdraw)
.bank-card {
  margin-top: 12px; padding: 14px 18px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;
  .bank-card-title { font-size: 13px; font-weight: 600; color: #92400E; margin-bottom: 10px; }
  .bank-card-row { display: flex; align-items: center; padding: 6px 0; border-bottom: 1px dashed #FDE68A;
    &:last-child { border-bottom: none; }
    .bank-label { width: 80px; font-size: 12px; color: #92400E; flex-shrink: 0; }
    .bank-value { font-size: 14px; color: #1F2937; font-weight: 500; }
  }
}

// Detail dialog
.detail-section { margin-bottom: 8px; }
.detail-section-title { font-size: 14px; font-weight: 600; color: #374151; margin: 16px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #E5E7EB; &:first-child { margin-top: 0; } }
.detail-remark { margin: 12px 0; padding: 10px 14px; background: #F9FAFB; border-radius: 6px; font-size: 13px; color: #4B5563; }

// Info card (used for sub-sections in detail)
.info-card {
  margin: 10px 0; padding: 12px 16px; background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px;
  .info-card-title { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; }
  .info-card-meta { font-size: 12px; color: #6B7280; margin-top: 8px; }
}

// Price comparison flow
.price-flow {
  display: flex; align-items: center; gap: 10px;
  .price-step { text-align: center; padding: 8px 16px; background: white; border-radius: 6px; border: 1px solid #E5E7EB;
    &.highlight { background: #FEF2F2; border-color: #FECACA;
      .price-step-value { color: #DC2626; }
    }
    .price-step-label { font-size: 11px; color: #6B7280; margin-bottom: 2px; }
    .price-step-value { font-size: 16px; font-weight: 700; color: #1F2937; font-family: 'SF Mono', Consolas, monospace; }
  }
  .price-arrow { color: #9CA3AF; font-size: 18px; }
}
.price-note { font-size: 12px; color: #EA580C; margin-top: 8px; }

.cidr-item { padding: 4px 0; font-size: 13px; }

// Mobile
@media (max-width: 768px) {
  .approval-page {
    .page-header { margin-bottom: 12px; .page-title { font-size: 17px; } }
    .el-row { margin-left: 0 !important; margin-right: 0 !important; }
    .el-col { padding-left: 4px !important; padding-right: 4px !important; max-width: 50% !important; flex: 0 0 50% !important; margin-bottom: 8px; }
    :deep(.el-table__body-wrapper) {
      .el-table__row > td.el-table__cell:nth-child(1),
      .el-table__row > td.el-table__cell:nth-child(2),
      .el-table__row > td.el-table__cell:nth-child(3),
      .el-table__row > td.el-table__cell:nth-child(7),
      .el-table__row > td.el-table__cell:nth-child(8) { display: none; }
    }
    :deep(.el-table__header-wrapper) {
      thead tr > th.el-table__cell:nth-child(1),
      thead tr > th.el-table__cell:nth-child(2),
      thead tr > th.el-table__cell:nth-child(3),
      thead tr > th.el-table__cell:nth-child(7),
      thead tr > th.el-table__cell:nth-child(8) { display: none; }
    }
    :deep(.el-table .el-button) { padding: 2px 4px !important; font-size: 12px !important; }
    .pagination-wrap { justify-content: center; }
  }
  .stat-card { padding: 10px 12px; .stat-num { font-size: 20px; } .stat-label { font-size: 11px; } }
  .price-flow { flex-wrap: wrap; gap: 6px; .price-step { padding: 6px 10px; .price-step-value { font-size: 14px; } } }
}
</style>
