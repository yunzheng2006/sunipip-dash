<template>
  <div class="profile-page">
    <h1 class="page-title">账号设置</h1>

    <el-tabs v-model="activeTab" class="profile-tabs">
      <!-- 基本资料 -->
      <el-tab-pane label="基本资料" name="profile">
        <el-card shadow="never" class="form-card">
          <el-form :model="form" label-width="120px" :rules="rules" ref="formRef" style="max-width: 560px">
            <el-form-item label="登录用户名">
              <el-input :value="profile?.username" disabled />
              <div class="hint">用户名不可修改</div>
            </el-form-item>
            <el-form-item label="显示名称" prop="customer_name">
              <el-input v-model="form.customer_name" />
            </el-form-item>
            <el-form-item label="邮箱">
              <el-input v-model="form.email" placeholder="用于接收到期提醒" />
            </el-form-item>
            <el-form-item label="手机号">
              <el-input v-model="form.phone" />
            </el-form-item>
            <el-form-item label="公司名">
              <el-input v-model="form.company_name" />
            </el-form-item>
            <el-form-item label="公司编号">
              <el-input v-model="form.company_id" />
            </el-form-item>
            <el-form-item label="地址">
              <el-input v-model="form.address" type="textarea" :rows="2" />
            </el-form-item>
            <el-form-item label="默认自动续费">
              <el-switch v-model="form.auto_renew_default" />
              <span class="hint">新订阅默认开启自动续费</span>
            </el-form-item>
            <el-form-item label="到期短信提醒">
              <el-switch v-model="form.sms_expiry_notify" />
              <span class="hint">IP到期前1天发送短信提醒（需绑定手机号）</span>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="submitting" @click="handleSave">
                保存
              </el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-tab-pane>

      <!-- 修改密码 -->
      <el-tab-pane label="修改密码" name="security">
        <el-card shadow="never" class="form-card">
          <el-form
            :model="passwordForm"
            :rules="passwordRules"
            label-width="120px"
            ref="passwordFormRef"
            style="max-width: 560px"
          >
            <el-form-item label="当前密码" prop="old_password">
              <el-input v-model="passwordForm.old_password" type="password" show-password />
            </el-form-item>
            <el-form-item label="新密码" prop="new_password">
              <el-input v-model="passwordForm.new_password" type="password" show-password />
              <div class="hint">至少 8 位字符</div>
            </el-form-item>
            <el-form-item label="确认新密码" prop="new_password_confirmation">
              <el-input v-model="passwordForm.new_password_confirmation" type="password" show-password />
            </el-form-item>
            <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 12px">
              修改密码后其他设备将被强制登出
            </el-alert>
            <el-form-item>
              <el-button type="primary" :loading="passwordSubmitting" @click="handleChangePassword">
                修改密码
              </el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getProfile, updateProfile } from '@/api/profile'
import { changePassword } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()
const activeTab = ref(route.query.tab === 'security' ? 'security' : 'profile')

const profile = ref(null)
const formRef = ref()
const submitting = ref(false)

const form = reactive({
  customer_name: '',
  email: '',
  phone: '',
  company_name: '',
  company_id: '',
  address: '',
  auto_renew_default: false,
  sms_expiry_notify: false,
})

const rules = {
  customer_name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
}

async function fetchProfile() {
  try {
    const res = await getProfile()
    profile.value = res
    Object.assign(form, {
      customer_name: res.customer_name || '',
      email: res.email || '',
      phone: res.phone || '',
      company_name: res.company_name || '',
      company_id: res.company_id || '',
      address: res.address || '',
      auto_renew_default: !!res.auto_renew_default,
      sms_expiry_notify: !!res.sms_expiry_notify,
    })
  } catch { /* handled */ }
}

async function handleSave() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    const res = await updateProfile({
      customer_name: form.customer_name,
      email: form.email || undefined,
      phone: form.phone || undefined,
      company_name: form.company_name || undefined,
      company_id: form.company_id || undefined,
      address: form.address || undefined,
      auto_renew_default: form.auto_renew_default,
      sms_expiry_notify: form.sms_expiry_notify,
    })
    profile.value = res
    // 同步到 auth store
    if (authStore.customer) {
      authStore.customer.customer_name = form.customer_name
      authStore.customer.email = form.email
      authStore.customer.phone = form.phone
    }
    ElMessage.success('资料已更新')
  } catch { /* handled */ }
  finally { submitting.value = false }
}

// Change password
const passwordFormRef = ref()
const passwordSubmitting = ref(false)
const passwordForm = reactive({
  old_password: '',
  new_password: '',
  new_password_confirmation: '',
})
const passwordRules = {
  old_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '密码至少 8 位', trigger: 'blur' },
  ],
  new_password_confirmation: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_, value, cb) => {
        if (value !== passwordForm.new_password) cb(new Error('两次密码不一致'))
        else cb()
      },
      trigger: 'blur',
    },
  ],
}

async function handleChangePassword() {
  const valid = await passwordFormRef.value.validate().catch(() => false)
  if (!valid) return
  passwordSubmitting.value = true
  try {
    await changePassword(passwordForm)
    ElMessage.success('密码修改成功')
    passwordForm.old_password = ''
    passwordForm.new_password = ''
    passwordForm.new_password_confirmation = ''
  } catch { /* handled */ }
  finally { passwordSubmitting.value = false }
}

onMounted(fetchProfile)
</script>

<style lang="scss" scoped>
.profile-page { display: flex; flex-direction: column; gap: 16px; }
.page-title { margin: 0; font-size: 22px; font-weight: 700; color: #2C3E50; }
.profile-tabs :deep(.el-tabs__item) { font-size: 14px; font-weight: 500; }
.form-card {
  border-radius: 14px;
  border: 1px solid #EADFD2;
  :deep(.el-card__body) { padding: 32px 28px; }
}
.hint { font-size: 12px; color: #909399; margin-top: 2px; }

@media (max-width: 768px) {
  .page-title { font-size: 18px; }
  .form-card :deep(.el-card__body) { padding: 16px 12px; }
  .form-card :deep(.el-form) {
    max-width: 100% !important;
    .el-form-item__label { width: auto !important; text-align: left; padding-right: 8px; font-size: 13px; }
    .el-form-item__content { flex: 1; }
  }
}
</style>
