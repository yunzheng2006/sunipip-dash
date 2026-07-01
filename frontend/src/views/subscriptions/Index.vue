<template>
  <div class="subscription-list">
    <h2 class="page-title">订阅管理</h2>

    <!-- Quick Actions -->
    <el-card class="quick-actions">
      <div class="quick-row">
        <div class="quick-left">
          <span class="quick-label">快捷筛选：</span>
          <el-button :type="quickFilter === 2 ? 'danger' : ''" @click="applyQuickFilter(2)">2天内到期</el-button>
          <el-button :type="quickFilter === 3 ? 'danger' : ''" @click="applyQuickFilter(3)">3天内到期</el-button>
          <el-button :type="quickFilter === 5 ? 'warning' : ''" @click="applyQuickFilter(5)">5天内到期</el-button>
          <el-button :type="quickFilter === 7 ? 'warning' : ''" @click="applyQuickFilter(7)">7天内到期</el-button>
          <el-button :type="quickFilter === 15 ? '' : ''" @click="applyQuickFilter(15)">15天内到期</el-button>
          <el-button :type="quickFilter === 30 ? '' : ''" @click="applyQuickFilter(30)">30天内到期</el-button>
          <el-button v-if="quickFilter" link @click="clearQuickFilter">清除</el-button>
        </div>
        <div class="quick-right">
          <el-button
            type="primary"
            :disabled="!selectedMainIds.length"
            @click="openBatchForward"
          >
            <el-icon><Share /></el-icon>
            NY 转发
            <el-tag v-if="selectedMainIds.length" size="small" type="primary" style="margin-left: 6px">
              {{ selectedMainIds.length }}
            </el-tag>
          </el-button>
          <el-button
            type="success"
            :disabled="!selectedMainIds.length"
            @click="openBatchXuiForward"
          >
            <el-icon><Connection /></el-icon>
            3x-ui 中转
            <el-tag v-if="selectedMainIds.length" size="small" type="success" style="margin-left: 6px">
              {{ selectedMainIds.length }}
            </el-tag>
          </el-button>
          <el-button
            type="info"
            :disabled="!selectedMainIds.length"
            @click="openBatchExpiry"
          >
            <el-icon><Calendar /></el-icon>
            批量改到期
            <el-tag v-if="selectedMainIds.length" size="small" type="info" style="margin-left: 6px">
              {{ selectedMainIds.length }}
            </el-tag>
          </el-button>
          <el-button
            :disabled="!selectedMainIds.length"
            @click="openBatchPrice"
          >
            <el-icon><Edit /></el-icon>
            批量改价
            <el-tag v-if="selectedMainIds.length" size="small" style="margin-left: 6px">
              {{ selectedMainIds.length }}
            </el-tag>
          </el-button>
          <el-button type="warning" @click="openBulkRenew">
            <el-icon><Refresh /></el-icon>
            批量续费
            <el-tag v-if="selectedMainIds.length" size="small" type="warning" style="margin-left: 6px">已选 {{ selectedMainIds.length }}</el-tag>
            <el-tag v-else-if="expiringCount" size="small" type="warning" style="margin-left: 6px">到期 {{ expiringCount }}</el-tag>
          </el-button>
          <el-button type="success" @click="$router.push('/subscriptions/create')">
            <el-icon><Plus /></el-icon>创建订单
          </el-button>
          <el-button
            type="danger"
            plain
            :disabled="!selectedMainIds.length"
            @click="openBatchRefund"
            style="margin-left: auto"
          >
            <el-icon><RefreshLeft /></el-icon>
            批量退订
            <el-tag v-if="selectedMainIds.length" size="small" type="danger" style="margin-left: 6px">
              {{ selectedMainIds.length }}
            </el-tag>
          </el-button>
        </div>
      </div>
    </el-card>

    <el-card class="search-card">
      <el-form :inline="true" :model="searchForm" @submit.prevent="handleSearch">
        <el-form-item>
          <el-input v-model="searchForm.keyword" placeholder="资产名 / IP地址" clearable :prefix-icon="Search" style="width: 200px" />
        </el-form-item>
        <el-form-item>
          <el-input v-model="searchForm.customer_name" placeholder="客户名称" clearable style="width: 140px" />
        </el-form-item>
        <el-form-item>
          <el-input v-model="searchForm.country" placeholder="地区" clearable style="width: 110px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.status" placeholder="状态" clearable style="width: 110px">
            <el-option label="活跃" value="active" />
            <el-option label="已过期" value="expired" />
            <el-option label="已退订" value="refunded" />
            <el-option label="已取消" value="cancelled" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.product_type" placeholder="产品类型" clearable style="width: 130px">
            <el-option label="静态IP" value="static" />
            <el-option label="IPLC视频专线" value="video" />
            <el-option label="IPLC直播专线(手机)" value="live_mobile" />
            <el-option label="IPLC直播专线(电脑)" value="live_pc" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.source_name" placeholder="IP来源" clearable style="width: 110px">
            <el-option label="斯帕克" value="斯帕克" />
            <el-option label="985" value="985" />
            <el-option label="ipipd" value="ipipd" />
            <el-option label="老师傅" value="老师傅" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.has_remark" placeholder="备注筛选" clearable style="width: 120px">
            <el-option label="有备注" :value="1" />
            <el-option label="无备注" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.sales_person" placeholder="业务员" clearable filterable :disabled="isSalesRole" style="width: 130px">
            <el-option label="无归属" value="__none__" />
            <el-option v-for="u in staffOptions" :key="u.id" :label="u.name" :value="u.name" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch"><el-icon><Search /></el-icon>搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="warning" plain @click="openIpFilter">
            <el-icon><Files /></el-icon>
            批量IP筛选
            <el-tag v-if="ipFilterList.length" size="small" type="warning" effect="dark" style="margin-left: 6px">{{ ipFilterList.length }}</el-tag>
          </el-button>
          <el-button v-if="ipFilterList.length" size="small" text type="danger" @click="clearIpFilter">清除IP筛选</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 批量IP筛选弹窗 -->
    <el-dialog v-model="ipFilterVisible" title="批量IP筛选" width="560px" :close-on-click-modal="false">
      <div style="color: #909399; font-size: 13px; margin-bottom: 10px">
        粘贴要筛选的 IP，一行一个。支持 IPv4 格式，会自动去重和去空行。
      </div>
      <el-input
        v-model="ipFilterText"
        type="textarea"
        :rows="12"
        placeholder="例如：&#10;1.2.3.4&#10;5.6.7.8&#10;10.20.30.40"
        resize="vertical"
        style="font-family: 'SFMono-Regular', Consolas, monospace"
      />
      <div style="margin-top: 10px; color: #606266; font-size: 13px">
        解析结果：<b style="color: #E8913A">{{ parsedIpCount }}</b> 个有效 IP
        <span v-if="parsedIpInvalidCount" style="color: #F56C6C; margin-left: 12px">
          已忽略 {{ parsedIpInvalidCount }} 个非 IP 格式
        </span>
      </div>
      <template #footer>
        <el-button @click="ipFilterVisible = false">取消</el-button>
        <el-button @click="ipFilterText = ''">清空</el-button>
        <el-button type="primary" :disabled="!parsedIpCount" @click="applyIpFilter">应用筛选 ({{ parsedIpCount }})</el-button>
      </template>
    </el-dialog>

    <el-card>
      <el-table
        :data="tableData"
        v-loading="loading"
        stripe
        ref="mainTableRef"
        @selection-change="onMainSelectionChange"
        @sort-change="onSortChange"
      >
        <el-table-column type="selection" width="42" :selectable="row => canRenew(row)" />
        <el-table-column prop="id" label="ID" width="55" sortable="custom" />
        <el-table-column label="客户" width="100">
          <template #default="{ row }">
            <span style="font-weight: 500">{{ row.customer?.customer_name || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="资产名称" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.proxy_ip?.asset_name || '-' }}</div>
            <div v-if="row.forward_rule && row.forward_rule.status === 'active'" class="forward-tag">
              <el-tag size="small" type="warning" effect="plain">
                转发 {{ row.forward_rule.device_group?.custom_connect_host || row.forward_rule.device_group?.original_connect_host }}:{{ row.forward_rule.listen_port }}
              </el-tag>
            </div>
            <el-tag v-if="row.forward_rule?.forward_plan?.module === 'video'" size="small" type="success" effect="plain" style="margin-top:2px">视频专线</el-tag>
            <el-tag v-else-if="row.forward_rule?.forward_plan?.module === 'live_mobile'" size="small" type="warning" effect="plain" style="margin-top:2px">直播-手机</el-tag>
            <el-tag v-else-if="row.forward_rule?.forward_plan?.module === 'live_pc'" size="small" type="danger" effect="plain" style="margin-top:2px">直播-电脑</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备注" width="140" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="remark-inline" @click.stop="startEditRemark(row)">
              <template v-if="editingRemarkId === row.id">
                <el-input
                  v-model="editingRemarkValue"
                  size="small"
                  placeholder="备注..."
                  @blur="saveRemark(row)"
                  @keyup.enter="$event.target.blur()"
                  :ref="el => { if (el) el.focus() }"
                />
              </template>
              <template v-else>
                <span v-if="row.remark" class="remark-text has">{{ row.remark }}</span>
                <span v-else class="remark-text empty">+ 备注</span>
              </template>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="地区" width="90">
          <template #default="{ row }">{{ row.proxy_ip?.country_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="来源" width="70">
          <template #default="{ row }">
            <el-tag size="small" type="info" effect="plain">{{ row.proxy_ip?.source_name || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="原价" width="70" align="right">
          <template #default="{ row }">
            <span style="color: #909399">{{ row.list_price != null ? `¥${Number(row.list_price).toFixed(0)}` : '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="成交价" width="75" align="right" prop="price" sortable="custom">
          <template #default="{ row }">
            <el-tooltip v-if="getDurationMonths(row) > 1" placement="top" :content="`总额 ¥${Number(row.price || 0).toFixed(0)}（${getDurationMonths(row)}个月）`">
              <span :style="{ color: row.price > 0 ? '#E8913A' : '#C0C4CC', fontWeight: 600 }">
                ¥{{ getMonthlyPrice(row).toFixed(0) }}
              </span>
            </el-tooltip>
            <span v-else :style="{ color: row.price > 0 ? '#E8913A' : '#C0C4CC', fontWeight: 600 }">
              ¥{{ Number(row.price || 0).toFixed(0) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="销售成本" width="90" align="right">
          <template #default="{ row }">
            <template v-if="row.sales_cost != null">
              <el-tooltip v-if="getForwardCost(row) > 0" placement="top">
                <template #content>
                  <div>IP软成本: ¥{{ Number(row.sales_cost).toFixed(0) }}</div>
                  <div>中转成本: ¥{{ getForwardCost(row).toFixed(0) }}</div>
                </template>
                <span style="color: #409EFF">¥{{ getTotalCost(row).toFixed(0) }}</span>
              </el-tooltip>
              <span v-else style="color: #409EFF">¥{{ Number(row.sales_cost).toFixed(0) }}</span>
            </template>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="利润" width="70" align="right">
          <template #default="{ row }">
            <span v-if="row.sales_cost != null" :style="{ color: getMonthlyPrice(row) - getTotalCost(row) >= 0 ? '#67C23A' : '#F56C6C', fontWeight: 600 }">
              ¥{{ (getMonthlyPrice(row) - getTotalCost(row)).toFixed(0) }}
            </span>
            <span v-else style="color: #C0C4CC">-</span>
          </template>
        </el-table-column>
        <el-table-column label="到期" width="95" prop="expires_at" sortable="custom">
          <template #default="{ row }">
            <span :style="{ color: isExpiringSoon(row.expires_at) ? '#F56C6C' : '' }">
              {{ formatDate(row.expires_at) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
            <el-tag v-if="row.is_test" type="warning" size="small" effect="dark" style="margin-top:2px">测试</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="70" align="center" fixed="right">
          <template #default="{ row }">
            <el-dropdown trigger="click" @command="cmd => handleRowAction(cmd, row)">
              <span class="action-trigger">操作<el-icon style="margin-left:2px"><ArrowDown /></el-icon></span>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="detail"><el-icon><View /></el-icon> 详情</el-dropdown-item>
                  <el-dropdown-item v-if="canRenew(row)" command="renew"><el-icon><Refresh /></el-icon> {{ row.status === 'expired' ? '续费激活' : '续费' }}</el-dropdown-item>
                  <el-dropdown-item v-if="row.is_test && (row.status === 'active' || row.status === 'expired')" command="convert"><el-icon><Refresh /></el-icon> 转正</el-dropdown-item>
                  <el-dropdown-item v-if="row.status === 'active' && row.has_forward" command="downgrade"><el-icon><Bottom /></el-icon> 降级</el-dropdown-item>
                  <el-dropdown-item v-if="canRefund(row)" command="refund"><el-icon><RefreshLeft /></el-icon> 退订</el-dropdown-item>
                  <el-dropdown-item v-if="canPartialRefund(row)" command="partialRefund"><el-icon><RefreshLeft /></el-icon> 部分退款</el-dropdown-item>
                  <el-dropdown-item v-if="row.status === 'active'" command="transfer" divided><el-icon><Share /></el-icon> 划转</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100, 200, 500, 1000, 2000]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="onPageSizeChange"
          @current-change="fetchData"
        />
        <div class="custom-size">
          自定义每页：
          <el-input-number
            v-model="customPageSize"
            :min="1"
            :max="5000"
            :step="50"
            size="small"
            controls-position="right"
            style="width: 130px"
          />
          <el-button size="small" type="primary" plain @click="applyCustomSize">应用</el-button>
        </div>
      </div>
    </el-card>

    <!-- Refund Dialog -->
    <el-dialog v-model="refundVisible" :title="refundTarget?.status === 'expired' ? '退订（已过期订阅）' : '退订订阅'" width="500px">
      <el-form :model="refundForm" label-width="90px">
        <el-form-item label="客户">
          <el-input :value="refundTarget?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="资产">
          <el-input :value="refundTarget?.proxy_ip?.asset_name" disabled />
        </el-form-item>
        <el-form-item label="原价">
          <el-input :value="`¥${Number(refundTarget?.price || 0).toFixed(2)}`" disabled />
        </el-form-item>
        <el-form-item label="退款到余额">
          <el-switch v-model="refundForm.refund_to_balance" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">关闭则仅退订，不退款到客户账户余额</div>
        </el-form-item>
        <el-form-item v-if="refundForm.refund_to_balance" label="退款金额">
          <el-input-number v-model="refundForm.refund_amount" :min="0" :precision="2" style="width: 100%" />
          <div style="font-size: 12px; color: #909399">默认全额退款</div>
        </el-form-item>
        <el-form-item label="退订原因">
          <el-input v-model="refundForm.reason" type="textarea" :rows="2" placeholder="选填，如客户反馈IP质量问题" />
        </el-form-item>
        <el-form-item v-if="refundTarget?.proxy_ip?.spark_instance_id || refundTarget?.proxy_ip?.ipipv_instance_id" label="释放上游">
          <el-switch v-model="refundForm.release_upstream" active-text="释放" inactive-text="不释放" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            <template v-if="refundForm.release_upstream">调用上游 API 释放 IP，IP 将变为已释放</template>
            <template v-else>不调用上游释放，IP 将回收至测试池</template>
          </div>
        </el-form-item>
        <el-form-item label="取消销售业绩">
          <el-switch v-model="refundForm.reverse_commission" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            默认取消关联的销售佣金和推荐返佣；关闭则保留业绩
          </div>
        </el-form-item>
        <el-divider />
        <el-form-item label="原路退款">
          <el-switch v-model="refundForm.gateway_refund" active-text="是" inactive-text="否" @change="onGatewayRefundToggle" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">开启后退款将同时退回客户支付宝（从余额扣除）</div>
        </el-form-item>
        <template v-if="refundForm.gateway_refund">
          <el-form-item label="支付订单">
            <el-select v-model="refundForm.gateway_order_id" placeholder="选择充值订单" style="width: 100%" :loading="gatewayOrdersLoading">
              <el-option
                v-for="o in gatewayOrders"
                :key="o.id"
                :value="o.id"
                :label="`${o.order_no} — ¥${Number(o.amount).toFixed(2)} (可退 ¥${Number(o.refundable_amount).toFixed(2)})`"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="退款金额">
            <el-input-number
              v-model="refundForm.gateway_amount"
              :min="0.01"
              :max="selectedGatewayOrder?.refundable_amount || 99999"
              :precision="2"
              style="width: 200px"
            />
          </el-form-item>
          <el-alert type="warning" :closable="false" style="margin-bottom: 12px">
            此金额将从客户余额扣除并退回支付宝。请确保退订退款到余额后，客户余额足够扣除。
          </el-alert>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="refundVisible = false">取消</el-button>
        <el-button type="warning" :loading="refundLoading" @click="submitRefund">{{ refundLoading ? '正在释放上游资源...' : '确认退订' }}</el-button>
      </template>
    </el-dialog>

    <!-- Partial Refund Dialog -->
    <el-dialog v-model="partialRefundVisible" title="部分退款（按剩余整月）" width="520px">
      <el-form label-width="100px">
        <el-form-item label="客户">
          <el-input :value="partialRefundTarget?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="资产">
          <el-input :value="partialRefundTarget?.proxy_ip?.asset_name || partialRefundTarget?.proxy_ip?.ip_address" disabled />
        </el-form-item>
        <el-form-item label="订阅总价">
          <el-input :value="`¥${Number(partialRefundTarget?.price || 0).toFixed(2)}`" disabled />
        </el-form-item>
        <el-form-item label="到期时间">
          <el-input :value="partialRefundTarget?.expires_at" disabled />
        </el-form-item>
        <el-divider content-position="left">退款计算</el-divider>
        <template v-if="partialRefundPreview">
          <el-descriptions :column="2" border size="small" style="margin-bottom: 16px">
            <el-descriptions-item label="总月数">{{ partialRefundPreview.total_months }} 个月</el-descriptions-item>
            <el-descriptions-item label="当前月">第 {{ partialRefundPreview.current_month }} 个月</el-descriptions-item>
            <el-descriptions-item label="可退月数">
              <el-tag type="warning">{{ partialRefundPreview.refundable_months }} 个月</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="月单价">¥{{ partialRefundPreview.monthly_price }}</el-descriptions-item>
            <el-descriptions-item label="退款金额" :span="2">
              <span style="font-size: 18px; font-weight: bold; color: #e6a23c">¥{{ partialRefundPreview.refund_amount }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="新到期时间" :span="2">{{ partialRefundPreview.new_expires_at }}</el-descriptions-item>
          </el-descriptions>
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
            退款将退回客户余额；IP将在当月到期后释放上游；佣金和业绩将按比例扣除（负记录）。
          </el-alert>
        </template>
        <template v-else-if="partialRefundLoading">
          <div style="text-align: center; padding: 20px"><el-icon class="is-loading" :size="20"><Refresh /></el-icon> 计算中...</div>
        </template>
        <el-form-item label="退款原因">
          <el-input v-model="partialRefundForm.reason" type="textarea" :rows="2" placeholder="选填，如IP不可用需更换" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="partialRefundVisible = false">取消</el-button>
        <el-button type="warning" :loading="partialRefundSubmitting" :disabled="!partialRefundPreview" @click="submitPartialRefund">确认部分退款</el-button>
      </template>
    </el-dialog>

    <!-- Batch Refund Dialog -->
    <el-dialog v-model="batchRefundVisible" title="批量退订" width="500px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        即将退订 <strong>{{ batchRefundIds.length }}</strong> 条订阅，此操作不可撤销。
      </el-alert>
      <el-form :model="batchRefundForm" label-width="90px">
        <el-form-item label="退款到余额">
          <el-switch v-model="batchRefundForm.refund_to_balance" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">开启则按订单原价全额退款到客户余额</div>
        </el-form-item>
        <el-form-item label="释放上游">
          <el-switch v-model="batchRefundForm.release_upstream" active-text="释放" inactive-text="不释放" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            <template v-if="batchRefundForm.release_upstream">调用上游 API 释放 IP</template>
            <template v-else>不调用上游释放，IP 回收至测试池</template>
          </div>
        </el-form-item>
        <el-form-item label="取消销售业绩">
          <el-switch v-model="batchRefundForm.reverse_commission" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            默认取消关联的销售佣金和推荐返佣
          </div>
        </el-form-item>
        <el-form-item label="退订原因">
          <el-input v-model="batchRefundForm.reason" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="batchRefundVisible = false">取消</el-button>
        <el-button type="danger" :loading="batchRefundLoading" @click="submitBatchRefund">确认批量退订</el-button>
      </template>
    </el-dialog>

    <!-- Transfer Dialog -->
    <el-dialog v-model="transferVisible" title="订阅划转" width="540px" :close-on-click-modal="false">
      <el-form :model="transferForm" label-width="100px">
        <el-form-item label="当前客户">
          <el-input :value="transferTarget?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="当前资产">
          <el-input :value="`${transferTarget?.proxy_ip?.ip_address} (${transferTarget?.proxy_ip?.asset_name || '-'})`" disabled />
        </el-form-item>
        <el-form-item label="订单金额">
          <el-input :value="`¥${Number(transferTarget?.price || 0).toFixed(2)}`" disabled />
        </el-form-item>
        <el-form-item label="目标客户" required>
          <el-select
            v-model="transferForm.target_customer_id"
            filterable remote :remote-method="searchTransferCustomers"
            :loading="transferCustomerSearching"
            placeholder="搜索目标客户"
            style="width:100%"
            @change="onTransferCustomerSelected"
          >
            <el-option v-for="c in transferCustomerOptions" :key="c.id" :label="c.customer_name" :value="c.id">
              <span>{{ c.customer_name }}</span>
              <span style="float:right;color:#909399;font-size:12px">{{ c.sales_person || '无业务员' }} | 余额 ¥{{ Number(c.balance).toFixed(2) }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="目标客户付费">
          <el-switch v-model="transferForm.charge_target" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            <template v-if="transferForm.charge_target">目标客户付费，业绩算目标客户的业务员</template>
            <template v-else>原客户已付费，业绩仍归原客户的业务员</template>
          </div>
        </el-form-item>
        <el-form-item v-if="transferForm.charge_target" label="付费方式">
          <el-radio-group v-model="transferForm.charge_method">
            <el-radio value="balance">立即扣余额</el-radio>
            <el-radio value="offline">线下已付款（不扣余额）</el-radio>
          </el-radio-group>
          <div v-if="transferForm.charge_method === 'balance'" style="font-size: 12px; color: #909399; margin-top: 4px">
            将从目标客户余额扣除 ¥{{ Number(transferTarget?.price || 0).toFixed(2) }}
          </div>
          <div v-else style="font-size: 12px; color: #909399; margin-top: 4px">
            目标客户已线下付款，系统仅划转订阅不扣余额
          </div>
        </el-form-item>
        <el-form-item v-if="transferForm.charge_target && transferForm.charge_method === 'balance'" label="退还原客户">
          <el-switch v-model="transferForm.refund_source" active-text="是" inactive-text="否" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            <template v-if="transferForm.refund_source">将 ¥{{ Number(transferTarget?.price || 0).toFixed(2) }} 退还到原客户余额</template>
            <template v-else>不退还原客户余额（适用于IP本身就是为目标客户开的场景）</template>
          </div>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="transferForm.remark" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="transferVisible = false">取消</el-button>
        <el-button type="primary" :loading="transferLoading" @click="submitTransfer">确认划转</el-button>
      </template>
    </el-dialog>

    <!-- Batch Update Price Dialog -->
    <el-dialog v-model="batchPriceVisible" title="批量修改价格" width="700px" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        修改后 <strong>下次续费</strong> 生效。如需本次续费立即生效，请先改价再续费。
      </el-alert>
      <el-form :model="batchPriceForm" label-width="80px">
        <el-form-item label="新价格" required>
          <el-input-number v-model="batchPriceForm.new_price" :min="0" :precision="2" :step="10" style="width: 200px" />
          <span style="margin-left: 8px; color: #909399; font-size: 13px">元/月</span>
        </el-form-item>
      </el-form>
      <el-table :data="batchPriceItems" max-height="400" stripe size="small" style="margin-top: 12px">
        <el-table-column label="客户" width="100">
          <template #default="{ row }">{{ row.customer?.customer_name || '-' }}</template>
        </el-table-column>
        <el-table-column label="资产" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.proxy_ip?.asset_name || row.proxy_ip?.ip_address || '-' }}</template>
        </el-table-column>
        <el-table-column label="当前月价" width="90" align="right">
          <template #default="{ row }">
            <span style="color: #909399">¥{{ getMonthlyPrice(row).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="新价格" width="90" align="right">
          <template #default>
            <span style="color: #E8913A; font-weight: 600">¥{{ Number(batchPriceForm.new_price || 0).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="差价" width="100" align="right">
          <template #default="{ row }">
            <span :style="{ color: priceDiff(row) > 0 ? '#F56C6C' : priceDiff(row) < 0 ? '#67C23A' : '#909399', fontWeight: 600 }">
              {{ priceDiff(row) > 0 ? '+' : '' }}¥{{ priceDiff(row).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="续费差价提示" min-width="180">
          <template #default="{ row }">
            <span v-if="priceDiff(row) !== 0" style="font-size: 12px; color: #606266">
              若本次续费需改价，差价 {{ priceDiff(row) > 0 ? '+' : '' }}¥{{ priceDiff(row).toFixed(2) }}/月
              <template v-if="getDurationMonths(row) > 1">
                ，{{ getDurationMonths(row) }}个月共 {{ priceDiff(row) > 0 ? '+' : '' }}¥{{ (priceDiff(row) * getDurationMonths(row)).toFixed(2) }}
              </template>
            </span>
            <span v-else style="font-size: 12px; color: #C0C4CC">价格未变</span>
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="batchPriceVisible = false">取消</el-button>
        <el-button type="primary" :loading="batchPriceLoading" @click="submitBatchPrice">
          确认修改 ({{ batchPriceItems.length }} 条)
        </el-button>
      </template>
    </el-dialog>

    <!-- Convert Test Dialog -->
    <el-dialog v-model="convertVisible" title="测试订单转正" width="500px">
      <el-alert type="success" :closable="false" show-icon style="margin-bottom: 16px">
        将测试订单转为正式订阅，取消自动回收，按指定时长开始计费。
      </el-alert>
      <el-form :model="convertForm" label-width="90px">
        <el-form-item label="客户">
          <el-input :value="convertTarget?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="资产">
          <el-input :value="convertTarget?.proxy_ip?.asset_name || convertTarget?.proxy_ip?.ip_address" disabled />
        </el-form-item>
        <el-form-item label="时长">
          <div style="display: flex; gap: 8px; width: 100%">
            <el-input-number v-model="convertForm.duration" :min="1" :max="36" style="flex: 1" />
            <span style="line-height: 32px; padding: 0 8px; color: #606266">月（30天/月）</span>
          </div>
        </el-form-item>
        <el-form-item label="单价/月">
          <el-input-number v-model="convertForm.price" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="扣款">
          <el-switch v-model="convertForm.charge_customer" active-text="从客户余额扣款" inactive-text="不扣款" />
          <div v-if="convertForm.charge_customer" style="font-size: 12px; color: #E6A23C; margin-top: 4px">
            将从客户余额扣除 ¥{{ convertTotalPrice }}（{{ convertForm.duration }}个月 × ¥{{ Number(convertForm.price || 0).toFixed(2) }}/月）
          </div>
          <div v-else style="font-size: 12px; color: #909399; margin-top: 4px">
            仅转正，不从客户余额扣款
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="convertVisible = false">取消</el-button>
        <el-button type="primary" :loading="convertLoading" @click="submitConvert">确认转正</el-button>
      </template>
    </el-dialog>

    <!-- Downgrade Dialog -->
    <el-dialog v-model="downgradeVisible" title="降级订阅（移除中转）" width="500px">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        将移除中转服务，降级为单IP。剩余中转费差价将退还到客户余额。
      </el-alert>
      <el-form :model="downgradeForm" label-width="90px">
        <el-form-item label="客户">
          <el-input :value="downgradeTarget?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="资产">
          <el-input :value="downgradeTarget?.proxy_ip?.asset_name || downgradeTarget?.proxy_ip?.ip_address" disabled />
        </el-form-item>
        <el-form-item label="当前套餐">
          <el-input :value="downgradeTarget?.forward_rule?.forward_plan?.name || '中转'" disabled />
        </el-form-item>
        <el-form-item label="中转费">
          <el-input :value="`¥${Number(downgradeTarget?.forward_rule?.forward_fee || 0).toFixed(2)}/月`" disabled />
        </el-form-item>
        <el-form-item label="到期时间">
          <el-input :value="downgradeTarget?.expires_at ? dayjs(downgradeTarget.expires_at).format('YYYY-MM-DD') : '-'" disabled />
        </el-form-item>
        <el-form-item label="剩余天数">
          <el-input :value="`${downgradeRemainingDays} 天`" disabled />
        </el-form-item>
        <el-divider />
        <el-form-item label="退差价">
          <el-switch v-model="downgradeForm.refund_to_balance" active-text="退到余额" inactive-text="不退" />
        </el-form-item>
        <el-form-item v-if="downgradeForm.refund_to_balance" label="退款金额">
          <el-input-number v-model="downgradeForm.refund_amount" :min="0" :precision="2" style="width: 100%" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            默认 = 中转费 ¥{{ Number(downgradeTarget?.forward_rule?.forward_fee || 0).toFixed(2) }} / 30天 × {{ downgradeRemainingDays }}天 = ¥{{ downgradeDefaultRefund.toFixed(2) }}
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="downgradeVisible = false">取消</el-button>
        <el-button type="warning" :loading="downgradeLoading" @click="submitDowngrade">确认降级</el-button>
      </template>
    </el-dialog>

    <!-- Renew Dialog -->
    <el-dialog v-model="renewVisible" title="续费订阅" width="480px">
      <el-form :model="renewForm" label-width="80px">
        <el-form-item label="客户">
          <el-input :value="renewTarget?.customer?.customer_name" disabled />
        </el-form-item>
        <el-form-item label="资产">
          <el-input :value="renewTarget?.proxy_ip?.asset_name" disabled />
        </el-form-item>
        <el-form-item label="续费时长">
          <el-input-number v-model="renewForm.duration" :min="1" :max="12" style="width: 100%" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            {{ renewForm.duration }} 个月（{{ renewForm.duration * 30 }} 天）
          </div>
        </el-form-item>
        <el-form-item label="月单价">
          <el-input-number v-model="renewForm.price" :min="0" :precision="2" style="width: 100%" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            每月单价（元），续费总额 <strong style="color: #E6A23C">¥{{ renewTotalPrice }}</strong>（{{ renewForm.duration }} 个月）
          </div>
        </el-form-item>
        <el-form-item label="扣费方式">
          <el-radio-group v-model="renewForm.skip_deduct">
            <el-radio :value="false">扣客户余额</el-radio>
            <el-radio :value="true">不扣余额（线下已付）</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="renewVisible = false">取消</el-button>
        <el-button type="primary" :loading="renewLoading" @click="submitRenew">
          {{ renewForm.skip_deduct ? '确认续费（不扣余额）' : `确认续费 ${renewForm.duration} 个月（扣 ¥${renewTotalPrice}）` }}
        </el-button>
      </template>
    </el-dialog>

    <!-- Bulk Renew Dialog -->
    <el-dialog
      v-model="bulkRenewVisible"
      title=""
      width="1180px"
      :close-on-click-modal="false"
      destroy-on-close
      top="3vh"
      class="bulk-renew-dialog"
    >
      <template #header>
        <div class="bulk-header">
          <div class="header-title">
            <el-icon :size="22" color="#E8913A"><Refresh /></el-icon>
            <span>一键续费</span>
          </div>
          <div class="header-sub">
            批量续费即将到期的订阅，减少重复操作
          </div>
        </div>
      </template>

      <!-- 顶部汇总卡片 -->
      <div class="bulk-summary">
        <div class="summary-item">
          <div class="label">候选订阅</div>
          <div class="value">{{ filteredBulkItems.length }}<span class="unit">条</span></div>
        </div>
        <div class="summary-divider"></div>
        <div class="summary-item">
          <div class="label">涉及客户</div>
          <div class="value">{{ uniqueCustomerCount }}<span class="unit">个</span></div>
        </div>
        <div class="summary-divider"></div>
        <div class="summary-item">
          <div class="label">已勾选</div>
          <div class="value selected">{{ selectedBulkIds.length }}<span class="unit">条</span></div>
        </div>
        <div class="summary-divider"></div>
        <div class="summary-item total">
          <div class="label">合计扣费</div>
          <div class="value highlight">¥{{ bulkTotal.toFixed(2) }}</div>
        </div>
      </div>

      <!-- 筛选 + 统一价栏 -->
      <div class="bulk-control-bar">
        <el-input
          v-model="bulkFilter"
          placeholder="搜索：客户名 / 资产名 / IP / 地区"
          clearable
          :prefix-icon="Search"
          size="default"
          style="width: 280px"
        />

        <div class="control-divider"></div>

        <div class="unified-price-group">
          <span class="label">统一价：</span>
          <el-input-number
            v-model="bulkUnifiedPrice"
            :min="0"
            :precision="2"
            placeholder="填入价格"
            size="default"
            style="width: 160px"
          />
          <el-button
            type="primary"
            size="default"
            :disabled="!bulkUnifiedPrice || !selectedBulkIds.length"
            @click="applyUnifiedPrice"
          >
            应用到勾选
          </el-button>
          <el-button
            size="default"
            link
            @click="resetBulkPrices"
          >
            重置原价
          </el-button>
        </div>

        <div class="control-divider"></div>

        <div class="duration-group">
          <span class="label">时长：</span>
          <el-input-number v-model="bulkDuration" :min="1" :max="12" size="default" style="width: 90px" />
          <span class="label" style="margin-left: 4px">个月</span>
        </div>

        <div class="control-divider"></div>

        <div class="deduct-group">
          <el-checkbox v-model="bulkSkipDeduct">不扣余额（线下已付）</el-checkbox>
        </div>
      </div>

      <!-- 客户分组列表 -->
      <div class="bulk-groups" v-loading="!bulkItems.length && bulkRenewVisible">
        <div
          v-for="group in groupedBulkItems"
          :key="group.customerId"
          class="customer-group"
        >
          <div class="group-header">
            <div class="group-left">
              <el-checkbox
                :model-value="isGroupFullySelected(group)"
                :indeterminate="isGroupPartiallySelected(group)"
                @change="toggleGroupSelection(group, $event)"
              />
              <div class="customer-info">
                <div class="customer-name">
                  {{ group.customerName }}
                  <el-tag v-if="group.salesPerson" size="small" type="info" effect="plain">
                    业务：{{ group.salesPerson }}
                  </el-tag>
                </div>
                <div class="customer-meta">
                  {{ group.items.length }} 条订阅，合计 ¥{{ group.totalCurrentPrice.toFixed(2) }} / 月
                </div>
              </div>
            </div>
            <div class="group-right">
              <div class="group-select-count" v-if="group.selectedCount > 0">
                已选 <strong>{{ group.selectedCount }}</strong> / {{ group.items.length }}
                ·
                <span class="group-charged">¥{{ group.selectedCharged.toFixed(2) }}</span>
              </div>
            </div>
          </div>

          <div class="group-items">
            <div
              v-for="item in group.items"
              :key="item.id"
              class="item-row"
              :class="{ selected: selectedBulkIds.includes(item.id) }"
              @click="toggleItem(item)"
            >
              <div class="row-check" @click.stop>
                <el-checkbox
                  :model-value="selectedBulkIds.includes(item.id)"
                  :disabled="!canRenew(item)"
                  @change="toggleItem(item)"
                />
              </div>
              <div class="row-asset">
                <div class="asset-name">{{ item.proxy_ip?.asset_name || '-' }}</div>
                <div class="asset-ip mono">{{ item.proxy_ip?.ip_address }}:{{ item.proxy_ip?.port }}</div>
              </div>
              <div class="row-country">
                <el-tag size="small" type="info" effect="plain">
                  {{ item.proxy_ip?.country_name || '-' }}
                </el-tag>
                <div class="source-name">{{ item.proxy_ip?.source_name || '-' }}</div>
              </div>
              <div class="row-expires">
                <div class="expires-date" :class="{ urgent: daysToExpire(item.expires_at) <= 3 }">
                  {{ formatDate(item.expires_at) }}
                </div>
                <div class="days-left">
                  剩 <strong>{{ daysToExpire(item.expires_at) }}</strong> 天
                </div>
              </div>
              <div class="row-current-price">
                <div class="label">当前月价</div>
                <div class="value">¥{{ Number(item.renewal_monthly_price ?? getMonthlyPrice(item)).toFixed(2) }}</div>
              </div>
              <div class="row-new-price" @click.stop>
                <div class="label">月单价</div>
                <el-input-number
                  v-model="bulkPrices[item.id]"
                  :min="0"
                  :precision="2"
                  size="small"
                  controls-position="right"
                  :disabled="!canRenew(item)"
                  @change="onRowPriceChange(item.id, $event)"
                  style="width: 110px"
                />
                <el-tag
                  v-if="rowPriceOverrides[item.id]"
                  size="small"
                  type="warning"
                  effect="plain"
                  style="margin-left: 4px; font-size: 10px"
                >
                  自定义
                </el-tag>
              </div>
            </div>
          </div>
        </div>

        <el-empty
          v-if="!filteredBulkItems.length && bulkItems.length"
          description="无符合筛选条件的订阅"
          :image-size="80"
        />
        <el-empty
          v-if="!bulkItems.length"
          description="暂无即将到期的订阅"
          :image-size="80"
        />
      </div>

      <template #footer>
        <div class="bulk-footer">
          <div class="footer-hint">
            <el-icon><InfoFilled /></el-icon>
            单独修改行价格后会标记为"自定义"，应用统一价时不会覆盖
          </div>
          <div class="footer-actions">
            <el-button @click="bulkRenewVisible = false" size="default">取消</el-button>
            <el-button
              type="warning"
              :loading="bulkRenewLoading"
              :disabled="!selectedBulkIds.length"
              size="default"
              @click="submitBulkRenew"
            >
              一键续费 {{ selectedBulkIds.length }} 条 · {{ bulkSkipDeduct ? '不扣余额' : `共扣费 ¥${bulkTotal.toFixed(2)}` }}
            </el-button>
          </div>
        </div>
      </template>
    </el-dialog>

    <!-- Batch Attach Forward Dialog -->
    <el-dialog v-model="batchForwardVisible" title="批量开通端口转发" width="680px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        将为 <strong>{{ selectedMainIds.length }}</strong> 条选中的订阅创建 NY 转发规则。
        <strong>已有转发/非活跃订阅会自动跳过</strong>。
      </el-alert>

      <el-form :model="batchForwardForm" label-width="120px">
        <el-form-item label="选中条数">
          <el-tag type="primary">{{ selectedMainIds.length }} 条订阅</el-tag>
        </el-form-item>

        <el-form-item label="中转套餐">
          <el-select
            v-model="batchForwardForm.forward_plan_id"
            placeholder="可选 — 选择后自动填充下方配置"
            style="width: 100%"
            filterable
            clearable
            @change="onForwardPlanChange"
          >
            <el-option
              v-for="plan in forwardPlanOptions"
              :key="plan.id"
              :label="plan.name"
              :value="plan.id"
            >
              <div style="display: flex; justify-content: space-between; align-items: center">
                <span>{{ plan.name }}</span>
                <span style="font-size: 11px; color: #909399">
                  {{ moduleLabel(plan.module) }} · ¥{{ Number(plan.base_price).toFixed(0) }}/月
                </span>
              </div>
            </el-option>
          </el-select>
          <div style="font-size: 12px; color: #909399; margin-top: 2px">
            选择套餐后会自动填充节点、限速、费用；也可不选，手动配置
          </div>
        </el-form-item>

        <el-form-item label="转发节点">
          <el-select
            v-model="batchForwardForm.device_group_id"
            placeholder="选择 NY 设备组"
            style="width: 100%"
            filterable
          >
            <el-option
              v-for="dg in deviceGroupOptions"
              :key="dg.id"
              :label="`[${dg.panel?.name}] ${dg.name}`"
              :value="dg.id"
            >
              <div style="display: flex; justify-content: space-between; align-items: center">
                <span>{{ dg.name }}</span>
                <span class="mono" style="font-size: 11px; color: #909399">
                  {{ dg.custom_connect_host || dg.original_connect_host }}
                </span>
              </div>
            </el-option>
          </el-select>
        </el-form-item>

        <el-form-item label="限速 (Mbps)">
          <el-input-number
            v-model="batchForwardForm.speed_limit_mbps"
            :min="1"
            :max="10000"
            placeholder="留空不限速"
            controls-position="right"
            style="width: 220px"
          />
          <span class="hint">留空 = 不限速</span>
        </el-form-item>

        <el-form-item label="额外转发费">
          <el-input-number
            v-model="batchForwardForm.forward_fee"
            :min="0"
            :precision="2"
            controls-position="right"
            style="width: 220px"
          />
          <span class="hint">每条每月，0 = 免费（不改订阅价格）</span>
        </el-form-item>

        <el-form-item v-if="batchForwardForm.forward_fee > 0" label="扣费方式">
          <el-radio-group v-model="batchForwardForm.deduct_balance">
            <el-radio value="current">
              本期扣余额
            </el-radio>
            <el-radio value="next">
              下期续费扣
            </el-radio>
          </el-radio-group>
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            <template v-if="batchForwardForm.deduct_balance === 'current'">
              立即从每个客户余额扣除 ¥{{ Number(batchForwardForm.forward_fee).toFixed(2) }}/条，合计约 ¥{{ (batchForwardForm.forward_fee * selectedMainIds.length).toFixed(2) }}
            </template>
            <template v-else>
              本期已线下收费，转发费加到订阅价格，下次续费时自动扣
            </template>
          </div>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="batchForwardVisible = false">取消</el-button>
        <el-button
          type="primary"
          :loading="batchForwardLoading"
          :disabled="!batchForwardForm.device_group_id"
          @click="submitBatchForward"
        >
          确认开通 {{ selectedMainIds.length }} 条
        </el-button>
      </template>
    </el-dialog>

    <!-- Batch Attach XUI Dialog -->
    <el-dialog
      v-model="batchXuiVisible"
      title="批量开通 3x-ui 中转"
      width="600px"
      :close-on-click-modal="false"
    >
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 14px">
        将为 <strong>{{ selectedMainIds.length }}</strong> 条选中订阅在 3x-ui 面板创建 vless+reality 中转。
        <br>每条约需 3-5 秒，任务会进入队列逐条处理，失败和已有中转的会自动跳过。
      </el-alert>

      <el-form label-width="130px">
        <el-form-item label="选中条数">
          <el-tag type="success">{{ selectedMainIds.length }} 条订阅</el-tag>
        </el-form-item>
        <el-form-item label="3x-ui 面板">
          <el-select
            v-model="batchXuiForm.xui_panel_id"
            placeholder="选择主面板（非备机）"
            style="width: 100%"
            filterable
          >
            <el-option
              v-for="p in xuiPanelOptions"
              :key="p.id"
              :label="`${p.name} (${p.connect_host || p.api_url})`"
              :value="p.id"
            />
          </el-select>
          <div class="hint">备机会自动同步，不用在这里选</div>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="batchXuiVisible = false">取消</el-button>
        <el-button
          type="success"
          :loading="batchXuiLoading"
          :disabled="!batchXuiForm.xui_panel_id"
          @click="submitBatchXui"
        >
          确认开通 {{ selectedMainIds.length }} 条
        </el-button>
      </template>
    </el-dialog>

    <!-- Batch Forward Progress Dialog -->
    <el-dialog
      v-model="batchProgressVisible"
      title="批量转发任务进度"
      width="600px"
      :close-on-click-modal="false"
      :show-close="batchProgress.finished"
      @close="closeBatchProgress"
    >
      <el-alert v-if="!batchProgress.finished" type="info" :closable="false" show-icon style="margin-bottom: 14px">
        任务正在队列中逐条处理，每条约需 1-2 秒。本弹窗可以关闭，稍后在订阅列表刷新即可查看结果。
      </el-alert>
      <el-alert v-else :type="batchProgress.failed > 0 ? 'warning' : 'success'" :closable="false" show-icon style="margin-bottom: 14px">
        {{ batchProgress.failed > 0
          ? `已完成：${batchProgress.active} 条成功 / ${batchProgress.failed} 条失败`
          : `全部完成：${batchProgress.active} 条转发成功 🎉` }}
      </el-alert>

      <el-progress
        :percentage="batchProgress.progress_pct"
        :status="batchProgress.finished ? (batchProgress.failed > 0 ? 'warning' : 'success') : undefined"
        :stroke-width="18"
      />

      <div class="batch-stats">
        <div class="stat-item"><span class="label">总数</span><span class="val">{{ batchProgress.total }}</span></div>
        <div class="stat-item"><span class="label">待处理</span><span class="val pending">{{ batchProgress.pending }}</span></div>
        <div class="stat-item"><span class="label">处理中</span><span class="val processing">{{ batchProgress.processing }}</span></div>
        <div class="stat-item"><span class="label">已成功</span><span class="val success">{{ batchProgress.active }}</span></div>
        <div class="stat-item"><span class="label">失败</span><span class="val fail">{{ batchProgress.failed }}</span></div>
      </div>

      <div v-if="batchProgress.failed > 0 && batchProgress.failed_rules?.length" class="failed-list">
        <div class="failed-title">失败详情（前 10 条）：</div>
        <div v-for="f in batchProgress.failed_rules.slice(0, 10)" :key="f.id" class="failed-row">
          <strong>#{{ f.subscription_id }}</strong>
          <span style="color: #909399">· {{ f.customer || '-' }} · {{ f.asset_name }}</span>
          <div style="color: #F56C6C; font-size: 12px; margin-top: 2px">{{ f.error }}</div>
        </div>
      </div>

      <template #footer>
        <el-button v-if="!batchProgress.finished" @click="closeBatchProgress">后台处理</el-button>
        <el-button v-else type="primary" @click="closeBatchProgress">关闭</el-button>
      </template>
    </el-dialog>

    <!-- Batch Update Expiry Dialog -->
    <el-dialog v-model="batchExpiryVisible" title="批量修改到期时间" width="540px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        将为 <strong>{{ selectedMainIds.length }}</strong> 条订阅强制设置到期时间。
        <br>此操作不会扣费、不会调用 Spark API，仅修改本地记录。
      </el-alert>

      <el-form :model="batchExpiryForm" label-width="120px">
        <el-form-item label="选中条数">
          <el-tag type="info">{{ selectedMainIds.length }} 条订阅</el-tag>
        </el-form-item>
        <el-form-item label="新到期日期">
          <el-date-picker
            v-model="batchExpiryForm.expires_at"
            type="date"
            placeholder="选择日期"
            value-format="YYYY-MM-DD"
            :disabled-date="(d) => d < dayjs().subtract(1, 'year').toDate()"
            style="width: 100%"
          />
          <div class="hint" style="margin-top: 4px">未来日期 → 状态自动设为 active；过去日期 → expired</div>
        </el-form-item>
        <el-form-item label="同步 IP 资产">
          <el-switch v-model="batchExpiryForm.sync_proxy_ip" />
          <span class="hint">同时更新 ProxyIp.upstream_expires_at（推荐开启）</span>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="batchExpiryVisible = false">取消</el-button>
        <el-button
          type="primary"
          :loading="batchExpiryLoading"
          :disabled="!batchExpiryForm.expires_at"
          @click="submitBatchExpiry"
        >
          确认修改 {{ selectedMainIds.length }} 条
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Refresh, RefreshLeft, InfoFilled, Share, Plus, Calendar, Connection, View, ArrowDown, Files, Edit, Bottom } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import {
  getSubscriptions, renewSubscription, refundSubscription, partialRefundSubscription,
  bulkRenewSubscriptions, batchAttachForward, getBatchForwardStatus,
  batchUpdateExpiry, batchAttachXuiForward, getBatchXuiForwardStatus,
  updateSubscriptionRemark, convertTestSubscription, transferSubscription,
  downgradeSubscription, batchUpdatePrice,
} from '@/api/subscriptions'
import { getRefundableOrders, refundPaymentOrder } from '@/api/paymentOrders'
import { getCustomers as getCustomerList } from '@/api/customers'
import { getNyEnabledDeviceGroups } from '@/api/nyPanels'
import { getForwardPlans } from '@/api/forwardPlans'
import { getUsableXuiPanels } from '@/api/xuiPanels'
import { getUsers } from '@/api/users'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()
const isSalesRole = computed(() => authStore.hasRole('staff') || authStore.hasRole('sales'))
const loading = ref(false)
const tableData = ref([])
const searchForm = reactive({ status: '', customer_name: '', country: '', keyword: '', source_name: '', product_type: '', expiring_soon: null, has_remark: null, sales_person: '', sort: '' })
const staffOptions = ref([])
const pagination = reactive({ page: 1, per_page: 20, total: 0 })
const customPageSize = ref(100)
const quickFilter = ref(null) // 当前快捷筛选天数
const expiringCount = ref(0)  // 5 天内到期的总数，用于"一键续费"徽章

// 批量 IP 筛选
const ipFilterVisible = ref(false)
const ipFilterText = ref('')
const ipFilterList = ref([]) // 已应用的 IP 数组

const IP_REGEX = /^(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/

function parseIpInput(text) {
  const lines = (text || '').split(/[\s,，;；]+/).map(s => s.trim()).filter(Boolean)
  const valid = []
  const invalid = []
  const seen = new Set()
  for (const l of lines) {
    if (IP_REGEX.test(l)) {
      if (!seen.has(l)) { seen.add(l); valid.push(l) }
    } else {
      invalid.push(l)
    }
  }
  return { valid, invalid }
}

const parsedIpCount = computed(() => parseIpInput(ipFilterText.value).valid.length)
const parsedIpInvalidCount = computed(() => parseIpInput(ipFilterText.value).invalid.length)

function openIpFilter() {
  // 打开时回填已应用的 IP，便于在原基础上编辑
  if (ipFilterList.value.length && !ipFilterText.value.trim()) {
    ipFilterText.value = ipFilterList.value.join('\n')
  }
  ipFilterVisible.value = true
}

function applyIpFilter() {
  const { valid } = parseIpInput(ipFilterText.value)
  if (!valid.length) { ElMessage.warning('请至少输入一个有效 IP'); return }
  ipFilterList.value = valid
  ipFilterVisible.value = false
  pagination.page = 1
  fetchData()
  ElMessage.success(`已按 ${valid.length} 个 IP 筛选`)
}

function clearIpFilter() {
  ipFilterList.value = []
  ipFilterText.value = ''
  pagination.page = 1
  fetchData()
}

function onPageSizeChange(size) {
  pagination.per_page = size
  pagination.page = 1
  fetchData()
}

function applyCustomSize() {
  if (!customPageSize.value || customPageSize.value < 1) return
  pagination.per_page = customPageSize.value
  pagination.page = 1
  fetchData()
}

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function isExpiringSoon(d) { return d && dayjs(d).diff(dayjs(), 'day') <= 7 && dayjs(d).isAfter(dayjs()) }
function daysToExpire(d) { return d ? Math.max(0, dayjs(d).diff(dayjs(), 'day')) : 0 }
function statusTag(s) { return { active: 'success', expired: 'danger', cancelled: 'info' }[s] || 'info' }
function statusLabel(s) { return { active: '活跃', expired: '已过期', cancelled: '已取消', refunded: '已退订', suspended: '已暂停' }[s] || s }

function getForwardCost(row) {
  const plan = row.forward_rule?.forward_plan
  if (!plan || plan.cost_price == null) return 0
  return Number(plan.cost_price)
}

function getTotalCost(row) {
  return Number(row.sales_cost || 0) + getForwardCost(row)
}

function getDurationMonths(row) {
  const d = Number(row.duration || 1)
  const u = Number(row.unit || 3)
  if (u === 1) return Math.max(1, Math.round(d / 30))
  if (u === 2) return Math.max(1, Math.round(d / 4.3))
  if (u === 4) return d * 12
  return d // unit=3 (months)
}

function getMonthlyPrice(row) {
  const months = getDurationMonths(row)
  return Number(row.price || 0) / months
}

function canRenew(row) {
  if (row.status === 'active') return true
  if (row.status === 'expired' && row.expires_at) {
    const daysSinceExpiry = dayjs().diff(dayjs(row.expires_at), 'day')
    return daysSinceExpiry <= 3
  }
  return false
}

function canRefund(row) {
  return row.status === 'active' || row.status === 'expired'
}

function canPartialRefund(row) {
  if (row.status !== 'active') return false
  const unit = parseInt(row.unit)
  if (unit !== 3 && unit !== 4) return false
  const totalMonths = unit === 4 ? row.duration * 12 : row.duration
  return totalMonths >= 2
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.status) params['filter[status]'] = searchForm.status
    if (searchForm.customer_name) params['filter[customer_name]'] = searchForm.customer_name
    if (searchForm.country) params['filter[country]'] = searchForm.country
    if (searchForm.keyword) params['filter[keyword]'] = searchForm.keyword
    if (searchForm.source_name) params['filter[source_name]'] = searchForm.source_name
    if (searchForm.product_type) params['filter[product_type]'] = searchForm.product_type
    if (searchForm.expiring_soon) params['filter[expiring_soon]'] = searchForm.expiring_soon
    if (searchForm.has_remark !== null && searchForm.has_remark !== '') params['filter[has_remark]'] = searchForm.has_remark
    if (searchForm.sales_person) params['filter[sales_person]'] = searchForm.sales_person
    if (ipFilterList.value.length) params['filter[ip_in]'] = ipFilterList.value.join(',')
    if (searchForm.sort) params.sort = searchForm.sort
    const res = await getSubscriptions(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
  } catch { /* handled */ }
  finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }

function onSortChange({ prop, order }) {
  if (!order) {
    searchForm.sort = ''
  } else {
    const dir = order === 'ascending' ? 'asc' : 'desc'
    const map = { id: 'id', price: 'price', expires_at: 'expires_at', created_at: 'created_at' }
    const field = map[prop] || prop
    searchForm.sort = `${field}_${dir}`
  }
  pagination.page = 1
  fetchData()
}

function handleReset() {
  Object.assign(searchForm, { status: '', customer_name: '', country: '', keyword: '', source_name: '', expiring_soon: null, has_remark: null, sales_person: isSalesRole.value ? searchForm.sales_person : '', sort: '' })
  quickFilter.value = null
  ipFilterList.value = []
  ipFilterText.value = ''
  pagination.page = 1
  fetchData()
}

function applyQuickFilter(days) {
  quickFilter.value = days
  searchForm.status = 'active'
  searchForm.expiring_soon = days
  pagination.page = 1
  fetchData()
}

function clearQuickFilter() {
  quickFilter.value = null
  searchForm.expiring_soon = null
  pagination.page = 1
  fetchData()
}

// 统计 5 天内到期数量（用于徽章）
async function fetchExpiringCount() {
  try {
    const res = await getSubscriptions({
      page: 1,
      per_page: 1,
      'filter[status]': 'active',
      'filter[expiring_soon]': 5,
    })
    expiringCount.value = res?.pagination?.total || 0
  } catch { expiringCount.value = 0 }
}

function handleRowAction(cmd, row) {
  switch (cmd) {
    case 'detail': router.push(`/subscriptions/${row.id}`); break
    case 'renew': openRenew(row); break
    case 'refund': openRefund(row); break
    case 'partialRefund': openPartialRefund(row); break
    case 'convert': openConvert(row); break
    case 'transfer': openTransfer(row); break
    case 'downgrade': openDowngrade(row); break
  }
}


// Renew
const renewVisible = ref(false)
const renewLoading = ref(false)
const renewTarget = ref(null)
const renewForm = reactive({ duration: 1, price: 0, skip_deduct: false })
const renewTotalPrice = computed(() => (Math.round(renewForm.price * renewForm.duration * 100) / 100).toFixed(2))

function durationToDays(duration, unit) {
  if (unit === 2) return duration * 7
  if (unit === 3) return duration * 30
  if (unit === 4) return duration * 365
  return duration
}

function openRenew(row) {
  renewTarget.value = row
  renewForm.duration = 1
  renewForm.price = row.renewal_monthly_price != null
    ? row.renewal_monthly_price
    : getMonthlyPrice(row)
  renewForm.skip_deduct = false
  renewVisible.value = true
}

async function submitRenew() {
  renewLoading.value = true
  try {
    await renewSubscription(renewTarget.value.id, {
      duration: renewForm.duration * 30,
      unit: 1,
      price: Math.round(renewForm.price * renewForm.duration * 100) / 100,
      skip_deduct: renewForm.skip_deduct,
    })
    ElMessage.success('续费成功')
    renewVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { renewLoading.value = false }
}

// Refund
const refundVisible = ref(false)
const refundLoading = ref(false)
const refundTarget = ref(null)
const refundForm = reactive({ refund_amount: 0, reason: '', release_upstream: true, refund_to_balance: true, reverse_commission: true, gateway_refund: false, gateway_order_id: null, gateway_amount: 0 })
const gatewayOrders = ref([])
const gatewayOrdersLoading = ref(false)

const selectedGatewayOrder = computed(() =>
  gatewayOrders.value.find(o => o.id === refundForm.gateway_order_id)
)

function openRefund(row) {
  refundTarget.value = row
  refundForm.refund_amount = parseFloat(row.price) || 0
  refundForm.reason = ''
  const isExpired = row.status === 'expired'
  const ageHours = row.started_at ? (Date.now() - new Date(row.started_at).getTime()) / 3600000 : 9999
  refundForm.release_upstream = !isExpired && ageHours <= 23
  refundForm.refund_to_balance = !isExpired
  refundForm.reverse_commission = true
  refundForm.gateway_refund = false
  refundForm.gateway_order_id = null
  refundForm.gateway_amount = 0
  gatewayOrders.value = []
  refundVisible.value = true
}

async function onGatewayRefundToggle(val) {
  if (!val) return
  const customerId = refundTarget.value?.customer_id
  if (!customerId) return
  gatewayOrdersLoading.value = true
  try {
    const res = await getRefundableOrders(customerId)
    gatewayOrders.value = Array.isArray(res) ? res : (res.data || [])
    if (gatewayOrders.value.length === 0) {
      ElMessage.warning('该客户没有可退款的支付宝充值订单')
      refundForm.gateway_refund = false
    }
  } catch { refundForm.gateway_refund = false }
  finally { gatewayOrdersLoading.value = false }
}

async function submitRefund() {
  refundLoading.value = true
  try {
    const payload = {
      reason: refundForm.reason,
      release_upstream: refundForm.release_upstream,
      refund_amount: refundForm.refund_to_balance ? refundForm.refund_amount : 0,
      reverse_commission: refundForm.reverse_commission,
    }
    const res = await refundSubscription(refundTarget.value.id, payload)
    const spark = res?.spark_release
    if (spark && spark.status === 'failed') {
      ElMessage({
        type: 'warning',
        message: `退订已处理，但 Spark 释放失败：${spark.message}。请到 IP 详情页查看并重试。`,
        duration: 8000,
      })
    } else if (spark && spark.status === 'confirmed') {
      ElMessage.success('退订成功，Spark 已确认释放')
    } else if (spark && spark.status === 'pending') {
      ElMessage({
        type: 'success',
        message: '退订成功，Spark 已受理释放请求（等待上游确认）',
        duration: 5000,
      })
    } else {
      ElMessage.success('退订成功')
    }

    if (refundForm.gateway_refund && refundForm.gateway_order_id && refundForm.gateway_amount > 0) {
      try {
        await refundPaymentOrder(refundForm.gateway_order_id, {
          amount: refundForm.gateway_amount,
          reason: refundForm.reason || '订阅退订原路退款',
          subscription_id: refundTarget.value.id,
        })
        ElMessage.success('原路退款已成功退回支付宝')
      } catch (err) {
        ElMessage.error('原路退款失败: ' + (err.response?.data?.message || err.message))
      }
    }

    refundVisible.value = false
    fetchData()
  } catch (err) {
    const msg = err?.response?.data?.message || err?.message || '退订失败'
    if (msg.startsWith('无权限')) {
      ElMessage.error('您没有退订权限，请联系管理员')
    } else {
      ElMessage.error(msg)
    }
  }
  finally { refundLoading.value = false }
}

// ========== Partial Refund ==========
const partialRefundVisible = ref(false)
const partialRefundLoading = ref(false)
const partialRefundSubmitting = ref(false)
const partialRefundTarget = ref(null)
const partialRefundPreview = ref(null)
const partialRefundForm = reactive({ reason: '' })

async function openPartialRefund(row) {
  partialRefundTarget.value = row
  partialRefundPreview.value = null
  partialRefundForm.reason = ''
  partialRefundVisible.value = true
  partialRefundLoading.value = true
  try {
    const res = await partialRefundSubscription(row.id, { confirm: false })
    partialRefundPreview.value = res
  } catch (err) {
    ElMessage.error(err?.response?.data?.message || '无法计算部分退款')
    partialRefundVisible.value = false
  } finally {
    partialRefundLoading.value = false
  }
}

async function submitPartialRefund() {
  partialRefundSubmitting.value = true
  try {
    const res = await partialRefundSubscription(partialRefundTarget.value.id, {
      confirm: true,
      reason: partialRefundForm.reason,
    })
    const p = partialRefundPreview.value
    ElMessage.success(`部分退款成功：退还${p.refundable_months}个月 ¥${p.refund_amount}`)
    partialRefundVisible.value = false
    fetchData()
  } catch (err) {
    ElMessage.error(err?.response?.data?.message || '部分退款失败')
  } finally {
    partialRefundSubmitting.value = false
  }
}

// ========== Batch Refund ==========
const batchRefundVisible = ref(false)
const batchRefundLoading = ref(false)
const batchRefundIds = ref([])
const batchRefundForm = reactive({ refund_to_balance: true, release_upstream: true, reverse_commission: true, reason: '' })

function openBatchRefund() {
  const refundableIds = selectedMainIds.value.filter(id => {
    const row = tableData.value.find(r => r.id === id)
    return row && (row.status === 'active' || row.status === 'expired')
  })
  if (!refundableIds.length) { ElMessage.warning('请选择活跃或已过期状态的订阅'); return }
  batchRefundIds.value = refundableIds
  batchRefundForm.refund_to_balance = true
  batchRefundForm.release_upstream = true
  batchRefundForm.reverse_commission = true
  batchRefundForm.reason = ''
  batchRefundVisible.value = true
}

async function submitBatchRefund() {
  batchRefundLoading.value = true
  let success = 0, fail = 0
  for (const id of batchRefundIds.value) {
    try {
      const row = tableData.value.find(r => r.id === id)
      await refundSubscription(id, {
        reason: batchRefundForm.reason,
        release_upstream: batchRefundForm.release_upstream,
        refund_amount: batchRefundForm.refund_to_balance ? (parseFloat(row?.price) || 0) : 0,
        reverse_commission: batchRefundForm.reverse_commission,
      })
      success++
    } catch { fail++ }
  }
  batchRefundLoading.value = false
  batchRefundVisible.value = false
  ElMessage.success(`已退订 ${success} 条${fail ? `，${fail} 条失败` : ''}`)
  fetchData()
}

// ========== Transfer ==========
const transferVisible = ref(false)
const transferLoading = ref(false)
const transferTarget = ref(null)
const transferForm = reactive({ target_customer_id: null, charge_target: false, charge_method: 'balance', refund_source: true, remark: '' })
const transferCustomerSearching = ref(false)
const transferCustomerOptions = ref([])

function openTransfer(row) {
  transferTarget.value = row
  transferForm.target_customer_id = null
  transferForm.charge_target = false
  transferForm.charge_method = 'balance'
  transferForm.refund_source = true
  transferForm.remark = ''
  transferCustomerOptions.value = []
  transferVisible.value = true
}

async function searchTransferCustomers(query) {
  if (!query || query.length < 1) { transferCustomerOptions.value = []; return }
  transferCustomerSearching.value = true
  try {
    const res = await getCustomerList({ 'filter[keyword]': query, per_page: 20 })
    transferCustomerOptions.value = (res?.items || res?.data || []).slice(0, 20)
  } catch { transferCustomerOptions.value = [] }
  finally { transferCustomerSearching.value = false }
}

function onTransferCustomerSelected() {}

async function submitTransfer() {
  if (!transferForm.target_customer_id) { ElMessage.warning('请选择目标客户'); return }
  if (transferForm.target_customer_id === transferTarget.value?.customer_id) { ElMessage.warning('目标客户不能与当前客户相同'); return }
  transferLoading.value = true
  try {
    await transferSubscription(transferTarget.value.id, {
      target_customer_id: transferForm.target_customer_id,
      charge_target: transferForm.charge_target,
      charge_method: transferForm.charge_target ? transferForm.charge_method : undefined,
      refund_source: transferForm.charge_target && transferForm.charge_method === 'balance' ? transferForm.refund_source : undefined,
      remark: transferForm.remark || undefined,
    })
    ElMessage.success('划转成功')
    transferVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { transferLoading.value = false }
}

// ========== Batch Update Price ==========
const batchPriceVisible = ref(false)
const batchPriceLoading = ref(false)
const batchPriceItems = ref([])
const batchPriceForm = reactive({ new_price: 0 })

function priceDiff(row) {
  return round2((batchPriceForm.new_price || 0) - getMonthlyPrice(row))
}

function round2(n) {
  return Math.round(n * 100) / 100
}

function openBatchPrice() {
  const items = selectedMainIds.value
    .map(id => tableData.value.find(r => r.id === id))
    .filter(Boolean)
  if (!items.length) { ElMessage.warning('请先选择订阅'); return }
  batchPriceItems.value = items
  const avgPrice = items.reduce((s, r) => s + getMonthlyPrice(r), 0) / items.length
  batchPriceForm.new_price = round2(avgPrice)
  batchPriceVisible.value = true
}

async function submitBatchPrice() {
  if (batchPriceForm.new_price == null || batchPriceForm.new_price < 0) {
    ElMessage.warning('请输入有效价格'); return
  }
  batchPriceLoading.value = true
  try {
    const res = await batchUpdatePrice({
      subscription_ids: batchPriceItems.value.map(r => r.id),
      new_price: batchPriceForm.new_price,
    })
    ElMessage.success(res?.message || `已更新 ${batchPriceItems.value.length} 条订阅价格`)
    batchPriceVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { batchPriceLoading.value = false }
}

// ========== Convert Test ==========
const convertVisible = ref(false)
const convertLoading = ref(false)
const convertTarget = ref(null)
const convertForm = reactive({ duration: 1, unit: 3, price: 0, charge_customer: true })
const convertTotalPrice = computed(() => (Math.round(convertForm.price * convertForm.duration * 100) / 100).toFixed(2))

function openConvert(row) {
  convertTarget.value = row
  convertForm.duration = 1
  convertForm.unit = 3
  convertForm.price = getMonthlyPrice(row)
  convertForm.charge_customer = true
  convertVisible.value = true
}

async function submitConvert() {
  convertLoading.value = true
  try {
    await convertTestSubscription(convertTarget.value.id, {
      duration: convertForm.duration,
      unit: convertForm.unit,
      price: convertForm.price,
      charge_customer: convertForm.charge_customer,
      total_charge: Math.round(convertForm.price * convertForm.duration * 100) / 100,
    })
    ElMessage.success('测试订单已转正')
    convertVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { convertLoading.value = false }
}

// ========== Downgrade ==========
const downgradeVisible = ref(false)
const downgradeLoading = ref(false)
const downgradeTarget = ref(null)
const downgradeForm = reactive({ refund_to_balance: true, refund_amount: 0 })

const downgradeRemainingDays = computed(() => {
  if (!downgradeTarget.value?.expires_at) return 0
  const diff = dayjs(downgradeTarget.value.expires_at).diff(dayjs(), 'day', true)
  return Math.max(0, Math.ceil(diff))
})

const downgradeDefaultRefund = computed(() => {
  const fee = parseFloat(downgradeTarget.value?.forward_rule?.forward_fee || 0)
  return Math.round(fee / 30 * downgradeRemainingDays.value * 100) / 100
})

function openDowngrade(row) {
  downgradeTarget.value = row
  downgradeForm.refund_to_balance = true
  nextTick(() => { downgradeForm.refund_amount = downgradeDefaultRefund.value })
  downgradeVisible.value = true
}

async function submitDowngrade() {
  downgradeLoading.value = true
  try {
    const res = await downgradeSubscription(downgradeTarget.value.id, { ...downgradeForm })
    ElMessage.success(res?.message || '降级成功')
    downgradeVisible.value = false
    fetchData()
  } catch { /* handled */ }
  finally { downgradeLoading.value = false }
}

// ========== Bulk Renew ==========
const bulkRenewVisible = ref(false)
const bulkRenewLoading = ref(false)
const bulkItems = ref([])          // 对话框内的候选订阅列表
const bulkPrices = reactive({})    // { subscriptionId: price } 每行单价
const rowPriceOverrides = reactive({}) // 标记被手动修改过的行，不受统一价影响
const selectedBulkIds = ref([])
const bulkSkipDeduct = ref(false)
const bulkFilter = ref('')
const bulkUnifiedPrice = ref(null)
const bulkDuration = ref(1)

const filteredBulkItems = computed(() => {
  const kw = bulkFilter.value.trim().toLowerCase()
  if (!kw) return bulkItems.value
  return bulkItems.value.filter(i => {
    return (i.customer?.customer_name || '').toLowerCase().includes(kw)
      || (i.proxy_ip?.asset_name || '').toLowerCase().includes(kw)
      || (i.proxy_ip?.ip_address || '').toLowerCase().includes(kw)
      || (i.proxy_ip?.country_name || '').toLowerCase().includes(kw)
  })
})


const bulkTotal = computed(() => {
  const perItemSum = selectedBulkIds.value.reduce((sum, id) => sum + (Number(bulkPrices[id]) || 0), 0)
  return Math.round(perItemSum * (bulkDuration.value || 1) * 100) / 100
})

// 涉及客户数量
const uniqueCustomerCount = computed(() => {
  const s = new Set(filteredBulkItems.value.map(i => i.customer?.id).filter(Boolean))
  return s.size
})

// 按客户分组
const groupedBulkItems = computed(() => {
  const map = new Map()
  for (const item of filteredBulkItems.value) {
    const id = item.customer?.id || 0
    if (!map.has(id)) {
      map.set(id, {
        customerId: id,
        customerName: item.customer?.customer_name || '未知客户',
        salesPerson: item.customer?.sales_person,
        items: [],
        totalCurrentPrice: 0,
      })
    }
    const group = map.get(id)
    group.items.push(item)
    group.totalCurrentPrice += Number(item.price || 0)
  }
  // 计算每组的选中数和选中后扣费
  for (const g of map.values()) {
    g.selectedCount = g.items.filter(i => selectedBulkIds.value.includes(i.id)).length
    g.selectedCharged = Math.round(g.items
      .filter(i => selectedBulkIds.value.includes(i.id))
      .reduce((s, i) => s + Number(bulkPrices[i.id] || 0), 0) * (bulkDuration.value || 1) * 100) / 100
  }
  // 按剩余天数最紧迫的排前面
  return Array.from(map.values()).sort((a, b) => {
    const dayA = Math.min(...a.items.map(i => daysToExpire(i.expires_at)))
    const dayB = Math.min(...b.items.map(i => daysToExpire(i.expires_at)))
    return dayA - dayB
  })
})

function isGroupFullySelected(group) {
  return group.items.length > 0 && group.items.every(i => selectedBulkIds.value.includes(i.id))
}

function isGroupPartiallySelected(group) {
  const selected = group.items.filter(i => selectedBulkIds.value.includes(i.id)).length
  return selected > 0 && selected < group.items.length
}

function toggleGroupSelection(group, checked) {
  const ids = group.items.filter(i => i.status === 'active').map(i => i.id)
  if (checked) {
    const set = new Set([...selectedBulkIds.value, ...ids])
    selectedBulkIds.value = Array.from(set)
  } else {
    selectedBulkIds.value = selectedBulkIds.value.filter(id => !ids.includes(id))
  }
}

function toggleItem(item) {
  if (!canRenew(item)) return
  const idx = selectedBulkIds.value.indexOf(item.id)
  if (idx >= 0) {
    selectedBulkIds.value.splice(idx, 1)
  } else {
    selectedBulkIds.value.push(item.id)
  }
}

function calcDefaultPrice(item) {
  if (item.renewal_monthly_price != null) return item.renewal_monthly_price
  return Number(item.price) || 0
}
function resetBulkPrices() {
  bulkItems.value.forEach(i => {
    bulkPrices[i.id] = calcDefaultPrice(i)
    delete rowPriceOverrides[i.id]
  })
  ElMessage.success('已重置为系统计算价')
}

async function openBulkRenew() {
  bulkRenewLoading.value = false
  bulkRenewVisible.value = true
  bulkSkipDeduct.value = false
  bulkItems.value = []

  const hasSelection = selectedMainIds.value.length > 0

  try {
    let items
    if (hasSelection) {
      // 用户已选中：拉取选中的订阅详情
      const res = await getSubscriptions({
        page: 1,
        per_page: 200,
        'filter[ids]': selectedMainIds.value.join(','),
      })
      items = res?.items || []
    } else {
      // 未选中：默认拉取 5 天内到期的活跃订阅
      const res = await getSubscriptions({
        page: 1,
        per_page: 200,
        'filter[status]': 'active',
        'filter[expiring_soon]': 5,
      })
      items = res?.items || []
    }
    bulkItems.value = items
    Object.keys(bulkPrices).forEach(k => delete bulkPrices[k])
    Object.keys(rowPriceOverrides).forEach(k => delete rowPriceOverrides[k])
    items.forEach(i => { bulkPrices[i.id] = calcDefaultPrice(i) })
    bulkUnifiedPrice.value = null
    selectedBulkIds.value = items.filter(i => canRenew(i)).map(i => i.id)
  } catch { /* handled */ }
}

function onRowPriceChange(id, value) {
  // 标记这一行为"手动覆盖"，不被统一价再同步
  rowPriceOverrides[id] = true
}

function applyUnifiedPrice() {
  if (!bulkUnifiedPrice.value) return
  selectedBulkIds.value.forEach(id => {
    // 只对未被手动覆盖的行应用统一价
    if (!rowPriceOverrides[id]) {
      bulkPrices[id] = bulkUnifiedPrice.value
    }
  })
  ElMessage.success(`已应用到 ${selectedBulkIds.value.filter(id => !rowPriceOverrides[id]).length} 行`)
}

async function submitBulkRenew() {
  if (!selectedBulkIds.value.length) {
    ElMessage.warning('请至少勾选一条')
    return
  }
  // 校验所有勾选行都有价格
  const invalid = selectedBulkIds.value.filter(id => !bulkPrices[id] || bulkPrices[id] <= 0)
  if (invalid.length) {
    ElMessage.warning(`有 ${invalid.length} 行未填写续费价格`)
    return
  }

  const confirmMsg = bulkSkipDeduct.value
    ? `确认对 ${selectedBulkIds.value.length} 条订阅进行续费？（不扣余额，线下已付）`
    : `确认对 ${selectedBulkIds.value.length} 条订阅进行续费？合计扣费 ¥${bulkTotal.value.toFixed(2)}`
  try {
    await ElMessageBox.confirm(confirmMsg, '批量续费确认', { type: 'warning' })
  } catch { return }

  bulkRenewLoading.value = true
  try {
    const payload = {
      duration: bulkDuration.value * 30,
      unit: 1,
      skip_deduct: bulkSkipDeduct.value,
      items: selectedBulkIds.value.map(id => ({
        id,
        price: Math.round(bulkPrices[id] * (bulkDuration.value || 1) * 100) / 100,
      })),
    }
    const res = await bulkRenewSubscriptions(payload)
    const { succeeded_count, failed_count, failed } = res || {}

    if (failed_count > 0) {
      const failDetail = failed.slice(0, 5).map(f => `#${f.id} ${f.customer || ''} - ${f.reason}`).join('<br>')
      ElMessageBox.alert(
        `<div>成功 <strong style="color:#67C23A">${succeeded_count}</strong> 条，
        失败 <strong style="color:#F56C6C">${failed_count}</strong> 条</div>
        <div style="margin-top:12px;font-size:13px;color:#606266">
          <div>失败原因：</div>
          ${failDetail}
          ${failed.length > 5 ? `<div style="margin-top:4px">... 还有 ${failed.length - 5} 条</div>` : ''}
        </div>`,
        '批量续费结果',
        { dangerouslyUseHTMLString: true, type: failed_count === succeeded_count + failed_count ? 'error' : 'warning' }
      )
    } else {
      ElMessage.success(`已成功续费 ${succeeded_count} 条`)
    }

    bulkRenewVisible.value = false
    fetchData()
    fetchExpiringCount()
  } catch { /* handled */ }
  finally { bulkRenewLoading.value = false }
}

// ========== Batch Attach Forward ==========
const mainTableRef = ref()
const selectedMainIds = ref([])
const deviceGroupOptions = ref([])
const forwardPlanOptions = ref([])

const batchForwardVisible = ref(false)
const batchForwardLoading = ref(false)
const batchForwardForm = reactive({
  forward_plan_id: null,
  device_group_id: null,
  speed_limit_mbps: null,
  forward_fee: 0,
  deduct_balance: 'next',
})

function onMainSelectionChange(rows) {
  selectedMainIds.value = rows.map(r => r.id)
}

async function loadDeviceGroups() {
  try {
    deviceGroupOptions.value = (await getNyEnabledDeviceGroups()) || []
  } catch { /* handled */ }
}

async function loadForwardPlans() {
  try {
    const res = await getForwardPlans()
    forwardPlanOptions.value = (res || []).filter(p => p.is_active && p.type === 'ny')
  } catch { /* handled */ }
}

function onForwardPlanChange(planId) {
  if (!planId) return
  const plan = forwardPlanOptions.value.find(p => p.id === planId)
  if (!plan) return
  if (plan.device_group_id) batchForwardForm.device_group_id = plan.device_group_id
  if (plan.speed_limit_mbps) batchForwardForm.speed_limit_mbps = plan.speed_limit_mbps
  if (plan.base_price != null) batchForwardForm.forward_fee = Number(plan.base_price)
}

const moduleLabel = (m) => ({ video: '视频专线', live_mobile: '直播-手机', live_pc: '直播-电脑' }[m] || '通用')

function openBatchForward() {
  if (!selectedMainIds.value.length) {
    ElMessage.warning('请先勾选订阅')
    return
  }
  batchForwardForm.forward_plan_id = null
  batchForwardForm.device_group_id = null
  batchForwardForm.speed_limit_mbps = null
  batchForwardForm.forward_fee = 0
  batchForwardForm.deduct_balance = 'next'
  batchForwardVisible.value = true
}

async function submitBatchForward() {
  if (!batchForwardForm.device_group_id) {
    ElMessage.warning('请选择转发节点')
    return
  }
  const fee = Number(batchForwardForm.forward_fee || 0)
  const deductNow = batchForwardForm.deduct_balance === 'current' && fee > 0
  const count = selectedMainIds.value.length
  let confirmMsg = `确认为 ${count} 条订阅提交批量转发任务？\n每条月费 +¥${fee.toFixed(2)}`
  if (deductNow) {
    confirmMsg += `\n\n⚠️ 将立即从客户余额扣除本期费用，合计约 ¥${(fee * count).toFixed(2)}`
  }
  confirmMsg += '\n\n任务会进入队列后台逐条处理，处理完后可以刷新列表查看结果。'
  try {
    await ElMessageBox.confirm(confirmMsg, '批量开通确认', { type: 'warning' })
  } catch { return }

  batchForwardLoading.value = true
  try {
    const res = await batchAttachForward({
      subscription_ids: selectedMainIds.value,
      forward_plan_id: batchForwardForm.forward_plan_id || undefined,
      device_group_id: batchForwardForm.device_group_id,
      speed_limit_mbps: batchForwardForm.speed_limit_mbps || null,
      forward_fee: fee,
      deduct_balance: batchForwardForm.deduct_balance,
    })

    const { batch_id, queued_count, skipped_count, skipped } = res || {}

    ElMessage.success(`已入队 ${queued_count} 条${skipped_count ? `，跳过 ${skipped_count} 条` : ''}`)

    batchForwardVisible.value = false
    mainTableRef.value?.clearSelection()
    selectedMainIds.value = []

    // 打开进度弹窗并启动轮询
    if (batch_id && queued_count > 0) {
      openBatchProgress(batch_id)
    } else if (skipped_count > 0) {
      showSkippedDetail(skipped)
    }

    fetchData()
  } catch { /* handled */ }
  finally { batchForwardLoading.value = false }
}

// ========== Batch Forward Progress Polling ==========
const batchProgressVisible = ref(false)
const batchProgress = reactive({
  batch_id: '',
  total: 0,
  pending: 0,
  processing: 0,
  active: 0,
  failed: 0,
  finished: false,
  progress_pct: 0,
  failed_rules: [],
})
let batchPollTimer = null
let batchPollKind = 'ny' // ny | xui

function openBatchProgress(batchId, kind = 'ny') {
  batchProgress.batch_id = batchId
  batchPollKind = kind
  batchProgress.total = 0
  batchProgress.pending = 0
  batchProgress.processing = 0
  batchProgress.active = 0
  batchProgress.failed = 0
  batchProgress.finished = false
  batchProgress.progress_pct = 0
  batchProgress.failed_rules = []
  batchProgressVisible.value = true
  pollBatchProgress()
}

async function pollBatchProgress() {
  if (!batchProgress.batch_id) return
  try {
    const res = batchPollKind === 'xui'
      ? await getBatchXuiForwardStatus(batchProgress.batch_id)
      : await getBatchForwardStatus(batchProgress.batch_id)
    Object.assign(batchProgress, res)
  } catch { /* 网络错误继续重试 */ }

  if (!batchProgress.finished && batchProgressVisible.value) {
    batchPollTimer = setTimeout(pollBatchProgress, 3000)
  } else if (batchProgress.finished) {
    fetchData()
  }
}

function closeBatchProgress() {
  batchProgressVisible.value = false
  if (batchPollTimer) {
    clearTimeout(batchPollTimer)
    batchPollTimer = null
  }
}

function showSkippedDetail(skipped) {
  const detail = (skipped || []).slice(0, 10)
    .map(s => `<div>#${s.id}: ${s.reason}</div>`).join('')
  ElMessageBox.alert(
    `<div>跳过的订阅：</div><div style="margin-top:8px;font-size:12px;color:#E6A23C">${detail}</div>`,
    '跳过详情',
    { dangerouslyUseHTMLString: true, type: 'info' }
  )
}

// ========== Batch Update Expiry ==========
const batchExpiryVisible = ref(false)
const batchExpiryLoading = ref(false)
const batchExpiryForm = reactive({
  expires_at: null,
  sync_proxy_ip: true,
})

function openBatchExpiry() {
  if (!selectedMainIds.value.length) {
    ElMessage.warning('请先勾选订阅')
    return
  }
  batchExpiryForm.expires_at = null
  batchExpiryForm.sync_proxy_ip = true
  batchExpiryVisible.value = true
}

async function submitBatchExpiry() {
  if (!batchExpiryForm.expires_at) {
    ElMessage.warning('请选择新到期日期')
    return
  }

  const isPast = dayjs(batchExpiryForm.expires_at).endOf('day').isBefore(dayjs())
  const count = selectedMainIds.value.length

  if (isPast) {
    try {
      await ElMessageBox.confirm(
        `所选日期 ${batchExpiryForm.expires_at} 早于今天，${count} 条订阅及对应的 IP 将被标记为「已过期」，过期后将无法编辑。确定继续？`,
        '过期警告',
        { type: 'error', confirmButtonText: '确认标记过期', cancelButtonText: '取消' }
      )
    } catch { return }
  } else {
    try {
      await ElMessageBox.confirm(
        `确认将 ${count} 条订阅的到期时间设置为 ${batchExpiryForm.expires_at}？`,
        '批量修改确认',
        { type: 'warning' }
      )
    } catch { return }
  }

  batchExpiryLoading.value = true
  try {
    const res = await batchUpdateExpiry({
      subscription_ids: selectedMainIds.value,
      expires_at: batchExpiryForm.expires_at,
      sync_proxy_ip: batchExpiryForm.sync_proxy_ip,
    })
    ElMessage.success(`已更新 ${res?.updated_count || 0} 条订阅`)
    batchExpiryVisible.value = false
    mainTableRef.value?.clearSelection()
    selectedMainIds.value = []
    fetchData()
  } catch { /* handled */ }
  finally { batchExpiryLoading.value = false }
}

// ========== Batch 3x-ui Forward ==========
const batchXuiVisible = ref(false)
const batchXuiLoading = ref(false)
const xuiPanelOptions = ref([])
const batchXuiForm = reactive({
  xui_panel_id: null,
})

async function loadXuiPanels() {
  try {
    xuiPanelOptions.value = (await getUsableXuiPanels()) || []
  } catch { /* handled */ }
}

function openBatchXuiForward() {
  if (!selectedMainIds.value.length) {
    ElMessage.warning('请先勾选订阅')
    return
  }
  batchXuiForm.xui_panel_id = null
  // 如果只有一个可用主面板，默认选上
  if (xuiPanelOptions.value.length === 1) {
    batchXuiForm.xui_panel_id = xuiPanelOptions.value[0].id
  }
  batchXuiVisible.value = true
}

async function submitBatchXui() {
  if (!batchXuiForm.xui_panel_id) {
    ElMessage.warning('请选择 3x-ui 面板')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确认为 ${selectedMainIds.value.length} 条订阅创建 3x-ui 中转？\n\n任务会进入队列处理，每条 3-5 秒，可以关闭弹窗在后台跑。`,
      '批量开通 3x-ui 中转',
      { type: 'warning' }
    )
  } catch { return }

  batchXuiLoading.value = true
  try {
    const res = await batchAttachXuiForward({
      subscription_ids: selectedMainIds.value,
      xui_panel_id: batchXuiForm.xui_panel_id,
    })

    const { batch_id, queued_count, skipped_count, skipped } = res || {}
    ElMessage.success(`已入队 ${queued_count} 条${skipped_count ? `，跳过 ${skipped_count} 条` : ''}`)

    batchXuiVisible.value = false
    mainTableRef.value?.clearSelection()
    selectedMainIds.value = []

    if (batch_id && queued_count > 0) {
      openBatchProgress(batch_id, 'xui')
    } else if (skipped_count > 0) {
      showSkippedDetail(skipped)
    }
    fetchData()
  } catch { /* handled */ }
  finally { batchXuiLoading.value = false }
}

// ========== Remark Inline Edit ==========
const editingRemarkId = ref(null)
const editingRemarkValue = ref('')

function startEditRemark(row) {
  editingRemarkId.value = row.id
  editingRemarkValue.value = row.remark || ''
}

async function saveRemark(row) {
  const newVal = editingRemarkValue.value?.trim() || null
  const oldVal = row.remark || null
  editingRemarkId.value = null
  if (newVal === oldVal) return
  try {
    await updateSubscriptionRemark(row.id, newVal)
    row.remark = newVal
    ElMessage.success('备注已更新')
  } catch { /* handled */ }
}

onMounted(async () => {
  if (isSalesRole.value) {
    searchForm.sales_person = authStore.user?.name || ''
  }
  fetchData()
  fetchExpiringCount()
  loadDeviceGroups()
  loadForwardPlans()
  loadXuiPanels()
  try {
    const res = await getUsers({ per_page: 100 })
    staffOptions.value = res?.items || res || []
  } catch {}
})
</script>

<style lang="scss" scoped>
.subscription-list {
  .page-title { margin: 0 0 20px; font-size: 20px; font-weight: 600; color: #2C3E50; }
  .search-card { margin-bottom: 16px; :deep(.el-card__body) { padding-bottom: 2px; } }
  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 16px;
    margin-top: 16px;
    flex-wrap: wrap;
    .custom-size {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: #606266;
      padding: 4px 10px;
      background: #FAF7F2;
      border-radius: 6px;
    }
  }

  .quick-actions {
    margin-bottom: 12px;
    :deep(.el-card__body) { padding: 14px 18px; }
    .quick-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }
    .quick-left {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
      .quick-label { font-size: 13px; color: #4A5568; margin-right: 4px; }
    }
    .quick-right { display: flex; gap: 8px; }
  }

  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 12px; color: #4A5568; }
  .forward-tag { margin-top: 2px; }
  .hint { margin-left: 8px; font-size: 12px; color: #909399; }
}

.batch-stats {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
  margin-top: 16px;
  .stat-item {
    text-align: center;
    padding: 10px 6px;
    background: #FAF7F2;
    border-radius: 8px;
    .label { display: block; font-size: 11px; color: #909399; }
    .val {
      display: block;
      font-size: 20px;
      font-weight: 700;
      color: #2C3E50;
      margin-top: 4px;
      font-family: 'SF Mono', Consolas, Monaco, monospace;
      &.pending { color: #909399; }
      &.processing { color: #409EFF; }
      &.success { color: #67C23A; }
      &.fail { color: #F56C6C; }
    }
  }
}
.failed-list {
  margin-top: 14px;
  max-height: 220px;
  overflow-y: auto;
  padding: 10px 12px;
  background: #FEF0F0;
  border: 1px solid #FDE2E2;
  border-radius: 6px;
  .failed-title { font-size: 13px; font-weight: 600; color: #F56C6C; margin-bottom: 6px; }
  .failed-row {
    padding: 6px 0;
    font-size: 12px;
    border-bottom: 1px dashed #FDE2E2;
    &:last-child { border-bottom: none; }
  }
}

// ========== Bulk Renew Dialog ==========
.bulk-renew-dialog {
  :deep(.el-dialog__header) {
    padding: 20px 24px 14px;
    border-bottom: 1px solid #F0E6DA;
  }
  :deep(.el-dialog__body) {
    padding: 16px 24px;
    background: #FAFAFA;
  }
  :deep(.el-dialog__footer) {
    padding: 14px 24px;
    border-top: 1px solid #F0E6DA;
    background: #fff;
  }
}

.bulk-header {
  .header-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 19px;
    font-weight: 700;
    color: #2C3E50;
  }
  .header-sub {
    margin-top: 4px;
    margin-left: 32px;
    font-size: 12px;
    color: #909399;
  }
}

.bulk-summary {
  display: flex;
  align-items: stretch;
  background: #fff;
  border: 1px solid #EADFD2;
  border-radius: 10px;
  padding: 14px 0;
  margin-bottom: 14px;

  .summary-item {
    flex: 1;
    text-align: center;
    padding: 0 12px;
    .label {
      font-size: 12px;
      color: #909399;
      margin-bottom: 4px;
    }
    .value {
      font-size: 22px;
      font-weight: 700;
      color: #2C3E50;
      .unit {
        font-size: 12px;
        font-weight: 400;
        color: #909399;
        margin-left: 2px;
      }
      &.selected { color: #409EFF; }
      &.highlight { color: #E8913A; font-size: 24px; }
    }
    &.total {
      background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
      border-radius: 0 8px 8px 0;
    }
  }
  .summary-divider {
    width: 1px;
    background: #F0E6DA;
  }
}

.bulk-control-bar {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border: 1px solid #EADFD2;
  border-radius: 10px;
  padding: 10px 14px;
  margin-bottom: 14px;
  flex-wrap: wrap;

  .control-divider {
    width: 1px;
    height: 22px;
    background: #E4E7ED;
  }

  .unified-price-group, .duration-group {
    display: flex;
    align-items: center;
    gap: 6px;
    .label {
      font-size: 13px;
      color: #4A5568;
      font-weight: 500;
    }
  }
}

.bulk-groups {
  max-height: 520px;
  overflow-y: auto;
  padding-right: 4px;
}

.customer-group {
  background: #fff;
  border: 1px solid #EADFD2;
  border-radius: 10px;
  margin-bottom: 12px;
  overflow: hidden;

  .group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
    border-bottom: 1px solid #F5D9B5;

    .group-left {
      display: flex;
      align-items: center;
      gap: 12px;
      .customer-info {
        .customer-name {
          font-size: 15px;
          font-weight: 700;
          color: #2C3E50;
          display: flex;
          align-items: center;
          gap: 8px;
        }
        .customer-meta {
          font-size: 12px;
          color: #909399;
          margin-top: 2px;
        }
      }
    }

    .group-select-count {
      font-size: 13px;
      color: #4A5568;
      strong { color: #409EFF; }
      .group-charged { color: #E8913A; font-weight: 600; }
    }
  }

  .group-items {
    .item-row {
      display: grid;
      grid-template-columns: 44px 1fr 140px 120px 90px 180px;
      gap: 12px;
      align-items: center;
      padding: 10px 16px;
      border-bottom: 1px dashed #F0E6DA;
      cursor: pointer;
      transition: background 0.15s;

      &:last-child { border-bottom: none; }
      &:hover { background: #FAFAFA; }
      &.selected {
        background: linear-gradient(90deg, rgba(232, 145, 58, 0.06), transparent);
        border-left: 3px solid #E8913A;
        padding-left: 13px;
      }

      .row-asset {
        .asset-name {
          font-size: 13px;
          font-weight: 500;
          color: #2C3E50;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .asset-ip {
          font-size: 11px;
          color: #909399;
          margin-top: 2px;
        }
      }

      .row-country {
        .source-name {
          font-size: 11px;
          color: #909399;
          margin-top: 3px;
        }
      }

      .row-expires {
        .expires-date {
          font-size: 13px;
          color: #E6A23C;
          font-weight: 500;
          &.urgent { color: #F56C6C; }
        }
        .days-left {
          font-size: 11px;
          color: #909399;
          margin-top: 2px;
          strong { color: #F56C6C; }
        }
      }

      .row-current-price {
        text-align: right;
        .label {
          font-size: 10px;
          color: #C0C4CC;
        }
        .value {
          font-size: 13px;
          color: #606266;
        }
      }

      .row-new-price {
        display: flex;
        align-items: center;
        gap: 4px;
        .label {
          font-size: 11px;
          color: #909399;
          margin-right: 2px;
        }
      }
    }
  }
}

.bulk-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  .footer-hint {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #909399;
  }
  .footer-actions {
    display: flex;
    gap: 8px;
  }
}

.action-trigger {
  cursor: pointer; font-size: 13px; color: #4F6AF6; font-weight: 500;
  display: inline-flex; align-items: center;
  &:hover { color: #3B51D4; }
}

.remark-inline {
  cursor: pointer;
  .remark-text {
    font-size: 12px;
    &.has { color: #E8913A; background: #FFF8F0; padding: 1px 6px; border-radius: 4px; }
    &.empty { color: #C0C4CC; font-size: 11px; }
  }
}

// ===== 手机端适配 =====
@media (max-width: 768px) {
  .subscription-list {
    .page-title {
      font-size: 17px;
      margin-bottom: 10px;
    }

    // quick-actions 堆叠
    .quick-actions {
      :deep(.el-card__body) {
        padding: 10px 12px !important;
      }
      .quick-row {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
      }
      .quick-left {
        gap: 4px;
        .quick-label { display: none; }
        .el-button { font-size: 11px; padding: 4px 8px; }
      }
      .quick-right {
        flex-wrap: wrap;
        gap: 6px;
        .el-button { font-size: 12px; flex: 1; min-width: 0; }
      }
    }

    // 表格：隐藏次要列
    // 列顺序（有selection）: 1-sel, 2-ID, 3-客户, 4-资产名称, 5-备注, 6-地区, 7-来源, 8-月价, 9-到期, 10-状态, 11-操作(fixed)
    // 手机保留: 3, 4, 9 + fixed 操作列
    :deep(.el-table__body-wrapper) {
      .el-table__row > td.el-table__cell:nth-child(1),
      .el-table__row > td.el-table__cell:nth-child(2),
      .el-table__row > td.el-table__cell:nth-child(5),
      .el-table__row > td.el-table__cell:nth-child(6),
      .el-table__row > td.el-table__cell:nth-child(7),
      .el-table__row > td.el-table__cell:nth-child(8),
      .el-table__row > td.el-table__cell:nth-child(10) {
        display: none;
      }
    }
    :deep(.el-table__header-wrapper) {
      thead tr > th.el-table__cell:nth-child(1),
      thead tr > th.el-table__cell:nth-child(2),
      thead tr > th.el-table__cell:nth-child(5),
      thead tr > th.el-table__cell:nth-child(6),
      thead tr > th.el-table__cell:nth-child(7),
      thead tr > th.el-table__cell:nth-child(8),
      thead tr > th.el-table__cell:nth-child(10) {
        display: none;
      }
    }

    .pagination-wrap {
      flex-direction: column;
      align-items: center;
      gap: 8px;
      .custom-size { display: none; }
    }

    // Bulk renew 弹窗在手机端项目栏改成两行
    .bulk-control-bar {
      padding: 8px;
      gap: 8px;
      .control-divider { display: none; }
      .el-input,
      .unified-price-group,
      .duration-group {
        width: 100%;
        flex-wrap: wrap;
      }
    }
    .bulk-summary {
      flex-wrap: wrap;
      padding: 8px 0;
      .summary-item {
        flex: 1 1 50%;
        padding: 6px;
        .value { font-size: 18px; }
        &.total .value { font-size: 20px; }
      }
      .summary-divider { display: none; }
    }
    .customer-group .group-items .item-row {
      grid-template-columns: 28px 1fr 80px;
      gap: 6px;
      padding: 8px;
      .row-country,
      .row-expires,
      .row-current-price {
        display: none;
      }
      .row-new-price .label { display: none; }
    }

    // 批量统计表格改成 2 列网格
    .batch-stats {
      grid-template-columns: repeat(3, 1fr);
      .stat-item .val { font-size: 16px; }
    }
  }
}
</style>
