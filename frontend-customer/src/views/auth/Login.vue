<template>
  <div class="auth-page">
    <div class="auth-split">
      <!-- Left: dark navy dashboard showcase -->
      <div class="auth-left">
        <div class="left-grid-bg"></div>
        <div class="left-glow left-glow--orange"></div>
        <div class="left-glow left-glow--blue"></div>

        <div class="left-content">
          <h1 class="left-title">全球代理 IP 基础设施</h1>
          <p class="left-subtitle">为企业与开发者提供高可用、低延迟的全球代理网络</p>

          <!-- Dashboard visual -->
          <div class="dashboard-visual">
            <div class="dash-globe">
              <svg viewBox="0 0 200 200" fill="none">
                <circle cx="100" cy="100" r="80" stroke="rgba(247,166,0,0.15)" stroke-width="1"/>
                <circle cx="100" cy="100" r="60" stroke="rgba(247,166,0,0.1)" stroke-width="1"/>
                <circle cx="100" cy="100" r="40" stroke="rgba(247,166,0,0.08)" stroke-width="1"/>
                <ellipse cx="100" cy="100" rx="80" ry="30" stroke="rgba(247,166,0,0.12)" stroke-width="0.8"/>
                <ellipse cx="100" cy="100" rx="30" ry="80" stroke="rgba(247,166,0,0.12)" stroke-width="0.8"/>
                <line x1="20" y1="100" x2="180" y2="100" stroke="rgba(247,166,0,0.08)" stroke-width="0.5"/>
                <line x1="100" y1="20" x2="100" y2="180" stroke="rgba(247,166,0,0.08)" stroke-width="0.5"/>
                <!-- Node dots -->
                <circle cx="55" cy="70" r="3" fill="#F7A600" opacity="0.9"/><circle cx="55" cy="70" r="6" fill="#F7A600" opacity="0.2"/>
                <circle cx="130" cy="55" r="2.5" fill="#3B82F6" opacity="0.8"/><circle cx="130" cy="55" r="5" fill="#3B82F6" opacity="0.15"/>
                <circle cx="145" cy="105" r="3" fill="#F7A600" opacity="0.9"/><circle cx="145" cy="105" r="6" fill="#F7A600" opacity="0.2"/>
                <circle cx="75" cy="135" r="2.5" fill="#3B82F6" opacity="0.8"/><circle cx="75" cy="135" r="5" fill="#3B82F6" opacity="0.15"/>
                <circle cx="110" cy="140" r="2" fill="#10B981" opacity="0.8"/><circle cx="110" cy="140" r="4" fill="#10B981" opacity="0.15"/>
                <circle cx="60" cy="100" r="2" fill="#10B981" opacity="0.8"/>
              </svg>
            </div>

            <!-- Floating stat cards -->
            <div class="float-card float-card--left">
              <div class="float-card__val">2亿+</div>
              <div class="float-card__label">全球 IP 池</div>
            </div>
            <div class="float-card float-card--right">
              <div class="float-card__val">0.38s</div>
              <div class="float-card__label">平均延迟</div>
            </div>
            <div class="float-card float-card--bottom">
              <div class="float-card__val">99.97%</div>
              <div class="float-card__label">连接成功率</div>
            </div>
          </div>

          <!-- Stats row -->
          <div class="stats-bar">
            <div class="stat"><span class="stat-val">190+</span><span class="stat-lbl">覆盖国家</span></div>
            <div class="stat-sep"></div>
            <div class="stat"><span class="stat-val">99.9%</span><span class="stat-lbl">服务可用</span></div>
            <div class="stat-sep"></div>
            <div class="stat"><span class="stat-val">秒级</span><span class="stat-lbl">自动交付</span></div>
          </div>
        </div>
      </div>

      <!-- Right: login form with logo -->
      <div class="auth-right">
        <div class="auth-container">
          <div class="auth-brand">
            <img v-if="appStore.siteLogo" :src="appStore.siteLogo" class="brand-logo-img" />
            <div v-else class="brand-logo">{{ (appStore.siteName || 'S')[0] }}</div>
          </div>

          <h2 class="form-title">登录</h2>

          <el-card class="auth-card" shadow="never">
            <el-tabs v-model="loginMode" class="login-tabs" stretch>
              <el-tab-pane label="密码登录" name="password" />
              <el-tab-pane label="验证码登录" name="sms" />
            </el-tabs>

            <el-form
              v-show="loginMode === 'password'"
              ref="formRef"
              :model="form"
              :rules="rules"
              size="large"
              @keyup.enter="handleLogin"
            >
              <el-form-item prop="username">
                <el-input v-model="form.username" placeholder="手机号" :prefix-icon="User" autocomplete="username" />
              </el-form-item>
              <el-form-item prop="password">
                <el-input v-model="form.password" type="password" placeholder="密码" :prefix-icon="Lock" show-password autocomplete="current-password" />
              </el-form-item>
              <el-form-item>
                <el-button type="primary" :loading="loading" style="width: 100%" @click="handleLogin">登 录</el-button>
              </el-form-item>
            </el-form>

            <el-form
              v-show="loginMode === 'sms'"
              ref="smsFormRef"
              :model="smsForm"
              :rules="smsRules"
              size="large"
              @keyup.enter="handleSmsLogin"
            >
              <el-form-item prop="phone">
                <el-input v-model="smsForm.phone" placeholder="手机号" :prefix-icon="Phone" />
              </el-form-item>
              <el-form-item prop="sms_code">
                <el-row :gutter="8" style="width:100%">
                  <el-col :span="14">
                    <el-input v-model="smsForm.sms_code" placeholder="6位验证码" maxlength="6" :prefix-icon="ChatDotSquare" />
                  </el-col>
                  <el-col :span="10">
                    <el-button :disabled="smsCooldown > 0 || !smsForm.phone" @click="openCaptcha" style="width:100%">
                      {{ smsCooldown > 0 ? `${smsCooldown}s` : '获取验证码' }}
                    </el-button>
                  </el-col>
                </el-row>
              </el-form-item>
              <el-form-item>
                <el-button type="primary" :loading="loading" style="width: 100%" @click="handleSmsLogin">登 录</el-button>
              </el-form-item>
            </el-form>

            <div class="auth-footer">
              还没有账号？<router-link to="/register" class="link">立即注册</router-link>
            </div>
          </el-card>
        </div>
      </div>
    </div>

    <el-dialog v-model="captchaVisible" title="安全验证" width="360px" :close-on-click-modal="false">
      <div style="text-align:center;margin-bottom:16px">
        <p style="font-size:18px;font-weight:600">{{ captcha.question }}</p>
      </div>
      <el-input v-model="captchaAnswer" placeholder="输入计算结果" size="large" @keyup.enter="submitCaptcha" />
      <template #footer>
        <el-button @click="captchaVisible = false">取消</el-button>
        <el-button type="primary" @click="submitCaptcha">确认并发送</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { User, Lock, Phone, ChatDotSquare } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import { getSmsCaptcha, sendSmsCode } from '@/api/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const appStore = useAppStore()

