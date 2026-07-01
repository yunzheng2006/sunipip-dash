<template>
  <div class="partnership-page">
    <!-- Hero + VIP 等级 (一体化) -->
    <section class="hero-vip-section">
      <div class="hero-vip-bg"></div>
      <div class="container">
        <!-- Hero 内容 -->
        <div class="hero-content">
          <div class="hero-eyebrow">Partner Program</div>
          <h1>代理合作计划<br>与太阳IP 共赢</h1>
          <p class="hero-desc">为分销商、渠道商、开发者、SaaS 团队提供业内最优的 VIP 返利体系与白牌方案。<br>充值越多，进货价越低，最高享 <strong>{{ topDiscountText }}</strong> 批发价</p>
          <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="/billing/topup">申请合作 →</a>
            <a class="btn btn-ghost btn-lg" href="#compare">查看折扣对比</a>
          </div>

          <!-- 大客户联系悬浮窗 -->
          <div v-if="partnershipContactImage" class="vip-contact-widget" @click="onContactClick">
            <div class="vip-contact-widget__save">VIP</div>
            <div class="vip-contact-widget__icon">
              <svg viewBox="0 0 48 48" fill="none" width="32" height="32">
                <path d="M24 4l5 10 11 2-8 8 2 11-10-5-10 5 2-11-8-8 11-2z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/>
              </svg>
            </div>
            <div class="vip-contact-widget__body">
              <span class="vip-contact-widget__title">大客户专属价格</span>
              <span class="vip-contact-widget__sub">联系客户经理获取专属报价 →</span>
            </div>
            <div v-if="partnershipContactImage" class="vip-contact-widget__popup">
              <img :src="partnershipContactImage" alt="联系客户经理">
            </div>
          </div>
        </div>

        <!-- 手机端弹窗 -->
        <div v-if="contactDialogVisible" class="contact-dialog-overlay" @click.self="contactDialogVisible = false">
          <div class="contact-dialog">
            <div class="contact-dialog__header">
              <span>联系客户经理</span>
              <button class="contact-dialog__close" @click="contactDialogVisible = false">✕</button>
            </div>
            <div class="contact-dialog__body">
              <img :src="partnershipContactImage" alt="联系客户经理">
            </div>
            <button class="contact-dialog__save" @click="saveContactImage">保存图片</button>
          </div>
        </div>

        <!-- VIP详情弹窗 -->
        <div v-if="vipDetailDialogVisible" class="contact-dialog-overlay" @click.self="vipDetailDialogVisible = false">
          <div class="contact-dialog">
            <div class="contact-dialog__header">
              <span>VIP 详情</span>
              <button class="contact-dialog__close" @click="vipDetailDialogVisible = false">✕</button>
            </div>
            <div class="contact-dialog__body">
              <img :src="vipDetailImage" alt="VIP详情">
            </div>
          </div>
        </div>

        <!-- VIP 卡片 -->
        <div id="vip-tiers" class="vip-grid" v-if="tiers.length">
          <article
            v-for="(t, idx) in tiers"
            :key="t.id"
            class="vip-card"
            :class="{
              'vip-card--popular': isCurrentTier(t),
              'vip-card--met': !isCurrentTier(t) && meetsTier(t),
            }"
            :style="{ '--tier-color': t.badge_color || tierColor(idx) }"
          >
            <span v-if="isCurrentTier(t)" class="vip-card__badge">我的等级</span>
            <span v-else-if="meetsTier(t)" class="vip-card__badge vip-card__badge--met">已达标</span>
            <div class="vip-card__head">
              <div class="vip-card__icon">
                <svg v-if="idx === 0" viewBox="0 0 48 48" fill="none"><path d="M8 36l6-18 10 10 10-16 6 24H8z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/><circle cx="24" cy="12" r="3" fill="#F7A600"/></svg>
                <svg v-else-if="idx === tiers.length - 1" viewBox="0 0 48 48" fill="none"><path d="M24 4l5 10 11 2-8 8 2 11-10-5-10 5 2-11-8-8 11-2z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/></svg>
                <svg v-else viewBox="0 0 48 48" fill="none"><path d="M24 6l-14 18h8v18h12V24h8z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/></svg>
              </div>
              <div>
                <h3 class="vip-card__title">{{ t.name }}</h3>
                <div class="vip-card__price">
                  <span class="vip-card__num">{{ formatDiscount(t.discount_percent) }}</span>
                  <span class="vip-card__unit">折</span>
                </div>
              </div>
              <span class="vip-card__save">省 {{ 100 - t.discount_percent }}%</span>
            </div>
            <div class="vip-card__divider"></div>
            <ul class="vip-card__features">
              <li v-if="Number(t.spending_threshold) > 0">累计消费满 <b>¥{{ Number(t.spending_threshold).toLocaleString() }}</b></li>
              <li v-if="Number(t.topup_threshold) > 0">或单笔充值满 <b>¥{{ Number(t.topup_threshold).toLocaleString() }}</b></li>
              <li>全平台 IP 产品立省 <b>{{ 100 - t.discount_percent }}%</b></li>
              <li>达成条件满足任一即可升级</li>
              <li>一经达成永久有效</li>
            </ul>
            <a class="vip-card__cta" @click.prevent="onVipCardClick(t)">
              <template v-if="isCurrentTier(t)">当前等级</template>
              <template v-else>查看详情</template>
              <span>→</span>
            </a>
          </article>
        </div>
      </div>
    </section>

    <!-- Stats -->
    <section class="sec stats-after-tiers">
      <div class="container">
        <div class="stats-bar stats-bar--flat">
          <div class="stat"><strong>500+</strong><span>合作分销商</span></div>
          <div class="stat"><strong>最高 {{ maxSavePercent }}%</strong><span>返利比例</span></div>
          <div class="stat"><strong>{{ topDiscountText }}</strong><span>最低进货价</span></div>
          <div class="stat"><strong>T+1</strong><span>结算周期</span></div>
          <div class="stat"><strong>24/7</strong><span>专属支持</span></div>
        </div>
      </div>
    </section>

    <!-- 六大核心权益 -->
    <section class="sec" style="padding-top: 60px">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">为什么合作</span>
          <h2>六大核心权益 · 助力分销业务增长</h2>
          <p>从技术对接到商务扶持，全方位赋能您的代理 IP 转售业务</p>
        </div>
        <div class="features-grid">
          <div class="feature">
            <img class="f-icon" src="/partner-img/wallet.svg" alt="">
            <h3>超高返利 / 折扣</h3>
            <p>充值越多折扣越大，最高达 {{ maxSavePercent }}% 差价空间，让您的分销业务拥有充足利润。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/gear.svg" alt="">
            <h3>完整开放 API</h3>
            <p>商品、库存、价格等全部开放 API 接入，支持 HMAC 签名鉴权，可直接集成到您的平台。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/building.svg" alt="">
            <h3>白牌定价体系</h3>
            <p>每个合作方拥有独立 price_markup 加成系数，终端售价完全可控，打造自主品牌。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/headset.svg" alt="">
            <h3>专属客户经理</h3>
            <p>高级 VIP 享有 1 对 1 专属客户经理，业务、技术、结算疑难一站对接。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/clock.svg" alt="">
            <h3>T+1 快速结算</h3>
            <p>月结账期或按单结算任选，T+1 到账，支持 USDT / 支付宝 / 银行转账。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/shield-check.svg" alt="">
            <h3>合规与稳定</h3>
            <p>99.9% SLA 保障、全球 195+ 国家覆盖、完善的 KYC 与合规审核，让您放心转售。</p>
          </div>
        </div>
      </div>
    </section>

    <!-- 折扣对比 -->
    <section id="compare" class="sec" v-if="compareRows.length">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">折扣对比</span>
          <h2>不同等级的真实成本对比</h2>
          <p>以示例产品 ¥{{ BASE_PRICE }} / IP / 月为参考</p>
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
              <tr v-for="r in compareRows" :key="r.name">
                <td>{{ r.name }}</td>
                <td>¥{{ r.threshold.toLocaleString() }}</td>
                <td>{{ r.discount }} 折</td>
                <td class="highlight">¥{{ r.unitPrice.toFixed(2) }}</td>
                <td>¥{{ (r.unitPrice * 100).toLocaleString() }}</td>
                <td>¥{{ (r.unitPrice * 1000).toLocaleString() }}</td>
                <td class="yes">¥{{ r.annualSave.toLocaleString() }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="tiers-note">
          年化节省 = (最低等级单价 − 当前等级单价) × 1,000 IP × 12 月。实际节省依您的采购规模而定。
        </p>
      </div>
    </section>

    <!-- 合作模式 -->
    <section class="sec" style="background: #F7F9FC">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">合作模式</span>
          <h2>三种合作方式 · 总有一款适合您</h2>
        </div>
        <div class="products-grid">
          <div class="product-card">
            <div class="icon"><img src="/partner-img/cart.svg" alt=""></div>
            <h3>自用分销</h3>
            <p>您以 VIP 折扣价向太阳IP 采购，自行向终端客户销售。最简单的分销模式。</p>
            <ul>
              <li>无需技术对接</li>
              <li>用户中心自助下单</li>
              <li>按 VIP 等级享折扣</li>
              <li>适合小团队 / 个人</li>
            </ul>
            <router-link class="cta" to="/store">立即开始</router-link>
          </div>

          <div class="product-card accent">
            <div class="icon"><img src="/partner-img/code.svg" alt=""></div>
            <h3>API 白牌接入</h3>
            <p>通过开放 API 将太阳IP 产品直接集成到您的平台，自定义品牌与定价。</p>
            <ul>
              <li>RESTful API + HMAC 签名</li>
              <li>独立 price_markup 系数</li>
              <li>商品 / 库存实时同步</li>
              <li>适合 SaaS / 平台商</li>
            </ul>
            <a class="cta" href="https://sunipip.com/API_PUBLIC.md" target="_blank" rel="noopener">查看 API 文档</a>
          </div>

          <div class="product-card">
            <div class="icon"><img src="/partner-img/building.svg" alt=""></div>
            <h3>战略合作</h3>
            <p>大规模团队与生态伙伴可签订年度战略合作协议，享独家资源与定制服务。</p>
            <ul>
              <li>自定义 SLA 与资源池</li>
              <li>独立子网 / ASN 配置</li>
              <li>联合市场推广</li>
              <li>适合企业 / 平台级伙伴</li>
            </ul>
            <a class="cta" @click.prevent="applyPartnership">申请洽谈</a>
          </div>
        </div>
      </div>
    </section>

    <!-- 接入流程 -->
    <section class="sec">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">接入流程</span>
          <h2>四步成为太阳IP 合作伙伴</h2>
        </div>
        <div class="steps-grid">
          <div class="step">
            <h3>注册账号</h3>
            <p>完成注册并实名认证，开通合作伙伴资格。</p>
          </div>
          <div class="step">
            <h3>首次充值</h3>
            <p>充值 {{ firstThreshold }} 起即可升级首级 VIP，享专属折扣，开启折扣之旅。</p>
          </div>
          <div class="step">
            <h3>申请 API Key</h3>
            <p>联系销售开通 API Key，配置 price_markup 与 scope，即可开始接入。</p>
          </div>
          <div class="step">
            <h3>运营变现</h3>
            <p>将商品上架到您的平台、广告位或 TG 群，按您的定价销售，赚取差价。</p>
          </div>
        </div>
      </div>
    </section>

    <!-- API 能力 -->
    <section class="sec" style="background: #F7F9FC">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">API 能力</span>
          <h2>开放完整的电商 API</h2>
          <p>从商品列表到库存同步，全流程 API 化</p>
        </div>
        <div class="features-grid">
          <div class="feature">
            <img class="f-icon" src="/partner-img/browser.svg" alt="">
            <h3>商品列表 API</h3>
            <p>GET /products — 返回全部产品（ISP 类型、网络类型、月单价、库存、国家）。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/globe.svg" alt="">
            <h3>国家聚合 API</h3>
            <p>GET /stock-by-country — 按国家聚合库存与价格区间，首页卡片展示首选。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/lock.svg" alt="">
            <h3>HMAC 签名鉴权</h3>
            <p>除 API Key 外，可开启 HMAC-SHA256 签名 + 时间戳防重放，安全性更高。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/wallet.svg" alt="">
            <h3>独立 Markup 系数</h3>
            <p>每个 Key 可单独设置 price_markup，同一后台支撑多品牌 / 多价格体系。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/dashboard.svg" alt="">
            <h3>实时调用统计</h3>
            <p>后台实时查看 Key 的调用次数、最后 IP、速率限制，异常即时告警。</p>
          </div>
          <div class="feature">
            <img class="f-icon" src="/partner-img/refresh.svg" alt="">
            <h3>5 分钟同步</h3>
            <p>上游库存每 5 分钟自动同步，确保您展示的数据与实际可售数据一致。</p>
          </div>
        </div>
      </div>
    </section>

    <!-- 典型伙伴 -->
    <section class="sec">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">典型伙伴</span>
          <h2>哪些业务最适合加入</h2>
        </div>
        <div class="usecase-grid">
          <div class="usecase"><img class="u-icon" src="/partner-img/cart.svg" alt=""><h4>代理 IP 转售商</h4><p>主营代理 IP 零售与批发</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/browser.svg" alt=""><h4>指纹浏览器</h4><p>AdsPower / BitBrowser 等</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/spider.svg" alt=""><h4>爬虫 SaaS</h4><p>数据采集平台内置代理</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/phone.svg" alt=""><h4>社媒工具</h4><p>多账号运营工具集成</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/search.svg" alt=""><h4>SEO 工具</h4><p>排名监测 / 关键词工具</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/gamepad.svg" alt=""><h4>游戏工作室</h4><p>多账号 / 区域解锁需求</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/headset.svg" alt=""><h4>技术服务商</h4><p>为终端客户提供代理方案</p></div>
          <div class="usecase"><img class="u-icon" src="/partner-img/building.svg" alt=""><h4>企业采购</h4><p>大规模内部用量客户</p></div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="sec" style="background: #F7F9FC">
      <div class="container">
        <div class="sec-head">
          <span class="sec-tag">常见问题</span>
          <h2>代理合作 FAQ</h2>
        </div>
        <div class="faq-list">
          <details class="faq-item" open>
            <summary>成为代理需要缴纳加盟费吗？</summary>
            <div>不需要。太阳IP 的代理合作计划完全免费，只需在用户中心注册并充值即可。累计充值金额直接决定您的 VIP 等级与折扣。</div>
          </details>
          <details class="faq-item">
            <summary>VIP 等级会下降吗？</summary>
            <div>不会。VIP 等级基于<strong>累计充值金额</strong>评定，一经达成永久有效，不会因消费减少而降级。</div>
          </details>
          <details class="faq-item">
            <summary>VIP 折扣应用在哪些产品上？</summary>
            <div>所有产品线均适用：动态住宅、静态 ISP、数据中心代理。折扣自动应用到您的采购价，无需手动操作。</div>
          </details>
          <details class="faq-item">
            <summary>API 白牌模式是如何定价的？</summary>
            <div>每个 API Key 可配置独立的 price_markup 系数（0.1 ~ 10 倍）。系统按「对客原价 × markup」返回最终价格，您据此定价并销售给终端客户，差价即为您的利润。</div>
          </details>
          <details class="faq-item">
            <summary>可以自定义产品名称吗？</summary>
            <div>高级 VIP 代理可申请自定义产品命名，支持白牌销售，终端客户看到的是您的品牌名而非太阳IP。</div>
          </details>
          <details class="faq-item">
            <summary>支持哪些结算方式？</summary>
            <div>支持预付费（自助充值）、T+1 结算、月结账期（高级 VIP）。付款方式包括 USDT、银行转账、支付宝、微信支付、信用卡。</div>
          </details>
          <details class="faq-item">
            <summary>API 的速率限制是多少？</summary>
            <div>默认每分钟 60 次请求。高级代理可申请提升至 600 次/分钟，大客户可定制更高配额。</div>
          </details>
          <details class="faq-item">
            <summary>我的终端客户遇到问题怎么办？</summary>
            <div>建议您作为一线支持对接，如遇复杂技术问题，可在用户中心提交工单转交太阳IP 工程师团队。高级代理享专属客户经理，支持群聊 / Telegram 直连。</div>
          </details>
        </div>
      </div>
    </section>

    <!-- CTA Banner -->
    <section class="sec">
      <div class="cta-banner">
        <h2>开启您的代理分销业务</h2>
        <p>注册账号即可参与代理合作计划，首充 {{ firstThreshold }} 起立即升级首级 VIP，享专属折扣。</p>
        <div class="btns">
          <a class="btn btn-primary btn-lg" href="/billing/topup">立即申请合作 →</a>
          <router-link class="btn btn-ghost btn-lg" to="/billing/vip-tiers">查看 VIP 折扣</router-link>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getVipInfo } from '@/api/vip'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
