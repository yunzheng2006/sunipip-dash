<template>
  <div class="reg-settings-page">
    <h2 class="page-title">注册与客户功能设置</h2>
    <el-card v-loading="loading" style="max-width: 600px">
      <el-form :model="form" label-width="160px">
        <el-divider content-position="left">注册设置</el-divider>
        <el-form-item label="开放注册">
          <el-switch v-model="form['registration.enabled']" />
          <span class="hint">关闭后客户面板不可注册新账号</span>
        </el-form-item>
        <el-form-item label="强制手机号验证">
          <el-switch v-model="form['registration.require_phone']" />
          <span class="hint">注册时必须通过短信验证码验证手机号</span>
        </el-form-item>
        <el-form-item label="必须填写邀请码">
          <el-switch v-model="form['registration.require_invite']" />
          <span class="hint">注册时必须输入业务员邀请码</span>
        </el-form-item>
        <el-form-item label="邀请注册自动开通中转">
          <el-switch v-model="form['registration.invite_auto_forward']" />
          <span class="hint">开启后通过邀请链接/推荐码注册的客户自动获得中转权限，无需审批</span>
        </el-form-item>
        <el-form-item label="新用户默认余额">
          <el-input-number v-model="form['registration.default_balance']" :min="0" :step="10" />
          <span class="hint" style="margin-left:8px">注册后赠送的初始余额(CNY)</span>
        </el-form-item>
        <el-divider content-position="left">客户自助功能</el-divider>
        <el-form-item label="客户自助退款">
          <el-switch v-model="form['customer.self_refund_enabled']" />
          <span class="hint">开启后客户可在 12 小时内自助退款，关闭则需联系客服</span>
        </el-form-item>
      </el-form>
      <div style="text-align:right;margin-top:16px">
        <el-button type="primary" :loading="saving" @click="save">保存设置</el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getRegistrationSettings, updateRegistrationSettings } from '@/api/smsProviders'

const loading = ref(false)
const saving = ref(false)
const form = reactive({
  'registration.enabled': true,
  'registration.require_phone': false,
  'registration.require_invite': false,
  'registration.invite_auto_forward': true,
  'registration.default_balance': 0,
  'customer.self_refund_enabled': false,
})

async function fetch() {
  loading.value = true
  try {
    const res = await getRegistrationSettings()
    if (res) Object.assign(form, res)
  } catch {} finally { loading.value = false }
}

async function save() {
  saving.value = true
  try {
    await updateRegistrationSettings({ ...form })
    ElMessage.success('设置已保存')
  } catch {} finally { saving.value = false }
}

onMounted(fetch)
</script>

<style scoped>
.reg-settings-page { .page-title { margin: 0 0 20px; font-size: 20px; font-weight: 600; color: #2C3E50; } }
.hint { font-size: 12px; color: #909399; margin-left: 8px; }
</style>
