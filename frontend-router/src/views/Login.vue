<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-brand">
        <el-icon :size="48" color="#409eff"><Monitor /></el-icon>
        <h1>SuniPIP Router</h1>
        <p class="login-subtitle">设备管理面板</p>
      </div>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        @submit.prevent="handleLogin"
      >
        <el-form-item label="账号" prop="identifier">
          <el-input
            v-model="form.identifier"
            placeholder="请输入邮箱或手机号"
            :prefix-icon="User"
            size="large"
          />
        </el-form-item>

        <el-form-item label="密码" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            :prefix-icon="Lock"
            size="large"
            show-password
            @keyup.enter="handleLogin"
          />
        </el-form-item>

        <el-form-item>
          <el-button
            type="primary"
            size="large"
            class="login-btn"
            :loading="loading"
            @click="handleLogin"
          >
            登 录
          </el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { User, Lock, Monitor } from '@element-plus/icons-vue'
import { login } from '../api/auth'
import { setToken } from '../utils/auth'

const router = useRouter()
const formRef = ref(null)
const loading = ref(false)

const form = reactive({
  identifier: '',
  password: ''
})

const rules = {
  identifier: [
    { required: true, message: '请输入账号', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' }
  ]
}

async function handleLogin() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  try {
    const { data } = await login(form.identifier, form.password)
    const token = data.data?.token || data.token
    if (!token) {
      ElMessage.error('登录失败：未获取到凭证')
      return
    }
    setToken(token)
    ElMessage.success('登录成功')
    router.push('/setup')
  } catch (err) {
    const msg = err.response?.data?.message || '登录失败，请检查账号和密码'
    ElMessage.error(msg)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 20px;
}

.login-card {
  width: 100%;
  max-width: 420px;
  background: #fff;
  border-radius: 12px;
  padding: 40px 36px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
}

.login-brand {
  text-align: center;
  margin-bottom: 32px;
}

.login-brand h1 {
  margin: 12px 0 4px;
  font-size: 24px;
  color: #303133;
}

.login-subtitle {
  color: #909399;
  font-size: 14px;
}

.login-btn {
  width: 100%;
  margin-top: 8px;
}

@media (max-width: 767px) {
  .login-card {
    padding: 32px 24px;
  }
}
</style>
