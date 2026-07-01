<template>
  <div class="create-order">
    <h2 class="page-title">创建订单</h2>

    <el-card class="section-card">
      <!-- 1. 选客户 -->
      <div class="section-header">1. 选择客户</div>
      <el-select
        v-model="customerId"
        filterable
        remote
        reserve-keyword
        placeholder="搜索客户名称"
        :remote-method="searchCustomers"
        :loading="customerLoading"
        style="width: 360px"
        @change="onCustomerChange"
      >
        <el-option
          v-for="c in customerOptions"
          :key="c.id"
          :label="`${c.customer_name} (${c.username})`"
          :value="c.id"
        />
      </el-select>
    </el-card>

    <!-- 2. 选来源模式 -->
    <el-card class="section-card">
      <div class="section-header">2. 选择IP来源</div>
      <el-radio-group v-model="sourceMode" size="large">
        <el-radio-button value="own">
          <el-icon><Box /></el-icon> 自有IP库存
        </el-radio-button>
        <el-radio-button value="spark">
          <el-icon><Connection /></el-icon> {{ providerNames.spark }}
        </el-radio-button>
        <el-radio-button value="ipipv">
          <el-icon><Connection /></el-icon> {{ providerNames.ipipv }}
        </el-radio-button>
      </el-radio-group>
    </el-card>

    <!-- 3A. 自有IP模式 -->
    <el-card v-if="sourceMode === 'own'" class="section-card">
      <div class="section-header">3. 选择IP (自有库存)</div>
      <el-table :data="ownItems" border>
        <el-table-column label="IP组" min-width="180">
          <template #default="{ row }">
            <el-select v-model="row.ip_group_id" placeholder="选择IP组" style="width: 100%" @change="onOwnIpGroupChange(row)">
              <el-option v-for="g in ipGroupOptions" :key="g.id" :label="g.name" :value="g.id" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="资产组(可选)" min-width="180">
          <template #default="{ row }">
            <el-select v-model="row.asset_group_id" placeholder="不限" clearable style="width: 100%" @change="fetchAvailable(row)">
              <el-option v-for="g in assetGroupOptions" :key="g.id" :label="g.name" :value="g.id" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="可用" width="70" align="center">
          <template #default="{ row }">
            <span :style="{ color: row.available > 0 ? '#67C23A' : '#F56C6C', fontWeight: 600 }">{{ row.available ?? '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="数量" width="100">
          <template #default="{ row }">
            <el-input-number v-model="row.quantity" :min="1" :max="row.available || 999" size="small" controls-position="right" />
          </template>
        </el-table-column>
        <el-table-column label="单价(元/月)" width="140">
          <template #default="{ row }">
            <el-input-number v-model="row.unit_price" :min="0" :precision="2" size="small" controls-position="right" />
          </template>
        </el-table-column>
        <el-table-column label="硬成本/月" width="130">
          <template #default="{ row }">
            <el-input-number v-model="row.hard_cost" :min="0" :precision="2" size="small" controls-position="right" placeholder="可选" />
          </template>
        </el-table-column>
        <el-table-column label="时长(月)" width="100">
          <template #default="{ row }">
            <el-input-number v-model="row.duration" :min="1" :max="36" size="small" controls-position="right" />
          </template>
        </el-table-column>
        <el-table-column label="小计" width="100" align="right">
          <template #default="{ row }">
            <span class="subtotal">¥{{ (row.quantity * row.unit_price * (row.duration || 1)).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column width="60" align="center">
          <template #default="{ $index }">
            <el-button type="danger" link size="small" @click="ownItems.splice($index, 1)">
              <el-icon><Delete /></el-icon>
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-button class="add-row-btn" @click="addOwnItem">
        <el-icon><Plus /></el-icon> 添加项目
      </el-button>
    </el-card>

    <!-- 3B. Spark模式 -->
    <el-card v-if="sourceMode === 'spark'" class="section-card spark-panel">
      <div class="section-header">
        <span>3. 选择{{ providerNames.spark }}产品</span>
        <el-button type="primary" size="small" @click="fetchSparkProductList" :loading="sparkLoading" style="margin-left: auto">
          <el-icon><Refresh /></el-icon> {{ sparkProducts.length ? '刷新' : '拉取产品列表' }}
        </el-button>
      </div>

      <el-alert v-if="!sparkProducts.length && !sparkLoading" type="info" :closable="false" show-icon>
        点击"拉取产品列表"实时获取可选产品、库存和价格。
      </el-alert>

      <el-alert
        v-if="canViewCost && sparkProducts.length && !priceAuthorized"
        type="warning"
        :closable="false"
        show-icon
        style="margin-bottom: 12px"
      >
        <template #title>
          <strong>成本价格未授权</strong> - 上游未返回真实成本价。请联系运营开通价格授权，下单时业务员必须手动填写售价。
        </template>
      </el-alert>

      <!-- 筛选工具栏 -->
      <div v-if="sparkProducts.length" class="filter-bar">
        <el-input
          v-model="sparkKeyword"
          placeholder="搜索产品/城市/国家"
          clearable
          :prefix-icon="Search"
          size="default"
          style="width: 240px"
        />
        <template v-if="!isSalesRole">
          <el-select v-model="filterIspType" placeholder="ISP类型" clearable size="default" style="width: 140px">
            <el-option label="全部ISP" value="" />
            <el-option label="单ISP" :value="1" />
            <el-option label="双ISP" :value="2" />
            <el-option label="原生ISP" :value="3" />
            <el-option label="机房数据中心" :value="4" />
          </el-select>
          <el-select v-model="filterNetType" placeholder="网络类型" clearable size="default" style="width: 130px">
            <el-option label="全部类型" value="" />
            <el-option label="原生" :value="1" />
            <el-option label="广播" :value="2" />
            <el-option label="未知" :value="0" />
          </el-select>
        </template>
        <el-select v-model="filterProxyType" placeholder="代理类型" clearable size="default" style="width: 140px">
          <el-option label="全部类型" value="" />
          <el-option label="静态住宅" :value="103" />
          <el-option label="动态住宅" :value="104" />
        </el-select>
        <el-select v-model="filterStock" placeholder="库存" clearable size="default" style="width: 130px">
          <el-option label="全部" value="" />
          <el-option label="有库存" value="available" />
          <el-option label="库存 > 100" value="high" />
          <el-option label="已售罄" value="sold_out" />
        </el-select>
        <el-select v-model="sortBy" size="default" style="width: 150px">
          <el-option label="库存优先" value="stock" />
          <el-option label="国家名称" value="country" />
          <el-option label="库存降序" value="stock_desc" />
        </el-select>
        <el-button @click="resetFilters" size="default" plain>
          <el-icon><Refresh /></el-icon> 重置
        </el-button>
        <div class="result-count">共 <strong>{{ totalFiltered }}</strong> 个产品</div>
      </div>

      <!-- 按大洲分组 -->
      <div v-if="groupedProducts.length" class="continent-tabs">
        <el-tabs v-model="activeContinent">
          <el-tab-pane v-for="group in groupedProducts" :key="group.name" :name="group.name">
            <template #label>
              <span>{{ group.name }}</span>
              <el-badge :value="group.total" :max="999" class="tab-badge" />
            </template>

            <div class="product-grid">
              <div
                v-for="p in group.products"
                :key="p.product_id"
                class="product-card"
                :class="{ 'out-of-stock': p.inventory <= 0, 'selected': isSelected(p.product_id) }"
                @click="p.inventory > 0 && addSparkItem(p)"
              >
                <div class="card-top">
                  <div class="country-flag"><img :src="getFlagUrl(p.iso2)" :alt="p.country_cn" class="flag-img" /></div>
                  <el-tag :type="stockTagType(p.inventory)" size="small" effect="dark">
                    {{ p.inventory > 0 ? `库存 ${p.inventory}` : '售罄' }}
                  </el-tag>
                </div>
                <div class="country-name">{{ p.country_cn }}</div>
                <div class="product-name" :title="p.product_name">{{ p.display_name }}</div>
                <div class="tags">
                  <el-tag v-if="p.proxy_type === 103" size="small" effect="plain" type="info">静态</el-tag>
                  <el-tag v-if="p.proxy_type === 104" size="small" effect="plain" type="info">动态</el-tag>
                  <template v-if="!isSalesRole">
                    <el-tag v-if="p.isp_label" size="small" effect="plain">{{ p.isp_label }}</el-tag>
                    <el-tag v-if="p.net_label" size="small" effect="plain" :type="p.net_type === 1 ? 'success' : 'warning'">{{ p.net_label }}</el-tag>
                  </template>
                </div>
                <div class="card-bottom">
                  <span class="duration">⏱ {{ p.duration }}{{ unitLabel(p.unit) }}</span>
                  <template v-if="isSalesRole">
                    <span v-if="p.sales_price" class="cost" style="color:#409EFF">成本 ¥{{ p.sales_price }}</span>
                  </template>
                  <template v-else-if="canViewCost">
                    <span v-if="p.cost_price !== null" class="cost">成本 ¥{{ p.cost_price }}</span>
                    <el-tooltip v-else content="Spark 未授权成本价，请联系运营">
                      <span class="cost-unavailable">价格未授权</span>
                    </el-tooltip>
                  </template>
                </div>
              </div>
            </div>
            <el-empty v-if="!group.products.length" description="暂无产品" :image-size="60" />
          </el-tab-pane>
        </el-tabs>
      </div>
      <el-empty v-else-if="sparkProducts.length && !groupedProducts.length" description="无符合条件的产品" />

      <!-- 已选 Spark 产品 -->
      <template v-if="sparkItems.length">
        <div class="section-sub-header">
          已选 {{ sparkItems.length }} 个产品（请填写售价）
        </div>
        <div class="selected-items">
          <div v-for="(row, idx) in sparkItems" :key="row.product_id" class="selected-item-card">
            <div class="item-main">
              <div class="item-product">
                <img :src="getFlagUrl(row.iso2)" :alt="row.country_cn" class="flag-img-sm" />
                <div class="item-info">
                  <strong>{{ row.country_cn }}</strong>
                  <span class="item-sub">{{ row.display_name }}</span>
                </div>
              </div>
              <div class="item-fields">
                <div class="field-group">
                  <label>数量</label>
                  <el-input-number v-model="row.quantity" :min="1" :max="row.inventory" size="small" controls-position="right" style="width: 100px" />
                </div>
                <div class="field-group">
                  <label>IP售价(元/条)</label>
                  <el-input-number v-model="row.sale_price" :min="0" :precision="2" size="small" controls-position="right" style="width: 130px" />
                </div>
                <div v-if="canViewCost && !isSalesRole" class="field-group">
                  <label>成本</label>
                  <span v-if="row.cost_price !== null" class="cost-text">¥{{ row.cost_price }}</span>
                  <span v-else class="cost-na">未授权</span>
                </div>
                <div v-if="isSalesRole" class="field-group">
                  <label>成本</label>
                  <span v-if="row.sales_price" class="cost-text" style="color:#409EFF">¥{{ row.sales_price }}</span>
                  <span v-else class="cost-na">-</span>
                </div>
                <div class="field-group">
                  <label>IP段</label>
                  <el-select v-if="row.cidr_options && row.cidr_options.length" v-model="row.selected_cidr"
                    clearable placeholder="随机" size="small" style="width: 160px">
                    <el-option label="随机分配" :value="null" />
                    <el-option v-for="c in row.cidr_options" :key="c.cidr"
                      :label="`${c.cidr} (${c.count})`" :value="c.cidr" />
                  </el-select>
                  <span v-else class="cost-na">随机分配</span>
                </div>
                <div class="field-group">
                  <label>中转套餐</label>
                  <el-select v-model="row.forward_plan_id" placeholder="不加中转" clearable size="small" style="width: 200px" @change="onForwardPlanChange(row)">
                    <el-option
                      v-for="fp in forwardPlanOptions"
                      :key="fp.id"
                      :label="fp.name"
                      :value="fp.id"
                    >
                      <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ fp.name }}</span>
                        <span style="font-size: 11px; color: #E8913A; flex-shrink: 0">¥{{ Number(fp.base_price).toFixed(0) }}/月</span>
                      </div>
                    </el-option>
                  </el-select>
                </div>
                <div v-if="row.forward_plan_id" class="field-group">
                  <label>中转价(可改)</label>
                  <el-input-number v-model="row.forward_price_override" :min="0" :precision="2" size="small" controls-position="right" style="width: 130px" />
                  <span class="field-hint">元/条/月</span>
                </div>
                <div class="field-group">
                  <label>时长(月)</label>
                  <el-input-number v-model="row.duration" :min="1" :max="36" size="small" controls-position="right" style="width: 100px" />
                </div>
              </div>
              <div class="item-summary">
                <div class="item-total">
                  <span class="subtotal">¥{{ getItemTotal(row).toFixed(2) }}</span>
                  <div v-if="row.forward_plan_id || row.duration > 1" class="total-detail">
                    <template v-if="row.forward_plan_id">
                      IP ¥{{ ((row.sale_price || 0) * row.quantity * (row.duration || 1)).toFixed(0) }} + 中转 ¥{{ (getItemForwardCost(row) * row.quantity * (row.duration || 1)).toFixed(0) }}
                    </template>
                    <template v-else>
                      {{ row.quantity }}条 × ¥{{ row.sale_price || 0 }} × {{ row.duration }}月
                    </template>
                  </div>
                </div>
                <el-button type="danger" link size="small" @click="sparkItems.splice(idx, 1)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
            </div>
          </div>
        </div>
      </template>
    </el-card>

    <!-- 3C. IPIPV 模式 -->
    <el-card v-if="sourceMode === 'ipipv'" class="section-card spark-panel">
      <div class="section-header">
        <span>3. 选择{{ providerNames.ipipv }}产品</span>
        <el-button type="primary" size="small" @click="fetchIpipvProductList" :loading="ipipvLoading" style="margin-left: auto">
          <el-icon><Refresh /></el-icon> {{ ipipvProducts.length ? '刷新' : '拉取产品列表' }}
        </el-button>
      </div>

      <el-alert v-if="!ipipvProducts.length && !ipipvLoading" type="info" :closable="false" show-icon>
        点击"拉取产品列表"获取可选产品。
      </el-alert>

      <div v-if="ipipvProducts.length" class="filter-bar">
        <el-input v-model="ipipvKeyword" placeholder="搜索产品/国家" clearable :prefix-icon="Search" size="default" style="width: 240px" />
        <div class="result-count">共 <strong>{{ filteredIpipvProducts.length }}</strong> 个产品</div>
      </div>

      <div v-if="filteredIpipvProducts.length" class="product-grid">
        <div
          v-for="p in filteredIpipvProducts"
          :key="p.productNo"
          class="product-card"
          :class="{ 'out-of-stock': p.inventory <= 0, 'selected': ipipvItems.some(i => i.product_no === p.productNo) }"
          @click="p.inventory > 0 && addIpipvItem(p)"
        >
          <div class="card-top">
            <div class="country-flag"><img :src="getFlagUrl(p.iso2)" :alt="p.countryName" class="flag-img" /></div>
            <el-tag :type="stockTagType(p.inventory || 0)" size="small" effect="dark">
              {{ (p.inventory || 0) > 0 ? `库存 ${p.inventory}` : '售罄' }}
            </el-tag>
          </div>
          <div class="country-name">{{ p.countryName || p.countryCode || '-' }}</div>
          <div class="product-name" :title="p.productName || p.productNo">{{ p.productName || p.productNo }}</div>
          <div class="tags">
            <el-tag v-if="p.cityName" size="small" effect="plain" type="info">{{ p.cityName }}</el-tag>
          </div>
          <div class="card-bottom">
            <span class="duration">⏱ {{ p.duration || 1 }}{{ unitLabel(p.unit || 3) }}</span>
            <template v-if="isSalesRole">
              <span v-if="p.sales_price" class="cost" style="color:#409EFF">成本 ¥{{ p.sales_price }}</span>
            </template>
            <template v-else-if="canViewCost">
              <span v-if="p.costPrice" class="cost">成本 ¥{{ p.costPrice }}</span>
            </template>
          </div>
        </div>
      </div>

      <!-- 已选 IPIPV 产品 -->
      <template v-if="ipipvItems.length">
        <div class="section-sub-header">
          已选 {{ ipipvItems.length }} 个产品（请填写售价）
        </div>
        <div class="selected-items">
          <div v-for="(row, idx) in ipipvItems" :key="row.product_no" class="selected-item-card">
            <div class="item-main">
              <div class="item-product">
                <div class="item-info">
                  <strong>{{ row.countryName || row.product_no }}</strong>
                  <span class="item-sub">{{ row.productName }} · {{ row.duration }}{{ unitLabel(row.unit) }}</span>
                </div>
              </div>
              <div class="item-fields">
                <div class="field-group">
                  <label>数量</label>
                  <el-input-number v-model="row.quantity" :min="1" :max="20" size="small" controls-position="right" style="width: 100px" />
                </div>
                <div class="field-group">
                  <label>IP售价(元/条)</label>
                  <el-input-number v-model="row.sale_price" :min="0" :precision="2" size="small" controls-position="right" style="width: 130px" />
                </div>
                <div class="field-group">
                  <label>IP段</label>
                  <el-select v-if="row.cidr_options && row.cidr_options.length" v-model="row.selected_cidr"
                    clearable placeholder="随机" size="small" style="width: 160px">
                    <el-option label="随机分配" :value="null" />
                    <el-option v-for="c in row.cidr_options" :key="c.cidr"
                      :label="`${c.cidr} (${c.count})`" :value="c.cidr" />
                  </el-select>
                  <span v-else class="cost-na">随机分配</span>
                </div>
                <div class="field-group">
                  <label>中转套餐</label>
                  <el-select v-model="row.forward_plan_id" placeholder="不加中转" clearable size="small" style="width: 200px" @change="onForwardPlanChange(row)">
                    <el-option
                      v-for="fp in forwardPlanOptions"
                      :key="fp.id"
                      :label="fp.name"
                      :value="fp.id"
                    >
                      <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ fp.name }}</span>
                        <span style="font-size: 11px; color: #E8913A; flex-shrink: 0">¥{{ Number(fp.base_price).toFixed(0) }}/月</span>
                      </div>
                    </el-option>
                  </el-select>
                </div>
                <div v-if="row.forward_plan_id" class="field-group">
                  <label>中转价(可改)</label>
                  <el-input-number v-model="row.forward_price_override" :min="0" :precision="2" size="small" controls-position="right" style="width: 130px" />
                  <span class="field-hint">元/条/月</span>
                </div>
                <div class="field-group">
                  <label>时长(月)</label>
                  <el-input-number v-model="row.duration" :min="1" :max="36" size="small" controls-position="right" style="width: 100px" />
                </div>
              </div>
              <div class="item-summary">
                <div class="item-total">
                  <span class="subtotal">¥{{ getItemTotal(row).toFixed(2) }}</span>
                  <div v-if="row.forward_plan_id || row.duration > 1" class="total-detail">
                    <template v-if="row.forward_plan_id">
                      IP ¥{{ ((row.sale_price || 0) * row.quantity * (row.duration || 1)).toFixed(0) }} + 中转 ¥{{ (getItemForwardCost(row) * row.quantity * (row.duration || 1)).toFixed(0) }}
                    </template>
                    <template v-else>
                      {{ row.quantity }}条 × ¥{{ row.sale_price || 0 }} × {{ row.duration }}月
                    </template>
                  </div>
                </div>
                <el-button type="danger" link size="small" @click="ipipvItems.splice(idx, 1)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
            </div>
          </div>
        </div>
      </template>
    </el-card>

    <!-- 4. 汇总提交 -->
    <el-card class="section-card summary-card">
      <div v-if="!isSalesMode" class="payment-method-row">
        <span class="payment-label">付款方式：</span>
        <el-radio-group v-model="paymentMethod">
          <el-radio value="offline">线下已付（不扣余额）</el-radio>
          <el-radio value="balance">
            扣客户余额
            <span v-if="customerBalance !== null" class="balance-hint">
              （当前余额：<b :style="{ color: customerBalance < parseFloat(totalAmount) ? '#F56C6C' : '#67C23A' }">¥{{ customerBalance.toFixed(2) }}</b>）
            </span>
          </el-radio>
        </el-radio-group>
        <el-alert
          v-if="paymentMethod === 'balance' && customerBalance !== null && customerBalance < parseFloat(totalAmount)"
          type="error"
          :closable="false"
          show-icon
          style="margin-top: 8px"
        >
          客户余额不足，请先为客户充值或选择"线下已付"
        </el-alert>
      </div>

      <el-form-item label="备注">
        <el-input v-model="remark" type="textarea" :rows="2" placeholder="选填" style="max-width: 500px" />
      </el-form-item>

      <!-- 测试模式 -->
      <div v-if="!isSalesMode && (sourceMode === 'spark' || sourceMode === 'ipipv')" class="test-mode-row">
        <el-switch v-model="isTestOrder" active-color="#E6A23C" />
        <span class="test-label">测试订单</span>
        <template v-if="isTestOrder">
          <el-radio-group v-model="testHours" size="small" style="margin-left: 12px">
            <el-radio-button :value="12">12小时</el-radio-button>
            <el-radio-button :value="23">23小时</el-radio-button>
          </el-radio-group>
          <el-tag type="warning" size="small" effect="dark" style="margin-left: 8px">
            {{ testHours }}小时后自动回收 + API释放 + 删除转发
          </el-tag>
        </template>
      </div>

      <div class="summary-row">
        <div class="total-label">
          订单总额：
          <span class="total-amount">¥{{ totalAmount }}</span>
          <div v-if="forwardTotal > 0" class="forward-breakdown">
            <span>含中转费：¥{{ forwardTotal.toFixed(2) }}</span>
          </div>
        </div>
        <el-button
          v-if="!isSalesMode"
          :type="isTestOrder ? 'warning' : 'primary'" size="large" :loading="submitting" :disabled="!canSubmit" @click="handleSubmit"
        >
          {{ isTestOrder ? '确认下单 (测试)' : '确认下单' }}
        </el-button>
        <el-button
          v-else
          type="warning" size="large" :loading="submitting" :disabled="!canSubmit" @click="handleSubmitApproval"
        >
          提交审批
        </el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import { getCustomers, getCustomer } from '@/api/customers'
