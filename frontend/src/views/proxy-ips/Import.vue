<template>
  <div class="proxy-ip-import">
    <div class="page-header">
      <el-button @click="$router.back()" :icon="ArrowLeft">返回</el-button>
      <h2 class="page-title">批量导入IP</h2>
    </div>

    <el-card>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" style="max-width: 650px">
        <el-form-item label="资产组" prop="asset_group_id">
          <el-select v-model="form.asset_group_id" placeholder="选择资产组（IP归属）" style="width: 100%">
            <el-option v-for="g in assetGroups" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
          <div class="form-tip">对应"IP归属"，如斯帕克、涛哥、木子等，需先在资产组管理中创建</div>
        </el-form-item>

        <el-form-item label="IP组" prop="ip_group_id">
          <el-select v-model="form.ip_group_id" placeholder="选择IP组（可选，用于定价分类）" clearable style="width: 100%">
            <el-option v-for="g in ipGroups" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
          <div class="form-tip">可选，用于按IP组定价。如"双Cogent"、"原生ISP"等</div>
        </el-form-item>

        <el-form-item label="CSV文件" prop="file">
          <el-upload
            ref="uploadRef"
            :auto-upload="false"
            :limit="1"
            :on-change="handleFileChange"
            :on-remove="handleFileRemove"
            accept=".csv"
            drag
          >
            <el-icon class="el-icon--upload"><UploadFilled /></el-icon>
            <div class="el-upload__text">将CSV文件拖到此处，或 <em>点击上传</em></div>
          </el-upload>
        </el-form-item>

        <el-form-item label="模板下载">
          <el-button type="primary" link @click="downloadTemplate">
            <el-icon><Download /></el-icon>下载导入模板（含示例数据）
          </el-button>
        </el-form-item>

        <el-form-item label="字段说明">
          <el-table :data="fieldDocs" size="small" border style="width: 100%">
            <el-table-column prop="field" label="CSV列名" width="130" />
            <el-table-column prop="required" label="必填" width="50" align="center">
              <template #default="{ row }">
                <el-tag :type="row.required ? 'danger' : 'info'" size="small">{{ row.required ? '是' : '否' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="desc" label="说明" />
          </el-table>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" size="large" :loading="importing" @click="handleImport">
            开始导入
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- Import Result -->
    <el-card v-if="importResult" class="result-card">
      <template #header>
        <span class="card-title">导入结果</span>
      </template>
      <el-result
        :icon="importResult.fail_count === 0 ? 'success' : 'warning'"
        :title="importResult.fail_count === 0 ? '导入完成' : '部分导入成功'"
      >
        <template #sub-title>
          <div class="result-stats">
            <p>成功: <strong class="text-success">{{ importResult.success_count }}</strong> 条</p>
            <p>失败: <strong class="text-danger">{{ importResult.fail_count }}</strong> 条</p>
            <p>总计: <strong>{{ importResult.total_count }}</strong> 条</p>
          </div>
        </template>
        <template #extra>
          <el-button type="primary" @click="$router.push('/proxy-ips')">查看IP列表</el-button>
          <el-button @click="importResult = null; selectedFile = null">继续导入</el-button>
        </template>
      </el-result>

      <el-table v-if="importResult.errors?.length" :data="importResult.errors" stripe size="small" style="margin-top: 16px">
        <el-table-column prop="row" label="行" width="60" />
        <el-table-column prop="message" label="错误信息" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ArrowLeft, UploadFilled, Download } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { importProxyIps } from '@/api/proxyIps'
import { getAllAssetGroups } from '@/api/assetGroups'
import { getAllIpGroups } from '@/api/ipGroups'

const formRef = ref(null)
const importing = ref(false)
const importResult = ref(null)
const assetGroups = ref([])
const ipGroups = ref([])
const selectedFile = ref(null)

const form = reactive({
  asset_group_id: null,
  ip_group_id: null,
})

const rules = {
  asset_group_id: [{ required: true, message: '请选择资产组', trigger: 'change' }],
}

const fieldDocs = [
  { field: 'socks5', required: true, desc: 'socks5连接串: IP:端口:用户名:密码' },
  { field: 'asset_name', required: true, desc: '资产名称，如"轻语-巴西-200.234.165.249"' },
  { field: 'country', required: true, desc: '地区/国家名称，如"巴西"、"墨西哥"、"美国"' },
  { field: 'customer_name', required: false, desc: '客户名称，填写后自动匹配或创建客户并分配IP' },
  { field: 'expires_at', required: false, desc: '到期时间，格式 2026-03-26 或 3.26（默认2026年）' },
  { field: 'sales_person', required: false, desc: '业务归属，如"陈小同"' },
  { field: 'source_name', required: false, desc: 'IP归属名称（覆盖资产组），如"斯帕克"、"涛哥"' },
  { field: 'remark', required: false, desc: '备注信息' },
]

function handleFileChange(file) { selectedFile.value = file.raw }
function handleFileRemove() { selectedFile.value = null }

function downloadTemplate() {
  const header = 'socks5,asset_name,country,customer_name,expires_at,sales_person,source_name,remark'
  const rows = [
    '200.234.165.249:34768:5A2jzDA200234165249A34768:3xwGBkGxGnQV,轻语-巴西-200.234.165.249,巴西,轻语,2026-03-26,陈小同,斯帕克,',
    '154.197.90.154:9263:y7T1j0B6e5K1:D9s1X9D2q5Z3,我会走狗屎运-墨西哥-154.197.90.154,墨西哥,我会走狗屎运,2026-04-26,陈小同,涛哥,',
    '45.170.250.71:1337:o2bm7bjn567x:77wsghh1zv6s,墨西哥-45.170.250.71,墨西哥,,2026-04-02,陈小同,木子,橱窗号',
  ]
  const content = [header, ...rows].join('\n')
  const blob = new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'IP导入模板.csv'
  link.click()
}

async function handleImport() {
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  if (!selectedFile.value) { ElMessage.warning('请选择CSV文件'); return }

  importing.value = true
  try {
    const fd = new FormData()
    fd.append('file', selectedFile.value)
    fd.append('asset_group_id', form.asset_group_id)
    if (form.ip_group_id) fd.append('ip_group_id', form.ip_group_id)
    const res = await importProxyIps(fd)
    importResult.value = res
    ElMessage.success('导入完成')
  } catch { /* handled */ }
  finally { importing.value = false }
}

onMounted(async () => {
  try {
    const [ag, ig] = await Promise.all([getAllAssetGroups(), getAllIpGroups()])
    assetGroups.value = Array.isArray(ag) ? ag : []
    ipGroups.value = Array.isArray(ig) ? ig : []
  } catch { /* handled */ }
})
</script>

<style lang="scss" scoped>
.proxy-ip-import {
  .page-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
  }
  .form-tip { font-size: 12px; color: #909399; margin-top: 4px; }
  .result-card {
    margin-top: 16px;
    .card-title { font-weight: 600; }
    .result-stats {
      p { margin: 4px 0; font-size: 14px; }
      .text-success { color: #67c23a; }
      .text-danger { color: #f56c6c; }
    }
  }
}
</style>
