<template>
  <div class="store-page">
    <!-- 顶部 CTA 横幅（后台可编辑） -->
    <div v-if="banner.enabled && (banner.title || banner.subtitle || banner.promises?.length || banner.buttons?.length)" class="cta-banner">
      <h2 v-if="banner.title">{{ banner.title }}</h2>
      <p v-if="banner.subtitle" class="cta-subtitle">{{ banner.subtitle }}</p>
      <template v-if="banner.promises?.length">
        <div v-for="(row, ri) in normalizedPromises" :key="ri" class="cta-promises">
          <span class="cta-promise" v-for="(p, pi) in row" :key="pi">{{ p }}</span>
        </div>
      </template>
      <div v-if="banner.buttons?.length" class="cta-btns">
        <template v-for="(b, i) in banner.buttons" :key="i">
          <a
            v-if="b.type === 'link' && b.url"
            class="cta-btn" :class="i === 0 ? 'cta-btn--primary' : 'cta-btn--ghost'"
            :href="b.url"
            target="_blank"
            rel="noopener"
          >{{ b.label }}</a>
          <span
            v-else-if="b.type === 'image' && b.image_url"
            class="cta-btn cta-btn--ghost cta-btn--image"
          >
            {{ b.label }}
            <span class="cta-btn-popup">
              <img :src="b.image_url" :alt="b.label" />
            </span>
          </span>
          <span v-else class="cta-btn" :class="i === 0 ? 'cta-btn--primary' : 'cta-btn--ghost'">{{ b.label }}</span>
        </template>
      </div>
    </div>

    <div class="store-notice">
      <span class="store-notice__line"></span>
      <span class="store-notice__text">你可以对比价格，但请不要让劣币驱逐良币</span>
      <span class="store-notice__line"></span>
    </div>

    <!-- 模块 Tab 切换 + 配置 + 搜索 -->
    <div class="module-tabs-wrapper">
      <div class="module-tabs-row">
        <div class="module-tabs-left">
          <div class="module-tabs-label">选择产品类型</div>
          <div class="module-tabs">
        <button
          v-if="showForwardTabs"
          class="module-tab" :class="{ active: activeModule === 'video' }"
          @click="switchModule('video')"
          style="font-weight: 600"
        >IPLC视频专线</button>
        <button
          v-if="showForwardTabs"
          class="module-tab" :class="{ active: activeModule === 'live' }"
          @click="switchModule('live')"
          style="font-weight: 600"
        >IPLC直播专线</button>
        <button
          class="module-tab" :class="{ active: activeModule === 'static' }"
          @click="switchModule('static')"
        >静态住宅IP</button>
          </div>
        </div>
        <div class="module-config">
          <template v-if="activeModule === 'static'">
            <div class="config-group">
              <span class="config-label">选择配置</span>
              <div class="config-options">
                <button class="config-btn active">整C带宽 1000M</button>
              </div>
            </div>
          </template>
          <template v-else-if="activeModule === 'video'">
            <div class="config-group">
              <span class="config-label">选择配置</span>
              <div class="config-options">
                <button class="config-btn active">流量200G<span class="config-hint">IPLC视频专线</span></button>
              </div>
            </div>
          </template>
          <template v-else-if="activeModule === 'live'">
            <div class="config-group">
              <span class="config-label">选择带宽</span>
              <div class="config-options">
                <button class="config-btn" :class="{ active: liveSubType === 'live_mobile' }" @click="liveSubType = 'live_mobile'">
                  带宽10M<span class="config-hint">推荐手机</span>
                </button>
                <button class="config-btn" :class="{ active: liveSubType === 'live_pc' }" @click="liveSubType = 'live_pc'">
                  带宽20M<span class="config-hint">推荐电脑</span>
                </button>
              </div>
            </div>
            <div class="config-group">
              <span class="config-label">流量</span>
              <div class="config-options">
                <button class="config-btn active">不限制</button>
              </div>
            </div>
          </template>
        </div>
        <el-input v-model="keyword" placeholder="搜索国家/地区" clearable :prefix-icon="Search" class="module-search" />
      </div>
    </div>

    <!-- 主内容：产品列表 + 购物车 -->
    <div v-loading="loading" class="store-body">
      <!-- 产品列表（所有大洲顺序排列） -->
      <div class="products-area">
        <div v-for="group in groupedProducts" :key="group.continent" class="continent-section">
          <div class="section-header">
            <span class="section-title">{{ group.continent }}</span>
            <span class="section-count">{{ group.items.length }} 个产品</span>
          </div>
          <div class="product-grid">
            <div
              v-for="p in group.items"
              :key="p.product_id"
              class="product-card"
              :class="{ selected: isSelected(p), 'sold-out': p.stock <= 0 }"
              @click="toggleSelect(p)"
            >
              <div class="card-check" v-if="isSelected(p)"><el-icon :size="11"><Check /></el-icon></div>
              <!-- ISP 特殊标签（浅红胶囊，右上角）-->
              <span v-if="p.net_label" class="card-net-tag" :class="p.net_type === 1 ? 'net-native' : 'net-broadcast'">{{ p.net_label }}</span>
              <!-- 国旗 + 国家 -->
              <div class="card-top">
                <span v-if="getIso2(p.country_code)" :class="`fi fi-${getIso2(p.country_code)}`" class="flag-icon"></span>
                <span v-else class="card-flag">{{ getCountryInfo(p.country_code).flag }}</span>
                <span class="card-name">{{ p.country_name }}</span>
              </div>
              <!-- 子地区 + ISP标签同行 -->
              <div class="card-meta" v-if="p.city_name || p.area_name || getIspTag(p)">
                <span v-if="p.city_name || p.area_name" class="card-region">{{ regionText(p.area_name, p.city_name) }}</span>
                <span v-if="getIspTag(p)" class="card-isp-inline">{{ getIspTag(p) }}</span>
              </div>
              <!-- 价格 & 库存同行 -->
              <div class="card-price">
                <span class="card-stock" :class="p.stock <= 0 ? 'gray' : (p.stock > 50 ? 'green' : 'red')">
                  <template v-if="p.stock > 0">库存<b>{{ p.stock }}</b></template>
                  <template v-else>已售罄</template>
                </span>
                <span class="card-price-right">
                  <template v-if="activeModule !== 'static'">
                    <span v-if="hasModuleDiscount(p)" class="price-old">¥{{ fmtPrice(moduleOriginalPrice(p)) }}</span>
                    <span class="price-now">¥{{ fmtPrice(modulePrice(p)) }}</span><span class="price-unit">/月</span>
                  </template>
                  <template v-else-if="p.has_special_price">
                    <span class="price-old">¥{{ fmtPrice(p.original_price) }}</span>
                    <span class="price-now">¥{{ fmtPrice(p.monthly_price) }}</span><span class="price-unit">/月</span>
                  </template>
                  <template v-else-if="p.vip_discount">
                    <span class="price-old">¥{{ fmtPrice(p.monthly_price) }}</span>
                    <span class="price-now">¥{{ fmtPrice(p.vip_price) }}</span><span class="price-unit">/月</span>
                  </template>
                  <template v-else>
                    <span class="price-now">¥{{ fmtPrice(p.monthly_price) }}</span><span class="price-unit">/月</span>
                  </template>
                </span>
              </div>
            </div>
          </div>
        </div>
        <el-empty v-if="!loading && !groupedProducts.length" description="暂无上架产品" />
      </div>

      <!-- 右侧购物车（始终显示） -->
      <aside class="cart-sidebar">
        <div class="cart-head">
          <span><el-icon><ShoppingCart /></el-icon> 购物车 <strong v-if="cart.length">{{ cartItemCount }}</strong></span>
          <el-button v-if="cart.length" link type="info" size="small" @click="clearCart">清空</el-button>
        </div>

        <!-- 空态 -->
        <div v-if="!cart.length" class="cart-empty">
          <p>点击产品添加到购物车</p>
        </div>

        <!-- 购物车列表 -->
        <template v-else>
          <div class="cart-list">
            <div v-for="item in cart" :key="item.product_id" class="ci">
              <div class="ci-top">
                <span>
                  <span v-if="getIso2(item.country_code)" :class="`fi fi-${getIso2(item.country_code)}`" class="ci-flag"></span>
                  <span v-else>{{ getCountryInfo(item.country_code).flag }}</span>
                  {{ item.country_name }}
                </span>
                <el-button link type="danger" @click="removeFromCart(item.product_id)" style="padding:0"><el-icon :size="12"><Close /></el-icon></el-button>
              </div>
              <div v-if="item.city_name || item.area_name" class="ci-region">{{ regionText(item.area_name, item.city_name) }}</div>
              <div class="ci-row">
                <el-input-number v-model="item.quantity" :min="1" :max="item.stock" size="small" controls-position="right" style="width:80px" />
                <span class="ci-price">¥{{ fmtPrice(cartItemPrice(item) * item.quantity) }}</span>
              </div>
              <el-select v-if="item.cidr_blocks && item.cidr_blocks.length" v-model="item.selected_cidr"
                clearable placeholder="IP段: 随机" size="small" style="width:100%;margin-top:4px">
                <el-option label="随机分配" :value="''" />
                <el-option v-for="c in item.cidr_blocks" :key="c.cidr" :label="`${c.cidr} (${c.count})`" :value="c.cidr" />
              </el-select>
            </div>
          </div>
          <div class="cart-opts">
            <div class="opt-row">
              <span class="opt-label">时长</span>
              <el-radio-group v-model="duration" size="small">
                <el-radio-button :value="1">30天</el-radio-button>
                <el-radio-button :value="2">60天</el-radio-button>
                <el-radio-button :value="3">90天</el-radio-button>
                <el-radio-button :value="4">120天</el-radio-button>
                <el-radio-button :value="12">360天</el-radio-button>
              </el-radio-group>
            </div>
            <div class="opt-row">
              <span class="opt-label">自动续费</span>
              <el-switch v-model="autoRenew" size="small" />
            </div>
            <div class="opt-row">
              <span class="opt-label">短信提醒</span>
              <SmsNotifyToggle />
            </div>
          </div>
          <div class="cart-pay">
            <div class="pay-total">¥{{ cartTotal.toFixed(2) }}</div>
            <div class="pay-detail">
              {{ cartItemCount }}条 × {{ duration * 30 }}天
              <template v-if="activeModule === 'video'"> + IPLC视频专线</template>
              <template v-if="activeModule === 'live'"> + IPLC直播专线 {{ liveSubType === 'live_pc' ? '20M' : '10M' }}</template>
              <span :class="canAfford ? 'bal-ok' : 'bal-low'">余额 ¥{{ balance.toFixed(2) }}</span>
            </div>
            <el-button v-if="canAfford" type="primary" :loading="submitting" @click="submitOrder" style="width:100%;margin-top:8px">
              确认购买
            </el-button>
            <el-button v-else type="warning" @click="showTopup = true" style="width:100%;margin-top:8px">
              余额不足，去充值
            </el-button>
            <div v-if="activeModule === 'static'" class="law-notice">
              中国大陆网络无法直接使用本代理<br>使用前请先自备境外网络<br>使用时须遵守国家法律法规！
            </div>
          </div>
        </template>
      </aside>
    </div>

    <!-- 手机端购物车 -->
    <div v-if="mobileCartOpen && cart.length" class="mcp-mask" @click="mobileCartOpen = false"></div>
    <div v-if="mobileCartOpen && cart.length" class="mcp-detail">
        <div class="mcd-head">
          <span>购物车 <strong>{{ cartItemCount }}</strong> 条</span>
          <div>
            <el-button link size="small" @click="clearCart">清空</el-button>
            <el-button link size="small" type="info" @click="mobileCartOpen = false">收起</el-button>
          </div>
        </div>
        <div class="mcd-list">
          <div v-for="item in cart" :key="item.product_id" class="mcd-item">
            <div class="mcd-item-top">
              <span>
                <span v-if="getIso2(item.country_code)" :class="`fi fi-${getIso2(item.country_code)}`" style="margin-right:4px"></span>
                {{ item.country_name }}
                <span v-if="item.city_name || item.area_name" style="color:#6366F1;font-size:11px;margin-left:4px">{{ regionText(item.area_name, item.city_name) }}</span>
              </span>
              <el-button link type="danger" @click="removeFromCart(item.product_id)" style="padding:0"><el-icon :size="12"><Close /></el-icon></el-button>
            </div>
            <div class="mcd-item-row">
              <el-input-number v-model="item.quantity" :min="1" :max="item.stock" size="small" controls-position="right" style="width:80px" />
              <span style="font-weight:700;color:#0F172A">¥{{ fmtPrice(cartItemPrice(item) * item.quantity) }}</span>
            </div>
            <el-select v-if="item.cidr_blocks && item.cidr_blocks.length" v-model="item.selected_cidr"
              clearable placeholder="IP段: 随机" size="small" style="width:100%;margin-top:5px">
              <el-option label="随机分配" :value="''" />
              <el-option v-for="c in item.cidr_blocks" :key="c.cidr" :label="`${c.cidr} (${c.count})`" :value="c.cidr" />
            </el-select>
          </div>
        </div>
        <div class="mcd-opts">
          <div class="mcd-opt-row">
            <span>时长</span>
            <el-radio-group v-model="duration" size="small">
              <el-radio-button :value="1">30天</el-radio-button>
              <el-radio-button :value="2">60天</el-radio-button>
              <el-radio-button :value="3">90天</el-radio-button>
              <el-radio-button :value="4">120天</el-radio-button>
              <el-radio-button :value="12">360天</el-radio-button>
            </el-radio-group>
          </div>
          <div class="mcd-opt-row">
            <span>自动续费</span>
            <el-switch v-model="autoRenew" size="small" />
          </div>
          <div class="mcd-opt-row">
            <span>短信提醒</span>
            <SmsNotifyToggle />
          </div>
        </div>
        <div v-if="activeModule === 'static'" class="law-notice mobile">
          中国大陆网络无法直接使用本代理<br>使用前请先自备境外网络<br>使用时须遵守国家法律法规！
        </div>
    </div>
    <div class="mcp-bar" v-if="cart.length">
      <div class="mcb-left" @click="mobileCartOpen = !mobileCartOpen">
        <el-icon><ShoppingCart /></el-icon>
        <span><strong>{{ cartItemCount }}</strong> 条</span>
        <span class="mcb-expand">{{ mobileCartOpen ? '收起 ▾' : '展开 ▴' }}</span>
      </div>
      <div class="mcb-price">¥{{ fmtPrice(cartTotal) }}</div>
      <el-button type="primary" size="small" round @click.stop="canAfford ? submitOrder() : (showTopup = true)">
        {{ canAfford ? '去支付' : '去充值' }}
      </el-button>
    </div>
    <div class="mcp-bar empty" v-else>
      <el-icon><ShoppingCart /></el-icon>
      <span>点击上方产品添加到购物车</span>
    </div>

    <!-- VIP 等级 & 充值折扣 -->
    <section v-if="vipTiers.length" class="vip-section">
      <div class="sec-head-row">
        <div class="sec-head">
          <span class="sec-tag">会员权益</span>
          <h2>所有 VIP 等级 · 总有一款适合您</h2>
          <p>累计充值达门槛自动升级，一经达成永久有效 · 最高享 <strong>{{ topTierDiscount }}</strong> 进货价</p>
        </div>
      </div>

      <div class="vip-grid">
        <article
          v-for="(t, idx) in vipTiers" :key="t.id"
          class="vip-card"
          :class="{ 'vip-card--popular': isPopularTier(idx) }"
          :style="{ '--tier-color': t.badge_color || vipColor(idx) }"
        >
          <span v-if="isPopularTier(idx)" class="vip-card__badge">推荐</span>
          <span v-else-if="isMyTier(t)" class="vip-card__badge vip-card__badge--mine">当前</span>
          <div class="vip-card__icon" v-html="vipIcon(idx)"></div>
          <h3 class="vip-card__title">VIP {{ t.name }}</h3>
          <div class="vip-card__price">
            <span class="vip-card__num">{{ fmtDiscount(t.discount_percent) }}</span>
            <span class="vip-card__unit">折</span>
            <span class="vip-card__save">立省 {{ 100 - t.discount_percent }}%</span>
          </div>
          <p class="vip-card__desc">{{ t.description || '达成门槛即永久享折扣，全平台 IP 产品统一生效。' }}</p>
          <ul class="vip-card__features">
            <li v-if="Number(t.spending_threshold) > 0">累计消费满 <b>¥{{ Number(t.spending_threshold).toLocaleString() }}</b></li>
            <li v-if="Number(t.topup_threshold) > 0">或单笔充值满 <b>¥{{ Number(t.topup_threshold).toLocaleString() }}</b></li>
            <li>购 IP 立省 <b>{{ 100 - t.discount_percent }}%</b>，永久有效</li>
            <li>达成条件满足任一即可升级</li>
          </ul>
          <a class="vip-card__cta" @click.prevent="showVipDetail">
            立即开始 <span>→</span>
          </a>
        </article>
      </div>

      <p class="vip-note">等级基于您的累计充值金额或单笔充值金额自动评定，一经达成永久有效。具体优惠以用户中心实时展示为准。</p>

      <div v-if="compareRows.length" class="compare-section">
        <div class="sec-head">
          <span class="sec-tag">折扣对比</span>
          <h2>不同等级的真实成本对比</h2>
          <p>以我们最热销的美国静态 ISP 产品 ¥{{ VIP_BASE_PRICE }} / IP / 月为例 · 年化节省按 1,000 IP × 12 月计算</p>
        </div>
        <div class="compare-wrap">
          <table class="compare-table">
            <thead>
              <tr>
                <th>VIP 等级</th>
                <th>充值门槛</th>
                <th>折扣</th>
                <th class="highlight">单 IP 月单价</th>
                <th>100 IP / 月</th>
                <th>1,000 IP / 月</th>
                <th>年化节省</th>
              </tr>
            </thead>
            <tbody>
              <tr class="row-standard">
                <td style="font-weight:800;color:var(--vip-muted);">标准价</td>
                <td>—</td>
                <td>10.0 折</td>
                <td>¥{{ VIP_BASE_PRICE.toFixed(2) }}</td>
                <td>¥{{ (VIP_BASE_PRICE * 100).toLocaleString() }}</td>
                <td>¥{{ (VIP_BASE_PRICE * 1000).toLocaleString() }}</td>
                <td>—</td>
              </tr>
              <tr v-for="r in compareRows" :key="r.name">
                <td :style="{ color: r.color, fontWeight: 800 }">VIP {{ r.name }}</td>
                <td>{{ r.thresholdText }}</td>
                <td>{{ r.discountText }} 折</td>
                <td class="td-highlight">¥{{ r.unitPrice.toFixed(2) }}</td>
                <td>¥{{ Math.round(r.unitPrice * 100).toLocaleString() }}</td>
                <td>¥{{ Math.round(r.unitPrice * 1000).toLocaleString() }}</td>
                <td>¥{{ Math.round(r.annualSave).toLocaleString() }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="vip-note">年化节省 = (标准单价 − 当前等级单价) × 1,000 IP × 12 月。实际节省依您的采购规模而定。</p>
      </div>
    </section>

    <TopupDialog v-model="showTopup" :need-amount="cartTotal" @paid="onTopupPaid" />
    <VerificationDialog v-model="showVerifyDialog" :pending="verificationPending" @verified="onVerified" />

    <!-- VIP详情弹窗 -->
    <div v-if="vipDetailVisible" class="vip-detail-overlay" @click.self="vipDetailVisible = false">
      <div class="vip-detail-dialog">
        <button class="vip-detail-dialog__close" @click="vipDetailVisible = false">✕</button>
        <div class="vip-detail-dialog__body">
          <img :src="vipDetailImage" alt="VIP详情">
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Check, Close, ShoppingCart } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import { getStoreProducts, checkout } from '@/api/store'
import { getVipInfo } from '@/api/vip'
import { getCountryInfo } from '@/utils/countries'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import VerificationDialog from '@/components/VerificationDialog.vue'
import TopupDialog from '@/components/TopupDialog.vue'
import SmsNotifyToggle from '@/components/SmsNotifyToggle.vue'
import { getVerificationStatus } from '@/api/verification'

