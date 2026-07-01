<template>
  <div class="store-banner-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">店铺顶部横幅</h2>
        <p class="page-desc">客户面板商店页顶部展示的 CTA 横幅</p>
      </div>
      <el-button type="primary" :loading="saving" @click="save">保存</el-button>
    </div>

    <el-card class="sec-card">
      <template #header><div class="sec-head"><span>开关</span></div></template>
      <el-switch v-model="form.enabled" active-text="启用 banner" />
      <div class="hint">关闭后商店页顶部不显示任何横幅</div>
    </el-card>

    <el-card class="sec-card">
      <template #header><div class="sec-head"><span>标题与描述</span></div></template>
      <el-form label-position="top">
        <el-form-item label="大标题">
          <el-input v-model="form.title" placeholder="如：太阳IP郑重承诺" maxlength="100" show-word-limit />
        </el-form-item>
        <el-form-item label="副标题 / 描述">
          <el-input v-model="form.subtitle" type="textarea" :rows="2" placeholder="一段简短描述文字" maxlength="500" show-word-limit />
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="sec-card">
      <template #header>
        <div class="sec-head">
          <span>承诺条目（按行分组，显示在描述下方）</span>
          <el-button type="primary" link @click="form.promises.push([''])"><el-icon><Plus /></el-icon> 添加行</el-button>
        </div>
      </template>
      <div v-if="!form.promises.length" class="empty-hint">暂无条目（可选），点击右上方「添加行」开始</div>
      <div v-for="(row, ri) in form.promises" :key="ri" class="promise-group">
        <div class="promise-group-head">
          <span class="promise-group-label">第 {{ ri + 1 }} 行</span>
          <div>
            <el-button type="primary" link size="small" @click="row.push('')"><el-icon><Plus /></el-icon> 添加条目</el-button>
            <el-button type="danger" link size="small" @click="form.promises.splice(ri, 1)">删除本行</el-button>
          </div>
        </div>
        <div v-for="(p, pi) in row" :key="pi" class="promise-row">
          <el-input v-model="row[pi]" placeholder="如：已售出IP永不下架可稳定续费" maxlength="200" show-word-limit />
          <el-button type="danger" link @click="row.splice(pi, 1)"><el-icon><Delete /></el-icon></el-button>
        </div>
        <div v-if="!row.length" class="empty-hint" style="padding:4px 0">本行暂无条目</div>
      </div>
    </el-card>

    <el-card class="sec-card">
      <template #header>
        <div class="sec-head">
          <span>按钮（最多 8 个）</span>
          <el-button v-if="form.buttons.length < 8" type="primary" link @click="addButton">
            <el-icon><Plus /></el-icon> 添加按钮
          </el-button>
        </div>
      </template>
      <div v-if="!form.buttons.length" class="empty-hint">暂无按钮（可选）</div>

      <el-table v-if="form.buttons.length" :data="form.buttons" stripe style="width: 100%">
        <el-table-column label="名称" width="180">
          <template #default="{ row, $index }">
            <el-input v-model="form.buttons[$index].label" placeholder="如：立即申请合作" maxlength="50" />
          </template>
        </el-table-column>
        <el-table-column label="类型" width="130">
          <template #default="{ row, $index }">
            <el-select v-model="form.buttons[$index].type">
              <el-option label="跳转链接" value="link" />
              <el-option label="悬浮图（如二维码）" value="image" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="链接 / 图片" min-width="320">
          <template #default="{ row, $index }">
            <el-input v-if="row.type === 'link'" v-model="form.buttons[$index].url"
              placeholder="https://... 或 /partnership" />
            <div v-else class="img-cell">
              <div v-if="row.image_url" class="img-preview">
                <el-image :src="row.image_url" style="width: 50px; height: 50px; border-radius: 6px" />
                <el-button type="danger" link @click="form.buttons[$index].image_path = null; form.buttons[$index].image_url = null">清除</el-button>
              </div>
              <template v-else>
                <el-upload
                  :show-file-list="false"
                  :before-upload="(f) => handleUpload(f, $index)"
                  accept="image/*">
                  <el-button :loading="uploadingIndex === $index">
                    <el-icon><Upload /></el-icon> 上传
                  </el-button>
                </el-upload>
                <span style="margin:0 6px;color:#C0C4CC">或</span>
                <el-input v-model="form.buttons[$index].image_url" placeholder="图片链接 https://..." size="default" style="width:240px" />
              </template>
              <span class="hint-inline">悬停按钮时浮现，推荐正方形 ≤ 300x300</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ $index }">
            <el-button type="danger" link @click="form.buttons.splice($index, 1)"><el-icon><Delete /></el-icon></el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Preview -->
    <el-card class="sec-card">
      <template #header><div class="sec-head"><span>预览</span></div></template>
      <div v-if="form.enabled && (form.title || form.subtitle || form.promises.length || form.buttons.length)" class="preview-cta">
        <h2 v-if="form.title">{{ form.title }}</h2>
        <p v-if="form.subtitle" class="preview-subtitle">{{ form.subtitle }}</p>
        <template v-if="form.promises.length">
          <div v-for="(row, ri) in form.promises" :key="ri" class="preview-promises">
            <span v-for="(p, pi) in row" :key="pi" class="preview-promise">{{ p || '（未填）' }}</span>
          </div>
        </template>
        <div v-if="form.buttons.length" class="preview-btns">
          <span v-for="(b, i) in form.buttons" :key="i"
            class="preview-btn" :class="[i === 0 ? 'primary' : 'ghost', { 'has-image': b.type === 'image' && b.image_url }]">
            {{ b.label || '（未命名）' }}
            <span v-if="b.type === 'image' && b.image_url" class="preview-popup">
              <img :src="b.image_url" alt="" />
            </span>
          </span>
        </div>
      </div>
      <div v-else class="empty-hint">banner 未启用或内容为空</div>
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

