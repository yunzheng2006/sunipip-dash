<template>
  <el-dialog v-model="visible" title="实名认证" width="520px" :close-on-click-modal="false" class="verify-dialog" @close="stopPolling">
    <!-- 认证成功状态 -->
    <div v-if="verifySuccess" class="success-state">
      <div class="success-icon">
        <el-icon :size="64" color="#67C23A"><CircleCheckFilled /></el-icon>
      </div>
      <h3 class="success-title">{{ successType === 'enterprise' ? '企业认证成功' : '个人认证成功' }}</h3>
      <p class="success-desc">实名认证已完成，您现在可以正常下单使用</p>
      <el-button type="primary" @click="handleSuccessClose" style="margin-top: 16px">完成</el-button>
    </div>

    <!-- 认证表单 -->
    <template v-else>
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        根据相关法规要求，下单前需完成实名认证。您的信息仅用于身份核验，将严格保密。
      </el-alert>

      <el-tabs v-model="activeTab">
        <!-- ========== 个人认证 ========== -->
        <el-tab-pane label="个人认证" name="personal">
          <template v-if="personalStep === 1">
            <!-- 有未完成的验证 -->
            <el-alert v-if="pendingInfo" type="warning" :closable="false" show-icon style="margin-bottom: 16px">
              <template #title>您有一次未完成的验证（{{ pendingInfo.pending_name }}），可以继续完成或重新发起。</template>
            </el-alert>
            <el-form :model="personalForm" ref="personalFormRef" label-width="100px" size="large">
              <el-form-item label="真实姓名" prop="real_name" :rules="[{ required: true, message: '请输入真实姓名' }]">
                <el-input v-model="personalForm.real_name" placeholder="请输入身份证上的姓名" />
              </el-form-item>
              <el-form-item label="身份证号" prop="id_number" :rules="[{ required: true, message: '请输入身份证号' }, { len: 18, message: '身份证号为18位' }]">
                <el-input v-model="personalForm.id_number" placeholder="18位身份证号码" maxlength="18" />
              </el-form-item>
            </el-form>
            <div style="text-align: right; display: flex; justify-content: flex-end; gap: 8px;">
              <el-button @click="visible = false">稍后认证</el-button>
              <el-button v-if="pendingInfo" type="warning" :loading="resuming" @click="handleResume">继续上次验证</el-button>
              <el-button type="primary" :loading="submitting" @click="initFace">开始人脸核身</el-button>
            </div>
          </template>

          <template v-else-if="personalStep === 2">
            <div class="face-verify-guide">
              <!-- 桌面端：显示二维码 -->
              <template v-if="!isMobile">
                <div class="guide-icon">
                  <el-icon :size="48" color="#409EFF"><Iphone /></el-icon>
                </div>
                <p class="guide-text">请使用手机<b>微信</b>扫描下方二维码完成人脸验证</p>
                <el-alert type="warning" :closable="false" style="margin-bottom:16px;text-align:left">
                  <template #title>操作指引</template>
                  <ol style="margin:4px 0 0;padding-left:18px;line-height:2">
                    <li>打开手机<b>微信</b> → 右上角「+」→「扫一扫」</li>
                    <li>扫描下方二维码，如提示"非微信官方网页"请点击「继续访问」</li>
                    <li>按指引完成人脸识别，完成后本页面将自动更新</li>
                  </ol>
                </el-alert>
                <div class="qr-area">
                  <img v-if="faceQrUrl" :src="faceQrUrl" class="qr-img" />
                  <div class="qr-actions">
                    <el-button size="small" @click="copyUrl(faceUrl)">复制链接</el-button>
                  </div>
                </div>
              </template>
              <!-- 手机端：二维码优先 + 直接跳转备选 -->
              <template v-else>
                <div class="guide-icon">
                  <el-icon :size="48" color="#409EFF"><Iphone /></el-icon>
                </div>
                <el-alert type="warning" :closable="false" style="margin-bottom:16px;text-align:left">
                  <template #title>请在微信中完成验证</template>
                  <div style="margin-top:4px;line-height:1.8;font-size:13px">
                    <b>推荐方式：</b>长按下方二维码保存截图，打开<b>微信</b>扫一扫 → 从相册选取识别<br>
                    <b>直接跳转：</b>如页面提示"非微信官方网页"，请点击「继续访问」即可
                  </div>
                </el-alert>
                <div class="mobile-face-actions">
                  <div class="mobile-qr-area" style="margin-bottom:16px">
                    <img v-if="faceQrUrl" :src="faceQrUrl" class="qr-img" />
                    <p class="mobile-qr-hint">长按保存二维码 → 微信扫一扫 → 相册识别</p>
                  </div>
                  <el-button type="primary" size="large" @click="openFaceUrl" style="width:100%">
                    <el-icon><Link /></el-icon> 直接跳转微信验证
                  </el-button>
                  <p style="color:#909399;font-size:12px;margin-top:8px">验证完成后请返回本页面</p>
                </div>
              </template>
              <div class="polling-status">
                <template v-if="pollCount < maxPollCount">
                  <el-icon class="polling-spin"><Loading /></el-icon>
                  <span>等待验证完成...</span>
                </template>
                <template v-else>
                  <span style="color:#E6A23C">自动检测已停止</span>
                </template>
              </div>
              <div class="guide-buttons">
                <el-button @click="personalStep = 1; stopPolling()">返回重新填写</el-button>
                <el-button type="success" :loading="confirming" @click="manualConfirm">我已完成验证</el-button>
              </div>
            </div>
          </template>
        </el-tab-pane>

        <!-- ========== 企业认证 ========== -->
        <el-tab-pane label="企业认证" name="enterprise">
          <!-- Step 1: 上传营业执照 -->
          <template v-if="enterpriseStep === 1">
            <div class="upload-section">
              <p class="upload-hint">请上传清晰的营业执照照片，系统将自动识别信息</p>
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
            <div style="text-align: right; margin-top: 16px">
              <el-button @click="visible = false">稍后认证</el-button>
              <el-button type="primary" :loading="ocrLoading" :disabled="!licenseImage" @click="doOcr">识别营业执照</el-button>
            </div>
          </template>

          <!-- Step 2: 确认OCR信息 + 输入法人身份证 -->
          <template v-else-if="enterpriseStep === 2">
            <el-form :model="enterpriseForm" ref="enterpriseFormRef" label-width="110px" size="large">
              <el-form-item label="企业名称" prop="enterprise_name" :rules="[{required:true,message:'必填'}]">
                <el-input v-model="enterpriseForm.enterprise_name" />
              </el-form-item>
              <el-form-item label="信用代码" prop="credit_code" :rules="[{required:true,message:'必填'}]">
                <el-input v-model="enterpriseForm.credit_code" maxlength="18" />
              </el-form-item>
              <el-form-item label="法人姓名" prop="legal_person_name" :rules="[{required:true,message:'必填'}]">
                <el-input v-model="enterpriseForm.legal_person_name" />
              </el-form-item>
              <el-form-item label="法人身份证" prop="legal_person_id" :rules="[{required:true,message:'必填'},{len:18,message:'身份证号为18位'}]">
                <el-input v-model="enterpriseForm.legal_person_id" placeholder="法人18位身份证号" maxlength="18" />
              </el-form-item>
            </el-form>
            <el-alert type="warning" :closable="false" show-icon style="margin-bottom:12px">
              提交后将进行企业信息权威核验，通过后需法人进行人脸验证
            </el-alert>
            <div style="text-align: right">
              <el-button @click="enterpriseStep = 1">重新上传</el-button>
              <el-button type="primary" :loading="submitting" @click="submitEnterpriseVerify">提交核验</el-button>
            </div>
          </template>

          <!-- Step 3: 法人扫脸（自动轮询） -->
          <template v-else-if="enterpriseStep === 3">
            <el-alert type="success" :closable="false" show-icon style="margin-bottom: 16px">
              企业信息核验通过！请法人代表完成人脸验证以完成认证。
            </el-alert>
            <div class="face-verify-guide">
              <template v-if="!isMobile">
                <div class="guide-icon">
                  <el-icon :size="48" color="#67C23A"><Iphone /></el-icon>
                </div>
                <p class="guide-text">请法人代表使用手机<b>微信</b>扫描二维码完成人脸验证</p>
                <el-alert type="warning" :closable="false" style="margin-bottom:16px;text-align:left">
                  <template #title>操作指引</template>
                  <ol style="margin:4px 0 0;padding-left:18px;line-height:2">
                    <li>打开手机<b>微信</b> → 右上角「+」→「扫一扫」</li>
                    <li>扫描下方二维码，如提示"非微信官方网页"请点击「继续访问」</li>
                    <li>按指引完成人脸识别，完成后本页面将自动更新</li>
                  </ol>
                </el-alert>
                <div class="qr-area">
                  <img v-if="enterpriseFaceQrUrl" :src="enterpriseFaceQrUrl" class="qr-img" />
                  <div class="qr-actions">
                    <el-button size="small" @click="copyUrl(enterpriseFaceUrl)">复制链接</el-button>
                  </div>
                </div>
              </template>
              <template v-else>
                <div class="guide-icon">
                  <el-icon :size="48" color="#67C23A"><Iphone /></el-icon>
                </div>
                <el-alert type="warning" :closable="false" style="margin-bottom:16px;text-align:left">
                  <template #title>请在微信中完成验证</template>
                  <div style="margin-top:4px;line-height:1.8;font-size:13px">
                    <b>推荐方式：</b>长按下方二维码保存截图，打开<b>微信</b>扫一扫 → 从相册选取识别<br>
                    <b>直接跳转：</b>如页面提示"非微信官方网页"，请点击「继续访问」即可
                  </div>
                </el-alert>
                <div class="mobile-face-actions">
                  <div class="mobile-qr-area" style="margin-bottom:16px">
                    <img v-if="enterpriseFaceQrUrl" :src="enterpriseFaceQrUrl" class="qr-img" />
                    <p class="mobile-qr-hint">长按保存二维码 → 微信扫一扫 → 相册识别</p>
                  </div>
                  <el-button type="primary" size="large" @click="openEnterpriseFaceUrl" style="width:100%">
                    <el-icon><Link /></el-icon> 直接跳转微信验证
                  </el-button>
                  <p style="color:#909399;font-size:12px;margin-top:8px">验证完成后请返回本页面</p>
                </div>
              </template>
              <div class="polling-status">
                <template v-if="pollCount < maxPollCount">
                  <el-icon class="polling-spin"><Loading /></el-icon>
                  <span>等待法人验证完成...</span>
                </template>
                <template v-else>
                  <span style="color:#E6A23C">自动检测已停止</span>
                </template>
              </div>
              <div class="guide-buttons">
                <el-button @click="enterpriseStep = 2; stopPolling()">返回上一步</el-button>
                <el-button type="success" :loading="confirming" @click="manualConfirm">我已完成验证</el-button>
              </div>
            </div>
          </template>
        </el-tab-pane>
      </el-tabs>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Iphone, Link, Loading, CircleCheckFilled } from '@element-plus/icons-vue'
