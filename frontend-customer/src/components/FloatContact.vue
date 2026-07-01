<template>
  <div v-if="items.length" class="float-contact" :class="{ 'float-contact--has-tabbar': hasTabbar, 'float-contact--store': isStorePage }">
    <template v-for="(btn, i) in items" :key="i">
      <!-- 跳转链接 -->
      <router-link v-if="btn.type === 'link' && btn.url && btn.url.startsWith('/')" class="float-contact__btn" :to="btn.url">
        <div class="float-contact__icon" :class="'float-contact__icon--' + (btn.icon_color || 'blue')">
          <span class="float-contact__dot">●</span>
        </div>
        <div class="float-contact__text">
          <span class="float-contact__title">{{ btn.label }}</span>
          <span v-if="btn.subtitle" class="float-contact__sub">{{ btn.subtitle }}</span>
        </div>
      </router-link>
      <a v-else-if="btn.type === 'link' && btn.url" class="float-contact__btn" :href="btn.url" target="_blank" rel="noopener">
        <div class="float-contact__icon" :class="'float-contact__icon--' + (btn.icon_color || 'blue')">
          <span class="float-contact__dot">●</span>
        </div>
        <div class="float-contact__text">
          <span class="float-contact__title">{{ btn.label }}</span>
          <span v-if="btn.subtitle" class="float-contact__sub">{{ btn.subtitle }}</span>
        </div>
      </a>

      <!-- 悬浮图片 -->
      <div v-else-if="btn.type === 'image'" class="float-contact__btn" @click="onImageClick(btn)">
        <div class="float-contact__icon" :class="'float-contact__icon--' + (btn.icon_color || 'blue')">
          <span class="float-contact__dot">●</span>
        </div>
        <div class="float-contact__text">
          <span class="float-contact__title">{{ btn.label }}</span>
          <span v-if="btn.subtitle" class="float-contact__sub">{{ btn.subtitle }}</span>
        </div>
        <div v-if="btn.image_url" class="float-contact__qr float-contact__qr--desktop">
          <img :src="btn.image_url" :alt="btn.label">
        </div>
      </div>

      <!-- 悬浮复制 -->
      <div v-else-if="btn.type === 'copy'" class="float-contact__btn">
        <div class="float-contact__icon" :class="'float-contact__icon--' + (btn.icon_color || 'blue')">
          <span class="float-contact__dot">●</span>
        </div>
        <div class="float-contact__text">
          <span class="float-contact__title">{{ btn.label }}</span>
          <span v-if="btn.subtitle" class="float-contact__sub">{{ btn.subtitle }}</span>
        </div>
        <div v-if="btn.copy_text" class="float-contact__copy">
          <span>{{ btn.copy_text }}</span>
          <button @click.stop="copy(btn.copy_text)">复制</button>
        </div>
      </div>
    </template>

    <!-- 手机端点击弹窗显示二维码 -->
    <div v-if="qrDialogVisible" class="qr-dialog-overlay" @click.self="qrDialogVisible = false">
      <div class="qr-dialog">
        <div class="qr-dialog__header">
          <span>{{ qrDialogLabel }}</span>
          <button class="qr-dialog__close" @click="qrDialogVisible = false">✕</button>
        </div>
        <div class="qr-dialog__body">
          <img :src="qrDialogImage" :alt="qrDialogLabel">
        </div>
        <button class="qr-dialog__save" @click="saveQrImage">保存二维码</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAppStore } from '@/stores/app'

const route = useRoute()
const appStore = useAppStore()
const isStorePage = computed(() => route.path === '/store')

const items = computed(() => {
  const configured = appStore.floatContact
  if (Array.isArray(configured) && configured.length) return configured
  const fallback = []
  if (appStore.supportQrImage) {
    fallback.push({ label: '客户经理', subtitle: '1对1专属服务', icon_color: 'blue', type: 'image', image_url: appStore.supportQrImage })
  } else if (appStore.supportWechat) {
    fallback.push({ label: '客户经理', subtitle: '1对1专属服务', icon_color: 'blue', type: 'copy', copy_text: '微信: ' + appStore.supportWechat })
  }
  if (appStore.supportPhone) {
    fallback.push({ label: '客服电话', subtitle: appStore.supportPhone, icon_color: 'orange', type: 'copy', copy_text: appStore.supportPhone })
  }
  return fallback
})

const hasTabbar = computed(() => window.innerWidth <= 768)
const isMobile = computed(() => window.innerWidth <= 768)

const qrDialogVisible = ref(false)
const qrDialogImage = ref('')
const qrDialogLabel = ref('')

function onImageClick(btn) {
  if (isMobile.value && btn.image_url) {
    qrDialogImage.value = btn.image_url
    qrDialogLabel.value = btn.label || '二维码'
    qrDialogVisible.value = true
  }
}