const form = reactive({
  enabled: false,
  title: '',
  subtitle: '',
  promises: [],
  buttons: [],
})

async function fetchData() {
  try {
    const data = await request.get('/settings/store-banner')
    let promises = data?.promises || []
    if (promises.length && typeof promises[0] === 'string') {
      promises = [promises]
    }
    Object.assign(form, {
      enabled: !!data?.enabled,
      title: data?.title || '',
      subtitle: data?.subtitle || '',
      promises,
      buttons: data?.buttons || [],
    })
  } catch {}
}

function addButton() {
  form.buttons.push({ label: '', type: 'link', url: '', image_path: null, image_url: null })
}

async function handleUpload(file, index) {
  const fd = new FormData()
  fd.append('image', file)
  uploadingIndex.value = index
  try {
    const { path, url } = await request.post('/settings/store-banner/upload-image', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    form.buttons[index].image_path = path
    form.buttons[index].image_url = url
    ElMessage.success('图片已上传')
  } catch {
    ElMessage.error('上传失败')
  } finally { uploadingIndex.value = -1 }
  return false
}

async function save() {
  saving.value = true
  try {
    await request.put('/settings/store-banner', {
      enabled: form.enabled,
      title: form.title,
      subtitle: form.subtitle,
      promises: form.promises
        .map(row => row.filter(p => p && p.trim()))
        .filter(row => row.length > 0),
      buttons: form.buttons
        .filter(b => b.label && b.label.trim())
        .map(b => ({
          label: b.label,
          type: b.type,
          url: b.url || '',
          image_path: b.image_path || null,
          image_url: b.image_url || null,
        })),
    })
    ElMessage.success('已保存，客户刷新 /store 即可看到')
    fetchData()
  } catch {} finally { saving.value = false }
}

onMounted(fetchData)
</script>

<style lang="scss" scoped>
.store-banner-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
  .sec-card { margin-bottom: 16px; }
  .sec-head { display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
  .empty-hint { color: #909399; font-size: 13px; padding: 10px; text-align: center; }
  .hint { color: #909399; font-size: 12px; margin-top: 6px; }
  .hint-inline { font-size: 12px; color: #C0C4CC; margin-left: 8px; }
  .promise-group {
    border: 1px solid #EBEEF5; border-radius: 8px; padding: 12px; margin-bottom: 12px;
    .promise-group-head {
      display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;
      .promise-group-label { font-size: 13px; color: #606266; font-weight: 500; }
    }
  }
  .promise-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
  .img-cell { display: flex; align-items: center; gap: 10px; }
  .img-preview { display: flex; align-items: center; gap: 10px; }
}

/* CTA-style Preview */
.preview-cta {
  background:
    radial-gradient(800px 400px at 20% 10%, rgba(247, 166, 0, 0.25), transparent 50%),
    linear-gradient(135deg, #132057, #0B1437);
  color: #fff;
  border-radius: 16px;
  padding: 40px 32px;
  text-align: center;

  h2 {
    color: #fff; font-size: 26px; font-weight: 800; margin: 0 0 12px;
    letter-spacing: 0.5px;
  }

  .preview-subtitle {
    color: rgba(255,255,255,0.7);
    max-width: 600px; margin: 0 auto 16px;
    font-size: 15px; line-height: 1.7;
  }

  .preview-promises {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 6px 24px;
    margin-bottom: 2px;
    &:last-of-type { margin-bottom: 28px; }
    .preview-promise {
      font-size: 15px; color: rgba(255,255,255,0.88); font-weight: 600; line-height: 1.9;
      &::before { content: '✓ '; color: #F7A600; font-weight: 700; }
    }
  }

  .preview-btns {
    display: flex; flex-wrap: wrap; gap: 12px; justify-content: center;
  }

  .preview-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px 24px; border-radius: 999px;
    font-weight: 700; font-size: 14px; cursor: pointer;
    transition: all 0.2s; position: relative; text-decoration: none;

    &.primary {
      background: #F7A600; color: #0B1437; border: 1.5px solid transparent;
      &:hover { box-shadow: 0 10px 24px rgba(247, 166, 0, 0.4); }
    }
    &.ghost {
      background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,0.3);
      &:hover { border-color: #F7A600; color: #F7A600; }
    }

    &.has-image:hover .preview-popup { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(4px); }
  }

  .preview-popup {
    position: absolute; top: calc(100% + 10px); left: 50%; transform: translateX(-50%);
    background: #fff; padding: 8px; border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    opacity: 0; visibility: hidden; transition: all .2s; z-index: 10;
    img { display: block; width: 180px; height: 180px; object-fit: cover; border-radius: 6px; }
  }
}
</style>
