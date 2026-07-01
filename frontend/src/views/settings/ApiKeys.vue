<template>
  <div class="api-keys-page">
    <div class="page-header">
      <div>
        <h2 class="page-title">API 密钥管理</h2>
        <p class="page-desc">为第三方合作方/分销商生成访问凭证，可定制加成比例和限流</p>
      </div>
      <el-button type="primary" @click="openDialog()"><el-icon><Plus /></el-icon> 创建 API Key</el-button>
    </div>

    <el-alert type="info" :closable="false" style="margin-bottom: 16px" show-icon>
      <template #title>接口地址</template>
      <div style="font-family: monospace; font-size: 12px">
        GET <strong>{{ apiBase }}/api/public/v1/products</strong> — 商品列表<br>
        GET <strong>{{ apiBase }}/api/public/v1/stock-by-country</strong> — 按国家聚合库存<br>
        GET <strong>{{ apiBase }}/api/public/v1/vip-tiers</strong> — VIP 等级（新）<br>
        请求头：<code>X-API-Key: sk_xxx</code>
      </div>
    </el-alert>

    <el-alert type="warning" :closable="true" style="margin-bottom: 16px" show-icon>
      <template #title>旧 Key 调用 VIP 接口提示</template>
      旧版本创建的 Key 默认只含「商品列表 / 按国家库存」权限，调用新的 VIP 端点会返回 403。
      请点击对应行的「编辑」，勾选「VIP 等级」后保存即可。
    </el-alert>

    <el-card>
      <el-table :data="list" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="name" label="名称" min-width="120" />
        <el-table-column label="API Key" min-width="260">
          <template #default="{ row }">
            <div style="font-family: monospace; font-size: 12px; display: flex; align-items: center; gap: 6px">
              <span>{{ row.key }}</span>
              <el-button link type="primary" size="small" @click="copyText(row.key)"><el-icon><CopyDocument /></el-icon></el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="权限范围" min-width="220">
          <template #default="{ row }">
            <template v-if="Array.isArray(row.scopes) && row.scopes.length">
              <el-tag
                v-for="s in row.scopes"
                :key="s"
                size="small"
                :type="scopeTagType(s)"
                effect="plain"
                style="margin-right: 4px; margin-bottom: 2px"
              >{{ scopeLabel(s) }}</el-tag>
            </template>
            <el-tag v-else size="small" type="success" effect="dark">全部权限</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="价格加成" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="row.price_markup > 1 ? 'warning' : ''" size="small">×{{ row.price_markup }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="限流/分钟" width="90" align="center">
          <template #default="{ row }">{{ row.rate_limit }}</template>
        </el-table-column>
        <el-table-column label="已调用" width="90" align="center">
          <template #default="{ row }">{{ row.request_count }}</template>
        </el-table-column>
        <el-table-column label="最后使用" width="150">
          <template #default="{ row }">
            <div v-if="row.last_used_at" style="font-size: 12px">
              {{ formatDate(row.last_used_at) }}<br>
              <span style="color: #909399">{{ row.last_used_ip }}</span>
            </div>
            <span v-else style="color: #C0C4CC">未使用</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="过期时间" width="110">
          <template #default="{ row }">{{ row.expires_at ? formatDate(row.expires_at) : '永久' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="220" align="center" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDialog(row)">编辑</el-button>
            <el-button link type="warning" size="small" @click="handleRegenerate(row)">重置密钥</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 创建/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑 API Key' : '创建 API Key'" width="560px">
      <el-form :model="form" label-width="110px">
        <el-form-item label="名称" required>
          <el-input v-model="form.name" placeholder="如：分销商A / 合作伙伴X" />
        </el-form-item>
        <el-form-item label="权限范围">
          <el-checkbox-group v-model="form.scopes">
            <el-checkbox value="store.products">商品列表</el-checkbox>
            <el-checkbox value="store.stock">按国家库存</el-checkbox>
            <el-checkbox value="vip.tiers">VIP 等级</el-checkbox>
          </el-checkbox-group>
          <div style="font-size: 12px; color: #909399; margin-top: 4px">
            至少选一项；全部不勾选 = 开放所有接口（仅限内部可信调用）
          </div>
        </el-form-item>
        <el-form-item label="价格加成倍数">
          <el-input-number v-model="form.price_markup" :min="0.1" :max="10" :step="0.1" :precision="2" />
          <span style="margin-left: 8px; font-size: 12px; color: #909399">1.00=原价, 1.20=加价20%, 0.9=九折</span>
        </el-form-item>
        <el-form-item label="限流/分钟">
          <el-input-number v-model="form.rate_limit" :min="10" :max="10000" :step="10" />
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker v-model="form.expires_at" type="datetime" placeholder="留空表示永久" style="width: 100%" />
        </el-form-item>
        <el-form-item v-if="editing" label="启用">
          <el-switch v-model="form.is_active" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 创建成功/重置 Secret 展示弹窗 -->
    <el-dialog v-model="secretVisible" title="凭证信息（请立即保存）" width="520px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" style="margin-bottom: 12px">
        <template #title>Secret 只在此时显示一次！请立即保存到合作方，关闭后无法再次查看。</template>
      </el-alert>
      <el-form label-width="80px">
        <el-form-item label="API Key">
          <el-input :model-value="secretData.key" readonly>
            <template #append><el-button @click="copyText(secretData.key)"><el-icon><CopyDocument /></el-icon></el-button></template>
          </el-input>
        </el-form-item>
        <el-form-item label="Secret">
          <el-input :model-value="secretData.secret" readonly>
            <template #append><el-button @click="copyText(secretData.secret)"><el-icon><CopyDocument /></el-icon></el-button></template>
          </el-input>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" @click="secretVisible = false">我已保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, CopyDocument } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import request from '@/utils/request'

const apiBase = computed(() => {
  const url = import.meta.env.VITE_API_URL || ''
  return url || window.location.origin
})

const loading = ref(false)
const submitting = ref(false)
const list = ref([])
const dialogVisible = ref(false)
const editing = ref(null)
const secretVisible = ref(false)
const secretData = reactive({ key: '', secret: '' })

const form = reactive({
  name: '',
  scopes: ['store.products', 'store.stock'],
  price_markup: 1.00,
  rate_limit: 60,
  is_active: true,
  expires_at: null,
  remark: '',
})

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }

