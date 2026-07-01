<template>
  <div class="vip-page">
    <!-- Hero -->
    <div class="hero">
      <div class="hero-bg-glow"></div>
      <div class="hero-inner">
        <div class="hero-badge">
          <svg class="icon-flame" viewBox="0 0 24 24" fill="none">
            <path d="M12 2c.5 3 2.5 4.5 2.5 7 0 1.3-.5 2-1.2 2.5-.2-1.3-1-2.3-1.8-3 .2 2.5-1.5 3.7-1.5 5.5 0 .8.4 1.5 1 2-2.5-.5-4-2.5-4-5 0-3 2-4.8 2-7.5 0-.5-.1-1-.3-1.5 1.3.5 2.3 1.3 3.3 2.5V2z" fill="#FF7A3C"/>
            <path d="M12 2c2.5 2 5 5 5 9 0 4-3 7-5 7s-5-3-5-7c0-2 1-3.5 2-5 0 1 .5 2 1.5 2.5C10 7 10.5 4 12 2z" fill="#FFD97A" opacity="0.7"/>
          </svg>
          <span>限时尊享 · VIP 等级折扣</span>
        </div>
        <h1 class="hero-title">
          充值越多<br class="br-mobile">
          <span class="hero-accent">折扣越狠</span>
        </h1>
        <p class="hero-desc">
          累计充值或单笔大额充值达到门槛，即可享受全平台 IP 购买折扣，折扣永久有效。<br class="br-desktop">
          不用抢、不用等，<b>门槛达到即刻生效</b>。
        </p>

        <div v-if="hasSpecialPricing" class="hero-current hero-current--special">
          <div class="hero-current-left">
            <div class="hero-current-label">专属折扣</div>
            <div class="hero-current-row">
              <div class="hero-current-badge" style="background: linear-gradient(135deg, #667eea, #764ba2)">专属定价</div>
              <div class="hero-current-discount" v-if="currentTier">+ {{ currentTier.name }} {{ formatDiscount(currentTier.discount_percent) }}折</div>
            </div>
            <div class="hero-current-sub">您已享有专属折扣价，部分产品价格已按特批价格生效</div>
          </div>
        </div>
        <div v-else-if="currentTier" class="hero-current">
          <div class="hero-current-left">
            <div class="hero-current-label">当前等级</div>
            <div class="hero-current-row">
              <div class="hero-current-badge" :style="{ background: currentTier.badge_color || '#E8913A' }">
                {{ currentTier.name }}
              </div>
              <div class="hero-current-discount">享 {{ formatDiscount(currentTier.discount_percent) }} 折</div>
            </div>
          </div>
        </div>
        <div v-else class="hero-current hero-current--none">
          <div class="hero-current-label">您还未达到任何等级</div>
          <div class="hero-current-sub">充值或累计消费达门槛自动升级</div>
        </div>
      </div>
    </div>

    <!-- 我的进度 -->
    <el-card class="progress-card" shadow="never" v-if="tiers.length && !hasSpecialPricing">
      <div class="progress-head">
        <div class="ph-item">
          <div class="ph-label">
            <el-icon :size="14"><TrendCharts /></el-icon>
            <span>我的累计消费</span>
          </div>
          <div class="ph-value">¥{{ Number(totalSpent).toLocaleString() }}</div>
        </div>
        <div class="ph-split"></div>
        <div class="ph-item">
          <div class="ph-label">
            <el-icon :size="14"><Coin /></el-icon>
            <span>最大单笔充值</span>
          </div>
          <div class="ph-value">¥{{ Number(maxSingleTopup).toLocaleString() }}</div>
        </div>
        <div class="ph-split"></div>
        <div class="ph-item">
          <div class="ph-label">
            <el-icon :size="14"><ArrowUp /></el-icon>
            <span>下一个等级</span>
          </div>
          <div v-if="nextTier" class="ph-value ph-next">
            {{ nextTier.name }} <span class="ph-next-disc">{{ formatDiscount(nextTier.discount_percent) }}折</span>
          </div>
          <div v-else class="ph-value ph-topped">
            <el-icon :size="18" color="#67C23A"><Trophy /></el-icon>
            <span>已是最高等级</span>
          </div>
        </div>
      </div>

      <div v-if="nextTier" class="progress-bar-wrap">
        <div class="progress-legend">
          <span class="pl-text">距离「{{ nextTier.name }}」还差</span>
          <span class="progress-amount">
            <b v-if="gapSpending !== null">累计 ¥{{ gapSpending.toLocaleString() }}</b>
            <span v-if="gapSpending !== null && gapTopup !== null" class="progress-or">或</span>
            <b v-if="gapTopup !== null">单笔 ¥{{ gapTopup.toLocaleString() }}</b>
          </span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" :style="{ width: progressPct + '%' }">
            <span class="progress-shine"></span>
          </div>
        </div>
        <div class="progress-pct">{{ progressPct }}%</div>
      </div>
    </el-card>

    <!-- 等级卡片 -->
    <div class="tiers">
      <div class="sec-head">
        <span class="sec-tag">会员权益</span>
        <h2 class="sec-title">所有 VIP 等级 · 总有一款适合您</h2>
        <p class="sec-subtitle">累计充值达门槛自动升级，一经达成永久有效 · 最高享 <strong>{{ topDiscountText }}</strong> 进货价</p>
      </div>

      <div class="vip-grid" v-if="tiers.length">
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
          <div class="vip-card__icon">
            <svg v-if="idx === 0" viewBox="0 0 48 48" fill="none"><path d="M8 36l6-18 10 10 10-16 6 24H8z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/><circle cx="24" cy="12" r="3" fill="#F7A600"/></svg>
            <svg v-else-if="idx === tiers.length - 1" viewBox="0 0 48 48" fill="none"><path d="M24 4l5 10 11 2-8 8 2 11-10-5-10 5 2-11-8-8 11-2z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/></svg>
            <svg v-else viewBox="0 0 48 48" fill="none"><path d="M24 6l-14 18h8v18h12V24h8z" fill="#FFF7E6" stroke="#F7A600" stroke-width="2.5" stroke-linejoin="round"/></svg>
          </div>
          <h3 class="vip-card__title">{{ t.name }}</h3>
          <div class="vip-card__price">
            <span class="vip-card__num">{{ formatDiscount(t.discount_percent) }}</span>
            <span class="vip-card__unit">折</span>
            <span class="vip-card__save">立省 {{ 100 - t.discount_percent }}%</span>
          </div>
          <p class="vip-card__desc">达成门槛即永久享折扣，全平台 IP 产品统一生效。</p>
          <ul class="vip-card__features">
            <li v-if="Number(t.spending_threshold) > 0">累计消费满 <b>¥{{ Number(t.spending_threshold).toLocaleString() }}</b></li>
            <li v-if="Number(t.topup_threshold) > 0">或单笔充值满 <b>¥{{ Number(t.topup_threshold).toLocaleString() }}</b></li>
            <li>购 IP 立省 <b>{{ 100 - t.discount_percent }}%</b>，永久有效</li>
            <li>达成条件满足任一即可升级</li>
          </ul>
          <a class="vip-card__cta" @click.prevent="openContact(t)">
            <template v-if="isCurrentTier(t)">当前等级</template>
            <template v-else>联系销售开通</template>
            <span>→</span>
          </a>
        </article>
      </div>

      <p class="tiers-note">等级基于您的累计充值金额或单笔充值金额自动评定，一经达成永久有效。具体优惠以用户中心实时展示为准。</p>
    </div>

    <!-- FAQ -->
    <el-card shadow="never" class="faq-card">
      <template #header>
        <span class="faq-title">常见问题</span>
      </template>
      <el-collapse>
        <el-collapse-item title="为什么要联系销售？不能自助开通吗？" name="1">
          VIP 等级对应的充值金额较大，为保障您的资金安全与权益，我们要求由专属销售协助办理。
          销售会与您核对充值金额、到账渠道及折扣生效时间，避免任何误解。
        </el-collapse-item>
        <el-collapse-item title="折扣如何生效？" name="2">
          销售确认充值后，系统会自动为您打上对应 VIP 等级标签。之后购买 IP 时，所有产品的价格都会按折扣自动扣费。
        </el-collapse-item>
        <el-collapse-item title="折扣是否永久有效？" name="3">
          是的。达到等级后不会降级。累计消费门槛一旦达到，永久享有该等级的折扣。
        </el-collapse-item>
        <el-collapse-item title="已经充过值的金额算在累计消费里吗？" name="4">
          累计消费指已购买 IP 或续费的实际扣费总额；单笔充值门槛指一次性充值金额。两者满足任一即可升级。
        </el-collapse-item>
      </el-collapse>
    </el-card>

    <!-- 联系销售弹窗 -->
    <el-dialog
      v-model="contactVisible"
      :title="`开通 ${contactTier?.name || 'VIP'} 折扣`"
      :width="dialogWidth"
      :close-on-click-modal="false"
      class="contact-dialog"
    >
      <div v-if="contactTier" class="contact-intro">
        <div class="contact-badge" :style="{ background: contactTier.badge_color || '#E8913A' }">
          {{ contactTier.name }} · {{ formatDiscount(contactTier.discount_percent) }}折
        </div>
        <p>请与您的销售确认充值金额与到账方式，完成后系统自动升级。</p>
      </div>

      <div class="contact-list">
        <div v-if="salesPerson" class="contact-item">
          <div class="ci-label">
            <el-icon :size="14"><User /></el-icon>
            <span>专属销售</span>
          </div>
          <div class="ci-value">
            <b>{{ salesPerson }}</b>
            <el-tag size="small" type="warning" effect="plain">已绑定</el-tag>
          </div>
          <div class="ci-hint">如果您已有销售联系方式，请直接联系 TA</div>
        </div>

        <div v-if="supportWechat" class="contact-item">
          <div class="ci-label">
            <el-icon :size="14"><ChatDotRound /></el-icon>
            <span>客服微信</span>
          </div>
          <div class="ci-value">
            <span class="ci-copy-text">{{ supportWechat }}</span>
            <el-button size="small" type="primary" plain @click="copy(supportWechat)">复制</el-button>
          </div>
        </div>

        <div v-if="supportPhone" class="contact-item">
          <div class="ci-label">
            <el-icon :size="14"><Phone /></el-icon>
            <span>客服电话</span>
          </div>
          <div class="ci-value">
            <span class="ci-copy-text">{{ supportPhone }}</span>
            <el-button size="small" type="primary" plain @click="copy(supportPhone)">复制</el-button>
          </div>
        </div>

        <div v-if="!salesPerson && !supportWechat && !supportPhone" class="contact-fallback">
          <el-icon><InfoFilled /></el-icon>
          暂未配置联系方式，请通过您注册时使用的邀请人或上游渠道联系销售团队。
        </div>
      </div>

      <template #footer>
        <el-button @click="contactVisible = false">知道了</el-button>
        <el-button type="primary" @click="contactVisible = false">我已联系销售</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
  InfoFilled, TrendCharts, Coin, ArrowUp, Trophy,
  ChatDotRound, User, Phone,
} from '@element-plus/icons-vue'
import { getVipInfo } from '@/api/vip'