import { getAllIpGroups } from '@/api/ipGroups'
import { getAllAssetGroups } from '@/api/assetGroups'
import { lookupPricing } from '@/api/pricingRules'
import { createOrder, getAvailableIps } from '@/api/subscriptions'
import { getSparkProducts, sparkProvision } from '@/api/spark'
import { getIpipvProducts, ipipvProvision } from '@/api/ipipv'
import { getForwardPlans } from '@/api/forwardPlans'
import { getCountryInfo, extractCityFromName, getFlagUrl, CONTINENTS } from '@/utils/countries'
import { useAuthStore } from '@/stores/auth'
import { submitApproval } from '@/api/approvals'
import request from '@/utils/request'

const router = useRouter()
const authStore = useAuthStore()

const providerNames = ref({ spark: 'Spark API', ipipv: 'IPIPV API' })
async function loadProviderNames() {
  try {
    const res = await request.get('/upstream-providers/display-names')
    if (res?.spark) providerNames.value.spark = res.spark
    if (res?.ipipv) providerNames.value.ipipv = res.ipipv
  } catch {}
}

// ===== 权限检测 =====
const isSalesMode = computed(() => {
  if (!authStore.user) return false
  if (authStore.user.roles?.includes('super_admin')) return false
  const perms = authStore.user.permissions || []
  // 有 submit_approval 但没有 subscription.create + spark.manage → 销售模式
  return perms.includes('subscription.submit_approval') && !perms.includes('subscription.create')
})