const loginMode = ref('password')
const formRef = ref()
const smsFormRef = ref()
const loading = ref(false)

const form = reactive({ username: '', password: '' })
const rules = {
  username: [{ required: true, message: '请输入手机号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

const smsForm = reactive({ phone: '', sms_code: '' })
const smsRules = {
  phone: [
    { required: true, message: '请输入手机号', trigger: 'blur' },
    { pattern: /^1[3-9]\d{9}$/, message: '请输入正确的手机号', trigger: 'blur' },
  ],
  sms_code: [
    { required: true, message: '请输入验证码', trigger: 'blur' },
    { len: 6, message: '验证码为6位数字', trigger: 'blur' },
  ],
}

const smsCooldown = ref(0)
const captchaVisible = ref(false)
const captchaAnswer = ref('')
const captcha = reactive({ question: '', a: 0, b: 0, expected: 0 })

async function openCaptcha() {
  try {
    const res = await getSmsCaptcha()
    Object.assign(captcha, res)
    captchaAnswer.value = ''
    captchaVisible.value = true
  } catch {}
}

async function submitCaptcha() {
  if (Number(captchaAnswer.value) !== captcha.expected) {
    ElMessage.error('计算结果不正确')
    return
  }
  captchaVisible.value = false
  await sendSms()
}

async function sendSms() {
  try {
    await sendSmsCode({
      phone: smsForm.phone,
      type: 'login',
      captcha_answer: captcha.expected,
      captcha_expected: captcha.expected,
    })
    ElMessage.success('验证码已发送')
    smsCooldown.value = 60
    const timer = setInterval(() => {
      smsCooldown.value--
      if (smsCooldown.value <= 0) clearInterval(timer)
    }, 1000)
  } catch {}
}

onMounted(async () => {
  appStore.fetchSiteInfo()
  const token = route.query.impersonate
  if (token) {
    const { setToken } = await import('@/utils/auth')
    setToken(token)
    authStore.token = token
    const customerName = route.query.name || '客户'
    localStorage.setItem('sunipip_impersonate', customerName)
    try {
      await authStore.fetchMe()
      ElMessage.success(`已模拟登录为「${customerName}」`)
      router.replace('/dashboard')
    } catch {
      localStorage.removeItem('sunipip_impersonate')
      ElMessage.error('模拟登录 token 无效或已过期')
    }
    return
  }
})

async function handleLogin() {
  localStorage.removeItem('sunipip_impersonate')
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  try {
    await authStore.login(form)
    ElMessage.success('登录成功')
    const redirect = route.query.redirect || '/dashboard'
    router.push(redirect)
  } catch {}
  finally { loading.value = false }
}

async function handleSmsLogin() {
  localStorage.removeItem('sunipip_impersonate')
  const valid = await smsFormRef.value.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  try {
    await authStore.loginBySms(smsForm)
    ElMessage.success('登录成功')
    const redirect = route.query.redirect || '/dashboard'
    router.push(redirect)
  } catch {}
  finally { loading.value = false }
}
</script>

<style lang="scss" scoped>
$brand: #F7A600;
$brand-blue: #1E40AF;
$navy: #0B1437;
$navy-light: #111C44;

.auth-page {
  min-height: 100vh;
  background: #F7F8FC;
}

.auth-split {
  display: flex;
  min-height: 100vh;
}

/* ========== LEFT — dark navy dashboard ========== */
.auth-left {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(160deg, $navy 0%, $navy-light 40%, #080f2d 100%);
  padding: 60px 48px;
  position: relative;
  overflow: hidden;
}

.left-grid-bg {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
  background-size: 60px 60px;
  mask-image: radial-gradient(ellipse 70% 60% at 50% 50%, #000 10%, transparent 100%);
  -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 50%, #000 10%, transparent 100%);
}

.left-glow {
  position: absolute;
  border-radius: 50%;
  pointer-events: none;

  &--orange {
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba($brand, 0.08) 0%, transparent 70%);
    top: 30%; left: 20%;
  }
  &--blue {
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba($brand-blue, 0.1) 0%, transparent 70%);
    bottom: 15%; right: 10%;
  }
}

.left-content {
  position: relative;
  z-index: 1;
  max-width: 420px;
  text-align: center;
}

.left-title {
  margin: 0 0 12px;
  font-size: 28px;
  font-weight: 700;
  color: #fff;
  letter-spacing: 1px;
}

.left-subtitle {
  margin: 0 0 36px;
  font-size: 14px;
  color: rgba(255,255,255,0.5);
  font-weight: 400;
}

/* Dashboard visual */
.dashboard-visual {
  position: relative;
  width: 280px;
  height: 220px;
  margin: 0 auto 36px;
}

.dash-globe {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: globe-rotate 30s linear infinite;

  svg { width: 200px; height: 200px; }
}

@keyframes globe-rotate {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.float-card {
  position: absolute;
  padding: 10px 16px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 10px;
  backdrop-filter: blur(12px);
  animation: float-card 4s ease-in-out infinite;

  &__val {
    font-size: 18px;
    font-weight: 700;
    color: $brand;
    font-variant-numeric: tabular-nums;
    font-family: 'SF Mono', 'Cascadia Code', Consolas, monospace;
  }
  &__label {
    font-size: 11px;
    color: rgba(255,255,255,0.45);
    margin-top: 2px;
  }

  &--left { left: -20px; top: 30px; animation-delay: 0s; }
  &--right { right: -20px; top: 50px; animation-delay: 1.5s; }
  &--bottom { left: 50%; bottom: -5px; transform: translateX(-50%); animation-delay: 3s; }
}

@keyframes float-card {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-6px); }
}

.float-card--bottom {
  @keyframes float-card-center {
    0%, 100% { transform: translateX(-50%) translateY(0); }
    50% { transform: translateX(-50%) translateY(-6px); }
  }
  animation: float-card-center 4s ease-in-out infinite;
  animation-delay: 3s;
}

/* Stats bar — dark version */
.stats-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 20px;
  padding: 18px 28px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  backdrop-filter: blur(8px);
}

.stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  flex: 1;
}

