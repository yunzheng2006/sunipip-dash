<template>
  <div class="wizard-page">
    <div class="wizard-card">
      <el-steps :active="step" finish-status="success" align-center class="wizard-steps">
        <el-step title="设备检测" />
        <el-step title="WiFi 配置" />
        <el-step title="完成" />
      </el-steps>

      <div class="wizard-content">
        <!-- 步骤 1：检测设备 -->
        <div v-if="step === 0" class="step-content">
          <div v-if="detecting" class="detecting">
            <el-icon :size="48" class="rotating"><Loading /></el-icon>
            <p>正在检测设备信息...</p>
          </div>
          <div v-else-if="deviceInfo" class="device-detected">
            <el-icon :size="48" color="#67c23a"><CircleCheckFilled /></el-icon>
            <h3>设备已就绪</h3>
            <el-descriptions :column="1" border class="device-desc">
              <el-descriptions-item label="设备名称">{{ deviceInfo.hostname || '未知' }}</el-descriptions-item>
              <el-descriptions-item label="Agent 版本">{{ deviceInfo.agent_version || '未知' }}</el-descriptions-item>
              <el-descriptions-item label="运行时间">{{ deviceInfo.uptime || '未知' }}</el-descriptions-item>
            </el-descriptions>
          </div>
          <div v-else class="device-error">
            <el-icon :size="48" color="#f56c6c"><CircleCloseFilled /></el-icon>
            <h3>无法连接设备</h3>
            <p>{{ detectError }}</p>
            <el-button type="primary" @click="detectDevice">重新检测</el-button>
          </div>
        </div>

        <!-- 步骤 2：WiFi 配置 -->
        <div v-if="step === 1" class="step-content">
          <div v-if="hasWifiAccounts">
            <el-icon :size="48" color="#67c23a"><CircleCheckFilled /></el-icon>
            <h3>已有 WiFi 账号配置</h3>
            <p>检测到设备已配置 {{ wifiAccounts.length }} 个 WiFi 账号，可跳过此步骤。</p>
          </div>
          <div v-else>
            <h3>创建首个 WiFi 账号</h3>
            <p class="step-tip">设备尚无 WiFi 账号，请创建第一个账号以启用网络。</p>
            <el-form
              ref="wifiFormRef"
              :model="wifiForm"
              :rules="wifiRules"
              label-width="100px"
              class="wifi-form"
            >
              <el-form-item label="用户名" prop="username">
                <el-input v-model="wifiForm.username" placeholder="WiFi 账号用户名" />
              </el-form-item>
              <el-form-item label="密码" prop="password">
                <el-input v-model="wifiForm.password" placeholder="WiFi 账号密码" />
              </el-form-item>
              <el-form-item label="备注" prop="label">
                <el-input v-model="wifiForm.label" placeholder="如：主人、客人" />
              </el-form-item>
              <el-form-item label="代理模式" prop="proxy_mode">
                <el-select v-model="wifiForm.proxy_mode" class="full-width">
                  <el-option label="代理模式" value="proxy" />
                  <el-option label="直连模式" value="direct" />
                </el-select>
              </el-form-item>
            </el-form>
          </div>
        </div>

        <!-- 步骤 3：完成 -->
        <div v-if="step === 2" class="step-content">
          <el-icon :size="64" color="#67c23a"><CircleCheckFilled /></el-icon>
          <h3>设置完成！</h3>
          <p>设备已准备就绪，您可以开始管理路由器了。</p>
        </div>
      </div>

      <!-- 底部按钮 -->
      <div class="wizard-footer">
        <el-button v-if="step > 0 && step < 2" @click="step--">上一步</el-button>
        <el-button
          v-if="step < 2"
          type="primary"
          :loading="submitting"
          @click="handleNext"
        >
          {{ step === 1 && !hasWifiAccounts ? '创建并继续' : '下一步' }}
        </el-button>
        <el-button v-if="step === 2" type="primary" @click="goToDashboard">
          进入仪表盘
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Loading, CircleCheckFilled, CircleCloseFilled } from '@element-plus/icons-vue'
import { getStatus } from '../../api/local'
import { getMyDevices, getWifiAccounts, createWifiAccount } from '../../api/platform'

const router = useRouter()

const step = ref(0)
const detecting = ref(true)
const deviceInfo = ref(null)
const detectError = ref('')
const wifiAccounts = ref([])
const hasWifiAccounts = ref(false)
const submitting = ref(false)
const currentDeviceId = ref(null)
const wifiFormRef = ref(null)

const wifiForm = reactive({
  username: '',
  password: '',
  label: '',
  proxy_mode: 'proxy'
})

const wifiRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' }
  ]
}

async function detectDevice() {
  detecting.value = true
  detectError.value = ''
  deviceInfo.value = null
  try {
    const { data } = await getStatus()
    deviceInfo.value = data.data || data
  } catch (err) {
    detectError.value = err.response?.data?.message || '无法连接本地 Agent，请检查网络连接'
  } finally {
    detecting.value = false
  }
}

async function loadDeviceAndWifi() {
  try {
    const { data } = await getMyDevices()
    const devices = data.data || []
    if (devices.length > 0) {
      currentDeviceId.value = devices[0].id
      const wifiRes = await getWifiAccounts(devices[0].id)
      wifiAccounts.value = wifiRes.data?.data || []
      hasWifiAccounts.value = wifiAccounts.value.length > 0
    }
  } catch {
    // 无法获取设备信息时继续流程
  }
}

async function handleNext() {
  if (step.value === 0) {
    if (!deviceInfo.value) {
      ElMessage.warning('请等待设备检测完成')
      return
    }
    await loadDeviceAndWifi()
    // 如果已有 WiFi 账号，跳到完成
    if (hasWifiAccounts.value) {
      step.value = 2
      return
    }
    step.value = 1
  } else if (step.value === 1) {
    if (hasWifiAccounts.value) {
      step.value = 2
      return
    }
    // 创建 WiFi 账号
    const valid = await wifiFormRef.value?.validate().catch(() => false)
    if (!valid) return

    submitting.value = true
    try {
      await createWifiAccount(currentDeviceId.value, { ...wifiForm })
      ElMessage.success('WiFi 账号创建成功')
      step.value = 2
    } catch (err) {
      ElMessage.error(err.response?.data?.message || '创建失败，请重试')
    } finally {
      submitting.value = false
    }
  }
}

function goToDashboard() {
  router.push('/dashboard')
}

onMounted(() => {
  detectDevice()
})
</script>

<style scoped>
.wizard-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f7fa;
  padding: 20px;
}

.wizard-card {
  width: 100%;
  max-width: 640px;
  background: #fff;
  border-radius: 12px;
  padding: 40px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}

.wizard-steps {
  margin-bottom: 40px;
}

.step-content {
  text-align: center;
  min-height: 240px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
}

.step-content h3 {
  font-size: 20px;
  color: #303133;
  margin: 4px 0;
}

.step-content p {
  color: #909399;
  font-size: 14px;
}

.step-tip {
  margin-bottom: 16px;
}

.detecting {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  color: #909399;
}

.rotating {
  animation: spin 1.5s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.device-desc {
  width: 100%;
  max-width: 400px;
  margin-top: 16px;
}

.wifi-form {
  width: 100%;
  max-width: 420px;
  text-align: left;
  margin-top: 12px;
}

.full-width {
  width: 100%;
}

.wizard-footer {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 32px;
  padding-top: 20px;
  border-top: 1px solid #ebeef5;
}

@media (max-width: 767px) {
  .wizard-card {
    padding: 24px 16px;
  }
}
</style>