dayjs.extend(relativeTime)

const router = useRouter()
const authStore = useAuthStore()
const appStore = useAppStore()
const banner = computed(() => appStore.storeBanner || { enabled: false, promises: [], buttons: [] })
const partnershipContactImage = computed(() => appStore.partnershipContactImage)
const vipDetailImage = computed(() => appStore.vipDetailImage)
const vipDetailVisible = ref(false)

function showVipDetail() {
  if (vipDetailImage.value) {
    vipDetailVisible.value = true
  }
}
const normalizedPromises = computed(() => {
  const p = banner.value.promises || []
  if (!p.length) return []
  if (typeof p[0] === 'string') return [p]
  return p
})

const loading = ref(false)
const products = ref([])
const lastRefreshedAt = ref(null)
const totalProducts = ref(0)
const totalStock = ref(0)
const submitting = ref(false)

const forwardCertified = ref(false)
const forwardEnabled = ref(false)
const forwardPlans = ref([])
const plansByModule = ref({ video: null, live_mobile: null, live_pc: null })
const vipTier = ref(null)
const accessType = ref('dedicated')
const keyword = ref('')
const sortBy = ref('stock')

// Module tabs
const activeModule = ref('static') // 'static' | 'video' | 'live'
const liveSubType = ref('live_mobile') // 'live_mobile' | 'live_pc'