.stat-val {
  font-size: 22px;
  font-weight: 700;
  color: #fff;
  font-variant-numeric: tabular-nums;
}

.stat-lbl {
  font-size: 11px;
  color: rgba(255,255,255,0.4);
  letter-spacing: 0.5px;
}

.stat-sep {
  width: 1px; height: 28px;
  background: linear-gradient(180deg, transparent, rgba(255,255,255,0.1), transparent);
  flex-shrink: 0;
}

/* ========== RIGHT ========== */
.auth-right {
  flex: 0 0 480px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
  background: #fff;
}

.auth-container { width: 100%; max-width: 380px; }

.auth-brand {
  text-align: center;
  margin-bottom: 28px;

  .brand-logo-img {
    width: 120px; height: 120px; margin: 0 auto;
    object-fit: contain; display: block;
    filter: drop-shadow(0 4px 20px rgba(0,0,0,0.1));
  }

  .brand-logo {
    width: 120px; height: 120px; margin: 0 auto;
    background: linear-gradient(135deg, $navy, $brand-blue);
    color: #fff; font-size: 52px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    border-radius: 28px; box-shadow: 0 8px 24px rgba(0,0,0,0.12);
  }
}

.form-title {
  margin: 0 0 20px;
  font-size: 20px;
  font-weight: 700;
  color: #1A1F36;
  text-align: center;
}