const loading = ref(false)
const tiers = ref([])
const currentTier = ref(null)
const hasSpecialPricing = ref(false)
const totalSpent = ref(0)
const maxSingleTopup = ref(0)
const salesPerson = ref('')
const supportWechat = ref('')
const supportPhone = ref('')

const contactVisible = ref(false)
const contactTier = ref(null)

const windowWidth = ref(window.innerWidth)
function onResize() { windowWidth.value = window.innerWidth }
onMounted(() => window.addEventListener('resize', onResize))
onUnmounted(() => window.removeEventListener('resize', onResize))

const dialogWidth = computed(() => windowWidth.value <= 768 ? '92%' : '420px')

async function fetchData() {
  loading.value = true
  try {
    const res = await getVipInfo()
    tiers.value = (res?.all_tiers || []).slice().sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))
    currentTier.value = res?.current_tier || null
    hasSpecialPricing.value = res?.has_special_pricing || false
    totalSpent.value = Number(res?.total_spent || 0)
    maxSingleTopup.value = Number(res?.max_single_topup || 0)
    salesPerson.value = res?.sales_person || ''
    supportWechat.value = res?.support_wechat || ''
    supportPhone.value = res?.support_phone || ''
  } catch (e) { /* handled */ }
  finally { loading.value = false }
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