const showForwardTabs = computed(() => forwardEnabled.value && forwardCertified.value)

const cart = ref([])
const duration = ref(1)
const autoRenew = ref(false)

const verificationRequired = ref(false)
const isVerified = ref(false)
const verificationPending = ref(null)
const showVerifyDialog = ref(false)
const showTopup = ref(false)
const mobileCartOpen = ref(false)

function onTopupPaid() {
  if (cart.value.length && canAfford.value) {
    ElMessage.success('充值成功！正在为您继续下单...')
    nextTick(() => submitOrder())
  } else {
    ElMessage.success('充值成功！可以继续下单了')
  }
}

const balance = computed(() => authStore.balance)

function getIso2(code) {
  const info = getCountryInfo(code)
  return info.iso2?.toLowerCase() || null
}

function getIspTag(p) {
  const name = (p.product_name || '').toUpperCase()
  const keywords = ['NTT', 'VNPT', 'HKCABLE', 'LACNIC', 'KHUSHI', 'SILKERA', 'DUCK', 'COMCAST', 'CHARTER', 'VERIZON', 'AT&T']
  for (const kw of keywords) {
    if (name.includes(kw)) {
      if (kw === 'KHUSHI' && name.includes('KHUSHI-CN')) return 'Khushi-CN'
      if (kw === 'SILKERA') return 'New Silkera'
      return kw
    }
  }
  return null
}

// Get the active forward plan for the current module
const activeForwardPlan = computed(() => {
  if (activeModule.value === 'video') return plansByModule.value.video
  if (activeModule.value === 'live') return plansByModule.value[liveSubType.value]
  return null
})

// Whether the active forward plan uses fixed (total) pricing
// 直播专线始终视为固定价，不叠加 IP 价格
const isFixedPricing = computed(() => {
  const plan = activeForwardPlan.value
  if (!plan) return false
  if (plan.pricing_mode === 'fixed') return true
  if (activeModule.value === 'live') return true
  return false
})

// Get the forward fee for a product in the current module
function getModuleForwardFee(item) {
  const plan = activeForwardPlan.value
  if (!plan) return 0
  const moduleKey = activeModule.value === 'live' ? liveSubType.value : activeModule.value
  const sfp = item.special_forward_prices
  if (sfp && sfp[moduleKey] != null) return Number(sfp[moduleKey])
  let price = Number(plan.base_price)
  // 无特批中转价但有视频折扣时，折扣应用到中转费（仅视频模块）
  if (activeModule.value === 'video' && item.discount_percent_video != null) {
    price = Math.round(price * item.discount_percent_video / 100 * 100) / 100
  }
  return price
}

// Module original price (no special pricing applied) for strikethrough display
function moduleOriginalPrice(p) {
  const plan = activeForwardPlan.value
  if (!plan) return null
  const basePlanPrice = Number(plan.base_price)
  if (isFixedPricing.value) return basePlanPrice
  const ipBase = p.original_price ? Number(p.original_price) : Number(p.monthly_price)
  return ipBase + basePlanPrice
}

