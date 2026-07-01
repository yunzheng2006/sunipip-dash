<template>
  <el-dialog v-model="visible" title="升级为企业认证" width="520px" :close-on-click-modal="false" class="upgrade-dialog" @close="stopPolling">
    <!-- 成功状态 -->
    <div v-if="verifySuccess" class="success-state">
      <div class="success-icon">
        <el-icon :size="64" color="#67C23A"><CircleCheckFilled /></el-icon>
      </div>
      <h3 class="success-title">企业认证成功</h3>
      <p class="success-desc">已成功升级为企业认证</p>
      <el-button type="primary" @click="handleSuccessClose" style="margin-top: 16px">完成</el-button>
    </div>

    <template v-else>
      <!-- Step 指示器 -->
      <el-steps :active="currentStep - 1" align-center style="margin-bottom: 20px" :space="120">
        <el-step title="上传执照" />
        <el-step title="企业核验" />
        <el-step title="法人扫脸" />
      </el-steps>

      <!-- Step 1: 上传营业执照 -->
      <template v-if="currentStep === 1">
        <div class="upload-section">
          <p class="upload-hint">请上传清晰的营业执照照片，系统将自动识别企业信息</p>
          <el-upload
            :auto-upload="false"
            :limit="1"
            accept="image/*"
            :on-change="handleLicenseUpload"
            :on-remove="handleLicenseRemove"
            list-type="picture-card"
            :file-list="licenseFileList"
          >
            <el-icon><Plus /></el-icon>
            <template #tip>
              <div class="el-upload__tip">支持 JPG/PNG，大小不超过 4MB</div>
            </template>
          </el-upload>
        </div>
        <div class="step-footer">
          <el-button @click="visible = false">取消</el-button>
          <el-button type="primary" :loading="ocrLoading" :disabled="!licenseImage" @click="doOcr">识别营业执照</el-button>
        </div>
      </template>

      <!-- Step 2: 确认企业信息 + 法人身份证 -->
      <template v-else-if="currentStep === 2">
        <el-form :model="form" ref="formRef" label-width="110px" size="large">
          <el-form-item label="企业名称" prop="enterprise_name" :rules="[{required:true,message:'必填'}]">
            <el-input v-model="form.enterprise_name" />
          </el-form-item>
          <el-form-item label="信用代码" prop="credit_code" :rules="[{required:true,message:'必填'}]">
            <el-input v-model="form.credit_code" maxlength="18" />
          </el-form-item>
          <el-form-item label="法人姓名" prop="legal_person_name" :rules="[{required:true,message:'必填'}]">
            <el-input v-model="form.legal_person_name" />
          </el-form-item>
          <el-form-item label="法人身份证" prop="legal_person_id" :rules="[{required:true,message:'必填'},{len:18,message:'身份证号为18位'}]">
            <el-input v-model="form.legal_person_id" placeholder="法人18位身份证号" maxlength="18" />
          </el-form-item>
        </el-form>
        <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 12px">
          提交后将进行企业信息权威核验，通过后需法人完成人脸验证
        </el-alert>
        <div class="step-footer">
          <el-button @click="currentStep = 1">重新上传</el-button>
          <el-button type="primary" :loading="submitting" @click="submitVerify">提交核验</el-button>
        </div>
      </template>

      <!-- Step 3: 法人扫脸 -->
      <template v-else-if="currentStep === 3">
        <el-alert type="success" :closable="false" show-icon style="margin-bottom: 16px">
          企业信息核验通过！请法人代表完成人脸验证。
        </el-alert>
        <div class="face-verify-guide">
          <div class="guide-icon">
            <el-icon :size="48" color="#67C23A"><Iphone /></el-icon>
          </div>
          <p class="guide-text">请法人代表使用微信扫描二维码完成人脸验证</p>
          <div class="qr-area">
            <img v-if="faceQrUrl" :src="faceQrUrl" class="qr-img" />
            <div class="qr-actions">
              <el-link type="primary" :href="faceUrl" target="_blank" :underline="false">
                <el-icon><Link /></el-icon> 在微信中打开验证
              </el-link>
              <el-button size="small" @click="copyUrl(faceUrl)" style="margin-top: 8px">复制链接</el-button>
            </div>
          </div>
          <div class="polling-status">
            <el-icon class="polling-spin"><Loading /></el-icon>
            <span>等待法人验证完成...</span>
          </div>
          <div class="guide-buttons">
            <el-button @click="currentStep = 2; stopPolling()">返回上一步</el-button>
          </div>
        </div>
      </template>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, reactive, computed, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Iphone, Link, Loading, CircleCheckFilled } from '@element-plus/icons-vue'
import QRCode from 'qrcode'
import {
  upgradeEnterpriseOcr,
  upgradeEnterpriseVerify,
  upgradeEnterprisePoll,
} from '@/api/verification'

const props = defineProps({ modelValue: Boolean })
const emit = defineEmits(['update:modelValue', 'verified'])

const visible = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const currentStep = ref(1)
const submitting = ref(false)
const ocrLoading = ref(false)
const verifySuccess = ref(false)

