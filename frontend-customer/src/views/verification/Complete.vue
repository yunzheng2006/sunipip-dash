<template>
  <div class="verify-complete">
    <div class="card">
      <div class="icon">
        <svg viewBox="0 0 48 48" width="64" height="64" fill="none">
          <circle cx="24" cy="24" r="22" stroke="#67C23A" stroke-width="3"/>
          <path d="M14 24l7 7 13-13" stroke="#67C23A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <h2>人脸验证已完成</h2>
      <p>请返回电脑端点击「我已完成验证」确认结果</p>
      <p class="sub">如果您在手机上操作，页面将自动返回</p>
      <div class="countdown" v-if="seconds > 0">{{ seconds }}s 后自动跳转...</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { isLoggedIn } from '@/utils/auth'

const router = useRouter()
const seconds = ref(3)

onMounted(() => {
  const timer = setInterval(() => {
    seconds.value--
    if (seconds.value <= 0) {
      clearInterval(timer)
      if (isLoggedIn()) {
        router.push('/dashboard')
      } else {
        window.location.href = 'https://user.sunipip.com'
      }
    }
  }, 1000)
})
</script>

<style scoped>
.verify-complete {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f7fa;
  padding: 20px;
}

.card {
  background: #fff;
  border-radius: 12px;
  padding: 48px 32px;
  text-align: center;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
  max-width: 400px;
  width: 100%;
}

.icon {
  margin-bottom: 20px;
}

h2 {
  font-size: 20px;
  color: #303133;
  margin: 0 0 12px;
}

p {
  color: #606266;
  font-size: 14px;
  margin: 0 0 8px;
}

.sub {
  color: #909399;
  font-size: 13px;
}

.countdown {
  margin-top: 20px;
  color: #909399;
  font-size: 13px;
}
</style>
