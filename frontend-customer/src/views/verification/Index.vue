<template>
  <div class="verification-page">
    <h1 class="page-title">实名认证</h1>

    <div v-loading="loading" class="verification-content">
      <!-- 未认证 -->
      <div v-if="!loading && !info.verified" class="empty-state">
        <div class="empty-shield">
          <el-icon :size="56" color="#D97706"><WarningFilled /></el-icon>
        </div>
        <h3>尚未完成实名认证</h3>
        <p>根据相关法规要求，下单前需完成实名认证。<br>您的信息仅用于身份核验，将严格保密。</p>
        <el-button type="primary" size="large" @click="showVerifyDialog = true">
          <el-icon><UserFilled /></el-icon> 立即认证
        </el-button>
      </div>

      <!-- 已认证 -->
      <template v-if="!loading && info.verified">
        <!-- 状态横幅 -->
        <div class="status-banner" :class="info.verified_type">
          <div class="banner-left">
            <div class="banner-icon">
              <el-icon :size="28"><CircleCheckFilled /></el-icon>
            </div>
            <div>
              <div class="banner-title">
                {{ info.verified_type === 'enterprise' ? '企业认证' : '个人认证' }}
                <el-tag
                  :type="info.verified_type === 'enterprise' ? 'warning' : ''"
                  size="small"
                  round
                >{{ info.verified_type === 'enterprise' ? '企业' : '个人' }}</el-tag>
              </div>
              <div class="banner-sub">认证时间：{{ info.verified_at || '-' }}</div>
            </div>
          </div>
          <el-button
            v-if="info.verified_type === 'personal'"
            type="warning"
            plain
            @click="showUpgradeDialog = true"
          >
            <el-icon><Top /></el-icon> 升级企业认证
          </el-button>
        </div>

        <!-- 认证信息卡片 -->
        <el-card shadow="never" class="info-card">
          <div class="info-section">
            <div class="section-title">
              <el-icon :size="16"><User /></el-icon>
              {{ info.verified_type === 'enterprise' ? '法人信息' : '个人信息' }}
            </div>
            <div class="info-table">
              <div class="info-row">
                <span class="info-label">{{ info.verified_type === 'enterprise' ? '法人姓名' : '真实姓名' }}</span>
                <span class="info-value masked">{{ info.verified_name || '-' }}</span>
              </div>
              <div class="info-row">
                <span class="info-label">身份证号</span>
                <span class="info-value masked">{{ info.verified_id_number || '-' }}</span>
              </div>
            </div>
          </div>

          <template v-if="info.verified_type === 'enterprise'">
            <div class="info-divider"></div>
            <div class="info-section">
              <div class="section-title">
                <el-icon :size="16"><OfficeBuilding /></el-icon>
                企业信息
              </div>
              <div class="info-table">
                <div class="info-row">
                  <span class="info-label">企业名称</span>
                  <span class="info-value">{{ info.verified_enterprise_name || '-' }}</span>
                </div>
                <div class="info-row">
                  <span class="info-label">统一社会信用代码</span>
                  <span class="info-value mono">{{ info.verified_credit_code || '-' }}</span>
                </div>
                <div v-if="info.verified_license_image" class="info-row">
                  <span class="info-label">营业执照</span>
                  <span class="info-value">
                    <el-image
                      :src="info.verified_license_image"
                      :preview-src-list="[info.verified_license_image]"
                      fit="cover"
                      class="license-thumb"
                    >
                      <template #placeholder>
                        <div class="license-loading">加载中...</div>
                      </template>
                    </el-image>
                  </span>
                </div>
              </div>
            </div>
          </template>
        </el-card>
      </template>
    </div>

    <VerificationDialog v-model="showVerifyDialog" :pending="verificationPending" @verified="onVerified" />
    <UpgradeEnterpriseDialog v-model="showUpgradeDialog" @verified="onVerified" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { CircleCheckFilled, WarningFilled, UserFilled, User, Top, OfficeBuilding } from '@element-plus/icons-vue'