// Module-aware price for product card display
function modulePrice(p) {
  if (isFixedPricing.value) return getModuleForwardFee(p)
  let base = p.vip_price != null ? p.vip_price : p.monthly_price
  // 视频叠加模式：用视频折扣重算IP部分（除非有固定特批价）
  // 有特批折扣时不叠加VIP，避免折上折
  if (activeModule.value === 'video' && p.discount_percent_video != null) {
    const hasFixedSpecialPrice = p.has_special_price && p.discount_percent_static == null
    if (!hasFixedSpecialPrice) {
      const origPrice = p.original_price ? Number(p.original_price) : Number(p.monthly_price)
      base = Math.round(origPrice * p.discount_percent_video / 100 * 100) / 100
    }
  }
  return Number(base) + getModuleForwardFee(p)
}

// Whether the module price has any discount (special IP, special forward, or VIP)
function hasModuleDiscount(p) {
  const orig = moduleOriginalPrice(p)
  const now = modulePrice(p)
  return orig !== null && orig > now
}

// Cart item unit price
function cartItemPrice(item) {
  if (isFixedPricing.value) return getModuleForwardFee(item)
  let ipPrice = item.vip_price != null ? item.vip_price : item.monthly_price
  if (activeModule.value === 'video' && item.discount_percent_video != null) {
    const hasFixedSpecialPrice = item.has_special_price && item.discount_percent_static == null
    if (!hasFixedSpecialPrice) {
      const origPrice = item.original_price ? Number(item.original_price) : Number(item.monthly_price)
      ipPrice = Math.round(origPrice * item.discount_percent_video / 100 * 100) / 100
    }
  }
  return Number(ipPrice) + getModuleForwardFee(item)
}

function switchModule(mod) {
  if (mod === activeModule.value) return
  cart.value = []
  activeModule.value = mod
}

const filteredProducts = computed(() => {
  let list = products.value
  const kw = keyword.value.trim().toLowerCase()
  if (kw) list = list.filter(p => {
    const fields = [p.country_name, p.country_code, p.area_name, p.city_name, p.product_name, p.isp_label, p.isp].filter(Boolean).join(' ').toLowerCase()
    return fields.includes(kw)
  })

  const countryAgg = {}
  for (const p of list) {
    const cc = p.country_code || ''
    if (!countryAgg[cc]) countryAgg[cc] = { maxStock: -Infinity, minPrice: Infinity, maxPrice: -Infinity, name: p.country_name || '' }
    const a = countryAgg[cc]
    if (p.stock > a.maxStock) a.maxStock = p.stock
    if (p.monthly_price < a.minPrice) a.minPrice = p.monthly_price
    if (p.monthly_price > a.maxPrice) a.maxPrice = p.monthly_price
  }

  const sorted = [...list]
  sorted.sort((a, b) => {
    const ccA = a.country_code || '', ccB = b.country_code || ''
    if (ccA !== ccB) {
      const aggA = countryAgg[ccA], aggB = countryAgg[ccB]
      switch (sortBy.value) {
        case 'stock': if (aggA.maxStock !== aggB.maxStock) return aggB.maxStock - aggA.maxStock; break
        case 'price_asc': if (aggA.minPrice !== aggB.minPrice) return aggA.minPrice - aggB.minPrice; break
        case 'price_desc': if (aggA.maxPrice !== aggB.maxPrice) return aggB.maxPrice - aggA.maxPrice; break
      }
      return aggA.name.localeCompare(aggB.name)
    }
    switch (sortBy.value) {
      case 'stock': return b.stock - a.stock
      case 'price_asc': return a.monthly_price - b.monthly_price
      case 'price_desc': return b.monthly_price - a.monthly_price
    }
    return 0
  })
  return sorted
})

const groupedProducts = computed(() => {
  const groups = {}
  for (const p of filteredProducts.value) {
    const cont = p.continent || '其他'
    if (!groups[cont]) groups[cont] = { continent: cont, items: [] }
    groups[cont].items.push(p)
  }
  return Object.values(groups)
})

const cartItemCount = computed(() => cart.value.reduce((s, i) => s + i.quantity, 0))

const cartTotal = computed(() => {
  return cart.value.reduce((s, i) => s + cartItemPrice(i) * i.quantity * duration.value, 0)
})
const canAfford = computed(() => balance.value >= cartTotal.value)

function formatRelative(t) { return t ? dayjs(t).fromNow() : '-' }
function fmtPrice(n) { const v = Number(n); return v % 1 === 0 ? v.toFixed(0) : v.toFixed(1) }
function isSelected(p) { return cart.value.some(c => c.product_id === p.product_id) }

const STATE_ABBR = {
  '加利福尼亚': '加州', '宾夕法尼亚': '宾州', '马萨诸塞': '麻省',
  '北卡罗来纳': '北卡', '南卡罗来纳': '南卡', '西弗吉尼亚': '西弗州',
  '明尼苏达': '明州', '康涅狄格': '康州', '路易斯安那': '路州',
  '新罕布什尔': '新罕州', '俄克拉荷马': '俄州', '密西西比': '密州',
}
function regionText(area, city) {
  if (!area && !city) return ''
  if (!city) return area
  if (!area) return city
  return `${STATE_ABBR[area] || area} · ${city}`
}

function toggleSelect(p) {
  if (p.stock <= 0) { ElMessage.warning('该产品暂无库存'); return }
  const idx = cart.value.findIndex(c => c.product_id === p.product_id)
  if (idx >= 0) {
    cart.value.splice(idx, 1)
  } else {
    cart.value.push({ ...p, quantity: 1, selected_cidr: '' })
  }
}

function removeFromCart(id) { cart.value = cart.value.filter(c => c.product_id !== id) }
function clearCart() { cart.value = [] }

async function fetchData() {
  loading.value = true
  try {
    const res = await getStoreProducts({ access_type: accessType.value })
    products.value = res?.products || []
    forwardCertified.value = res?.forward_certified || false
    forwardEnabled.value = res?.forward_enabled || false
    forwardPlans.value = res?.forward_plans || []
    plansByModule.value = res?.forward_plans_by_module || { video: null, live_mobile: null, live_pc: null }
    if (forwardEnabled.value && forwardCertified.value && activeModule.value === 'static') {
      activeModule.value = 'video'
    }
    vipTier.value = res?.vip_tier || null
    lastRefreshedAt.value = res?.last_refreshed_at
    totalProducts.value = res?.total_products || 0
    totalStock.value = res?.total_stock || 0
    cart.value = cart.value.filter(c => products.value.some(p => p.product_id === c.product_id && p.stock > 0))
    try {
      const vStatus = await getVerificationStatus()
      verificationRequired.value = vStatus?.required || false
      isVerified.value = vStatus?.verified || false
      verificationPending.value = vStatus?.has_pending ? vStatus : null
    } catch {}
  } catch {}
  finally { loading.value = false }
}

function onVerified(type) {
  isVerified.value = true
  ElMessage.success('认证完成，可以继续下单了')
}

// Resolve the checkout module param
function getCheckoutModule() {
  if (activeModule.value === 'static') return 'static'
  if (activeModule.value === 'video') return 'video'
  if (activeModule.value === 'live') return liveSubType.value
  return 'static'
}

async function submitOrder() {
  if (verificationRequired.value && !isVerified.value) {
    showVerifyDialog.value = true
    return
  }
  if (!canAfford.value) { ElMessage.warning('余额不足'); return }
  if (!cart.value.length) return

  const mod = getCheckoutModule()
  const itemsDesc = cart.value.map(i => `${i.country_name} IP ×${i.quantity}`).join('\n')
  let moduleDesc = ''
  if (mod === 'video') moduleDesc = '\n专线: IPLC视频专线'
  else if (mod === 'live_mobile') moduleDesc = '\n专线: IPLC直播专线 10M'
  else if (mod === 'live_pc') moduleDesc = '\n专线: IPLC直播专线 20M'

  try {
    await ElMessageBox.confirm(
      `${itemsDesc}${moduleDesc}\n\n时长 ${duration.value * 30} 天\n合计 ¥${cartTotal.value.toFixed(2)}`,
      '确认购买',
      { type: 'info', confirmButtonText: '确认支付', cancelButtonText: '再想想', distinguishCancelAndClose: true }
    )
  } catch { return }

  submitting.value = true
  try {
    const payload = {
      items: cart.value.map(c => ({
        product_id: c.product_id,
        quantity: c.quantity,
        cidr: c.selected_cidr || undefined,
      })),
      duration: duration.value,
      auto_renew: autoRenew.value,
      module: mod,
    }
    const res = await checkout(payload)
    const subCount = res?.subscription_ids?.length || 0
    if (subCount > 0) {
      ElMessage.success(`购买成功！已开通 ${subCount} 条 IP，正在跳转...`)
    } else {
      ElMessage({
        type: 'success',
        message: '购买成功！正在开通中，请稍后刷新查看',
        duration: 5000,
      })
    }
    cart.value = []
    authStore.updateBalance(res.new_balance)
    setTimeout(() => router.push('/ips'), 1200)
  } catch {}
  finally { submitting.value = false }
}