// ===== 客户 =====
const customerId = ref(null)
const customerOptions = ref([])
const customerLoading = ref(false)

async function searchCustomers(keyword) {
  if (!keyword) {
    // 关键字为空时加载最近的客户
    loadRecentCustomers()
    return
  }
  customerLoading.value = true
  try {
    const res = await getCustomers({ 'filter[keyword]': keyword, per_page: 30 })
    customerOptions.value = res?.items || (Array.isArray(res) ? res : [])
  } catch { /* handled */ }
  finally { customerLoading.value = false }
}

async function loadRecentCustomers() {
  try {
    const res = await getCustomers({ per_page: 30 })
    customerOptions.value = res?.items || []
  } catch { /* handled */ }
}

// ===== 付款方式 =====
const paymentMethod = ref('offline')
const customerBalance = ref(null)

async function onCustomerChange(id) {
  customerBalance.value = null
  if (!id) return
  try {
    const res = await getCustomer(id)
    const c = res?.data || res
    customerBalance.value = parseFloat(c?.balance ?? 0)
  } catch { /* handled */ }
}

// ===== 来源模式 =====
const sourceMode = ref('own')

// ===== 下拉选项 =====
const ipGroupOptions = ref([])
const assetGroupOptions = ref([])

async function loadOptions() {
  try {
    const [ipRes, assetRes] = await Promise.all([getAllIpGroups(), getAllAssetGroups()])
    ipGroupOptions.value = Array.isArray(ipRes) ? ipRes : []
    assetGroupOptions.value = Array.isArray(assetRes) ? assetRes : []
  } catch { /* handled */ }
}