const partnershipContactImage = computed(() => appStore.partnershipContactImage)
const vipDetailImage = computed(() => appStore.vipDetailImage)
const vipDetailDialogVisible = ref(false)

function onVipCardClick(tier) {
  if (vipDetailImage.value) {
    vipDetailDialogVisible.value = true
  }
}
const contactDialogVisible = ref(false)
const isMobile = computed(() => window.innerWidth <= 768)

function onContactClick() {
  if (isMobile.value && partnershipContactImage.value) {
    contactDialogVisible.value = true
  }
}

async function saveContactImage() {
  try {
    const response = await fetch(partnershipContactImage.value)
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'contact.png'
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('已保存')
  } catch {
    ElMessage.warning('保存失败，请长按图片保存')
  }
}

const tiers = ref([])
const currentTier = ref(null)
const totalSpent = ref(0)
const maxSingleTopup = ref(0)
const salesPerson = ref('')
const supportWechat = ref('')
const supportPhone = ref('')

const BASE_PRICE = 55 // 示例单价

async function fetchData() {
  try {
    const res = await getVipInfo()
    tiers.value = (res?.all_tiers || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
    currentTier.value = res?.current_tier || null
    totalSpent.value = Number(res?.total_spent || 0)
    maxSingleTopup.value = Number(res?.max_single_topup || 0)
    salesPerson.value = res?.sales_person || ''
    supportWechat.value = res?.support_wechat || ''
    supportPhone.value = res?.support_phone || ''
  } catch {}
}

function isCurrentTier(t) {
  return currentTier.value && currentTier.value.id === t.id
}

function meetsTier(t) {
  const spend = Number(t.spending_threshold || 0)
  const topup = Number(t.topup_threshold || 0)
  if (spend > 0 && totalSpent.value >= spend) return true
  if (topup > 0 && maxSingleTopup.value >= topup) return true
  return false
}

const defaultTierColors = ['#F7A600', '#1E40AF', '#c9c23b', '#E8913A', '#6366F1']
function tierColor(idx) {
  return defaultTierColors[idx % defaultTierColors.length]
}

const maxSavePercent = computed(() => {
  if (!tiers.value.length) return 30
  const maxDiscount = Math.min(...tiers.value.map(t => Number(t.discount_percent)))
  return 100 - maxDiscount
})

const topDiscountText = computed(() => {
  if (!tiers.value.length) return '7 折'
  const maxDiscount = Math.min(...tiers.value.map(t => Number(t.discount_percent)))
  return `${formatDiscount(maxDiscount)} 折`
})

// 70(%) → "7"；85(%) → "8.5"
function formatDiscount(percent) {
  const n = Number(percent) / 10
  return Number.isInteger(n) ? String(n) : n.toFixed(1)
}

const firstThreshold = computed(() => {
  if (!tiers.value.length) return '¥1,000'
  return `¥${Number(tiers.value[0].spending_threshold).toLocaleString()}`
})

const compareRows = computed(() => {
  if (!tiers.value.length) return []
  const baseDiscount = Math.max(...tiers.value.map(t => Number(t.discount_percent))) // 最低等级（折扣最大）
  const basePrice = BASE_PRICE * (baseDiscount / 100)
  return tiers.value.map(t => {
    const discount = Number(t.discount_percent)
    const unitPrice = BASE_PRICE * (discount / 100)
    const annualSave = Math.round((basePrice - unitPrice) * 1000 * 12)
    return {
      name: t.name,
      threshold: Number(t.spending_threshold),
      discount: discount / 10,
      unitPrice,
      annualSave: Math.max(0, annualSave),
    }
  })
})

function applyPartnership() {
  const parts = []
  if (salesPerson.value) parts.push(`专属销售：${salesPerson.value}`)
  if (supportWechat.value) parts.push(`客服微信：${supportWechat.value}`)
  if (supportPhone.value) parts.push(`客服电话：${supportPhone.value}`)
  if (!parts.length) parts.push('请通过您注册时使用的邀请人或上游渠道联系销售团队')
  ElMessage({ message: parts.join(' · '), type: 'success', duration: 6000, showClose: true })
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.partnership-page {
  --brand: #F7A600;
  --brand-600: #E89500;
  --brand-50: #FFF7E6;
  --accent: #1E40AF;
  --accent-600: #183389;
  --accent-50: #EEF2FB;
  --bg-soft: #F7F9FC;
  --bg-dark: #0B1437;
  --border: #E5E9F2;
  --text: #0B1437;
  --text-2: #3A4466;
  --muted: #6B7488;
  --muted-on-dark: #A6B0CC;
  --radius: 14px;
  --radius-lg: 22px;
  --radius-pill: 999px;
  --shadow-sm: 0 2px 8px rgba(11, 20, 55, 0.06);
  --shadow-md: 0 10px 30px rgba(11, 20, 55, 0.08);
  --shadow-lg: 0 24px 60px rgba(11, 20, 55, 0.12);

  color: var(--text);
  font-family: 'Inter', 'Noto Sans SC', 'Noto Sans TC', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', sans-serif;
  /* 拉满 main-content 的 padding，撑满整个右侧主区 */
  margin: -24px -28px 0;

  /* 重置标题 */
  h1, h2, h3, h4 {
    color: var(--text);
    margin: 0;
    line-height: 1.3;
    font-weight: 800;
  }
  h1 { font-size: clamp(32px, 5vw, 48px); }
  h2 { font-size: clamp(24px, 3.5vw, 36px); }
  h3 { font-size: 18px; }
  p { margin: 0; }
  a { color: var(--accent); text-decoration: none; }

  .container { max-width: 1200px; margin: 0 auto; padding: 0 clamp(16px, 3vw, 32px); }
}

/* 按钮 */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px 24px;
  border-radius: var(--radius-pill);
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
  border: 1.5px solid transparent;
  transition: all 0.2s;
  cursor: pointer;
  white-space: nowrap;
}
.btn-primary {
  background: var(--brand);
  color: var(--bg-dark);
  &:hover { box-shadow: 0 14px 30px rgba(247, 166, 0, 0.48); }
}
.btn-ghost {
  background: transparent;
  border-color: var(--border);
  color: var(--text);
  &:hover { border-color: var(--brand); color: var(--brand); }
}
.btn-lg { padding: 14px 28px; font-size: 15px; }

/* Section */
.sec { padding: clamp(48px, 6vw, 90px) 0; }
.sec-head {
  text-align: center;
  max-width: 740px;
  margin: 0 auto 44px;
  .sec-tag {
    display: inline-block;
    padding: 6px 14px;
    border-radius: var(--radius-pill);
    background: var(--brand-50);
    color: var(--brand-600);
    font-weight: 700;
    font-size: 13px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 16px;
  }
  h2 { margin-bottom: 12px; }
  p { color: var(--muted); font-size: 16px; }
}

/* Hero + VIP 一体化 */
.hero-vip-section {
  position: relative;
  padding: clamp(48px, 6vw, 80px) 0 clamp(56px, 7vw, 90px);
  overflow: hidden;
}
.hero-vip-bg {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(700px 400px at 75% 0%, rgba(247, 166, 0, 0.14), transparent 55%),
    radial-gradient(500px 350px at 20% 50%, rgba(30, 64, 175, 0.06), transparent 50%),
    linear-gradient(180deg, #0B1437 0%, #0D1A45 30%, #111D4A 55%, #1a2656 70%, #2a3668 82%, #F7F9FC 100%);
  z-index: 0;
}
.hero-vip-section .container {
  position: relative;
  z-index: 1;
}
.hero-content {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  color: #fff;
  margin-bottom: clamp(36px, 4.5vw, 56px);
  h1 {
    color: #fff;
    margin-bottom: 18px;
    font-size: clamp(28px, 4.5vw, 44px);
    line-height: 1.25;
    letter-spacing: -0.01em;
  }
}
.hero-eyebrow {
  display: inline-block;
  padding: 6px 16px;
  border-radius: var(--radius-pill);
  background: rgba(247, 166, 0, 0.15);
  color: var(--brand);
  font-weight: 700;
  font-size: 13px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  margin-bottom: 20px;
  border: 1px solid rgba(247, 166, 0, 0.2);
}
.hero-desc {
  color: var(--muted-on-dark);
  max-width: 700px;
  font-size: clamp(14px, 1.5vw, 16px);
  line-height: 1.8;
  text-align: center;
  strong { color: var(--brand); font-weight: 800; }
}
.hero-actions {
  margin-top: 28px;
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
  .btn-ghost { color: #fff; border-color: rgba(255, 255, 255, 0.25); &:hover { border-color: var(--brand); color: var(--brand); } }
}

/* Stats */
.stats-after-tiers { padding-top: 0; padding-bottom: clamp(24px, 3vw, 48px); }
.stats-bar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 20px;
  padding: 36px 32px;
  background: #fff;
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-md);
  position: relative;
  z-index: 5;
  .stat {
    text-align: center;
    padding: 0 10px;
    border-right: 1px dashed var(--border);
    &:last-child { border-right: none; }
    strong {
      display: block;
      font-size: clamp(26px, 3.5vw, 40px);
      font-weight: 800;
      background: linear-gradient(135deg, var(--brand), #FFC441);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      line-height: 1;
      margin-bottom: 6px;
    }
    span { display: block; color: var(--muted); font-size: 13px; font-weight: 600; }
  }
}

/* Features grid */
.features-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 22px;
}
.feature {
  padding: 28px 24px;
  border-radius: var(--radius);
  background: #fff;
  border: 1px solid var(--border);
  .f-icon { width: 52px; height: 52px; margin-bottom: 14px; display: block; }
  h3 { font-size: 17px; margin-bottom: 8px; }
  p { font-size: 14px; color: var(--muted); line-height: 1.65; }
}


/* VIP Cards */
.vip-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
  gap: 20px;
}

