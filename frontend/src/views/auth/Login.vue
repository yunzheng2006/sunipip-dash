<template>
  <div class="login-container">
    <!-- 装饰背景 -->
    <div class="bg-decoration">
      <div class="circle circle-1"></div>
      <div class="circle circle-2"></div>
      <div class="circle circle-3"></div>
    </div>

    <div class="login-card">
      <div class="login-left">
        <div class="brand-area">
          <div class="brand-icon">
            <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="24" cy="24" r="20" fill="rgba(255,255,255,0.2)"/>
              <path d="M16 20c0-4.4 3.6-8 8-8s8 3.6 8 8-3.6 8-8 8" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
              <circle cx="24" cy="28" r="4" fill="rgba(255,255,255,0.6)"/>
              <path d="M20 32l-4 6m16-6l4 6" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <h1>{{ appStore.siteName || 'SuniPIP' }}</h1>
          <p class="brand-desc">Proxy IP Asset Management</p>
          <div class="brand-features">
            <div class="feature-item">
              <span class="dot"></span>
              <span>全球代理IP资产管理</span>
            </div>
            <div class="feature-item">
              <span class="dot"></span>
              <span>智能订阅与计费系统</span>
            </div>
            <div class="feature-item">
              <span class="dot"></span>
              <span>多平台API对接</span>
            </div>
          </div>
        </div>
      </div>

      <div class="login-right">
        <div class="form-area">
          <h2>欢迎回来</h2>
          <p class="form-subtitle">请登录您的管理账户</p>

          <el-form
            ref="formRef"
            :model="form"
            :rules="rules"
            size="large"
            @keyup.enter="handleLogin"
          >
            <el-form-item prop="username">
              <el-input
                v-model="form.username"
                placeholder="请输入用户名"
                :prefix-icon="User"
              />
            </el-form-item>
            <el-form-item prop="password">
              <el-input
                v-model="form.password"
                type="password"
                placeholder="请输入密码"
                :prefix-icon="Lock"
                show-password
              />
            </el-form-item>
            <el-form-item>
              <el-button
                type="primary"
                :loading="loading"
                class="login-btn"
                @click="handleLogin"
              >
                {{ loading ? '登录中...' : '登 录' }}
              </el-button>
            </el-form-item>
          </el-form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { User, Lock } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'

const router = useRouter()
const authStore = useAuthStore()
const appStore = useAppStore()

onMounted(() => appStore.fetchSiteInfo())

const formRef = ref(null)
const loading = ref(false)

const form = ref({
  username: '',
  password: '',
})

const rules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

async function handleLogin() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  try {
    await authStore.login(form.value)
    ElMessage.success('登录成功')
    router.push('/dashboard')
  } catch {
    // Error handled by interceptor
  } finally {
    loading.value = false
  }
}
</script>

<style lang="scss" scoped>
.login-container {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(160deg, #FFF8F0 0%, #FDF0E2 30%, #F7E4CE 60%, #F0D5B5 100%);
  position: relative;
  overflow: hidden;
}

.bg-decoration {
  position: absolute;
  inset: 0;
  pointer-events: none;

  .circle {
    position: absolute;
    border-radius: 50%;
    opacity: 0.15;
  }

  .circle-1 {
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, #E8913A, transparent 70%);
    top: -150px;
    right: -100px;
  }

  .circle-2 {
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, #F2A85A, transparent 70%);
    bottom: -100px;
    left: -80px;
  }

  .circle-3 {
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, #E8913A, transparent 70%);
    top: 40%;
    left: 20%;
  }
}

.login-card {
  display: flex;
  width: 860px;
  min-height: 480px;
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(200, 160, 100, 0.12), 0 1px 3px rgba(0, 0, 0, 0.04);
  overflow: hidden;
  position: relative;
  z-index: 1;
}

.login-left {
  width: 380px;
  background: linear-gradient(145deg, #E8913A, #D47A28, #C06A1F);
  padding: 50px 40px;
  display: flex;
  align-items: center;

  .brand-area {
    color: #fff;

    .brand-icon {
      width: 52px;
      height: 52px;
      margin-bottom: 24px;
    }

    h1 {
      font-size: 32px;
      font-weight: 700;
      letter-spacing: 3px;
      margin-bottom: 8px;
    }

    .brand-desc {
      font-size: 13px;
      opacity: 0.75;
      letter-spacing: 1px;
      margin-bottom: 40px;
    }

    .brand-features {
      display: flex;
      flex-direction: column;
      gap: 16px;

      .feature-item {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
        opacity: 0.9;

        .dot {
          width: 6px;
          height: 6px;
          border-radius: 50%;
          background: rgba(255, 255, 255, 0.7);
          flex-shrink: 0;
        }
      }
    }
  }
}

.login-right {
  flex: 1;
  padding: 50px 48px;
  display: flex;
  align-items: center;

  .form-area {
    width: 100%;

    h2 {
      font-size: 24px;
      font-weight: 600;
      color: #2C3E50;
      margin-bottom: 8px;
    }

    .form-subtitle {
      font-size: 14px;
      color: #909399;
      margin-bottom: 36px;
    }

    .login-btn {
      width: 100%;
      height: 46px;
      font-size: 16px;
      letter-spacing: 4px;
      border-radius: 10px;
      background: linear-gradient(135deg, #E8913A, #F2A85A) !important;
      border: none !important;
      box-shadow: 0 4px 16px rgba(232, 145, 58, 0.3);
      transition: all 0.3s;

      &:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 24px rgba(232, 145, 58, 0.4);
      }

      &:active {
        transform: translateY(0);
      }
    }

    :deep(.el-input__wrapper) {
      border-radius: 10px;
      padding: 4px 14px;
      box-shadow: 0 0 0 1px #EADFD2 inset;

      &:hover {
        box-shadow: 0 0 0 1px #E8913A inset;
      }

      &.is-focus {
        box-shadow: 0 0 0 1px #E8913A inset;
      }
    }
  }
}

@media (max-width: 900px) {
  .login-card {
    flex-direction: column;
    width: 90%;
    max-width: 420px;
  }

  .login-left {
    width: 100%;
    padding: 30px;

    .brand-features {
      display: none;
    }
  }
}
</style>
