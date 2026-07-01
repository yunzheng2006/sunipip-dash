<template>
  <div class="site-settings-page">
    <div class="page-header">
      <h2 class="page-title">网站设置</h2>
      <p class="page-desc">修改网站名称和 Logo，会同步显示在管理后台和客户面板的左上角。</p>
    </div>

    <el-row :gutter="24">
      <!-- 左侧：设置表单 -->
      <el-col :span="14">
        <el-card v-loading="loading">
          <template #header><strong>基本信息</strong></template>

          <el-form label-width="100px" style="max-width: 440px">
            <el-form-item label="网站名称">
              <el-input v-model="siteName" placeholder="SuniPIP" maxlength="50" show-word-limit />
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="saveName">保存名称</el-button>
            </el-form-item>
          </el-form>

          <el-divider />

          <el-form label-width="100px">
            <el-form-item label="网站 Logo">
              <div class="logo-upload-area">
                <!-- 当前 Logo 预览 -->
                <div class="logo-preview-box">
                  <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="logo-preview-img" />
                  <div v-else class="logo-preview-placeholder">
                    <span>{{ (siteName || 'S')[0] }}</span>
                  </div>
                </div>

                <div class="logo-actions">
                  <el-upload
                    ref="uploadRef"
                    :show-file-list="false"
                    :before-upload="beforeUpload"
                    :http-request="handleUpload"
                    accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp"
                  >
                    <el-button type="primary" :loading="uploading">
                      {{ logoUrl ? '更换 Logo' : '上传 Logo' }}
                    </el-button>
                  </el-upload>
                  <el-button v-if="logoUrl" type="danger" plain @click="handleDeleteLogo" :loading="deleting">
                    删除 Logo
                  </el-button>
                </div>
              </div>

              <div class="upload-tips">
                <p>建议上传已去除背景的 PNG / SVG 图片</p>
                <p>最佳尺寸：高度 32-40px，宽度不超过 160px，文件不超过 2MB</p>
              </div>
            </el-form-item>

            <el-divider />

            <el-form-item label="网站图标">
              <div class="logo-upload-area">
                <div class="logo-preview-box">
                  <img v-if="faviconUrl" :src="faviconUrl" alt="Favicon" class="logo-preview-img" style="max-width:32px;max-height:32px" />
                  <div v-else class="logo-preview-placeholder" style="width:32px;height:32px;font-size:16px">
                    <span>{{ (siteName || 'S')[0] }}</span>
                  </div>
                </div>
                <div class="logo-actions">
                  <el-upload
                    :show-file-list="false"
                    :before-upload="beforeUploadFavicon"
                    :http-request="handleUploadFavicon"
                    accept="image/x-icon,image/png,image/svg+xml"
                  >
                    <el-button type="primary" :loading="uploadingFavicon">
                      {{ faviconUrl ? '更换图标' : '上传图标' }}
                    </el-button>
                  </el-upload>
                  <el-button v-if="faviconUrl" type="danger" plain @click="handleDeleteFavicon" :loading="deletingFavicon">
                    删除图标
                  </el-button>
                </div>
              </div>
              <div class="upload-tips">
                <p>浏览器标签页显示的小图标（favicon）</p>
                <p>推荐 32×32 或 64×64 的 ICO / PNG 文件，不超过 512KB</p>
              </div>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>

      <!-- 右侧：实时预览 -->
      <el-col :span="10">
        <el-card>
          <template #header><strong>效果预览</strong></template>

          <!-- 管理后台侧边栏预览 -->
          <div class="preview-label">管理后台侧边栏</div>
          <div class="preview-sidebar">
            <div class="preview-logo-area">
              <img v-if="logoUrl" :src="logoUrl" class="preview-logo-img" />
              <div v-else class="preview-logo-icon">{{ (siteName || 'S')[0] }}</div>
              <span class="preview-logo-text">{{ siteName || 'SuniPIP' }}</span>
            </div>
            <div class="preview-menu-item active">
              <span class="dot"></span> 仪表盘
            </div>
            <div class="preview-menu-item">
              <span class="dot"></span> 客户管理
            </div>
            <div class="preview-menu-item">
              <span class="dot"></span> IP 资产
            </div>
          </div>

          <!-- 客户面板顶栏预览 -->
          <div class="preview-label" style="margin-top: 20px">客户面板顶栏</div>
          <div class="preview-topbar">
            <div class="preview-topbar-logo">
              <img v-if="logoUrl" :src="logoUrl" class="preview-topbar-img" />
              <div v-else class="preview-topbar-icon">{{ (siteName || 'S')[0] }}</div>
              <span class="preview-topbar-text">{{ siteName || 'SuniPIP' }}</span>
            </div>
            <div class="preview-topbar-menu">
              <span class="active">首页</span>
              <span>商店</span>
              <span>我的IP</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 合作页联系图 -->
    <el-row style="margin-top: 24px">
      <el-col :span="14">
        <el-card v-loading="pcLoading">
          <template #header><strong>合作页 · 大客户联系图</strong></template>
          <p style="color:#909399;font-size:13px;margin-bottom:12px">
            合作页"大客户专属价格"悬浮窗展示此图。支持上传文件或填写外部链接。
          </p>
          <div style="display:flex;gap:8px;align-items:center">
            <el-input v-model="pcUrlInput" placeholder="图片链接 https://... 或上传后自动填充" style="flex:1" />
            <el-upload :show-file-list="false" :before-upload="beforeUploadPc" :http-request="handleUploadPc" accept="image/png,image/jpeg,image/gif,image/webp">
              <el-button :loading="pcUploading"><el-icon><Upload /></el-icon> 上传</el-button>
            </el-upload>
            <el-button type="primary" :loading="pcSaving" @click="savePcUrl">保存</el-button>
          </div>
          <div v-if="pcUrlInput" style="margin-top:8px">
            <el-image :src="pcUrlInput" style="max-width:120px;max-height:120px;border-radius:6px" fit="contain" />
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- VIP 详情图 -->
    <el-row style="margin-top: 24px">
      <el-col :span="14">
        <el-card v-loading="vdLoading">
          <template #header><strong>合作页 · VIP 详情图</strong></template>
          <p style="color:#909399;font-size:13px;margin-bottom:12px">
            合作页 VIP 卡片"查看详情"弹出此图。建议上传等级说明长图。
          </p>
          <div style="display:flex;gap:8px;align-items:center">
            <el-input v-model="vdUrlInput" placeholder="图片链接 https://... 或上传后自动填充" style="flex:1" />
            <el-upload :show-file-list="false" :before-upload="beforeUploadPc" :http-request="handleUploadVd" accept="image/png,image/jpeg,image/gif,image/webp">
              <el-button :loading="vdUploading"><el-icon><Upload /></el-icon> 上传</el-button>
            </el-upload>
            <el-button type="primary" :loading="vdSaving" @click="saveVdUrl">保存</el-button>
          </div>
          <div v-if="vdUrlInput" style="margin-top:8px">
            <el-image :src="vdUrlInput" style="max-width:120px;max-height:120px;border-radius:6px" fit="contain" />
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Upload } from '@element-plus/icons-vue'
import request from '@/utils/request'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()