async function saveQrImage() {
  try {
    const response = await fetch(qrDialogImage.value)
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${qrDialogLabel.value || 'qrcode'}.png`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('已保存')
  } catch {
    ElMessage.warning('保存失败，请长按图片保存')
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制到剪贴板')
  } catch {
    ElMessage.warning('复制失败，请手动选择文字')
  }
}
</script>

<style lang="scss" scoped>
.float-contact {
  position: fixed;
  right: 20px;
  bottom: 24px;
  z-index: 2000;
  display: flex;
  flex-direction: column;
  gap: 8px;

  &--has-tabbar {
    bottom: 72px;
  }
}

.float-contact__btn {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  background: #fff;
  border: 1px solid #E5E9F2;
  border-radius: 14px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
  cursor: pointer;
  transition: all 0.25s;
  text-decoration: none;
  color: inherit;
  min-width: 160px;

  &:hover {
    border-color: #F7A600;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
    transform: translateX(-4px);

    .float-contact__qr,
    .float-contact__copy {
      opacity: 1;
      visibility: visible;
      transform: translateX(0) translateY(-50%);
    }
  }
}

.float-contact__icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  .float-contact__dot { font-size: 14px; }

  &--blue { background: #EEF2FB; .float-contact__dot { color: #409EFF; } }
  &--orange { background: #FFF7E6; .float-contact__dot { color: #F7A600; } }
  &--dark { background: #F1F5F9; .float-contact__dot { color: #475569; } }
  &--green { background: #E8F9EF; .float-contact__dot { color: #10B981; } }
  &--purple { background: #F0EAFF; .float-contact__dot { color: #8B5CF6; } }
}

.float-contact__text {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.float-contact__title {
  font-size: 13px;
  font-weight: 700;
  color: #0B1437;
  white-space: nowrap;
}

.float-contact__sub {
  font-size: 11px;
  color: #6B7488;
  white-space: nowrap;
}

.float-contact__qr {
  position: absolute;
  right: calc(100% + 12px);
  top: 50%;
  transform: translateX(8px) translateY(-50%);
  opacity: 0;
  visibility: hidden;
  transition: all 0.25s;
  background: #fff;
  border: 1px solid #E5E9F2;
  border-radius: 12px;
  padding: 8px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);

  img {
    width: 200px;
    height: auto;
    display: block;
    border-radius: 6px;
  }
}

.float-contact__copy {
  position: absolute;
  right: calc(100% + 12px);
  top: 50%;
  transform: translateX(8px) translateY(-50%);
  opacity: 0;
  visibility: hidden;
  transition: all 0.25s;
  background: #fff;
  border: 1px solid #E5E9F2;
  border-radius: 10px;
  padding: 10px 14px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;

  span {
    font-size: 13px;
    font-weight: 600;
    color: #0B1437;
    font-family: 'SF Mono', Consolas, monospace;
  }

  button {
    padding: 4px 12px;
    background: #F7A600;
    color: #0B1437;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s;
    &:hover { background: #E89500; }
  }
}

// 弹窗样式
.qr-dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.qr-dialog {
  background: #fff;
  border-radius: 16px;
  width: 300px;
  max-width: 90vw;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
}

.qr-dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #F0F0F0;
  font-size: 16px;
  font-weight: 600;
  color: #1E293B;
}

.qr-dialog__close {
  background: none;
  border: none;
  font-size: 18px;
  color: #94A3B8;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;
  &:hover { background: #F1F5F9; }
}

.qr-dialog__body {
  padding: 24px;
  display: flex;
  justify-content: center;

  img {
    width: 100%;
    max-width: 240px;
    height: auto;
    border-radius: 8px;
  }
}

.qr-dialog__save {
  display: block;
  width: calc(100% - 40px);
  margin: 0 20px 20px;
  padding: 12px;
  background: #409EFF;
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
  &:hover { background: #337ecc; }
}

@media (max-width: 768px) {
  .float-contact {
    right: 10px;
    bottom: 68px;
    gap: 6px;

    &--store {
      z-index: 149;
      bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 52px);
    }
  }

  .float-contact__btn {
    padding: 8px 10px;
    min-width: auto;
    border-radius: 12px;
    gap: 8px;
  }

  .float-contact__icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    .float-contact__dot { font-size: 12px; }
  }

  .float-contact__title { font-size: 12px; }
  .float-contact__sub { font-size: 10px; }

  // 手机端隐藏 hover 悬浮二维码，改为点击弹窗
  .float-contact__qr--desktop { display: none !important; }

  .float-contact__copy {
    right: 0;
    left: auto;
    top: auto;
    bottom: calc(100% + 8px);
    transform: translateY(8px);
  }
  .float-contact__btn:hover {
    .float-contact__copy {
      transform: translateY(0);
    }
  }
}
</style>