import QRCode from 'qrcode'
import {
  initFaceVerification,
  confirmFaceVerification,
  pollPersonalVerification,
  resumePersonalVerification,
  ocrBusinessLicense,
  verifyEnterprise as verifyEnterpriseApi,
  pollEnterpriseVerification,
  confirmEnterprise,
} from '@/api/verification'

const props = defineProps({
  modelValue: Boolean,
  pending: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'verified'])

const visible = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const activeTab = ref('personal')
const submitting = ref(false)
const resuming = ref(false)
const ocrLoading = ref(false)
const verifySuccess = ref(false)
const successType = ref('')

const confirming = ref(false)
const pollCount = ref(0)
const maxPollCount = 60
const isMobile = ref(/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent))

const pendingInfo = computed(() => props.pending?.has_pending ? props.pending : null)

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
  emit('verified', successType.value)
  verifySuccess.value = false
}

// ===== Personal verification =====
const personalStep = ref(1)
const personalFormRef = ref()
const personalForm = reactive({ real_name: '', id_number: '' })
const faceUrl = ref('')
const faceBizToken = ref('')
const faceQrUrl = ref('')

async function initFace() {
  if (!(await personalFormRef.value.validate().catch(() => false))) return
  submitting.value = true
  try {
    const res = await initFaceVerification({
      real_name: personalForm.real_name,
      id_number: personalForm.id_number,
    })
    const data = res?.data || res
    if (data.already_verified) {
      successType.value = 'personal'
      verifySuccess.value = true
      return
    }
    faceUrl.value = data.url || ''
    faceBizToken.value = data.biz_token || ''
    if (faceUrl.value) {
      faceQrUrl.value = await QRCode.toDataURL(faceUrl.value, { width: 200, margin: 2 })
    }
    personalStep.value = 2
    startPersonalPolling()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || e?.message || '发起验证失败')
  } finally {
    submitting.value = false
  }
}