import { getVerificationInfo, getVerificationStatus } from '@/api/verification'
import VerificationDialog from '@/components/VerificationDialog.vue'
import UpgradeEnterpriseDialog from '@/components/UpgradeEnterpriseDialog.vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const loading = ref(true)
const info = ref({})
const showVerifyDialog = ref(false)
const showUpgradeDialog = ref(false)
const verificationPending = ref(null)

async function fetchInfo() {
  loading.value = true
  try {
    const res = await getVerificationInfo()
    info.value = res?.data || res || {}
    try {
      const vStatus = await getVerificationStatus()
      verificationPending.value = vStatus?.has_pending ? vStatus : null
    } catch {}
  } catch { /* handled */ }
  finally { loading.value = false }
}

function onVerified() {
  authStore.fetchMe()
  fetchInfo()
}

onMounted(fetchInfo)
</script>

<style lang="scss" scoped>
$brand: #6366F1;

.verification-page {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.page-title {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  color: #1E293B;
}

.verification-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-height: 200px;
}

// ===== 未认证空状态 =====
.empty-state {
  text-align: center;
  padding: 60px 20px;
  background: #fff;
  border-radius: 16px;
  border: 1.5px dashed #E2E8F0;

  .empty-shield {
    width: 88px;
    height: 88px;
    margin: 0 auto 20px;
    background: #FEF3C7;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  h3 {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 700;
    color: #1E293B;
  }

  p {
    margin: 0 0 24px;
    font-size: 14px;
    color: #64748B;
    line-height: 1.7;
  }
}

// ===== 状态横幅 =====
.status-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-radius: 14px;
  gap: 16px;

  &.personal {
    background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
    border: 1.5px solid #C7D2FE;
    .banner-icon { color: #16A34A; background: #DCFCE7; }
  }
  &.enterprise {
    background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
    border: 1.5px solid #BBF7D0;
    .banner-icon { color: #16A34A; background: rgba(#16A34A, 0.12); }
  }
}

.banner-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.banner-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.banner-title {
  font-size: 17px;
  font-weight: 700;
  color: #1E293B;
  display: flex;
  align-items: center;
  gap: 8px;
}

.banner-sub {
  margin-top: 2px;
  font-size: 13px;
  color: #64748B;
}

// ===== 信息卡片 =====
.info-card {
  border-radius: 14px;
  border: 1.5px solid #E2E8F0;
  :deep(.el-card__body) { padding: 0; }
}

.info-section {
  padding: 24px;
}

.info-divider {
  height: 1px;
  background: #F1F5F9;
  margin: 0 24px;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 700;
  color: #334155;
  margin-bottom: 16px;
}

.info-table {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.info-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  min-height: 28px;
}

.info-label {
  width: 130px;
  flex-shrink: 0;
  font-size: 13px;
  color: #94A3B8;
  font-weight: 500;
  padding-top: 3px;
}

.info-value {
  font-size: 14px;
  color: #1E293B;
  font-weight: 500;

  &.masked {
    font-family: 'SF Mono', Consolas, monospace;
    letter-spacing: 0.5px;
    color: #475569;
  }
  &.mono {
    font-family: 'SF Mono', Consolas, monospace;
    letter-spacing: 0.3px;
  }
}

.license-thumb {
  width: 140px;
  height: 90px;
  border-radius: 8px;
  border: 1px solid #E2E8F0;
  cursor: pointer;
  transition: box-shadow 0.15s;
  &:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
}

.license-loading {
  width: 140px;
  height: 90px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F8FAFC;
  color: #94A3B8;
  font-size: 12px;
  border-radius: 8px;
}

// ===== 移动端 =====
@media (max-width: 768px) {
  .page-title { font-size: 18px; }

  .empty-state {
    padding: 40px 16px;
    .empty-shield { width: 72px; height: 72px; }
    h3 { font-size: 16px; }
    p { font-size: 13px; }
  }

  .status-banner {
    flex-direction: column;
    align-items: flex-start;
    padding: 16px;
  }

  .info-section { padding: 16px; }
  .info-divider { margin: 0 16px; }
  .info-label { width: 100px; font-size: 12px; }
  .info-value { font-size: 13px; }
}
</style>
