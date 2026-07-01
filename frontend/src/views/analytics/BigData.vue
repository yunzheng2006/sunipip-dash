<template>
  <div class="bigdata-wrapper">
    <iframe
      ref="iframeRef"
      src="/bigdata/index.html"
      class="bigdata-iframe"
      frameborder="0"
      allowfullscreen
    ></iframe>
    <div class="fullscreen-hint">
      <el-button size="small" type="primary" plain @click="openFullscreen">
        <el-icon><FullScreen /></el-icon>
        全屏模式
      </el-button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { FullScreen } from '@element-plus/icons-vue'

const iframeRef = ref(null)

function openFullscreen() {
  window.open('/bigdata/index.html', '_blank')
}
</script>

<style scoped>
.bigdata-wrapper {
  position: relative;
  width: 100%;
  height: calc(100vh - 96px);
  margin: -20px;
  border-radius: 8px;
  overflow: hidden;
}

.bigdata-iframe {
  width: 100%;
  height: 100%;
  border: none;
  background: #080d1e;
}

.fullscreen-hint {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 10;
}
</style>