.auth-card {
  border-radius: 16px;
  border: 1px solid #EAECF3;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  :deep(.el-card__body) { padding: 32px; }
}

.login-tabs {
  margin-bottom: 20px;
  :deep(.el-tabs__header) { margin-bottom: 0; }
  :deep(.el-tabs__item) { font-size: 15px; font-weight: 600; }
}

.auth-footer {
  text-align: center; font-size: 13px; color: #94A3B8; margin-top: 8px;
  .link { color: $brand-blue; text-decoration: none; font-weight: 500; margin-left: 4px;
    &:hover { text-decoration: underline; }
  }
}

/* ========== RESPONSIVE ========== */
@media (max-width: 900px) {
  .auth-page { background: #fff; }
  .auth-split { display: block; }
  .auth-left { display: none; }

  .auth-right {
    flex: none;
    min-height: 100vh;
    padding: 24px 20px;
  }

  .auth-container { max-width: 420px; }
}

@media (max-width: 768px) {
  .auth-right { padding: 16px 12px; }
  .auth-container { max-width: 100%; }
  .auth-brand {
    margin-bottom: 16px;
    .brand-logo, .brand-logo-img { width: 80px; height: 80px; }
  }
  .auth-card :deep(.el-card__body) { padding: 20px 16px; }
  .login-tabs :deep(.el-tabs__item) { font-size: 14px; }
}
</style>