// ===== 自有IP模式 =====
const ownItems = ref([])

function addOwnItem() {
  ownItems.value.push({ ip_group_id: null, asset_group_id: null, quantity: 1, unit_price: 0, hard_cost: null, duration: 1, available: null })
}

async function onOwnIpGroupChange(row) {
  if (row.ip_group_id) {
    try {
      const rule = await lookupPricing({ ip_group_id: row.ip_group_id })
      if (rule) row.unit_price = parseFloat(rule.price) || 0
    } catch { /* no rule */ }
  }
  fetchAvailable(row)
}

async function fetchAvailable(row) {
  if (!row.ip_group_id) return
  try {
    const params = { ip_group_id: row.ip_group_id }
    if (row.asset_group_id) params.asset_group_id = row.asset_group_id
    const res = await getAvailableIps(params)
    row.available = res?.available_count ?? 0
  } catch { row.available = 0 }
}

// ===== Spark 模式 =====
const sparkLoading = ref(false)
const sparkProducts = ref([])
const sparkItems = ref([])
const sparkKeyword = ref('')
const activeContinent = ref('')

// 筛选条件
const filterIspType = ref('')
const filterNetType = ref('')
const filterProxyType = ref('')
const filterStock = ref('')
const sortBy = ref('stock')

const ISP_LABELS = { 1: '单ISP', 2: '双ISP', 3: '原生ISP', 4: '机房数据中心' }