.vip-card {
  position: relative;
  padding: 28px 26px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(12px);
  border: 1.5px solid rgba(255, 255, 255, 0.25);
  border-radius: var(--radius-lg);
  transition: all 0.28s ease;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  --tier-color: var(--brand);

  &::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--tier-color), var(--brand));
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s ease;
  }
  &:hover {
    border-color: rgba(247, 166, 0, 0.4);
    transform: translateY(-4px);
    box-shadow: 0 20px 50px rgba(11, 20, 55, 0.18);
    &::before { transform: scaleX(1); }
    .vip-card__cta span { transform: translateX(4px); }
  }

  &--popular {
    border-color: rgba(247, 166, 0, 0.5);
    box-shadow: 0 10px 30px rgba(247, 166, 0, 0.14);
    &::before {
      transform: scaleX(1);
      background: linear-gradient(90deg, var(--brand), #FFC441);
    }
  }

  &--met {
    border-color: rgba(103, 194, 58, 0.5);
  }
}

.vip-card__badge {
  position: absolute;
  top: 14px; right: 14px;
  background: linear-gradient(135deg, #FFC441, var(--brand));
  color: var(--bg-dark);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  box-shadow: 0 3px 8px rgba(247, 166, 0, 0.3);

  &--met {
    background: linear-gradient(135deg, #86EFAC, #67C23A);
  }
}

.vip-card__head {
  display: flex;
  align-items: center;
  gap: 14px;
}

.vip-card__icon {
  width: 48px; height: 48px; flex-shrink: 0;
  svg { width: 100%; height: 100%; display: block; }
}

.vip-card__title {
  font-size: 17px; font-weight: 800; color: var(--text);
  margin: 0; line-height: 1.2;
}

.vip-card__price {
  display: flex; align-items: baseline; gap: 3px;
  margin-top: 2px;
}
.vip-card__num {
  font-size: 32px; font-weight: 900; line-height: 1;
  font-family: 'Inter', -apple-system, 'SF Pro Display', system-ui, sans-serif;
  background: linear-gradient(135deg, var(--tier-color), var(--brand));
  -webkit-background-clip: text; background-clip: text;
  color: transparent;
  letter-spacing: -0.02em;
}
.vip-card__unit {
  font-size: 15px; font-weight: 800;
  color: var(--brand-600);
}
.vip-card__save {
  margin-left: auto;
  font-size: 12px; font-weight: 800;
  color: var(--brand-600); background: var(--brand-50);
  padding: 4px 10px; border-radius: var(--radius-pill);
  letter-spacing: 0.02em;
  border: 1px solid rgba(247, 166, 0, 0.15);
  white-space: nowrap;
  align-self: center;
}

.vip-card__divider {
  height: 1px;
  background: var(--border);
  margin: 18px 0;
}

.vip-card__features {
  list-style: none; padding: 0; margin: 0 0 20px;
  display: flex; flex-direction: column; gap: 10px;
  li {
    font-size: 13px; color: var(--text-2);
    padding-left: 24px; position: relative; line-height: 1.5;
    &::before {
      content: "\2713";
      position: absolute; left: 0; top: 1px;
      width: 16px; height: 16px;
      background: var(--brand-50);
      color: var(--brand-600);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 10px;
    }
    b { color: var(--text); font-weight: 800; }
  }
}

.vip-card__cta {
  margin-top: auto;
  display: inline-flex; align-items: center; gap: 6px;
  color: var(--brand-600);
  font-weight: 700; font-size: 13px;
  cursor: pointer; user-select: none;
  text-decoration: none;
  padding-top: 4px;
  span { transition: transform 0.2s; display: inline-block; }
  &:hover { color: var(--brand); }
}

/* Products / 合作模式 */
.products-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 22px;
}
.product-card {
  position: relative;
  padding: 32px 28px;
  background: #fff;
  border: 1.5px solid var(--border);
  border-radius: var(--radius-lg);
  transition: all 0.25s;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  &::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--brand), var(--accent));
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s;
  }
  &:hover {
    border-color: var(--brand);
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
    &::before { transform: scaleX(1); }
    .cta::after { transform: translateX(4px); }
  }
  &.accent {
    border-color: var(--brand);
    box-shadow: var(--shadow-md);
    &::before { transform: scaleX(1); }
  }
  .icon {
    width: 56px;
    height: 56px;
    margin-bottom: 18px;
    display: block;
    img { width: 100%; height: 100%; display: block; }
  }
  h3 { margin-bottom: 10px; }
  p { font-size: 14px; color: var(--text-2); margin-bottom: 18px; min-height: 42px; line-height: 1.6; }
  ul {
    list-style: none;
    padding: 0;
    margin: 0 0 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    li {
      font-size: 13px;
      color: var(--text-2);
      padding-left: 22px;
      position: relative;
      &::before {
        content: "✓";
        position: absolute;
        left: 0;
        top: 0;
        color: var(--brand);
        font-weight: 800;
      }
    }
  }
  .cta {
    margin-top: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--accent);
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    &::after { content: "→"; transition: transform 0.2s; }
  }
}