let pollTimer = null

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onBeforeUnmount(() => stopPolling())

function handleSuccessClose() {
  visible.value = false
  emit('verified', 'enterprise')
  verifySuccess.value = false
  currentStep.value = 1
}

// ===== Step 1: Upload license =====
const licenseFileList = ref([])
const licenseImage = ref('')

function handleLicenseUpload(file) {
  const rawFile = file.raw || file
  if (rawFile.size > 4 * 1024 * 1024) {
    ElMessage.warning('文件大小不能超过 4MB')
    licenseFileList.value = []
    return
  }
  const reader = new FileReader()
  reader.onload = (e) => {
    licenseImage.value = e.target.result.split(',')[1]
  }
  reader.readAsDataURL(rawFile)
  licenseFileList.value = [file]
}

function handleLicenseRemove() {
  licenseImage.value = ''
  licenseFileList.value = []
}

async function doOcr() {
  if (!licenseImage.value) {
    ElMessage.warning('请先上传营业执照照片')
    return
  }
  ocrLoading.value = true
  try {
    const res = await upgradeEnterpriseOcr({ image: licenseImage.value })
    const data = res?.data || res
    form.enterprise_name = data.name || ''
    form.credit_code = data.credit_code || ''
    form.legal_person_name = data.legal_person || ''
    form.legal_person_id = ''
    currentStep.value = 2
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '营业执照识别失败，请重试')
  } finally {
    ocrLoading.value = false
  }
}

// ===== Step 2: Verify enterprise =====
const formRef = ref()
const form = reactive({
  enterprise_name: '',
  credit_code: '',
  legal_person_name: '',
  legal_person_id: '',
})

const faceUrl = ref('')
const faceBizToken = ref('')
const faceQrUrl = ref('')

async function submitVerify() {
  if (!(await formRef.value.validate().catch(() => false))) return
  submitting.value = true
  try {
    const res = await upgradeEnterpriseVerify({
      ...form,
      license_image: licenseImage.value,
    })
    const data = res?.data || res

    faceUrl.value = data.url || ''
    faceBizToken.value = data.biz_token || ''
    if (faceUrl.value) {
      faceQrUrl.value = await QRCode.toDataURL(faceUrl.value, { width: 200, margin: 2 })
    }
    ElMessage.success(data.message || '企业信息核验通过')
    currentStep.value = 3
    startPolling()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '企业核验失败')
  } finally {
    submitting.value = false
  }
}

// ===== Step 3: Poll face result =====
function startPolling() {
  stopPolling()
  pollTimer = setInterval(async () => {
    if (!faceBizToken.value) return
    try {
      const res = await upgradeEnterprisePoll({
        biz_token: faceBizToken.value,
        enterprise_name: form.enterprise_name,
        credit_code: form.credit_code,
        legal_person_name: form.legal_person_name,
      })
      const data = res?.data || res
      if (data.status === 'success') {
        stopPolling()
        verifySuccess.value = true
      } else if (data.status === 'failed') {
        stopPolling()
        ElMessage.error(data.message || '法人验证未通过，请重试')
        currentStep.value = 2
      }
    } catch (_) {}
  }, 3000)
}

function copyUrl(url) {
  if (!url) return
  navigator.clipboard.writeText(url).then(() => {
    ElMessage.success('链接已复制')
  }).catch(() => {
    ElMessage.warning('复制失败，请手动复制')
  })
}
</script>

<style scoped>
.success-state {
  text-align: center;
  padding: 40px 0;
}
.success-icon {
  animation: successBounce 0.6s ease;
}
.success-title {
  margin-top: 16px;
  font-size: 20px;
  color: #303133;
}
.success-desc {
  margin-top: 8px;
  color: #909399;
  font-size: 14px;
}
@keyframes successBounce {
  0% { transform: scale(0); opacity: 0; }
  50% { transform: scale(1.2); }
  100% { transform: scale(1); opacity: 1; }
}

.upload-section {
  text-align: center;
}
.upload-hint {
  color: #606266;
  font-size: 14px;
  margin-bottom: 16px;
}
.upload-section :deep(.el-upload-list--picture-card .el-upload-list__item),
.upload-section :deep(.el-upload--picture-card) {
  width: 180px;
  height: 180px;
}

.step-footer {
  text-align: right;
  margin-top: 16px;
}

.face-verify-guide {
  text-align: center;
  padding: 16px 0;
}
.guide-icon {
  margin-bottom: 12px;
}
.guide-text {
  color: #606266;
  font-size: 14px;
  margin-bottom: 20px;
}
.qr-area {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;
}
.qr-img {
  width: 200px;
  height: 200px;
  border: 1px solid #EBEEF5;
  border-radius: 8px;
  padding: 8px;
}
.qr-actions {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.polling-status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-bottom: 20px;
  color: #909399;
  font-size: 14px;
}
.polling-spin {
  animation: spin 1.5s linear infinite;
}
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.guide-buttons {
  display: flex;
  justify-content: center;
  gap: 12px;
}
</style>