const loading = ref(false)
const saving = ref(false)
const uploading = ref(false)
const deleting = ref(false)

const siteName = ref('SuniPIP')
const logoUrl = ref(null)
const faviconUrl = ref(null)
const uploadingFavicon = ref(false)
const deletingFavicon = ref(false)

async function fetchSettings() {
  loading.value = true
  try {
    const res = await request.get('/settings/site')
    siteName.value = res['site.name'] || 'SuniPIP'
    logoUrl.value = res['site.logo'] || null
    faviconUrl.value = res['site.favicon'] || null
  } catch {} finally { loading.value = false }
}

function beforeUploadFavicon(file) {
  const ok = /\.(ico|png|svg)$/i.test(file.name) || /^image\/(x-icon|png|svg\+xml|vnd\.microsoft\.icon)$/.test(file.type)
  if (!ok) { ElMessage.error('只支持 ICO / PNG / SVG 格式'); return false }
  if (file.size > 512 * 1024) { ElMessage.error('文件不能超过 512KB'); return false }
  return true
}

// 刷新浏览器标签页的 favicon（强制绕过缓存）
function applyFavicon(url) {
  const bustedUrl = url ? `${url}?t=${Date.now()}` : null
  // 移除所有现有 favicon link，避免浏览器使用旧的
  document.querySelectorAll("link[rel~='icon'], link[rel='shortcut icon']").forEach(el => el.remove())
  if (bustedUrl) {
    const link = document.createElement('link')
    link.rel = 'icon'
    link.href = bustedUrl
    document.head.appendChild(link)
  }
}