function resetFilters() {
  sparkKeyword.value = ''
  filterIspType.value = ''
  filterNetType.value = ''
  filterProxyType.value = ''
  filterStock.value = ''
  sortBy.value = 'stock'
}

function stockTagType(n) {
  if (n <= 0) return 'danger'
  if (n < 10) return 'warning'
  return 'success'
}

const priceAuthorized = ref(false) // 价格是否已授权
const canViewCost = ref(false) // 是否有权看成本价
const isSalesRole = ref(false) // 当前用户是否是销售角色

const STATE_ABBR = {
  '加利福尼亚': '加州', '宾夕法尼亚': '宾州', '马萨诸塞': '麻省',
  '北卡罗来纳': '北卡', '南卡罗来纳': '南卡', '西弗吉尼亚': '西弗州',
  '明尼苏达': '明州', '康涅狄格': '康州', '路易斯安那': '路州',
  '新罕布什尔': '新罕州', '俄克拉荷马': '俄州', '密西西比': '密州',
}
function buildDisplayName(p) {
  const area = p.area_name ? (STATE_ABBR[p.area_name] || p.area_name) : ''
  const city = p.city_name || ''
  if (area && city) return `${area} · ${city}`
  if (area || city) return area || city
  return extractCityFromName(p.product_name, p.country_code) || p.product_name
}

async function fetchSparkProductList() {
  sparkLoading.value = true
  try {
    const res = await getSparkProducts({ pageSize: 200 })
    const raw = res?.products || []
    canViewCost.value = res?.can_view_cost ?? false
    isSalesRole.value = res?.is_sales_role ?? false

    // 检测价格是否授权：如果所有产品 costPrice 都相同且 >= 3 条产品，视为未授权（占位值）
    const prices = raw
      .map(p => parseFloat(p.cost_price))
      .filter(v => !isNaN(v) && v > 0)
    const uniquePrices = [...new Set(prices)]
    // 有效的授权：至少2种不同价格 或 只有1个产品
    priceAuthorized.value = canViewCost.value && (raw.length < 3 || uniquePrices.length >= 2)

    sparkProducts.value = raw.map(p => {
      const info = getCountryInfo(p.country_code)
      const rawCost = parseFloat(p.cost_price)
      return {
        ...p,
        // 有权限 + 价格授权时才显示真实价格，否则为 null
        cost_price: priceAuthorized.value && !isNaN(rawCost) && rawCost > 0 ? rawCost : null,
        iso2: info.iso2,
        country_cn: info.cn,
        continent: info.continent,
        area_name: p.area_name || '',
        city_name: p.city_name || '',
        display_name: buildDisplayName(p),
        isp_label: ISP_LABELS[p.isp_type],
        net_label: p.net_type === 1 ? '原生' : (p.net_type === 2 ? '广播' : null),
      }
    })
    if (groupedProducts.value.length) {
      activeContinent.value = groupedProducts.value[0].name
    }
  } catch { /* handled */ }
  finally { sparkLoading.value = false }
}

const filteredProducts = computed(() => {
  const kw = sparkKeyword.value.trim().toLowerCase()
  return sparkProducts.value.filter(p => {
    // 关键字
    if (kw) {
      const match = p.country_cn.toLowerCase().includes(kw) ||
        (p.country_code || '').toLowerCase().includes(kw) ||
        (p.product_name || '').toLowerCase().includes(kw) ||
        (p.display_name || '').toLowerCase().includes(kw) ||
        (p.area_name || '').toLowerCase().includes(kw) ||
        (p.city_name || '').toLowerCase().includes(kw)
      if (!match) return false
    }
    // ISP 类型
    if (filterIspType.value !== '' && p.isp_type !== filterIspType.value) return false
    // 网络类型
    if (filterNetType.value !== '' && p.net_type !== filterNetType.value) return false
    // 代理类型
    if (filterProxyType.value !== '' && p.proxy_type !== filterProxyType.value) return false
    // 库存
    if (filterStock.value === 'available' && p.inventory <= 0) return false
    if (filterStock.value === 'high' && p.inventory < 100) return false
    if (filterStock.value === 'sold_out' && p.inventory > 0) return false
    return true
  })
})

const totalFiltered = computed(() => filteredProducts.value.length)

const groupedProducts = computed(() => {
  const map = new Map()
  for (const cont of CONTINENTS) map.set(cont, [])
  map.set('其他', [])

  for (const p of filteredProducts.value) {
    const key = map.has(p.continent) ? p.continent : '其他'
    map.get(key).push(p)
  }

  // 排序函数
  const sortFn = (a, b) => {
    switch (sortBy.value) {
      case 'stock':
        return (b.inventory > 0) - (a.inventory > 0) || a.country_cn.localeCompare(b.country_cn)
      case 'stock_desc':
        return b.inventory - a.inventory
      case 'country':
        return a.country_cn.localeCompare(b.country_cn)
      default:
        return 0
    }
  }

  return Array.from(map.entries())
    .map(([name, products]) => ({
      name,
      products: [...products].sort(sortFn),
      total: products.length,
    }))
    .filter(g => g.products.length > 0)
})

function isSelected(productId) {
  return sparkItems.value.some(i => i.product_id === productId)
}

function addSparkItem(product) {
  const exists = sparkItems.value.find(i => i.product_id === product.product_id)
  if (exists) { exists.quantity++; return }
  sparkItems.value.push({
    product_id: product.product_id,
    product_name: product.product_name,
    display_name: product.display_name,
    country_code: product.country_code,
    country_cn: product.country_cn,
    iso2: product.iso2,
    cost_price: product.cost_price,
    sales_price: product.sales_price || null,
    duration: durationToMonths(product.duration, product.unit),
    unit: 3,
    inventory: product.inventory,
    quantity: 1,
    sale_price: product.sale_price_ref || 0,
    cidr_options: product.cidr_blocks || [],
    selected_cidr: null,
    forward_plan_id: null,
    forward_price_override: null,
  })
}

function unitLabel(u) {
  return { 1: '天', 2: '周', 3: '月', 4: '年' }[u] || ''
}

