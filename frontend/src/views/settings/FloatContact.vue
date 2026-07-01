<template>
  <div class="float-contact-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">悬浮联系按钮</h2>
        <p class="page-desc">客户面板每个页面右下角的悬浮联系方式按钮</p>
      </div>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </div>

    <el-card class="sec-card">
      <template #header>
        <div class="sec-head">
          <span>按钮列表（最多 10 个，从上到下排列）</span>
          <el-button v-if="buttons.length < 10" type="primary" link @click="addButton">
            <el-icon><Plus /></el-icon> 添加按钮
          </el-button>
        </div>
      </template>

      <div v-if="!buttons.length" class="empty-hint">暂无按钮，点击右上方「添加按钮」开始</div>

      <div v-for="(btn, i) in buttons" :key="i" class="btn-card">
        <div class="btn-card-head">
          <span class="btn-card-index">#{{ i + 1 }}</span>
          <div class="btn-card-actions">
            <el-button v-if="i > 0" link size="small" @click="move(i, -1)">上移</el-button>
            <el-button v-if="i < buttons.length - 1" link size="small" @click="move(i, 1)">下移</el-button>
            <el-button type="danger" link size="small" @click="buttons.splice(i, 1)">删除</el-button>
          </div>
        </div>
        <el-form label-width="90px" size="default">
          <el-row :gutter="16">
            <el-col :span="12">
              <el-form-item label="标题">
                <el-input v-model="btn.label" placeholder="如：客户经理" maxlength="50" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="副标题">
                <el-input v-model="btn.subtitle" placeholder="如：1对1专属服务" maxlength="100" />
              </el-form-item>
            </el-col>
          </el-row>
          <el-row :gutter="16">
            <el-col :span="8">
              <el-form-item label="图标颜色">
                <el-select v-model="btn.icon_color" style="width:100%">
                  <el-option label="蓝色" value="blue" />
                  <el-option label="橙色" value="orange" />
                  <el-option label="深灰" value="dark" />
                  <el-option label="绿色" value="green" />
                  <el-option label="紫色" value="purple" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="按钮类型">
                <el-select v-model="btn.type" style="width:100%">
                  <el-option label="跳转链接" value="link" />
                  <el-option label="悬浮图片" value="image" />
                  <el-option label="悬浮复制" value="copy" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item v-if="btn.type === 'link'" label="链接地址">
                <el-input v-model="btn.url" placeholder="/partnership 或 https://..." />
              </el-form-item>
              <el-form-item v-else-if="btn.type === 'copy'" label="复制文本">
                <el-input v-model="btn.copy_text" placeholder="如：微信号 / 电话号码" />
              </el-form-item>
              <el-form-item v-else-if="btn.type === 'image'" label="悬浮图片">
                <div class="img-cell">
                  <div v-if="btn.image_url" class="img-preview">
                    <el-image :src="btn.image_url" style="width:50px;height:50px;border-radius:6px" />
                    <el-button type="danger" link @click="btn.image_path = null; btn.image_url = null">清除</el-button>
                  </div>
                  <template v-else>
                    <el-upload :show-file-list="false" :before-upload="(f) => handleUpload(f, i)" accept="image/*">
                      <el-button :loading="uploadingIndex === i" size="small"><el-icon><Upload /></el-icon> 上传</el-button>
                    </el-upload>
                    <span style="margin:0 6px;color:#C0C4CC">或</span>
                    <el-input v-model="btn.image_url" placeholder="图片链接 https://..." size="small" style="width:220px" />
                  </template>
                </div>
              </el-form-item>
            </el-col>
          </el-row>
        </el-form>
      </div>
    </el-card>

    <!-- 预览 -->
    <el-card class="sec-card">
      <template #header><div class="sec-head"><span>预览（右下角效果）</span></div></template>
      <div v-if="buttons.length" class="preview-area">
        <div class="preview-float">
          <div v-for="(btn, i) in buttons" :key="i" class="preview-btn" :class="{ 'has-popup': btn.type !== 'link' }">
            <div class="preview-icon" :class="'preview-icon--' + (btn.icon_color || 'blue')">●</div>
            <div class="preview-text">
              <span class="preview-label">{{ btn.label || '（未命名）' }}</span>
              <span class="preview-sub">{{ btn.subtitle || '' }}</span>
            </div>
            <div v-if="btn.type === 'image' && btn.image_url" class="preview-popup-img">
              <img :src="btn.image_url" />
            </div>
            <div v-else-if="btn.type === 'copy' && btn.copy_text" class="preview-popup-copy">
              {{ btn.copy_text }} <span class="copy-tag">复制</span>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="empty-hint">暂无按钮</div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Delete, Upload } from '@element-plus/icons-vue'
