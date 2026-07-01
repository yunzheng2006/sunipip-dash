<template>
  <div v-if="text" class="qr-cell">
    <el-image
      v-if="dataUrl"
      :src="dataUrl"
      :preview-src-list="[largeDataUrl || dataUrl]"
      :preview-teleported="true"
      :initial-index="0"
      :z-index="3000"
      fit="contain"
      class="qr-img"
      @show="onPreviewShow"
    />
    <div class="qr-hint">点击放大</div>
  </div>
  <span v-else class="empty-hint">-</span>
</template>

<script setup>
import { ref, watchEffect } from 'vue'
import QRCode from 'qrcode'

const props = defineProps({
  text: { type: String, default: '' },
})

const dataUrl = ref('')
const largeDataUrl = ref('')

// 缓存避免重复生成
const cache = new Map()

watchEffect(async () => {
  if (!props.text) {
    dataUrl.value = ''
    largeDataUrl.value = ''
    return
  }
  if (cache.has(props.text)) {
    const [small, large] = cache.get(props.text)
    dataUrl.value = small
    largeDataUrl.value = large
    return
  }
  try {
    const [small, large] = await Promise.all([
      QRCode.toDataURL(props.text, {
        width: 80,
        margin: 1,
        errorCorrectionLevel: 'M',
        color: { dark: '#2C3E50', light: '#FFFFFF' },
      }),
      QRCode.toDataURL(props.text, {
        width: 500,
        margin: 2,
        errorCorrectionLevel: 'M',
        color: { dark: '#2C3E50', light: '#FFFFFF' },
      }),
    ])
    cache.set(props.text, [small, large])
    dataUrl.value = small
    largeDataUrl.value = large
  } catch (e) {
    console.warn('QR generate failed:', e)
  }
})

function onPreviewShow() {
  // 预览打开时确保大图已生成
}
</script>

<style lang="scss" scoped>
.qr-cell {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}
.qr-img {
  width: 64px;
  height: 64px;
  cursor: zoom-in;
  border: 1px solid #EADFD2;
  border-radius: 4px;
  padding: 2px;
  background: #fff;
  transition: transform 0.15s;
  &:hover {
    transform: scale(1.05);
    border-color: #E8913A;
  }
  :deep(img) {
    border-radius: 2px;
  }
}
.qr-hint {
  font-size: 10px;
  color: #C0C4CC;
  user-select: none;
}
.empty-hint {
  color: #C0C4CC;
  font-size: 12px;
}
</style>