function startPersonalPolling() {
  stopPolling()
  pollCount.value = 0
  pollTimer = setInterval(async () => {
    if (!faceBizToken.value) return
    pollCount.value++
    if (pollCount.value >= maxPollCount) {
      stopPolling()
      return
    }
    try {
      const res = await pollPersonalVerification({ biz_token: faceBizToken.value })
      const data = res?.data || res
      if (data.status === 'success') {
        stopPolling()
        successType.value = 'personal'
        verifySuccess.value = true
      } else if (data.status === 'failed') {
        stopPolling()
        ElMessage.error(data.message || '验证未通过，请重试')
        personalStep.value = 1
      }
    } catch (_) {}
  }, 3000)
}

async function handleResume() {
  resuming.value = true
  try {
    const res = await resumePersonalVerification()
    const data = res?.data || res
    if (data.status === 'success' || data.status === 'already_verified') {
      successType.value = 'personal'
      verifySuccess.value = true
      return
    }
    if (data.status === 'pending' && data.biz_token) {
      faceBizToken.value = data.biz_token
      personalStep.value = 2
      faceUrl.value = ''
      faceQrUrl.value = ''
      ElMessage.info('验证仍在进行中，请在微信中完成人脸识别')
      startPersonalPolling()
      return
    }
    ElMessage.info('上次验证已过期，请重新发起')
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '恢复验证失败，请重新发起')
  } finally {
    resuming.value = false
  }
}