function durationToMonths(duration, unit) {
  const d = duration || 1
  const u = unit || 3
  if (u === 1) return Math.max(1, Math.round(d / 30))
  if (u === 2) return Math.max(1, Math.round(d * 7 / 30))
  if (u === 4) return d * 12
  return d
}

// ===== IPIPV 模式 =====
const ipipvLoading = ref(false)
const ipipvProducts = ref([])
const ipipvItems = ref([])
const ipipvKeyword = ref('')

async function fetchIpipvProductList() {
  ipipvLoading.value = true
  try {
    const res = await getIpipvProducts()
    const list = res?.products || []
    if (res?.can_view_cost !== undefined) canViewCost.value = res.can_view_cost
    if (res?.is_sales_role !== undefined) isSalesRole.value = res.is_sales_role
    ipipvProducts.value = list.map(p => {
      const info = getCountryInfo(p.countryCode)
      return {
        ...p,
        productName: p.productName || p.productNo,
        countryName: info.cn || p.countryCode || '',
        cityName: p.cityName || p.cityCode || '',
        inventory: typeof p.inventory === 'number' ? p.inventory : parseInt(p.inventory) || 0,
        costPrice: p.costPrice ?? p.cost ?? null,
        duration: p.duration || 1,
        unit: p.unit || 3,
        iso2: info.iso2 || '',
        cidrBlocks: p.cidrBlocks || [],
      }
    })
  } catch { /* handled */ }
  finally { ipipvLoading.value = false }
}

const filteredIpipvProducts = computed(() => {
  const kw = ipipvKeyword.value.trim().toLowerCase()
  if (!kw) return ipipvProducts.value
  return ipipvProducts.value.filter(p => {
    const fields = [p.productName, p.countryName, p.countryCode, p.cityName, p.productNo].filter(Boolean).join(' ').toLowerCase()
    return fields.includes(kw)
  })
})

function addIpipvItem(product) {
  const exists = ipipvItems.value.find(i => i.product_no === product.productNo)
  if (exists) { exists.quantity++; return }
  ipipvItems.value.push({
    product_no: product.productNo,
    productName: product.productName,
    countryName: product.countryName,
    countryCode: product.countryCode,
    duration: durationToMonths(product.duration, product.unit),
    unit: 3,
    quantity: 1,
    sale_price: 0,
    cidr_options: product.cidrBlocks || [],
    selected_cidr: null,
    forward_plan_id: null,
    forward_price_override: null,
  })
}

// ===== 中转套餐 (per-item) =====
const forwardPlanOptions = ref([])

const selectedCustomer = computed(() =>
  customerOptions.value.find(c => c.id === customerId.value)
)

function getForwardPlanCost(planId) {
  if (!planId) return 0
  const plan = forwardPlanOptions.value.find(fp => fp.id === planId)
  return plan ? Number(plan.base_price) : 0
}

function getItemForwardCost(row) {
  if (!row.forward_plan_id) return 0
  if (row.forward_price_override !== undefined && row.forward_price_override !== null) {
    return Number(row.forward_price_override)
  }
  return getForwardPlanCost(row.forward_plan_id)
}

function onForwardPlanChange(row) {
  if (row.forward_plan_id) {
    row.forward_price_override = getForwardPlanCost(row.forward_plan_id)
  } else {
    row.forward_price_override = null
  }
}

function getItemTotal(row) {
  const ipPrice = Number(row.sale_price || 0)
  const fwdPrice = getItemForwardCost(row)
  return (ipPrice + fwdPrice) * (row.quantity || 1) * (row.duration || 1)
}

const hasAnyForwardPlan = computed(() => {
  const items = sourceMode.value === 'ipipv' ? ipipvItems.value : sparkItems.value
  return items.some(i => i.forward_plan_id)
})

async function loadForwardPlans() {
  try {
    forwardPlanOptions.value = (await getForwardPlans()) || []
  } catch { /* handled */ }
}

// ===== 汇总 =====
const remark = ref('')
const isTestOrder = ref(false)
const testHours = ref(12)
const submitting = ref(false)

const sparkBaseTotal = computed(() =>
  sparkItems.value.reduce((s, i) => s + i.quantity * i.sale_price * (i.duration || 1), 0)
)

const ipipvBaseTotal = computed(() =>
  ipipvItems.value.reduce((s, i) => s + i.quantity * (i.sale_price || 0) * (i.duration || 1), 0)
)

const forwardTotal = computed(() => {
  const items = sourceMode.value === 'ipipv' ? ipipvItems.value : sparkItems.value
  return items.reduce((s, i) => s + getItemForwardCost(i) * (i.quantity || 0) * (i.duration || 1), 0)
})

const totalAmount = computed(() => {
  if (sourceMode.value === 'own') {
    return ownItems.value.reduce((s, i) => s + i.quantity * i.unit_price * (i.duration || 1), 0).toFixed(2)
  }
  if (sourceMode.value === 'ipipv') {
    return (ipipvBaseTotal.value + forwardTotal.value).toFixed(2)
  }
  return (sparkBaseTotal.value + forwardTotal.value).toFixed(2)
})

const canSubmit = computed(() => {
  if (!customerId.value) return false
  if (sourceMode.value === 'own') return ownItems.value.some(i => i.ip_group_id)
  if (sourceMode.value === 'spark' && !sparkItems.value.length) return false
  if (sourceMode.value === 'ipipv' && !ipipvItems.value.length) return false
  return true
})