async function handleUploadFavicon({ file }) {
  uploadingFavicon.value = true
  try {
    const formData = new FormData()
    formData.append('favicon', file)
    const res = await request.post('/settings/site/favicon', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    faviconUrl.value = res.favicon_url + '?t=' + Date.now() // 预览也加戳防缓存
    applyFavicon(res.favicon_url)
    ElMessage.success('图标已更新')
  } catch {} finally { uploadingFavicon.value = false }
}

async function handleDeleteFavicon() {
  try { await ElMessageBox.confirm('确定删除网站图标？', '确认', { type: 'warning' }) } catch { return }
  deletingFavicon.value = true
  try {
    await request.delete('/settings/site/favicon')
    faviconUrl.value = null
    applyFavicon(null)
    ElMessage.success('图标已删除')
  } catch {} finally { deletingFavicon.value = false }
}

async function saveName() {
  saving.value = true
  try {
    await request.put('/settings/site', { 'site.name': siteName.value })
    appStore.updateSiteInfo(siteName.value, undefined)
    ElMessage.success('名称已保存')
  } catch {} finally { saving.value = false }
}

function beforeUpload(file) {
  const isImage = /^image\/(png|jpe?g|gif|svg\+xml|webp)$/.test(file.type)
  if (!isImage) {
    ElMessage.error('只支持 PNG / JPG / GIF / SVG / WebP 格式')
    return false
  }
  if (file.size > 2 * 1024 * 1024) {
    ElMessage.error('文件大小不能超过 2MB')
    return false
  }
  return true
}

async function handleUpload({ file }) {
  uploading.value = true
  try {
    const formData = new FormData()
    formData.append('logo', file)
    const res = await request.post('/settings/site/logo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const busted = res.logo_url + '?t=' + Date.now()
    logoUrl.value = busted
    appStore.updateSiteInfo(undefined, busted)
    ElMessage.success('Logo 已上传')
  } catch {} finally { uploading.value = false }
}

async function handleDeleteLogo() {
  try {
    await ElMessageBox.confirm('确认删除 Logo？将恢复为默认首字母图标', '确认', { type: 'warning' })
  } catch { return }

  deleting.value = true
  try {
    await request.delete('/settings/site/logo')
    logoUrl.value = null
    appStore.updateSiteInfo(undefined, null)
    ElMessage.success('Logo 已删除')
  } catch {} finally { deleting.value = false }
}

// ===== 通用图片配置（大客户联系图 + VIP详情图）=====
function beforeUploadPc(file) {
  const isImage = /^image\/(png|jpe?g|gif|webp)$/.test(file.type)
  if (!isImage) { ElMessage.error('只支持 PNG / JPG / GIF / WebP 格式'); return false }
  if (file.size > 4 * 1024 * 1024) { ElMessage.error('文件不能超过 4MB'); return false }
  return true
}

async function fetchImageConfig(key) {
  try {
    const res = await request.get('/settings/image-config', { params: { key } })
    return res.resolved_url || res.value || null
  } catch { return null }
}

async function saveImageUrl(key, url) {
  const formData = new FormData()
  formData.append('key', key)
  formData.append('mode', 'url')
  formData.append('url', url)
  const res = await request.post('/settings/image-config', formData)
  return res.resolved_url
}

async function uploadImage(key, file) {
  const formData = new FormData()
  formData.append('key', key)
  formData.append('mode', 'upload')
  formData.append('image', file)
  const res = await request.post('/settings/image-config', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return res.resolved_url
}

// 大客户联系图
const pcLoading = ref(false)
const pcUploading = ref(false)
const pcSaving = ref(false)
const pcUrlInput = ref('')

async function fetchPartnershipContact() {
  pcLoading.value = true
  try { pcUrlInput.value = await fetchImageConfig('partnership.contact_image') || '' } catch {}
  finally { pcLoading.value = false }
}

async function handleUploadPc({ file }) {
  pcUploading.value = true
  try {
    const url = await uploadImage('partnership.contact_image', file)
    pcUrlInput.value = url + '?t=' + Date.now()
    ElMessage.success('已上传')
  } catch {} finally { pcUploading.value = false }
}

async function savePcUrl() {
  const url = pcUrlInput.value.trim()
  if (!url) { ElMessage.warning('请输入链接或上传图片'); return }
  pcSaving.value = true
  try {
    await saveImageUrl('partnership.contact_image', url)
    ElMessage.success('已保存')
  } catch (e) { ElMessage.error(e?.response?.data?.message || '保存失败') }
  finally { pcSaving.value = false }
}

// VIP 详情图
const vdLoading = ref(false)
const vdUploading = ref(false)
const vdSaving = ref(false)
const vdUrlInput = ref('')

async function fetchVipDetailImage() {
  vdLoading.value = true
  try { vdUrlInput.value = await fetchImageConfig('partnership.vip_detail_image') || '' } catch {}
  finally { vdLoading.value = false }
}

async function handleUploadVd({ file }) {
  vdUploading.value = true
  try {
    const url = await uploadImage('partnership.vip_detail_image', file)
    vdUrlInput.value = url + '?t=' + Date.now()
    ElMessage.success('已上传')
  } catch {} finally { vdUploading.value = false }
}

async function saveVdUrl() {
  const url = vdUrlInput.value.trim()
  if (!url) { ElMessage.warning('请输入链接或上传图片'); return }
  vdSaving.value = true
  try {
    await saveImageUrl('partnership.vip_detail_image', url)
    ElMessage.success('已保存')
  } catch (e) { ElMessage.error(e?.response?.data?.message || '保存失败') }
  finally { vdSaving.value = false }
}

onMounted(() => {
  fetchSettings()
  fetchPartnershipContact()
  fetchVipDetailImage()
})
</script>

<style lang="scss" scoped>
.site-settings-page {
  .page-header {
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
}

.logo-upload-area {
  display: flex;
  align-items: center;
  gap: 20px;
}

.logo-preview-box {
  width: 80px;
  height: 80px;
  border-radius: 14px;
  border: 2px dashed #DCDFE6;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #FAFAFA;
  flex-shrink: 0;

  .logo-preview-img {
    max-width: 72px;
    max-height: 72px;
    object-fit: contain;
  }

  .logo-preview-placeholder {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #E8913A, #F2A85A);
    color: #fff;
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.logo-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.upload-tips {
  margin-top: 10px;
  p {
    margin: 0;
    font-size: 12px;
    color: #909399;
    line-height: 1.6;
  }
}

/* ===== 预览区 ===== */
.preview-label {
  font-size: 12px;
  color: #909399;
  font-weight: 600;
  margin-bottom: 8px;
  text-transform: uppercase;
  letter-spacing: 1px;
}

/* 侧边栏预览 */
.preview-sidebar {
  background: linear-gradient(180deg, #1A1A2E, #16213E);
  border-radius: 10px;
  padding: 12px;
  min-height: 140px;

  .preview-logo-area {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 8px;

    .preview-logo-img {
      height: 28px;
      max-width: 28px;
      object-fit: contain;
      border-radius: 6px;
    }

    .preview-logo-icon {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: linear-gradient(135deg, #E8913A, #F2A85A);
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .preview-logo-text {
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 1px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .preview-menu-item {
    color: rgba(255,255,255,0.5);
    font-size: 12px;
    padding: 5px 8px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    gap: 6px;

    .dot {
      width: 4px;
      height: 4px;
      border-radius: 50%;
      background: currentColor;
    }

    &.active {
      background: rgba(242, 168, 90, 0.15);
      color: #F2A85A;
    }
  }
}

/* 客户面板预览 */
.preview-topbar {
  background: #fff;
  border: 1px solid #EADFD2;
  border-radius: 10px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  gap: 16px;

  .preview-topbar-logo {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;

    .preview-topbar-img {
      height: 24px;
      max-width: 24px;
      object-fit: contain;
      border-radius: 6px;
    }

    .preview-topbar-icon {
      width: 24px;
      height: 24px;
      border-radius: 6px;
      background: linear-gradient(135deg, #E8913A, #D87A1E);
      color: #fff;
      font-size: 12px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .preview-topbar-text {
      font-size: 13px;
      font-weight: 700;
      color: #2C3E50;
    }
  }

  .preview-topbar-menu {
    display: flex;
    gap: 10px;

    span {
      font-size: 11px;
      color: #909399;
      padding: 2px 6px;
      border-radius: 4px;

      &.active {
        background: #FDF0E2;
        color: #E8913A;
        font-weight: 600;
      }
    }
  }
}
</style>