const nextTier = computed(() => {
  return tiers.value.find(t => !meetsTier(t)) || null
})

const gapSpending = computed(() => {
  if (!nextTier.value) return null
  const thr = Number(nextTier.value.spending_threshold || 0)
  if (thr <= 0) return null
  return Math.max(0, thr - totalSpent.value)
})

const gapTopup = computed(() => {
  if (!nextTier.value) return null
  const thr = Number(nextTier.value.topup_threshold || 0)
  if (thr <= 0) return null
  return Math.max(0, thr - maxSingleTopup.value)
})

const progressPct = computed(() => {
  if (!nextTier.value) return 100
  const thrSpend = Number(nextTier.value.spending_threshold || 0)
  const thrTopup = Number(nextTier.value.topup_threshold || 0)
  let best = 0
  if (thrSpend > 0) best = Math.max(best, Math.min(100, (totalSpent.value / thrSpend) * 100))
  if (thrTopup > 0) best = Math.max(best, Math.min(100, (maxSingleTopup.value / thrTopup) * 100))
  return Math.round(best)
})

// 70(%) → "7"；85(%) → "8.5"；100(%) → "10"
function formatDiscount(percent) {
  const n = Number(percent) / 10
  return Number.isInteger(n) ? String(n) : n.toFixed(1)
}