async function handleSubmit() {
  if (!customerId.value) { ElMessage.warning('请选择客户'); return }

  if (sourceMode.value === 'spark') {
    const unpriced = sparkItems.value.filter(i => !i.sale_price || i.sale_price <= 0)
    if (unpriced.length) {
      ElMessage.warning(`有 ${unpriced.length} 个产品未填写售价，请先填写`)
      return
    }
  }
  if (sourceMode.value === 'ipipv') {
    const unpriced = ipipvItems.value.filter(i => !i.sale_price || i.sale_price <= 0)
    if (unpriced.length) {
      ElMessage.warning(`有 ${unpriced.length} 个产品未填写售价，请先填写`)
      return
    }
  }

  if (paymentMethod.value === 'balance') {
    if (customerBalance.value === null) {
      ElMessage.warning('正在获取客户余额，请稍后重试')
      return
    }
    if (customerBalance.value < parseFloat(totalAmount.value)) {
      ElMessage.error('客户余额不足，请先充值或选择"线下已付"')
      return
    }
  }

  try {
    const payLabel = paymentMethod.value === 'balance' ? '（扣客户余额）' : '（线下已付）'
    const confirmMsg = isTestOrder.value
      ? `⚠️ 测试订单：IP 将在 ${testHours.value} 小时后自动回收并释放。\n\n确认下单？总金额：¥${totalAmount.value} ${payLabel}`
      : `确认下单？总金额：¥${totalAmount.value} ${payLabel}`
    await ElMessageBox.confirm(confirmMsg, isTestOrder.value ? '测试下单确认' : '确认', { type: 'warning' })
  } catch { return }

  // 检查客户中转权限
  if (hasAnyForwardPlan.value && customerId.value) {
    const cust = selectedCustomer.value
    if (cust && !cust.forward_certified) {
      try {
        await ElMessageBox.confirm(
          '该客户尚未开通中转权限。是否同时为该客户开启中转权限？',
          '中转权限提醒',
          { confirmButtonText: '开启并继续', cancelButtonText: '取消', type: 'warning' }
        )
        await request.put(`/customers/${customerId.value}`, { forward_certified: true })
        cust.forward_certified = true
      } catch { return }
    }
  }

  submitting.value = true
  try {
    if (sourceMode.value === 'own') {
      await createOrder({
        customer_id: customerId.value,
        payment_method: paymentMethod.value,
        items: ownItems.value.filter(i => i.ip_group_id).map(i => ({
          ip_group_id: i.ip_group_id,
          asset_group_id: i.asset_group_id || undefined,
          quantity: i.quantity,
          unit_price: i.unit_price,
          hard_cost: i.hard_cost || undefined,
          duration: i.duration || 1,
          unit: 3,
        })),
        remark: remark.value || undefined,
      })
      ElMessage.success('订单创建成功，IP已分配')
    } else if (sourceMode.value === 'spark') {
      const sparkGroup = assetGroupOptions.value.find(g => g.source_type === 'spark_api')
      if (!sparkGroup) {
        ElMessage.warning('请先在"资产组管理"中创建一个 API 类型的资产组')
        submitting.value = false
        return
      }

      for (const item of sparkItems.value) {
        const forwardCost = getItemForwardCost(item)
        const provisionPayload = {
          product_id: item.product_id,
          product_name: item.product_name,
          country_code: item.country_code,
          country_cn: item.country_cn,
          sale_price: Number(item.sale_price) + forwardCost,
          quantity: item.quantity,
          duration: item.duration,
          unit: item.unit,
          asset_group_id: sparkGroup.id,
          customer_id: customerId.value,
          payment_method: paymentMethod.value,
          forward_plan_id: item.forward_plan_id || undefined,
          is_test: isTestOrder.value || undefined,
          test_hours: isTestOrder.value ? testHours.value : undefined,
        }
        if (item.selected_cidr) {
          provisionPayload.cidr_blocks = [{ cidr: item.selected_cidr, count: item.quantity }]
        }
        await sparkProvision(provisionPayload)
      }
      ElMessage.success('Spark 开通请求已提交，IP开通后自动入库')
    } else if (sourceMode.value === 'ipipv') {
      for (const item of ipipvItems.value) {
        const forwardCost = getItemForwardCost(item)
        await ipipvProvision({
          product_no: item.product_no,
          product_name: item.productName,
          country_code: item.countryCode,
          country_cn: item.countryName,
          quantity: item.quantity,
          duration: item.duration,
          unit: item.unit,
          customer_id: customerId.value,
          payment_method: paymentMethod.value,
          sale_price: Number(item.sale_price) + forwardCost,
          forward_plan_id: item.forward_plan_id || undefined,
          is_test: isTestOrder.value || undefined,
          test_hours: isTestOrder.value ? testHours.value : undefined,
          ...(item.selected_cidr ? { cidr_blocks: [{ cidr: item.selected_cidr, count: item.quantity }] } : {}),
        })
      }
      ElMessage.success('IPIPV 开通请求已提交，IP开通后自动入库')
    }
    router.push('/subscriptions')
  } catch { /* handled */ }
  finally { submitting.value = false }
}


async function handleSubmitApproval() {
  if (!customerId.value) { ElMessage.warning('请选择客户'); return }
  if (sourceMode.value !== 'spark' && sourceMode.value !== 'ipipv') {
    ElMessage.warning('销售模式目前仅支持 API 开通'); return
  }

  const activeItems = sourceMode.value === 'ipipv' ? ipipvItems.value : sparkItems.value
  if (!activeItems.length) { ElMessage.warning('请先选择产品'); return }

  const unpriced = activeItems.filter(i => !i.sale_price || i.sale_price <= 0)
  if (unpriced.length) {
    ElMessage.warning(`有 ${unpriced.length} 个产品未填写售价，请先填写`)
    return
  }

  try {
    await ElMessageBox.confirm(`确认提交审批？总金额：¥${totalAmount.value}`, '确认提交', { type: 'warning' })
  } catch { return }

  let assetGroup = null
  if (sourceMode.value === 'spark') {
    assetGroup = assetGroupOptions.value.find(g => g.source_type === 'spark_api')
    if (!assetGroup) { ElMessage.warning('请先在"资产组管理"中创建一个 API 类型的资产组'); return }
  }

  submitting.value = true
  try {
    for (const item of activeItems) {
      const salePrice = Number(item.sale_price)
      const qty = item.quantity || 1
      const duration = item.duration || 1
      const fwdCost = getItemForwardCost(item)
      const total = round2((salePrice + fwdCost) * qty * duration)

      const orderData = sourceMode.value === 'ipipv' ? {
        provider: 'ipipv',
        product_no: item.product_no,
        product_name: item.productName,
        country_code: item.countryCode,
        country_cn: item.countryName,
        sale_price: salePrice + fwdCost,
        quantity: qty,
        duration: duration,
        unit: item.unit || 3,
        auto_renew: false,
        ...(item.forward_plan_id ? { forward_plan_id: item.forward_plan_id } : {}),
        ...(item.selected_cidr ? { cidr_blocks: [{ cidr: item.selected_cidr, count: qty }] } : {}),
        remark: remark.value || undefined,
      } : {
        product_id: item.product_id,
        product_name: item.product_name,
        country_code: item.country_code,
        country_cn: item.country_cn,
        sale_price: salePrice + fwdCost,
        quantity: qty,
        duration: duration,
        unit: item.unit || 3,
        asset_group_id: assetGroup.id,
        auto_renew: false,
        ...(item.forward_plan_id ? { forward_plan_id: item.forward_plan_id } : {}),
        ...(item.selected_cidr ? { cidr_blocks: [{ cidr: item.selected_cidr, count: qty }] } : {}),
        remark: remark.value || undefined,
      }

      await submitApproval({
        type: 'provision',
        customer_id: customerId.value,
        total_amount: total,
        order_data: orderData,
      })
    }
    ElMessage.success('审批已提交，请等待经理审批')
    router.push('/approvals')
  } catch { /* handled */ }
  finally { submitting.value = false }
}