function openFaceUrl() {
  if (faceUrl.value) {
    window.location.href = faceUrl.value
  }
}

async function manualConfirm() {
  const isEnterprise = activeTab.value === 'enterprise' && enterpriseFaceBizToken.value
  const token = isEnterprise ? enterpriseFaceBizToken.value : faceBizToken.value
  if (!token) return
  confirming.value = true
  try {
    let res
    if (isEnterprise) {
      res = await confirmEnterprise({
        biz_token: token,
        enterprise_name: enterpriseForm.enterprise_name,
        credit_code: enterpriseForm.credit_code,
        legal_person_name: enterpriseForm.legal_person_name,
      })
    } else {
      res = await confirmFaceVerification({ biz_token: token })
    }
    const data = res?.data || res
    if (data.verified_type || data.status === 'success') {
      stopPolling()
      successType.value = isEnterprise ? 'enterprise' : 'personal'
      verifySuccess.value = true
    } else if (data.status === 'failed') {
      ElMessage.error(data.message || '验证未通过，请重试')
    } else {
      ElMessage.warning(data.message || '验证尚未完成，请先在微信中完成人脸识别')
    }
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '查询验证状态失败')
  } finally {
    confirming.value = false
  }
}

// ===== Enterprise verification =====
const enterpriseStep = ref(1)
const enterpriseFormRef = ref()
const enterpriseForm = reactive({
  enterprise_name: '',
  credit_code: '',
  legal_person_name: '',
  legal_person_id: '',
})
const licenseFileList = ref([])
const licenseImage = ref('')
const enterpriseFaceUrl = ref('')
const enterpriseFaceBizToken = ref('')
const enterpriseFaceQrUrl = ref('')