function scopeLabel(s) {
  return { 'store.products': '商品列表', 'store.stock': '按国家库存', 'vip.tiers': 'VIP 等级' }[s] || s
}
function scopeTagType(s) {
  return { 'store.products': '', 'store.stock': 'info', 'vip.tiers': 'warning' }[s] || ''
}

async function copyText(t) {
  try { await navigator.clipboard.writeText(t); ElMessage.success('已复制') }
  catch { ElMessage.warning('复制失败') }
}

async function fetchList() {
  loading.value = true
  try { list.value = (await request.get('/api-keys')) || [] }
  catch {} finally { loading.value = false }
}

function openDialog(row) {
  editing.value = row
  if (row) {
    Object.assign(form, {
      name: row.name,
      scopes: row.scopes || ['store.products', 'store.stock'],
      price_markup: Number(row.price_markup),
      rate_limit: row.rate_limit,
      is_active: row.is_active,
      expires_at: row.expires_at,
      remark: row.remark,
    })
  } else {
    Object.assign(form, {
      name: '', scopes: ['store.products', 'store.stock'],
      price_markup: 1.00, rate_limit: 60, is_active: true, expires_at: null, remark: '',
    })
  }
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!form.name) { ElMessage.warning('请填写名称'); return }
  submitting.value = true
  try {
    if (editing.value) {
      await request.put(`/api-keys/${editing.value.id}`, { ...form })
      ElMessage.success('已更新')
    } else {
      const res = await request.post('/api-keys', { ...form })
      secretData.key = res.key
      secretData.secret = res.secret
      secretVisible.value = true
    }
    dialogVisible.value = false
    fetchList()
  } catch {} finally { submitting.value = false }
}

async function handleDelete(row) {
  try { await ElMessageBox.confirm(`删除 API Key「${row.name}」？合作方将无法继续调用`, '确认', { type: 'warning' }) } catch { return }
  try { await request.delete(`/api-keys/${row.id}`); ElMessage.success('已删除'); fetchList() } catch {}
}

async function handleRegenerate(row) {
  try { await ElMessageBox.confirm(`重置 Secret？旧 Secret 立即失效`, '确认', { type: 'warning' }) } catch { return }
  try {
    const res = await request.post(`/api-keys/${row.id}/regenerate`)
    secretData.key = row.key
    secretData.secret = res.secret
    secretVisible.value = true
    fetchList()
  } catch {}
}

onMounted(fetchList)
</script>

<style lang="scss" scoped>
.api-keys-page {
  .page-header {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;
    .page-title { margin: 0; font-size: 20px; font-weight: 600; color: #2C3E50; }
    .page-desc { color: #909399; margin: 4px 0 0; font-size: 13px; }
  }
}
</style>