function round2(n) { return Math.round(n * 100) / 100 }

onMounted(() => {
  loadOptions()
  loadRecentCustomers()
  loadForwardPlans()
  loadProviderNames()
  addOwnItem()
})
</script>

<style lang="scss" scoped>
.create-order {
  .page-title {
    font-size: 22px;
    font-weight: 600;
    color: #2C3E50;
    margin-bottom: 20px;
  }
  .section-card { margin-bottom: 16px; }
  .section-header {
    font-size: 15px;
    font-weight: 600;
    color: #2C3E50;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
  }
  .section-sub-header {
    font-size: 14px;
    font-weight: 500;
    color: #4A5568;
    margin: 16px 0 8px;
  }
  .add-row-btn { margin-top: 12px; width: 100%; border-style: dashed; }
  .subtotal { color: #E8913A; font-weight: 600; }
  .summary-card {
    .summary-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 16px;
      border-top: 1px solid #F0E6DA;
    }
    .total-label { font-size: 16px; color: #4A5568; }
    .total-amount { font-size: 28px; font-weight: 700; color: #E8913A; margin-left: 8px; }
    .forward-breakdown {
      margin-top: 4px;
      font-size: 12px;
      color: #909399;
    }
  }
  .test-mode-row {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    margin-bottom: 14px;
    background: #FDF6EC;
    border: 1px dashed #E6A23C;
    border-radius: 8px;
    .test-label {
      margin-left: 10px;
      font-size: 14px;
      font-weight: 500;
      color: #E6A23C;
    }
  }
  .payment-method-row {
    margin-bottom: 16px;
    padding: 14px 16px;
    background: #FAFAFA;
    border: 1px solid #EBEEF5;
    border-radius: 8px;
    .payment-label {
      font-size: 14px;
      font-weight: 600;
      color: #2C3E50;
      margin-right: 12px;
    }
    .balance-hint {
      font-size: 12px;
      color: #909399;
      b { font-weight: 600; }
    }
  }
  .field-hint { font-size: 12px; color: #909399; margin-left: 4px; }
  .mono { font-family: 'SF Mono', Consolas, Monaco, monospace; }
  :deep(.el-radio-button__inner) { display: flex; align-items: center; gap: 6px; }

  // 已选产品卡片布局
  .selected-items {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .selected-item-card {
    border: 1px solid #EADFD2;
    border-radius: 10px;
    padding: 14px 18px;
    background: #FEFCF9;
    transition: border-color 0.2s;

    &:hover { border-color: #E8913A; }

    .item-main {
      display: flex;
      align-items: flex-start;
      gap: 16px;
    }

    .item-product {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 140px;
      flex-shrink: 0;

      .flag-img-sm {
        width: 28px;
        height: 21px;
        object-fit: cover;
        border-radius: 3px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
      }

      .item-info {
        display: flex;
        flex-direction: column;
        strong { font-size: 14px; color: #2C3E50; }
        .item-sub { font-size: 12px; color: #909399; margin-top: 2px; }
      }
    }

    .item-fields {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      flex: 1;
      align-items: center;

      .field-group {
        display: flex;
        align-items: center;
        gap: 6px;

        > label {
          font-size: 12px;
          color: #606266;
          white-space: nowrap;
        }

        .cost-text { font-size: 13px; color: #909399; font-weight: 500; }
        .cost-na { font-size: 12px; color: #C0C4CC; }
      }
    }

    .item-summary {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-shrink: 0;
      margin-left: auto;

      .item-total {
        text-align: right;

        .total-detail {
          font-size: 11px;
          color: #909399;
          margin-top: 2px;
        }
      }
    }
  }

  // Spark 产品选择器
  .spark-panel {
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      padding: 14px 16px;
      background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
      border: 1px solid #F5D9B5;
      border-radius: 10px;
      margin-bottom: 16px;

      .result-count {
        margin-left: auto;
        font-size: 13px;
        color: #4A5568;
        strong {
          color: #E8913A;
          font-size: 16px;
          margin: 0 2px;
        }
      }
    }

    .continent-tabs {
      :deep(.el-tabs__item) {
        font-size: 14px;
        padding-right: 30px;
      }
      .tab-badge {
        margin-left: 6px;
        :deep(.el-badge__content) {
          background: #E8913A !important;
        }
      }
    }

    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 8px;
    }

    .product-card {
      border: 1px solid #EADFD2;
      border-radius: 12px;
      padding: 14px;
      cursor: pointer;
      transition: all 0.2s;
      background: #fff;
      position: relative;

      &:hover:not(.out-of-stock) {
        border-color: #E8913A;
        box-shadow: 0 4px 16px rgba(232, 145, 58, 0.15);
        transform: translateY(-2px);
      }

      &.out-of-stock {
        opacity: 0.55;
        cursor: not-allowed;
        background: #FAFAFA;
      }

      &.selected {
        border-color: #E8913A;
        background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
        box-shadow: 0 0 0 2px rgba(232, 145, 58, 0.2);

        &::after {
          content: '✓';
          position: absolute;
          top: 10px;
          right: 10px;
          width: 22px;
          height: 22px;
          border-radius: 50%;
          background: #E8913A;
          color: #fff;
          font-size: 14px;
          font-weight: 700;
          display: flex;
          align-items: center;
          justify-content: center;
        }
      }

      .card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
      }

      .country-flag {
        line-height: 1;

        .flag-img {
          width: 36px;
          height: 27px;
          object-fit: cover;
          border-radius: 3px;
          box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        }
      }

      .flag-img-sm {
        width: 24px;
        height: 18px;
        object-fit: cover;
        border-radius: 2px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
      }

      .country-name {
        font-size: 15px;
        font-weight: 600;
        color: #2C3E50;
        margin-bottom: 4px;
      }

      .product-name {
        font-size: 12px;
        color: #718096;
        margin-bottom: 8px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .tags {
        display: flex;
        gap: 4px;
        margin-bottom: 10px;
        flex-wrap: wrap;

        :deep(.el-tag) {
          font-size: 11px;
          height: 20px;
          padding: 0 6px;
          line-height: 18px;
        }
      }

      .card-bottom {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        padding-top: 8px;
        border-top: 1px dashed #F0E6DA;

        .duration {
          color: #909399;
        }

        .cost {
          color: #E8913A;
          font-weight: 600;
        }

        .cost-unavailable {
          color: #C0C4CC;
          font-size: 11px;
          font-style: italic;
        }
      }
    }
  }
}
</style>