const defaultTierColors = ['#F7A600', '#1E40AF', '#c9c23b', '#E8913A', '#6366F1']
function tierColor(idx) {
  return defaultTierColors[idx % defaultTierColors.length]
}

const topDiscountText = computed(() => {
  if (!tiers.value.length) return '7 折'
  const maxDiscount = Math.min(...tiers.value.map(t => Number(t.discount_percent)))
  return `${formatDiscount(maxDiscount)} 折`
})

function openContact(t) {
  contactTier.value = t
  contactVisible.value = true
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制到剪贴板')
  } catch {
    ElMessage.warning('复制失败，请手动选择文字')
  }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.vip-page {
  max-width: 1200px;
  margin: 0 auto;
  padding-bottom: 40px;
}

/* === Hero === */
.hero {
  position: relative;
  border-radius: 20px;
  padding: 48px 40px 40px;
  margin-bottom: 28px;
  overflow: hidden;
  background:
    radial-gradient(ellipse at top right, rgba(255, 180, 80, 0.25), transparent 60%),
    linear-gradient(135deg, #1a0e07 0%, #3b1d07 55%, #6b2d0a 100%);
  color: #fff;
  box-shadow: 0 24px 60px -20px rgba(232, 145, 58, 0.5);

  .hero-bg-glow {
    position: absolute;
    top: -80px; right: -80px;
    width: 320px; height: 320px;
    background: radial-gradient(circle, rgba(255, 200, 100, 0.45), transparent 70%);
    filter: blur(20px);
    pointer-events: none;
  }
  .hero-inner { position: relative; z-index: 1; }
  .hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 16px 6px 10px;
    border-radius: 999px;
    background: linear-gradient(90deg, #FFD97A, #FF7A3C);
    color: #2a1700;
    font-weight: 700;
    font-size: 13px;
    letter-spacing: 0.5px;
    margin-bottom: 18px;
    box-shadow: 0 4px 20px rgba(255, 155, 60, 0.4);

    .icon-flame { width: 18px; height: 18px; flex-shrink: 0; }
  }
  .hero-title {
    font-size: 42px;
    font-weight: 800;
    line-height: 1.15;
    margin: 0 0 14px;
    letter-spacing: -0.5px;
    .hero-accent {
      background: linear-gradient(90deg, #FFE082, #FF9A3C);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      filter: drop-shadow(0 0 14px rgba(255, 178, 90, 0.4));
    }
  }
  .hero-desc {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.82);
    line-height: 1.8;
    margin: 0 0 24px;
    max-width: 640px;
  }
  .hero-current {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);

    .hero-current-left { display: flex; flex-direction: column; gap: 6px; }
    .hero-current-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .hero-current-label {
      font-size: 12px;
      color: rgba(255, 255, 255, 0.6);
    }
    .hero-current-badge {
      padding: 6px 14px;
      border-radius: 10px;
      font-weight: 700;
      color: #fff;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }
    .hero-current-discount {
      font-size: 20px;
      font-weight: 800;
      color: #FFD97A;
    }
    .hero-current-sub {
      font-size: 13px;
      color: rgba(255, 255, 255, 0.6);
      margin-left: 8px;
    }
  }
  .hero-current--none {
    .hero-current-label {
      font-size: 14px;
      color: rgba(255, 255, 255, 0.8);
    }
  }
}

.br-mobile { display: none; }

/* === Progress === */
.progress-card {
  border-radius: 16px;
  margin-bottom: 28px;
  border: 1px solid #f0e4d5;

  :deep(.el-card__body) { padding: 24px 28px; }

  .progress-head {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 20px;
  }
  .ph-item { flex: 1; min-width: 0; }
  .ph-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #909399;
    margin-bottom: 4px;
  }
  .ph-value { font-size: 22px; font-weight: 700; color: #2c3e50; }
  .ph-next { color: #E8913A; }
  .ph-next-disc {
    margin-left: 4px;
    background: linear-gradient(90deg, #FFB74D, #FF7043);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    font-size: 18px;
  }
  .ph-topped {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #67C23A;
    font-size: 18px;
  }
  .ph-split {
    width: 1px;
    height: 36px;
    background: #ebeef5;
  }

  .progress-bar-wrap {
    margin-top: 10px;
  }
  .progress-legend {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #606266;
    margin-bottom: 10px;
    gap: 8px;
    flex-wrap: wrap;

    .progress-amount b { color: #E8913A; margin: 0 4px; }
    .progress-or {
      margin: 0 6px;
      color: #909399;
      font-size: 12px;
    }
  }
  .progress-bar {
    height: 14px;
    border-radius: 999px;
    background: #f5f0ea;
    overflow: hidden;
    position: relative;
  }
  .progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #FFB74D 0%, #FF7043 60%, #FF5722 100%);
    position: relative;
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 10px rgba(255, 112, 67, 0.45);
  }
  .progress-shine {
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
    animation: shine 2.5s infinite;
  }
  .progress-pct {
    text-align: right;
    font-size: 14px;
    font-weight: 700;
    color: #E8913A;
    margin-top: 6px;
  }
}

@keyframes shine {
  0% { left: -100%; }
  100% { left: 100%; }
}

/* === Tiers (pricing card style) === */
.tiers {
  margin-bottom: 28px;
  padding: 40px 28px;
  border-radius: 20px;
  background: #F7F9FC;
}

.sec-head {
  text-align: center;
  max-width: 740px;
  margin: 0 auto 40px;

  .sec-tag {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    background: #FFF7E6;
    color: #E89500;
    font-weight: 700;
    font-size: 13px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 16px;
  }
  .sec-title {
    font-size: 32px;
    font-weight: 800;
    color: #0B1437;
    margin: 0 0 12px;
    line-height: 1.3;
  }
  .sec-subtitle {
    color: #6B7488;
    font-size: 16px;
    margin: 0;
    strong { color: #F7A600; font-weight: 800; }
  }
}

/* === VIP Cards (official site style) === */
.vip-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
  gap: 24px;
}

.vip-card {
  position: relative;
  padding: 36px 30px;
  background: #fff;
  border: 1.5px solid #E5E9F2;
  border-radius: 22px;
  transition: all 0.28s ease;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  --tier-color: #F7A600;

  &::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--tier-color), #F7A600);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s ease;
  }
  &:hover {
    border-color: var(--tier-color);
    transform: translateY(-5px);
    box-shadow: 0 24px 60px rgba(11, 20, 55, 0.12);
    &::before { transform: scaleX(1); }
    .vip-card__cta span { transform: translateX(4px); }
  }

  &--popular {
    border-color: #F7A600;
    box-shadow: 0 10px 30px rgba(247, 166, 0, 0.14);
    &::before {
      transform: scaleX(1);
      background: linear-gradient(90deg, #F7A600, #FFC441);
    }
  }

  &--met {
    border-color: #67C23A;
  }
}

.vip-card__badge {
  position: absolute;
  top: 16px; right: 16px;
  background: linear-gradient(135deg, #FFC441, #F7A600);
  color: #0B1437;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  padding: 5px 11px;
  border-radius: 999px;
  box-shadow: 0 4px 10px rgba(247, 166, 0, 0.3);

  &--met {
    background: linear-gradient(135deg, #86EFAC, #67C23A);
  }
}

.vip-card__icon {
  width: 60px; height: 60px; margin-bottom: 18px;
  svg { width: 100%; height: 100%; display: block; }
}

.vip-card__title {
  font-size: 22px; font-weight: 800; color: #0B1437;
  margin: 0 0 14px; line-height: 1.25;
}

.vip-card__price {
  display: flex; align-items: baseline; gap: 6px;
  margin-bottom: 20px; flex-wrap: wrap;
}
.vip-card__num {
  font-size: 52px; font-weight: 900; line-height: 1;
  font-family: 'Inter', -apple-system, 'SF Pro Display', system-ui, sans-serif;
  background: linear-gradient(135deg, #F7A600, #FFC441);
  -webkit-background-clip: text; background-clip: text;
  color: transparent;
  letter-spacing: -0.02em;
}
.vip-card__unit {
  font-size: 20px; font-weight: 800;
  color: #E89500; margin-right: 8px;
}
.vip-card__save {
  font-size: 12px; font-weight: 800;
  color: #E89500; background: #FFF7E6;
  padding: 5px 12px; border-radius: 999px;
  letter-spacing: 0.04em;
  border: 1px solid rgba(247, 166, 0, 0.2);
}

.vip-card__desc {
  font-size: 14px; color: #6B7488; margin: 0 0 22px;
  line-height: 1.6;
}

.vip-card__features {
  list-style: none; padding: 0; margin: 0 0 26px;
  display: flex; flex-direction: column; gap: 11px;
  li {
    font-size: 14px; color: #3A4466;
    padding-left: 26px; position: relative; line-height: 1.55;
    &::before {
      content: "\2713";
      position: absolute; left: 0; top: 1px;
      width: 18px; height: 18px;
      background: #FFF7E6;
      color: #E89500;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 11px;
    }
    b { color: #0B1437; font-weight: 800; }
  }
}

.vip-card__cta {
  margin-top: auto;
  display: inline-flex; align-items: center; gap: 6px;
  color: #E89500;
  font-weight: 700; font-size: 14px;
  cursor: pointer; user-select: none;
  text-decoration: none;
  padding-top: 6px;
  span { transition: transform 0.2s; display: inline-block; }
  &:hover { color: #F7A600; }
}

.tiers-note { text-align: center; margin-top: 28px; color: #6B7488; font-size: 13px; }

/* === FAQ === */
.faq-card {
  border-radius: 16px;
  border: 1px solid #ebeef5;
  .faq-title { font-size: 16px; font-weight: 700; color: #2c3e50; }
}

/* === Contact Dialog === */
.contact-dialog {
  .contact-intro {
    text-align: center;
    margin-bottom: 20px;
    p { color: #606266; font-size: 14px; line-height: 1.6; margin: 12px 0 0; }
  }
  .contact-badge {
    display: inline-block;
    padding: 6px 18px;
    border-radius: 999px;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
  }
  .contact-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .contact-item {
    padding: 12px 14px;
    border-radius: 10px;
    background: #faf7f3;
    border: 1px solid #f0e4d5;

    .ci-label {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      color: #909399;
      margin-bottom: 6px;
    }
    .ci-value {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      font-size: 15px;
      color: #2c3e50;
      b { font-weight: 700; }
    }
    .ci-copy-text {
      font-family: 'SFMono-Regular', Consolas, monospace;
      word-break: break-all;
      flex: 1;
      min-width: 0;
    }
    .ci-hint { font-size: 12px; color: #909399; margin-top: 6px; }
  }
  .contact-fallback {
    padding: 14px;
    text-align: center;
    color: #909399;
    font-size: 13px;
    background: #fafafa;
    border-radius: 10px;
  }
}

/* === Mobile === */
@media (max-width: 768px) {
  .vip-page { padding: 0 2px 80px; }

  .hero {
    padding: 28px 18px 24px;
    border-radius: 14px;
    margin-bottom: 18px;
    box-shadow: 0 16px 40px -16px rgba(232, 145, 58, 0.4);

    .hero-badge {
      font-size: 12px;
      padding: 5px 12px 5px 8px;
      margin-bottom: 14px;
      .icon-flame { width: 16px; height: 16px; }
    }
    .hero-title {
      font-size: 28px;
      line-height: 1.2;
      margin-bottom: 12px;
    }
    .hero-desc {
      font-size: 13px;
      line-height: 1.7;
      margin-bottom: 18px;
    }
    .hero-current {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      padding: 12px 14px;
      gap: 8px;
      width: 100%;
      box-sizing: border-box;
    }
    .hero-current-badge {
      padding: 4px 10px;
      font-size: 13px;
    }
    .hero-current-discount { font-size: 17px; }
    .hero-current-sub { margin-left: 0; }
  }
  .br-mobile { display: initial; }
  .br-desktop { display: none; }

  .progress-card {
    border-radius: 12px;
    margin-bottom: 18px;
    :deep(.el-card__body) { padding: 16px; }
  }
  .progress-head {
    flex-direction: column;
    align-items: stretch;
    gap: 12px !important;
    .ph-split { display: none; }
    .ph-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
    }
    .ph-label { font-size: 11px; margin-bottom: 0; }
    .ph-value { font-size: 16px; }
    .ph-next-disc { font-size: 15px; }
    .ph-topped { font-size: 15px; }
  }
  .progress-legend {
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    font-size: 12px;
    .progress-or { margin: 0 4px; }
  }
  .progress-bar { height: 10px; }

  .tiers {
    padding: 28px 14px;
    border-radius: 14px;
    margin-bottom: 18px;
  }
  .sec-head {
    margin-bottom: 26px;
    .sec-tag { font-size: 11px; padding: 4px 12px; margin-bottom: 10px; }
    .sec-title { font-size: 22px; margin-bottom: 8px; }
    .sec-subtitle { font-size: 13px; }
  }
  .vip-grid { grid-template-columns: 1fr; gap: 16px; }
  .vip-card {
    padding: 26px 22px; border-radius: 14px;
    &:hover { transform: none; }
  }
  .vip-card__icon { width: 52px; height: 52px; margin-bottom: 14px; }
  .vip-card__title { font-size: 19px; }
  .vip-card__num { font-size: 42px; }
  .vip-card__unit { font-size: 17px; }
  .vip-card__save { font-size: 11px; padding: 4px 10px; }
  .vip-card__desc { font-size: 13px; margin-bottom: 18px; }
  .vip-card__features li { font-size: 13px; padding-left: 22px; }

  .faq-card {
    border-radius: 12px;
    :deep(.el-card__header) { padding: 12px 14px; }
    :deep(.el-card__body) { padding: 0 4px; }
    .faq-title { font-size: 15px; }
    :deep(.el-collapse-item__header) {
      font-size: 14px;
      line-height: 1.5;
      padding: 12px 10px;
      height: auto;
      min-height: 48px;
    }
    :deep(.el-collapse-item__content) {
      font-size: 13px;
      line-height: 1.7;
      padding: 0 10px 14px;
    }
  }

  /* Dialog 在手机端变紧凑 */
  :global(.contact-dialog .el-dialog__header) { padding: 14px 14px 10px; }
  :global(.contact-dialog .el-dialog__body) { padding: 0 14px 10px; }
  :global(.contact-dialog .el-dialog__footer) { padding: 10px 14px 14px; }
  :global(.contact-dialog .el-dialog__title) { font-size: 15px; }

  .contact-dialog {
    .contact-intro {
      margin-bottom: 14px;
      p { font-size: 13px; margin-top: 10px; }
    }
    .contact-badge {
      padding: 5px 14px;
      font-size: 13px;
    }
    .contact-item {
      padding: 10px 12px;
      .ci-value { font-size: 14px; gap: 8px; flex-wrap: wrap; }
      .ci-hint { font-size: 11px; }
    }
  }
}

/* 更窄屏幕（小米/折叠屏内屏）— 进一步压缩 */
@media (max-width: 380px) {
  .hero .hero-title { font-size: 24px; }
  .product-card .product-price-num { font-size: 30px; }
  .progress-head .ph-value { font-size: 15px; }
}
</style>