function handleLicenseUpload(file) {
  const rawFile = file.raw || file
  if (rawFile.size > 4 * 1024 * 1024) {
    ElMessage.warning('文件大小不能超过 4MB')
    licenseFileList.value = []
    return
  }
  const reader = new FileReader()
  reader.onload = (e) => {
    const base64 = e.target.result.split(',')[1]
    licenseImage.value = base64
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
    const res = await ocrBusinessLicense({ image: licenseImage.value })
    const data = res?.data || res
    enterpriseForm.enterprise_name = data.name || ''
    enterpriseForm.credit_code = data.credit_code || ''
    enterpriseForm.legal_person_name = data.legal_person || ''
    enterpriseForm.legal_person_id = ''
    enterpriseStep.value = 2
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '营业执照识别失败，请重试')
  } finally {
    ocrLoading.value = false
  }
}

async function submitEnterpriseVerify() {
  if (!(await enterpriseFormRef.value.validate().catch(() => false))) return
  submitting.value = true
  try {
    const res = await verifyEnterpriseApi({ ...enterpriseForm, license_image: licenseImage.value })
    const data = res?.data || res

    if (data.already_verified) {
      successType.value = 'enterprise'
      verifySuccess.value = true
      return
    }

    enterpriseFaceUrl.value = data.url || ''
    enterpriseFaceBizToken.value = data.biz_token || ''
    if (enterpriseFaceUrl.value) {
      enterpriseFaceQrUrl.value = await QRCode.toDataURL(enterpriseFaceUrl.value, { width: 200, margin: 2 })
    }
    ElMessage.success(data.message || '企业信息核验通过')
    enterpriseStep.value = 3
    startEnterprisePolling()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '企业核验失败')
  } finally {
    submitting.value = false
  }
}

function openEnterpriseFaceUrl() {
  if (enterpriseFaceUrl.value) {
    window.location.href = enterpriseFaceUrl.value
  }
}

function startEnterprisePolling() {
  stopPolling()
  pollCount.value = 0
  pollTimer = setInterval(async () => {
    if (!enterpriseFaceBizToken.value) return
    pollCount.value++
    if (pollCount.value >= maxPollCount) {
      stopPolling()
      return
    }
    try {
      const res = await pollEnterpriseVerification({
        biz_token: enterpriseFaceBizToken.value,
        enterprise_name: enterpriseForm.enterprise_name,
        credit_code: enterpriseForm.credit_code,
        legal_person_name: enterpriseForm.legal_person_name,
      })
      const data = res?.data || res
      if (data.status === 'success') {
        stopPolling()
        successType.value = 'enterprise'
        verifySuccess.value = true
      } else if (data.status === 'failed') {
        stopPolling()
        ElMessage.error(data.message || '法人验证未通过，请重试')
        enterpriseStep.value = 2
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
.verify-dialog :deep(.el-tabs__content) {
  padding-top: 12px;
}

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

.mobile-face-actions {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 24px;
  padding: 0 20px;
}

.mobile-qr-area {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.mobile-qr-hint {
  color: #909399;
  font-size: 12px;
}
</style>
