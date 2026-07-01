<template>
  <div class="activate-device">
    <el-card class="section-card" shadow="never">
      <div class="section-title" style="margin-bottom: 24px">激活软路由设备</div>

      <el-steps :active="step" align-center style="margin-bottom: 32px">
        <el-step title="输入序列号" />
        <el-step title="选择模块" />
        <el-step title="完成" />
      </el-steps>

      <!-- Step 1: 输入序列号 -->
      <div v-if="step === 0" class="step-content">
        <p class="step-desc">请输入设备背面标签上的序列号</p>
        <el-input v-model="form.serial_number" placeholder="设备序列号" size="large"
          style="max-width: 400px; margin: 0 auto; display: block" @keyup.enter="nextStep" />
        <div class="step-actions">
          <el-button type="primary" :disabled="!form.serial_number" @click="nextStep">下一步</el-button>
        </div>
      </div>

      <!-- Step 2: 选择模块 -->
      <div v-if="step === 1" class="step-content">
        <p class="step-desc">选择此设备的代理模块类型</p>
        <div class="module-options">
          <div v-for="m in modules" :key="m.value" class="module-card"
            :class="{ selected: form.module === m.value }" @click="form.module = m.value">
            <div class="module-name">{{ m.label }}</div>
            <div class="module-desc">{{ m.desc }}</div>
          </div>
        </div>
        <div class="step-actions">
          <el-button @click="step = 0">上一步</el-button>
          <el-button type="primary" :disabled="!form.module" @click="handleActivate" :loading="submitting">
            确认激活
          </el-button>
        </div>
      </div>

      <!-- Step 3: 完成 -->
      <div v-if="step === 2" class="step-content">
        <el-result icon="success" :title="`设备 ${activatedDeviceNo || ''} 激活成功`" sub-title="您现在可以管理 WiFi 账号和代理节点">
          <template #extra>
            <el-button type="primary" @click="$router.push(`/router/${activatedDeviceId}`)">管理设备</el-button>
            <el-button @click="$router.push('/router')">返回列表</el-button>
          </template>
        </el-result>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { activateDevice } from '@/api/router'

const step = ref(0)
const submitting = ref(false)
const activatedDeviceId = ref(null)
const activatedDeviceNo = ref(null)

const form = reactive({ serial_number: '', module: '' })

const modules = [
  { value: 'video', label: '视频专线', desc: '适用于视频平台直播、短视频等场景' },
  { value: 'live_mobile', label: '直播专线(手机)', desc: '适用于手机直播推流场景' },
  { value: 'live_pc', label: '直播专线(电脑)', desc: '适用于电脑 OBS 推流场景' },
]

function nextStep() {
  if (!form.serial_number.trim()) {
    ElMessage.warning('请输入序列号')
    return
  }
  step.value = 1
}

async function handleActivate() {
  submitting.value = true
  try {
    const res = await activateDevice({
      serial_number: form.serial_number.trim(),
      module: form.module,
    })
    activatedDeviceId.value = res?.id
    activatedDeviceNo.value = res?.device_no
    step.value = 2
    ElMessage.success('设备激活成功')
  } catch { /* handled */ }
  finally { submitting.value = false }
}
</script>

<style scoped>
.section-title { font-size: 16px; font-weight: 600; color: #1e293b; }
.step-content { max-width: 500px; margin: 0 auto; text-align: center; }
.step-desc { color: #64748b; margin-bottom: 20px; }
.step-actions { margin-top: 24px; display: flex; justify-content: center; gap: 12px; }
.module-options { display: flex; flex-direction: column; gap: 12px; }
.module-card {
  padding: 16px; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer;
  text-align: left; transition: all 0.15s;
}
.module-card:hover { border-color: #6366f1; }
.module-card.selected { border-color: #6366f1; background: #eef2ff; }
.module-name { font-size: 15px; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
.module-desc { font-size: 13px; color: #94a3b8; }
</style>
