<template>
  <div class="sms-notify-toggle">
    <el-switch v-model="enabled" size="small" :loading="saving" @change="onToggle" />
    <span class="sms-notify-label" @click="enabled = !enabled; onToggle(enabled)">短信到期提醒</span>
  </div>
</template>

<script setup>
import { ref, watchEffect } from 'vue'
import { ElMessage } from 'element-plus'
import { updateProfile } from '@/api/profile'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const enabled = ref(false)
const saving = ref(false)

watchEffect(() => {
  enabled.value = !!authStore.customer?.sms_expiry_notify
})

async function onToggle(val) {
  saving.value = true
  try {
    await updateProfile({ sms_expiry_notify: val })
    if (authStore.customer) authStore.customer.sms_expiry_notify = val
    ElMessage.success(val ? '已开启短信到期提醒' : '已关闭短信到期提醒')
  } catch {
    enabled.value = !val
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.sms-notify-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.sms-notify-label {
  font-size: 13px;
  color: #475569;
  cursor: pointer;
  user-select: none;
}
</style>