import request from '@/utils/request'

const saving = ref(false)
const uploadingIndex = ref(-1)
const buttons = ref([])

async function fetchData() {
  try {
    const data = await request.get('/settings/float-contact')
    buttons.value = Array.isArray(data) ? data : []
  } catch {}
}

function addButton() {
  buttons.value.push({
    label: '', subtitle: '', icon_color: 'blue', type: 'link',
    url: '', copy_text: '', image_path: null, image_url: null,
  })
}

function move(index, dir) {
  const arr = buttons.value
  const target = index + dir
  if (target < 0 || target >= arr.length) return
  ;[arr[index], arr[target]] = [arr[target], arr[index]]
}

async function handleUpload(file, index) {
  const fd = new FormData()
  fd.append('image', file)
  uploadingIndex.value = index
  try {
    const { path, url } = await request.post('/settings/float-contact/upload-image', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    buttons.value[index].image_path = path
    buttons.value[index].image_url = url
    ElMessage.success('图片已上传')
  } catch {
    ElMessage.error('上传失败')
  } finally { uploadingIndex.value = -1 }
  return false
}

async function save() {
  saving.value = true
  try {
    await request.put('/settings/float-contact', {
      buttons: buttons.value
        .filter(b => b.label && b.label.trim())
        .map(b => ({
          label: b.label,
          subtitle: b.subtitle || '',
          icon_color: b.icon_color || 'blue',
          type: b.type || 'link',
          url: b.url || '',
          copy_text: b.copy_text || '',
          image_path: b.image_path || null,
          image_url: b.image_url || null,
        })),
    })
    ElMessage.success('已保存，客户刷新页面即可看到')
    fetchData()
  } catch {} finally { saving.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.float-contact-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
  .sec-card { margin-bottom: 16px; }
  .sec-head { display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
  .empty-hint { color: #909399; font-size: 13px; padding: 10px; text-align: center; }
}

.btn-card {
  border: 1px solid #EBEEF5; border-radius: 8px; padding: 14px; margin-bottom: 12px;
  .btn-card-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
    .btn-card-index { font-size: 13px; color: #606266; font-weight: 600; }
  }
  .img-cell { display: flex; align-items: center; gap: 10px; }
  .img-preview { display: flex; align-items: center; gap: 10px; }
}

/* 预览 */
.preview-area {
  display: flex; justify-content: flex-end; padding: 20px;
  background: #f5f7fa; border-radius: 10px; min-height: 120px;
}
.preview-float {
  display: flex; flex-direction: column; gap: 8px;
}
.preview-btn {
  position: relative;
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px; background: #fff; border: 1px solid #E5E9F2;
  border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);
  min-width: 160px; cursor: default;
  &.has-popup:hover {
    border-color: #F7A600;
    .preview-popup-img, .preview-popup-copy { opacity: 1; visibility: visible; transform: translateX(0) translateY(-50%); }
  }
}
.preview-icon {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
  &--blue { background: #EEF2FB; color: #409EFF; }
  &--orange { background: #FFF7E6; color: #F7A600; }
  &--dark { background: #F1F5F9; color: #475569; }
  &--green { background: #E8F9EF; color: #10B981; }
  &--purple { background: #F0EAFF; color: #8B5CF6; }
}
.preview-text {
  display: flex; flex-direction: column; gap: 1px;
  .preview-label { font-size: 13px; font-weight: 700; color: #0B1437; white-space: nowrap; }
  .preview-sub { font-size: 11px; color: #6B7488; white-space: nowrap; }
}
.preview-popup-img, .preview-popup-copy {
  position: absolute; right: calc(100% + 12px); top: 50%;
  transform: translateX(8px) translateY(-50%);
  opacity: 0; visibility: hidden; transition: all 0.25s;
  background: #fff; border: 1px solid #E5E9F2; border-radius: 10px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.12); z-index: 10;
}
.preview-popup-img {
  padding: 8px;
  img { display: block; width: 200px; height: auto; border-radius: 6px; }
}
.preview-popup-copy {
  padding: 10px 14px; white-space: nowrap; font-size: 13px; font-weight: 600; color: #0B1437;
  .copy-tag {
    display: inline-block; padding: 2px 10px; background: #F7A600; color: #0B1437;
    border-radius: 6px; font-size: 12px; font-weight: 700; margin-left: 8px;
  }
}
</style>