// VIP tiers for bottom section
const vipTiers = ref([])
const currentVipTier = ref(null)
const totalSpent = ref(0)
const maxSingleTopup = ref(0)
const VIP_BASE_PRICE = 55

async function fetchVipTiers() {
  try {
    const res = await getVipInfo()
    vipTiers.value = (res?.all_tiers || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
    currentVipTier.value = res?.current_tier || null
    totalSpent.value = Number(res?.total_spent || 0)
    maxSingleTopup.value = Number(res?.max_single_topup || 0)
  } catch {}
}

function isMyTier(t) { return currentVipTier.value && currentVipTier.value.id === t.id }
function meetsTier(t) {
  const spend = Number(t.spending_threshold || 0)
  const topup = Number(t.topup_threshold || 0)
  return (spend > 0 && totalSpent.value >= spend) || (topup > 0 && maxSingleTopup.value >= topup)
}

const vipColors = ['#F7A600', '#1E40AF', '#c9c23b', '#E8913A', '#6366F1']
function vipColor(idx) { return vipColors[idx % vipColors.length] }

function fmtDiscount(percent) {
  const n = Number(percent) / 10
  return Number.isInteger(n) ? String(n) : n.toFixed(1)
}

function isPopularTier(idx) {
  return vipTiers.value.length >= 3 && idx === Math.floor((vipTiers.value.length - 1) / 2)
}

const VIP_ICONS = [
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="30" fill="#EEF2FB"/><rect x="14" y="40" width="6" height="12" fill="#1E40AF"/><rect x="23" y="32" width="6" height="20" fill="#1E40AF"/><rect x="32" y="24" width="6" height="28" fill="#F7A600"/><rect x="41" y="16" width="6" height="36" fill="#F7A600"/><path d="M12 38 L20 30 L28 34 L38 18 L50 10" stroke="#1E40AF" stroke-width="2.5" fill="none" stroke-linecap="round"/><circle cx="50" cy="10" r="3" fill="#F7A600"/></svg>',
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="30" fill="#FFF7E6"/><polygon points="32,8 38,24 56,24 42,36 48,54 32,44 16,54 22,36 8,24 26,24" fill="#F7A600" stroke="#1E40AF" stroke-width="2" stroke-linejoin="round"/></svg>',
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="30" fill="#FFF7E6"/><path d="M32 10 L50 24 L32 54 L14 24 Z" fill="#F7A600"/><path d="M14 24 H50" stroke="#1E40AF" stroke-width="2"/><path d="M32 10 L22 24 L32 54" stroke="#1E40AF" stroke-width="2" fill="none"/><path d="M32 10 L42 24 L32 54" stroke="#1E40AF" stroke-width="2" fill="none"/></svg>',
]
function vipIcon(idx) { return VIP_ICONS[idx % VIP_ICONS.length] }

const topTierDiscount = computed(() => {
  if (!vipTiers.value.length) return ''
  const top = vipTiers.value[vipTiers.value.length - 1]
  return fmtDiscount(top.discount_percent) + ' 折'
})

const compareRows = computed(() => {
  if (!vipTiers.value.length) return []
  return vipTiers.value.map(t => {
    const discount = Number(t.discount_percent)
    const unitPrice = VIP_BASE_PRICE * (discount / 100)
    const annualSave = (VIP_BASE_PRICE - unitPrice) * 1000 * 12
    const topup = Number(t.topup_threshold || 0)
    const spending = Number(t.spending_threshold || 0)
    const thresholdText = topup && topup === spending
      ? `¥${spending.toLocaleString()}`
      : topup
        ? `¥${topup.toLocaleString()} 或 ¥${spending.toLocaleString()}`
        : `¥${spending.toLocaleString()}`
    return {
      name: t.name,
      color: t.badge_color || '#F7A600',
      thresholdText,
      discountText: fmtDiscount(discount),
      unitPrice,
      annualSave: Math.max(0, annualSave),
    }
  })
})

onMounted(async () => {
  await authStore.fetchMe()
  fetchData()
  fetchVipTiers()
})
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$brand-border: #C5CDFC;
$accent: #F5A623;
$text-primary: #1E293B;
$text-secondary: #475569;
$text-muted: #94A3B8;
$border: #E2E8F0;
$radius: 12px;

.store-page {
  display: flex; flex-direction: column; gap: 16px;
  max-width: 1200px; margin: 0 auto; width: 100%;
}

// ===== Store Notice =====
.store-notice {
  display: flex;
  align-items: center;
  gap: 16px;
  margin: -4px 0;

  &__line {
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(#F7A600, 0.3), transparent);
  }

  &__text {
    color: #94A3B8;
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 3px;
    white-space: nowrap;
  }
}

// ===== CTA Banner =====
.cta-banner {
  background:
    radial-gradient(800px 400px at 20% 10%, rgba(247, 166, 0, 0.25), transparent 50%),
    linear-gradient(135deg, #132057, #0B1437);
  color: #fff;
  border-radius: 16px;
  padding: clamp(28px, 5vw, 50px) clamp(20px, 4vw, 40px);
  text-align: center;

  h2 {
    color: #fff; font-size: clamp(20px, 2.8vw, 28px); font-weight: 800;
    margin: 0 0 12px; letter-spacing: 0.5px;
  }

  .cta-subtitle {
    color: rgba(255,255,255,0.7);
    max-width: 640px; margin: 0 auto 14px;
    font-size: 15px; line-height: 1.7;
  }

  .cta-promises {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 24px;
    margin-bottom: 2px;
    &:last-of-type { margin-bottom: 36px; }
    .cta-promise {
      font-size: 15px; color: rgba(255,255,255,0.88); font-weight: 600; line-height: 1.9;
      &::before { content: '\2713\00a0'; color: #F7A600; font-weight: 700; }
    }
  }

  .cta-btns {
    display: flex; flex-wrap: wrap; gap: 12px; justify-content: center;
  }

  .cta-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px 24px; border-radius: 999px;
    font-weight: 700; font-size: 14px; cursor: pointer;
    transition: all 0.2s; position: relative; text-decoration: none;
    white-space: nowrap;
  }
  .cta-btn--primary {
    background: #F7A600; color: #0B1437; border: 1.5px solid transparent;
    &:hover { box-shadow: 0 10px 24px rgba(247, 166, 0, 0.4); }
  }
  .cta-btn--ghost {
    background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,0.3);
    &:hover { border-color: #F7A600; color: #F7A600; }
  }
  .cta-btn--image {
    .cta-btn-popup {
      position: absolute; top: calc(100% + 10px); left: 50%; transform: translateX(-50%);
      background: #fff; padding: 8px; border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0,0,0,.25);
      opacity: 0; visibility: hidden; pointer-events: none;
      transition: opacity .2s, transform .2s;
      z-index: 10;
      img { display: block; width: 180px; height: 180px; object-fit: cover; border-radius: 6px; }
    }
    &:hover .cta-btn-popup {
      opacity: 1; visibility: visible; transform: translateX(-50%) translateY(4px);
    }
  }
}

// ===== Module Tabs =====
.module-tabs-wrapper {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.module-tabs-row {
  display: flex;
  align-items: flex-end;
  gap: 12px;
}
.module-tabs-left {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}
.module-tabs-label {
  font-size: 15px;
  color: #1E293B;
  font-weight: 600;
}
.module-search {
  width: 200px;
  flex-shrink: 0;
  margin-left: auto;
}
.module-tabs {
  display: inline-flex;
  gap: 10px;
  align-items: stretch;

  .module-tab {
    position: relative;
    padding: 10px 18px;
    min-width: 100px;
    text-align: center;
    background: transparent;
    border: 1.5px solid $brand;
    border-radius: 10px;
    cursor: pointer;
    transition: background 0.3s, color 0.3s, box-shadow 0.3s;
    font-size: 14px;
    font-weight: 600;
    color: $brand;
    white-space: nowrap;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;

    &::after {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(
        120deg,
        transparent 0%,
        rgba(255, 255, 255, 0.15) 40%,
        rgba(255, 255, 255, 0.3) 50%,
        rgba(255, 255, 255, 0.15) 60%,
        transparent 100%
      );
      transition: none;
      pointer-events: none;
    }

    &:hover {
      background: rgba(59, 130, 246, 0.08);
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);

      &::after {
        animation: shimmer-sweep 0.7s ease-out forwards;
      }
    }

    &.active {
      background: $brand;
      color: #fff;
      font-weight: 600;
      box-shadow: 0 2px 10px rgba(59, 130, 246, 0.3);
    }

    &--dual {
      flex-direction: column;
      gap: 3px;
      padding: 10px 16px;
      min-width: 150px;

      .tab-main {
        font-size: 14px;
        font-weight: 500;
        line-height: 1.2;
      }
      .tab-sub {
        font-size: 14px;
        font-weight: 600;
        line-height: 1.2;
      }
    }
  }
}

@keyframes shimmer-sweep {
  0% { left: -100%; }
  100% { left: 100%; }
}

// ===== Module Config Options =====
.module-config {
  display: flex;
  align-items: flex-end;
  gap: 16px;
  margin-left: 4px;
  align-self: flex-end;
  padding-bottom: 2px;
}

.config-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.config-label {
  font-size: 13px;
  color: $text-muted;
  font-weight: 600;
  white-space: nowrap;
}

.config-options {
  display: inline-flex;
  gap: 6px;
  background: #F1F5F9;
  border-radius: 8px;
  padding: 3px;
}

.config-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 14px;
  background: transparent;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
  font-size: 14px;
  font-weight: 500;
  color: $text-muted;
  white-space: nowrap;

  &:hover { color: $text-secondary; }

  &.active {
    background: #fff;
    color: $brand;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  }

  .config-hint {
    font-size: 12px;
    color: $text-muted;
    font-weight: 400;
    margin-left: 2px;
  }

  &.active .config-hint {
    color: $brand;
    opacity: 0.7;
  }
}

// ===== Store Body =====
.store-body { display: flex; gap: 14px; align-items: flex-start; }

.products-area { flex: 1; min-width: 0; }

// ===== Section =====
.continent-section { margin-bottom: 20px; }
.section-header {
  display: flex; align-items: center; gap: 8px; margin-bottom: 10px;
  .section-title {
    font-size: 15px; font-weight: 700; color: $text-primary;
    padding-left: 10px; border-left: 3px solid $brand;
  }
  .section-count { font-size: 12px; color: $text-muted; }
}

// ===== Product Grid =====
.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 8px;
}

.product-card {
  position: relative; padding: 9px 10px 8px; background: #fff;
  border: 1.5px solid transparent; border-radius: 10px; cursor: pointer;
  transition: all 0.15s; box-shadow: 0 1px 2px rgba(0,0,0,0.04);

  &:hover:not(.sold-out) { border-color: rgba($brand, 0.3); box-shadow: 0 4px 12px rgba($brand, 0.1); }
  &.selected { border-color: $brand; background: $brand-light; }
  &.sold-out { opacity: 0.35; cursor: not-allowed; }

  .card-check {
    position: absolute; top: -4px; left: -4px; width: 20px; height: 20px;
    background: $brand; border-radius: 50%; display: flex; align-items: center;
    justify-content: center; color: #fff; font-size: 11px;
  }

  .card-stock {
    font-size: 11px;
    font-weight: 500;
    color: #94A3B8;
    font-family: -apple-system, 'PingFang SC', sans-serif;
    b {
      font-family: 'SF Mono', Consolas, monospace;
      font-weight: 700;
      margin-left: 2px;
    }
    &.green b { color: #16A34A; }
    &.orange b { color: #DC2626; }
    &.red b { color: #DC2626; }
    &.gray { color: #94A3B8; font-weight: 600; }
  }

  .card-net-tag {
    position: absolute;
    top: 6px;
    right: 6px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 10px;
    letter-spacing: 0.2px;
    white-space: nowrap;
    line-height: 1.5;
    z-index: 1;
    &.net-native { background: #ECFDF5; color: #059669; }
    &.net-broadcast { background: #FEF3C7; color: #D97706; }
  }

  .card-top { display: flex; align-items: center; gap: 6px; margin-bottom: 2px; padding-right: 56px; }
  .flag-icon { font-size: 16px; flex-shrink: 0; }
  .card-flag { font-size: 16px; line-height: 1; }
  .card-name { font-size: 13px; font-weight: 600; color: $text-primary; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  .card-meta {
    display: flex; align-items: center; gap: 4px; margin-bottom: 2px;
    min-width: 0;
    .card-region { font-size: 12px; color: $brand; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
  }

  .card-isp-inline {
    flex-shrink: 0;
    font-size: 10px; font-weight: 700; color: #DC2626;
    background: #FEE2E2; padding: 1px 6px; border-radius: 8px;
    white-space: nowrap; line-height: 1.5;
  }

  .card-price {
    display: flex; align-items: baseline; justify-content: space-between; gap: 6px; margin-top: 4px;
    .card-price-right { display: inline-flex; align-items: baseline; gap: 2px; }
    .price-now { font-size: 17px; font-weight: 800; color: $accent; font-variant-numeric: tabular-nums; line-height: 1; }
    .price-old { font-size: 11px; color: #CBD5E1; text-decoration: line-through; margin-right: 3px; }
    .price-unit { font-size: 11px; color: $text-muted; }
  }
}

// ===== Cart Sidebar =====
.cart-sidebar {
  width: 270px; flex-shrink: 0; position: sticky; top: 0;
  max-height: calc(100vh - 60px); display: flex; flex-direction: column;
  background: #fff;
  border-radius: 24px;
  border: 1px solid #F1F5F9;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
  overflow: hidden;

  :deep(.el-input-number--small) {
    .el-input__wrapper { border-radius: 10px; box-shadow: 0 0 0 1px #E2E8F0 inset; padding: 0 8px; }
    .el-input-number__increase, .el-input-number__decrease { border-radius: 0; border-color: #E2E8F0; }
  }
  :deep(.el-select--small .el-select__wrapper) { border-radius: 10px; box-shadow: 0 0 0 1px #E2E8F0 inset; min-height: 28px; }
  :deep(.el-radio-button__inner) { border-radius: 8px !important; padding: 5px 10px; font-size: 12px; border: none !important; }
  :deep(.el-radio-button:first-child .el-radio-button__inner) { border-radius: 8px !important; }
  :deep(.el-radio-button:last-child .el-radio-button__inner) { border-radius: 8px !important; }
  :deep(.el-radio-group) { background: #F1F5F9; border-radius: 10px; padding: 3px; gap: 2px; display: inline-flex; }
  :deep(.el-radio-button.is-active .el-radio-button__inner) { background: #fff; color: $brand; box-shadow: 0 1px 4px rgba(0,0,0,0.08); font-weight: 600; }
  :deep(.el-radio-button:not(.is-active) .el-radio-button__inner) { background: transparent; color: $text-muted; box-shadow: none; }
  :deep(.el-button--primary) { border-radius: 14px; font-weight: 600; letter-spacing: 0.3px; }
  :deep(.el-switch--small) { height: 18px; }
}

.cart-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 18px 14px;
  font-size: 14px; font-weight: 600; color: $text-primary;
  strong { color: $brand; margin-left: 3px; }
  .el-icon { margin-right: 6px; vertical-align: middle; color: $brand; font-size: 16px; }
}

.cart-empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 56px 20px; color: #D1D5DB; font-size: 13px; text-align: center;
  p { margin: 0; line-height: 1.6; }
}

.cart-list {
  flex: 1; overflow-y: auto; padding: 2px 16px;
  .ci {
    padding: 12px 0;
    border-bottom: 1px solid #F8FAFC;
    &:last-child { border-bottom: none; }
  }
  .ci-top {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 13px; font-weight: 500; color: $text-primary;
  }
  .ci-flag { font-size: 13px; vertical-align: middle; margin-right: 4px; }
  .ci-region { font-size: 11px; color: $brand; font-weight: 500; margin-top: 2px; padding-left: 1px; }
  .ci-row { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
  .ci-price {
    font-size: 15px; font-weight: 700; color: $text-primary;
    font-variant-numeric: tabular-nums;
  }
}

.cart-opts {
  padding: 14px 16px;
  border-top: 1px solid #F1F5F9;
  display: flex; flex-direction: column; gap: 10px;
  .opt-row { display: flex; align-items: center; gap: 8px; }
  .opt-label { font-size: 12px; color: $text-secondary; font-weight: 500; white-space: nowrap; min-width: 48px; }
}

// ===== Live Type Tabs =====
.live-type-tabs {
  display: flex;
  background: #F1F5F9;
  border-radius: 8px;
  padding: 3px;
  width: 100%;

  .ltt-btn {
    flex: 1;
    display: flex; align-items: center; justify-content: center; gap: 4px;
    padding: 6px 8px;
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    font-weight: 500;
    color: $text-muted;
    white-space: nowrap;

    .ltt-icon { width: 14px; height: 14px; flex-shrink: 0; }
    .ltt-price { font-weight: 700; color: $accent; }

    &:hover { color: $text-secondary; }

    &.active {
      background: #fff;
      color: $brand;
      font-weight: 600;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
      .ltt-price { color: $accent; }
    }
  }
}

.ltt-desc {
  font-size: 11px;
  color: $text-muted;
  line-height: 1.4;
  margin-top: 4px;
  padding: 0 2px;
}

.law-notice {
  margin: 10px 14px 0;
  padding: 8px 10px;
  background: #FEF3C7;
  border-radius: 8px;
  font-size: 11px;
  color: #92400E;
  line-height: 1.5;
  text-align: center;
  &.mobile {
    margin: 8px 14px 10px;
    font-size: 10px;
  }
}

.cart-pay {
  padding: 16px 18px;
  border-top: 1px solid #F1F5F9;
  background: #FAFBFE;
  .pay-total {
    font-size: 26px; font-weight: 800; color: $text-primary;
    font-variant-numeric: tabular-nums;
    span { color: $accent; }
  }
  .pay-detail {
    font-size: 12px; color: $text-muted; margin-top: 4px;
    .bal-ok { color: #10B981; font-weight: 500; }
    .bal-low { color: #EF4444; font-weight: 600; }
  }
}

// ===== Responsive =====
@media (min-width: 769px) {
  .mcp-mask, .mcp-detail, .mcp-bar { display: none !important; }
}

@media (max-width: 768px) {
  .cart-sidebar { display: none !important; }

  .mcp-mask {
    position: fixed;
    inset: 0; z-index: 148;
    background: rgba(0,0,0,0.3);
  }

  .mcp-detail {
    position: fixed;
    bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 48px);
    left: 8px; right: 8px;
    z-index: 150;
    background: #fff;
    border-radius: 16px;
    max-height: 60vh;
    display: flex; flex-direction: column;
    box-shadow: 0 -4px 24px rgba(0,0,0,0.1), 0 0 0 1px rgba(0,0,0,0.04);
    overflow: hidden;
  }

  .mcp-bar {
    position: fixed;
    bottom: calc(56px + env(safe-area-inset-bottom, 0px));
    left: 0; right: 0;
    z-index: 150;
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px;
    background: #fff;
    border-top: 1px solid #F1F5F9;
    box-shadow: 0 -2px 12px rgba(0,0,0,0.05);

    .mcb-left {
      display: flex; align-items: center; gap: 6px;
      font-size: 13px; color: #64748B; cursor: pointer;
      strong { color: #0F172A; font-size: 15px; }
      .el-icon { font-size: 18px; color: #6366F1; }
    }
    .mcb-expand {
      font-size: 11px; color: #6366F1; font-weight: 500;
      padding: 2px 6px; background: #EEF2FF; border-radius: 6px;
    }
    .mcb-price {
      font-size: 18px; font-weight: 800; color: #0F172A;
      font-variant-numeric: tabular-nums;
      margin-left: auto;
    }
    &.empty {
      justify-content: center; gap: 8px;
      color: #CBD5E1; font-size: 13px;
      .el-icon { font-size: 16px; }
    }
  }

  .mcd-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 16px;
    font-size: 15px; font-weight: 600; color: #0F172A;
    border-bottom: 1px solid #F1F5F9;
    strong { color: #6366F1; }
  }
  .mcd-list {
    flex: 1; overflow-y: auto; padding: 8px 16px;
    max-height: 35vh;
  }
  .mcd-item {
    padding: 10px 0;
    border-bottom: 1px solid #F8FAFC;
    &:last-child { border-bottom: none; }
  }
  .mcd-item-top {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 14px; font-weight: 500; color: #0F172A;
  }
  .mcd-item-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 6px;
  }
  .mcd-opts {
    padding: 10px 16px; border-top: 1px solid #F1F5F9;
    display: flex; flex-direction: column; gap: 8px;
    .mcd-opt-row {
      display: flex; align-items: center; gap: 8px;
      font-size: 13px; color: #64748B; font-weight: 500;
      span:first-child { min-width: 50px; }
    }
  }
  .law-notice.mobile {
    margin: 0; padding: 8px 16px;
    border-top: 1px solid #F1F5F9;
    border-radius: 0;
  }

  .store-page { gap: 8px; padding-bottom: 120px; }
  .module-tabs-row {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
  .module-search { width: 100% !important; }
  .module-config {
    margin-left: 0;
    align-self: stretch;
    flex-wrap: wrap;
    gap: 10px;
  }
  .config-label { font-size: 12px; }
  .config-btn { font-size: 13px; padding: 5px 12px; }
  .config-hint { font-size: 11px; }
  .module-tabs-label { font-size: 13px; }
  .module-tabs {
    gap: 6px;
    .module-tab {
      padding: 8px 10px;
      min-width: 70px;
      font-size: 12px;

      &--dual {
        padding: 8px 10px;
        min-width: 115px;
        .tab-main { font-size: 12px; }
        .tab-sub { font-size: 12px; }
      }
    }
  }
  .section-header { margin-bottom: 8px;
    .section-title { font-size: 13px; }
    .section-count { font-size: 11px; }
  }
  .cta-banner {
    padding: 24px 16px; border-radius: 12px;
    .cta-promise { font-size: 12px; }
    .cta-btn { padding: 8px 18px; font-size: 13px; }
    .cta-btn--image .cta-btn-popup img { width: 150px; height: 150px; }
  }
  .store-notice__text { font-size: 11px; letter-spacing: 1.5px; }
  .store-body { flex-direction: column; }
  .product-grid { grid-template-columns: repeat(2, 1fr); gap: 6px; }
  .product-card {
    padding: 7px 8px;
    .flag-icon { font-size: 14px; }
    .card-name { font-size: 12px; }
    .card-meta { .card-region { font-size: 10px; } }
    .card-isp-inline { font-size: 8px; padding: 1px 4px; }
    .card-price .price-now { font-size: 14px; }
    .card-price .price-unit { font-size: 10px; }
    .card-stock { font-size: 10px; }
    .card-net-tag { font-size: 8px; padding: 1px 5px; top: 3px; right: 3px; }
    .card-top { padding-right: 44px; }
  }
}

// ===== VIP Section (1:1 from sunipip.com) =====
.sec-head-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 28px;
  margin-bottom: 32px;

  .sec-head { margin-bottom: 0; flex: none; }
}

.vip-contact-widget {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 24px;
  background: linear-gradient(135deg, #0F1B45, #132057);
  border: 1.5px solid rgba(247, 166, 0, 0.35);
  border-radius: 16px;
  box-shadow: 0 8px 24px rgba(11, 20, 55, 0.18);
  text-decoration: none;
  cursor: pointer;
  transition: all 0.28s ease;
  position: relative;
  overflow: hidden;

  &::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #F7A600, #FFC441);
  }

  &:hover {
    border-color: #F7A600;
    transform: translateY(-3px);
    box-shadow: 0 16px 40px rgba(247, 166, 0, 0.2);
  }

  &__save {
    position: absolute;
    top: 10px; right: 12px;
    background: linear-gradient(135deg, #FFC441, #F7A600);
    color: #0B1437;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.08em;
    padding: 2px 8px;
    border-radius: 999px;
  }

  &__icon {
    width: 44px; height: 44px; flex-shrink: 0;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    svg { width: 32px; height: 32px; }
  }

  &__body {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding-right: 28px;
  }

  &__title {
    font-size: 16px;
    font-weight: 800;
    color: #fff;
    white-space: nowrap;
  }

  &__sub {
    font-size: 12px;
    font-weight: 600;
    color: #F7A600;
    white-space: nowrap;
  }
}

.vip-section {
  --vip-brand: #F7A600;
  --vip-brand-600: #E89500;
  --vip-brand-50: #FFF7E6;
  --vip-accent: #1E40AF;
  --vip-text: #0B1437;
  --vip-text-2: #3A4466;
  --vip-muted: #6B7488;
  --vip-border: #E5E9F2;
  --vip-bg-soft: #F7F9FC;
  --vip-radius: 14px;
  --vip-radius-lg: 22px;
  --vip-radius-pill: 999px;
  --vip-shadow-lg: 0 24px 60px rgba(11,20,55,.12);

  margin-top: 32px;
  padding: 48px 0 16px;
  border-top: 1px solid #E2E8F0;
}

.sec-head {
  text-align: center;
  margin-bottom: 32px;

  .sec-tag {
    display: inline-block;
    padding: 6px 16px;
    border-radius: var(--vip-radius-pill);
    background: var(--vip-brand-50);
    color: var(--vip-brand-600);
    font-weight: 700;
    font-size: 12px;
    letter-spacing: 0.08em;
    margin-bottom: 14px;
  }

  h2 {
    font-size: 24px;
    font-weight: 800;
    color: var(--vip-text);
    margin: 0 0 10px;
    line-height: 1.3;
  }

  p {
    font-size: 14px;
    color: var(--vip-muted);
    margin: 0;
    line-height: 1.6;
    strong { color: var(--vip-brand-600); }
  }
}

.vip-note {
  text-align: center;
  margin-top: 30px;
  color: var(--vip-muted);
  font-size: 13px;
}

.vip-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
  gap: 24px;
}

.vip-card {
  position: relative;
  padding: 36px 30px;
  background: #fff;
  border: 1.5px solid var(--vip-border);
  border-radius: var(--vip-radius-lg);
  transition: all .28s ease;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  --tier-color: var(--vip-brand);

  &::before {
    content: "";
    position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: linear-gradient(90deg, var(--tier-color), var(--vip-brand));
    transform: scaleX(0);
    transform-origin: left;
    transition: transform .3s ease;
  }

  &:hover {
    border-color: var(--tier-color);
    transform: translateY(-5px);
    box-shadow: var(--vip-shadow-lg);
  }
  &:hover::before { transform: scaleX(1); }
  &:hover .vip-card__cta span { transform: translateX(4px); }
}

.vip-card--popular {
  border-color: var(--vip-brand);
  box-shadow: 0 10px 30px rgba(247, 166, 0, .14);
  &::before {
    transform: scaleX(1);
    background: linear-gradient(90deg, var(--vip-brand), #FFC441);
  }
}

.vip-card__badge {
  position: absolute;
  top: 16px; right: 16px;
  background: linear-gradient(135deg, #FFC441, var(--vip-brand));
  color: var(--vip-text);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .08em;
  padding: 5px 11px;
  border-radius: var(--vip-radius-pill);
  box-shadow: 0 4px 10px rgba(247, 166, 0, .3);

  &--mine {
    background: linear-gradient(135deg, #86EFAC, #67C23A);
  }
}

.vip-card__icon {
  width: 60px; height: 60px; margin-bottom: 18px;
  :deep(svg) { width: 100%; height: 100%; display: block; }
}

.vip-card__title {
  font-size: 22px; font-weight: 800; color: var(--vip-text);
  margin: 0 0 14px; line-height: 1.25;
}

.vip-card__price {
  display: flex; align-items: baseline; gap: 6px;
  margin-bottom: 20px; flex-wrap: wrap;
}

.vip-card__num {
  font-size: 52px; font-weight: 900; line-height: 1;
  font-family: 'Inter', -apple-system, 'SF Pro Display', system-ui, sans-serif;
  background: linear-gradient(135deg, var(--vip-brand), #FFC441);
  -webkit-background-clip: text; background-clip: text;
  color: transparent;
  letter-spacing: -0.02em;
}

.vip-card__unit {
  font-size: 20px; font-weight: 800;
  color: var(--vip-brand-600); margin-right: 8px;
}

.vip-card__save {
  font-size: 12px; font-weight: 800;
  color: var(--vip-brand-600); background: var(--vip-brand-50);
  padding: 5px 12px; border-radius: var(--vip-radius-pill);
  letter-spacing: .04em;
  border: 1px solid rgba(247, 166, 0, .2);
}

.vip-card__desc {
  font-size: 14px; color: var(--vip-muted); margin: 0 0 22px;
  line-height: 1.6;
}

.vip-card__features {
  list-style: none; padding: 0; margin: 0 0 26px;
  display: flex; flex-direction: column; gap: 11px;

  li {
    font-size: 14px; color: var(--vip-text-2);
    padding-left: 26px; position: relative; line-height: 1.55;

    &::before {
      content: "\2713";
      position: absolute; left: 0; top: 1px;
      width: 18px; height: 18px;
      background: var(--vip-brand-50);
      color: var(--vip-brand-600);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 11px;
    }

    b { color: var(--vip-text); font-weight: 800; }
  }
}

.vip-card__cta {
  margin-top: auto;
  display: inline-flex; align-items: center; gap: 6px;
  color: var(--vip-brand-600);
  font-weight: 700; font-size: 14px;
  text-decoration: none;
  padding-top: 6px;
  span { transition: transform .2s; display: inline-block; }
  &:hover { color: var(--vip-brand); }
}

// Compare section
.compare-section { margin-top: 48px; }

.compare-wrap {
  overflow-x: auto;
  background: #fff;
  border-radius: var(--vip-radius-lg);
  border: 1px solid var(--vip-border);
  box-shadow: 0 2px 8px rgba(11, 20, 55, 0.04);
  -webkit-overflow-scrolling: touch;
}

.compare-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 700px;

  th, td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid var(--vip-border);
    font-size: 13px;
    color: var(--vip-text-2);
  }

  thead th {
    background: var(--vip-bg-soft);
    color: var(--vip-text);
    font-weight: 800;
    font-size: 13px;

    &.highlight {
      background: linear-gradient(135deg, #FFF7E6, #FFE8B3);
      color: var(--vip-brand-600);
    }
  }

  .td-highlight { font-weight: 700; color: var(--vip-brand-600); }
  tbody tr:hover { background: #FFFBF0; }
}

@media (max-width: 768px) {
  .vip-section { margin-top: 16px; padding-top: 32px; }
  .sec-head-row {
    flex-direction: column;
    gap: 16px;
    margin-bottom: 20px;
    .sec-head { text-align: center; }
  }
  .vip-contact-widget {
    width: 100%;
    justify-content: center;
  }
  .sec-head {
    margin-bottom: 20px;
    h2 { font-size: 20px; }
    p { font-size: 13px; }
  }
  .vip-grid { grid-template-columns: 1fr; gap: 16px; }
  .vip-card {
    padding: 26px 22px; border-radius: var(--vip-radius);
    &:hover { transform: none; }
  }
  .vip-card__icon { width: 52px; height: 52px; margin-bottom: 14px; }
  .vip-card__title { font-size: 19px; }
  .vip-card__num { font-size: 42px; }
  .vip-card__unit { font-size: 17px; }
  .vip-card__save { font-size: 11px; padding: 4px 10px; }
  .vip-card__desc { font-size: 13px; margin-bottom: 18px; }
  .vip-card__features li { font-size: 13px; padding-left: 22px; }
  .compare-section { margin-top: 32px; }
  .compare-wrap { margin: 0 -4px; }
  .compare-table th, .compare-table td { padding: 10px 12px; font-size: 12px; }
}
.vip-detail-overlay {
  position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,.55);
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.vip-detail-dialog {
  position: relative; background: #fff; border-radius: 12px; max-width: 500px; width: 100%;
  max-height: 85vh; overflow-y: auto;
  &__close {
    position: absolute; top: 8px; right: 12px; z-index: 1;
    background: rgba(0,0,0,.4); color: #fff; border: none; border-radius: 50%;
    width: 28px; height: 28px; font-size: 14px; cursor: pointer;
  }
  &__body { img { width: 100%; display: block; border-radius: 12px; } }
}
</style>