/* Use cases */
.usecase-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
}
.usecase {
  background: linear-gradient(145deg, #fff, var(--bg-soft));
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 22px 18px;
  text-align: center;
  transition: all 0.2s;
  &:hover {
    border-color: var(--brand);
    background: linear-gradient(145deg, #fff, var(--brand-50));
  }
  .u-icon { width: 48px; height: 48px; margin: 0 auto 10px; display: block; }
  h4 { font-size: 14px; margin-bottom: 4px; }
  p { font-size: 12px; color: var(--muted); }
}

/* Steps */
.steps-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 22px;
  counter-reset: step;
}
.step {
  padding: 28px 22px;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  position: relative;
  counter-increment: step;
  &::before {
    content: counter(step, decimal-leading-zero);
    position: absolute;
    top: 20px; right: 22px;
    font-size: 32px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--brand), var(--accent));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    opacity: 0.35;
  }
  h3 { font-size: 17px; margin-bottom: 8px; padding-right: 40px; }
  p { font-size: 13px; color: var(--muted); line-height: 1.65; }
}

/* Compare table */
.compare-wrap {
  overflow-x: auto;
  background: #fff;
  border-radius: var(--radius-lg);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-sm);
  -webkit-overflow-scrolling: touch;
}
.compare-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 720px;
  th, td {
    padding: 16px 18px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
  }
  thead th {
    background: var(--bg-soft);
    color: var(--text);
    font-weight: 800;
    font-size: 14px;
    &.highlight {
      background: linear-gradient(135deg, #FFF7E6, #FFE8B3);
      color: var(--brand-600);
      position: relative;
      &::after {
        content: "推荐";
        position: absolute;
        top: 6px; right: 8px;
        background: var(--brand);
        color: #0B1437;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 800;
      }
    }
  }
  tbody td:first-child { font-weight: 700; color: var(--text); background: var(--bg-soft); }
  tbody td.yes { color: #16A34A; font-weight: 700; }
  tbody tr:hover { background: #FFFBF0; }
}

/* FAQ */
.faq-list {
  max-width: 820px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.faq-item {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  summary {
    cursor: pointer;
    padding: 18px 22px;
    font-weight: 700;
    color: var(--text);
    list-style: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    font-size: 15px;
    &::-webkit-details-marker { display: none; }
    &::after {
      content: "+";
      width: 24px; height: 24px;
      flex: 0 0 24px;
      border-radius: 50%;
      background: var(--brand-50);
      color: var(--brand-600);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      font-weight: 800;
      transition: transform 0.2s;
    }
  }
  &[open] {
    border-color: var(--brand);
    summary::after { transform: rotate(45deg); }
  }
  > div {
    padding: 0 22px 20px;
    font-size: 14px;
    color: var(--text-2);
    line-height: 1.75;
    strong { color: var(--text); font-weight: 700; }
  }
}

/* CTA banner */
.cta-banner {
  background:
    radial-gradient(800px 400px at 20% 10%, rgba(247, 166, 0, 0.25), transparent 50%),
    linear-gradient(135deg, #132057, #0B1437);
  color: #fff;
  border-radius: var(--radius-lg);
  padding: clamp(40px, 6vw, 70px);
  text-align: center;
  margin: 0 auto;
  max-width: 1200px;
  h2 { color: #fff; margin-bottom: 14px; }
  p { color: var(--muted-on-dark); max-width: 640px; margin: 0 auto 24px; line-height: 1.7; }
  .btns {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
  }
}

/* 大客户联系悬浮窗 — 定位在 hero 右侧 */
.vip-contact-widget {
  position: absolute;
  right: clamp(16px, 3vw, 32px);
  bottom: -10px;
  top: auto;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px 28px;
  width: fit-content;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(12px);
  border: 1.5px solid rgba(247, 166, 0, 0.45);
  border-radius: var(--radius-lg);
  box-shadow: 0 10px 30px rgba(247, 166, 0, 0.14);
  cursor: pointer;
  transition: all 0.28s ease;
  overflow: hidden;

  &::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--brand), #FFC441);
  }

  &:hover {
    border-color: var(--brand);
    transform: translateY(-4px);
    box-shadow: 0 20px 50px rgba(247, 166, 0, 0.22);

    .vip-contact-widget__popup {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }
  }
}

.vip-contact-widget__save {
  position: absolute;
  top: 10px; right: 14px;
  background: linear-gradient(135deg, #FFC441, var(--brand));
  color: var(--bg-dark);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  box-shadow: 0 3px 8px rgba(247, 166, 0, 0.3);
}

.vip-contact-widget__icon {
  width: 48px;
  height: 48px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  svg { width: 100%; height: 100%; display: block; }
}

.vip-contact-widget__body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-right: 36px;
}

.vip-contact-widget__title {
  font-size: 17px;
  font-weight: 800;
  color: var(--text);
  white-space: nowrap;
}

.vip-contact-widget__sub {
  font-size: 13px;
  font-weight: 700;
  color: var(--brand-600);
  white-space: nowrap;
}

.vip-contact-widget__popup {
  position: absolute;
  top: calc(100% + 12px);
  right: 0;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-8px);
  transition: all 0.25s;
  background: #fff;
  border: 1.5px solid var(--border);
  border-radius: var(--radius);
  padding: 12px;
  box-shadow: 0 16px 50px rgba(0, 0, 0, 0.18);

  img {
    width: 240px;
    height: auto;
    display: block;
    border-radius: 8px;
  }
}

/* 手机端联系弹窗 */
.contact-dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.contact-dialog {
  background: #fff;
  border-radius: 16px;
  width: 300px;
  max-width: 90vw;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
}

.contact-dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #F0F0F0;
  font-size: 16px;
  font-weight: 600;
  color: #1E293B;
}

.contact-dialog__close {
  background: none;
  border: none;
  font-size: 18px;
  color: #94A3B8;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;
  &:hover { background: #F1F5F9; }
}

.contact-dialog__body {
  padding: 24px;
  display: flex;
  justify-content: center;

  img {
    width: 100%;
    max-width: 240px;
    height: auto;
    border-radius: 8px;
  }
}

.contact-dialog__save {
  display: block;
  width: calc(100% - 40px);
  margin: 0 20px 20px;
  padding: 12px;
  background: var(--brand);
  color: var(--bg-dark);
  border: none;
  border-radius: 10px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s;
  &:hover { background: var(--brand-600); }
}

/* Mobile */
@media (max-width: 768px) {
  .partnership-page { margin: -12px -10px 0; }
  .sec { padding: 36px 0; }
  .sec-head { margin-bottom: 28px; }
  .hero-vip-section { padding: 36px 0 28px; }
  .hero-content h1 { font-size: 24px; }
  .hero-eyebrow { font-size: 11px; padding: 5px 12px; margin-bottom: 14px; }
  .hero-desc { font-size: 13px; }
  .hero-content { margin-bottom: 24px; }
  .hero-actions { margin-top: 18px; gap: 8px; .btn { font-size: 13px; padding: 10px 20px; } }

  .stats-bar {
    padding: 22px 16px;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    .stat {
      padding: 10px 4px;
      border-right: none;
      border-bottom: 1px dashed var(--border);
      &:nth-last-child(-n+2) { border-bottom: none; }
      &:last-child { grid-column: 1 / -1; border-top: 1px dashed var(--border); padding-top: 14px; }
      strong { font-size: 22px; }
      span { font-size: 12px; }
    }
  }

  .features-grid { grid-template-columns: 1fr; gap: 14px; }
  .feature { padding: 22px 18px; }

  .vip-grid { grid-template-columns: 1fr; gap: 12px; }
  .vip-card {
    padding: 22px 20px; border-radius: var(--radius);
    &:hover { transform: none; }
  }
  .vip-card__icon { width: 40px; height: 40px; }
  .vip-card__title { font-size: 15px; }
  .vip-card__num { font-size: 28px; }
  .vip-card__unit { font-size: 13px; }
  .vip-card__save { font-size: 11px; padding: 3px 8px; }
  .vip-card__divider { margin: 14px 0; }
  .vip-card__features li { font-size: 12px; padding-left: 20px; }

  .products-grid { grid-template-columns: 1fr; gap: 14px; }
  .product-card { padding: 24px 20px; &:hover { transform: none; } }

  .usecase-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .usecase { padding: 16px 12px; .u-icon { width: 40px; height: 40px; } h4 { font-size: 13px; } p { font-size: 11px; } }

  .steps-grid { grid-template-columns: 1fr; gap: 12px; }
  .step { padding: 22px 18px; }

  .faq-item summary { padding: 14px 16px; font-size: 14px; }
  .faq-item > div { padding: 0 16px 16px; font-size: 13px; }

  .compare-wrap { margin: 0 -2px; border-radius: 12px; }
  .compare-table th, .compare-table td { padding: 12px 14px; font-size: 12px; }

  .cta-banner { padding: 32px 20px; border-radius: 16px; }
  .cta-banner .btn { width: 100%; }

  .vip-contact-widget {
    position: relative;
    right: auto;
    top: auto;
    transform: none;
    margin: 20px auto 0;
    padding: 14px 18px;
    gap: 10px;
    border-radius: var(--radius);
    &:hover { transform: none; }
  }
  .vip-contact-widget__save { top: 8px; right: 10px; font-size: 10px; padding: 2px 8px; }
  .vip-contact-widget__icon { width: 36px; height: 36px; }
  .vip-contact-widget__body { padding-right: 28px; gap: 2px; }
  .vip-contact-widget__title { font-size: 14px; }
  .vip-contact-widget__sub { font-size: 11px; }
  .vip-contact-widget__popup { display: none !important; }
}

@media (max-width: 380px) {
  .stats-bar { grid-template-columns: 1fr; }
  .stats-bar .stat { border-bottom: 1px dashed var(--border); padding: 10px 0; }
  .stats-bar .stat:last-child { border-top: none; padding-top: 10px; }
}
</style>
