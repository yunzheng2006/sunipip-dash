<template>
  <div class="my-ips-page">
    <div class="page-head">
      <div>
        <h1 class="page-title">我的 IP 资产</h1>
        <p class="page-sub">共 <strong>{{ pagination.total }}</strong> 条 IP</p>
      </div>
      <div class="head-actions">
        <div v-if="selectedIds.length" class="selection-hint">
          已选 <strong>{{ selectedIds.length }}</strong> 条
          <el-button type="warning" size="small" @click="openBatchRenew" :disabled="selectedActiveCount === 0">
            批量续费 ({{ selectedActiveCount }})
          </el-button>
          <el-dropdown v-if="ipGroups.length" trigger="click" @command="handleAddToGroup" size="small">
            <el-button size="small" plain>添加到分组 <el-icon style="margin-left:2px"><ArrowDown /></el-icon></el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item v-for="g in ipGroups" :key="g.id" :command="g.id">{{ g.name }}</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <el-button link type="primary" size="small" @click="clearSelection">取消选择</el-button>
        </div>
        <el-dropdown trigger="click" @command="onExport">
          <el-button type="primary" plain :loading="exporting">
            <el-icon><Download /></el-icon>
            {{ selectedIds.length ? `导出选中 (${selectedIds.length})` : '导出全部' }}
            <el-icon style="margin-left: 4px"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="qr">
                <el-icon><Picture /></el-icon> Excel表格 (含二维码)
              </el-dropdown-item>
              <el-dropdown-item command="socks5" divided>
                <el-icon><Document /></el-icon> 文本文档 (ip:port:user:pass)
              </el-dropdown-item>
              <el-dropdown-item command="csv">
                <el-icon><Grid /></el-icon> CSV 表格
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <el-alert v-if="pendingOrders > 0" type="info" :closable="false" show-icon style="margin-bottom: 12px; border-radius: 10px">
      <template #title>
        有 <strong>{{ pendingOrders }}</strong> 条订单正在开通中（约 1-3 分钟），页面将自动刷新
      </template>
    </el-alert>

    <el-card class="filter-card" shadow="never">
      <el-form :inline="true" :model="searchForm">
        <el-form-item>
          <el-input
            v-model="searchForm.keyword"
            placeholder="搜索 资产名 / IP"
            clearable
            :prefix-icon="Search"
            style="width: 240px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-input v-model="searchForm.country" placeholder="地区 如：美国/USA" clearable style="width: 160px" @keyup.enter="handleSearch" @clear="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.product_type" placeholder="产品类型" clearable style="width: 130px" @change="handleSearch">
            <el-option label="静态IP" value="static" />
            <template v-if="forwardCertified">
              <el-option label="IPLC视频专线" value="video" />
              <el-option label="IPLC直播专线(手机)" value="live_mobile" />
              <el-option label="IPLC直播专线(电脑)" value="live_pc" />
            </template>
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="searchForm.sort" placeholder="排序方式" style="width: 150px" @change="handleSearch">
            <el-option label="即将到期" value="expires_asc" />
            <el-option label="最早开通" value="created_asc" />
            <el-option label="最近开通" value="created_desc" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="margin-left:auto">
          <SmsNotifyToggle />
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 分组栏 -->
    <div class="group-bar">
      <div class="group-tabs">
        <button class="group-tab" :class="{ active: !activeGroupId }" @click="switchGroup(null)">
          全部
        </button>
        <button
          v-for="g in ipGroups" :key="g.id"
          class="group-tab" :class="{ active: activeGroupId === g.id }"
          @click="switchGroup(g.id)"
          @contextmenu.prevent="openGroupMenu($event, g)"
        >
          {{ g.name }}
          <span v-if="g.proxy_ips_count != null" class="group-count">{{ g.proxy_ips_count }}</span>
        </button>
        <button class="group-tab group-tab--add" @click="openCreateGroup">
          <el-icon :size="14"><Plus /></el-icon> 新建分组
        </button>
      </div>
      <div v-if="activeGroupId && selectedIds.length" class="group-action">
        <el-button type="danger" size="small" plain @click="removeFromCurrentGroup">移出当前分组</el-button>
      </div>
    </div>

    <!-- 分组右键菜单 -->
    <div v-if="groupMenuVisible" class="group-context-menu" :style="groupMenuStyle" @mouseleave="groupMenuVisible = false">
      <div class="ctx-item" @click="startRenameGroup">重命名</div>
      <div class="ctx-item ctx-danger" @click="confirmDeleteGroup">删除分组</div>
    </div>

    <!-- ===== 桌面端：表格 ===== -->
    <el-card shadow="never" class="desktop-table">
      <el-table
        ref="tableRef"
        :data="tableData"
        v-loading="loading"
        stripe
        @selection-change="onSelectionChange"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="55" />
        <el-table-column label="产品类型" width="95" align="center">
          <template #default="{ row }">
            {{ productTypeLabel(row) }}
          </template>
        </el-table-column>
        <el-table-column label="资产名称" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.asset_name || '-' }}</div>
            <div class="country-tag">{{ row.country_name || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="备注" width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="remark-inline" @click.stop="startEditRemark(row)">
              <template v-if="editingRemarkId === row.active_subscription?.id">
                <el-input
                  ref="remarkInputRef"
                  v-model="editingRemarkValue"
                  size="small"
                  placeholder="备注..."
                  @blur="saveRemark(row)"
                  @keyup.enter="$event.target.blur()"
                />
              </template>
              <template v-else>
                <span v-if="row.active_subscription?.customer_remark" class="remark-text has">{{ row.active_subscription.customer_remark }}</span>
                <span v-else class="remark-text empty">+ 备注</span>
              </template>
            </div>
          </template>
        </el-table-column>

        <template v-if="forwardCertified">
          <el-table-column label="中转后Socks5 IP" min-width="250">
            <template #default="{ row }">
              <template v-if="forwardOf(row)">
                <div class="creds forwarded-premium">
                  <span class="premium-badge">专线</span>
                  <span class="mono cred-text">{{ resolveConnection(row).socks5 }}</span>
                  <el-button link size="small" class="copy-btn-fwd" @click="copyText(resolveConnection(row).socks5)">复制</el-button>
                </div>
                <div class="creds sub-link">
                  <el-button link size="small" @click="copyText(buildForwardSocks5Url(row))"><el-icon><Link /></el-icon> 复制订阅链接</el-button>
                </div>
              </template>
              <div v-else-if="forwardPending(row)" class="pending-forward">
                <el-icon class="pending-spin"><Loading /></el-icon>
                <span>专线开通中，请稍等...</span>
              </div>
              <div v-else-if="canUpgrade(row)" class="upgrade-cell">
                <el-button type="primary" plain size="small" @click="openUpgrade(row)">
                  <el-icon><TopRight /></el-icon> 升级到视频专线
                </el-button>
              </div>
              <span v-else class="empty-hint">-</span>
            </template>
          </el-table-column>
          <el-table-column label="中转后二维码" width="90" align="center">
            <template #default="{ row }">
              <QrCell v-if="forwardOf(row)" :text="buildForwardSocks5Url(row)" />
              <div v-else-if="forwardPending(row)" class="pending-qr">
                <el-icon class="pending-spin" :size="16"><Loading /></el-icon>
              </div>
              <span v-else class="empty-hint">-</span>
            </template>
          </el-table-column>
        </template>

        <el-table-column :label="forwardCertified ? '原Socks5' : '连接串'" min-width="180">
          <template #default="{ row }">
            <div :class="{ 'source-dimmed': forwardCertified && forwardOf(row) }">
              <div class="creds">
                <span class="mono cred-text" style="font-size:11px; word-break:break-all; line-height:1.3">{{ buildSocks5(row) }}</span>
                <el-button link type="primary" size="small" @click="copyText(buildSocks5(row))">复制</el-button>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="源二维码" width="80" align="center">
          <template #default="{ row }">
            <div :class="{ 'qr-dimmed': forwardCertified && forwardOf(row) }">
              <QrCell :text="buildSocks5Url(row)" />
            </div>
          </template>
        </el-table-column>

        <el-table-column label="购买时间" width="105">
          <template #default="{ row }">
            <span style="font-size:12px">{{ formatDateTime(row.active_subscription?.started_at) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="到期时间" width="105">
          <template #default="{ row }">
            <span :style="{ color: daysToExpire(subExpiresAt(row)) <= 7 ? '#F56C6C' : '', fontSize: '12px' }">
              {{ formatDateTime(subExpiresAt(row)) }}
            </span>
            <div style="font-size: 11px; color: #94A3B8">
              剩 {{ daysToExpire(subExpiresAt(row)) }} 天
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="70" align="center">
          <template #default="{ row }">
            <el-button
              v-if="row.active_subscription?.status === 'active'"
              type="warning" link size="small"
              @click="openRenewDialog(row)"
            >续费</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>

    <!-- ===== 手机端：卡片列表 ===== -->
    <div class="mobile-cards" v-loading="loading">
      <div v-if="!loading && tableData.length === 0" class="mobile-empty">暂无数据</div>
      <div v-for="row in tableData" :key="row.id" class="ip-card">
        <div class="ip-card-head">
          <div class="ip-card-name">
            <span class="name-text">{{ row.asset_name || '-' }}</span>
            <span class="country-badge">{{ row.country_name || '-' }}</span>
          </div>
          <div class="ip-card-expiry" :class="{ urgent: daysToExpire(subExpiresAt(row)) <= 7 }">
            {{ formatDate(subExpiresAt(row)) }} · 剩 {{ daysToExpire(subExpiresAt(row)) }} 天
          </div>
        </div>

        <!-- 中转后连接（优先） -->
        <template v-if="forwardCertified && forwardOf(row)">
          <div class="ip-card-section forwarded-premium-m">
            <div class="section-label"><span class="premium-badge">专线</span> 中转后连接</div>
            <div class="conn-row">
              <span class="mono conn-text">{{ resolveConnection(row).socks5 }}</span>
              <el-button size="small" type="primary" @click="copyText(resolveConnection(row).socks5)">复制</el-button>
            </div>
            <div class="conn-actions">
              <el-button link size="small" @click="copyText(buildForwardSocks5Url(row))"><el-icon><Link /></el-icon> 订阅链接</el-button>
            </div>
            <div class="qr-row">
              <QrCell :text="buildForwardSocks5Url(row)" />
            </div>
          </div>
        </template>

        <!-- 专线开通中 -->
        <div v-else-if="forwardCertified && forwardPending(row)" class="ip-card-section pending-forward-m">
          <div class="section-label"><span class="premium-badge">专线</span> 中转后连接</div>
          <div class="pending-forward">
            <el-icon class="pending-spin"><Loading /></el-icon>
            <span>专线开通中，请稍等...</span>
          </div>
        </div>

        <!-- 升级按钮（静态IP，无转发，需中转权限） -->
        <div v-else-if="forwardCertified && canUpgrade(row)" class="ip-card-section upgrade-section-m">
          <el-button type="primary" plain @click="openUpgrade(row)" style="width: 100%">
            <el-icon><TopRight /></el-icon> 升级到 IPLC 视频专线
          </el-button>
        </div>

        <!-- 源连接 -->
        <div class="ip-card-section" :class="{ 'source-dimmed-m': forwardCertified && forwardOf(row) }">
          <div class="section-label">{{ forwardCertified && forwardOf(row) ? '原始连接' : '连接信息' }}</div>
          <div class="conn-row">
            <span class="mono conn-text">{{ buildSocks5(row) }}</span>
            <el-button size="small" :type="forwardCertified && forwardOf(row) ? 'default' : 'primary'" @click="copyText(buildSocks5(row))">复制</el-button>
          </div>
          <div class="conn-actions">
            <el-button link size="small" @click="copyText(buildSocks5Url(row))"><el-icon><Link /></el-icon> 订阅链接</el-button>
          </div>
          <div v-if="!(forwardCertified && forwardOf(row))" class="qr-row">
            <QrCell :text="buildSocks5Url(row)" />
          </div>
        </div>
      </div>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.per_page"
          :total="pagination.total"
          :page-sizes="[10, 20, 50]"
          layout="total, prev, pager, next"
          size="small"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </div>

    <!-- 创建/重命名分组弹窗 -->
    <el-dialog v-model="groupDialogVisible" :title="groupEditing ? '重命名分组' : '新建分组'" width="360px" :close-on-click-modal="false">
      <el-input v-model="groupDialogName" placeholder="输入分组名称" maxlength="50" @keyup.enter="submitGroupDialog" />
      <template #footer>
        <el-button @click="groupDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="groupDialogLoading" @click="submitGroupDialog">确认</el-button>
      </template>
    </el-dialog>

    <!-- 续费弹窗 -->
    <el-dialog v-model="renewDialogVisible" title="续费" width="400px" :close-on-click-modal="false">
      <div v-if="renewTarget" style="margin-bottom: 16px">
        <div style="font-weight: 500; margin-bottom: 8px">{{ renewTarget.asset_name || renewTarget.ip_address }}</div>
        <div style="font-size: 13px; color: #909399">
          到期时间：{{ formatDate(renewTarget.active_subscription?.expires_at) }}
          · 剩余 {{ daysToExpire(renewTarget.active_subscription?.expires_at) }} 天
        </div>
      </div>
      <el-form label-width="80px">
        <el-form-item label="续费时长">
          <el-select v-model="renewDuration" style="width: 100%">
            <el-option v-for="n in 12" :key="n" :label="`${n} 个月`" :value="n" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="renewDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="renewLoading" @click="submitRenew">确认续费</el-button>
      </template>
    </el-dialog>

    <!-- 批量续费弹窗 -->
    <el-dialog v-model="batchRenewVisible" title="批量续费" width="450px" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        将为选中的 <strong>{{ batchRenewIds.length }}</strong> 条 IP 逐一续费，每条 1 月 = 30 天
      </el-alert>
      <el-form label-width="80px">
        <el-form-item label="续费时长">
          <el-select v-model="batchRenewDuration" style="width: 100%">
            <el-option v-for="n in 12" :key="n" :label="`${n} 个月（${n * 30} 天）`" :value="n" />
          </el-select>
        </el-form-item>
      </el-form>
      <div v-if="batchRenewResults.length" style="margin-top: 12px; max-height: 200px; overflow-y: auto">
        <div v-for="r in batchRenewResults" :key="r.id" style="font-size: 13px; margin-bottom: 4px">
          <el-icon v-if="r.ok" style="color:#67C23A"><SuccessFilled /></el-icon>
          <el-icon v-else style="color:#F56C6C"><CircleCloseFilled /></el-icon>
          {{ r.name }} — {{ r.msg }}
        </div>
      </div>
      <template #footer>
        <el-button @click="batchRenewVisible = false">{{ batchRenewDone ? '关闭' : '取消' }}</el-button>
        <el-button v-if="!batchRenewDone" type="primary" :loading="batchRenewLoading" @click="submitBatchRenew">
          确认批量续费
        </el-button>
      </template>
    </el-dialog>

    <!-- 升级视频专线弹窗 -->
    <UpgradeForwardDialog
      v-model="upgradeDialogVisible"
      :subscription-id="upgradeSubId"
      @upgraded="onUpgraded"
    />

    <!-- 导出排序确认弹窗 -->
    <el-dialog
      v-model="exportDialogVisible"
      title="导出设置"
      width="480px"
      :close-on-click-modal="false"
    >
      <div class="export-info">
        <el-icon :size="14"><Download /></el-icon>
        <span>
          {{ selectedIds.length ? `导出选中 ${selectedIds.length} 条` : '导出全部' }}
          · 格式：{{ formatLabel(exportPendingFormat) }}
        </span>
      </div>

      <div class="sort-section">
        <div class="sort-title">排列顺序</div>
        <el-radio-group v-model="exportSortBy" class="sort-radio-group">
          <div
            v-for="opt in sortOptions"
            :key="opt.value"
            class="sort-option"
            :class="{ active: exportSortBy === opt.value }"
            @click="exportSortBy = opt.value"
          >
            <el-radio :value="opt.value">
              <span class="sort-label">{{ opt.label }}</span>
              <span class="sort-desc">{{ opt.desc }}</span>
            </el-radio>
          </div>
        </el-radio-group>
      </div>

      <template #footer>
        <el-button @click="exportDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="exporting" @click="confirmExport">
          确认导出
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, Download, Document, Grid, ArrowDown, Share, Picture, Link, TopRight, Loading, SuccessFilled, CircleCloseFilled, Plus } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import { getMyIps, exportMyIps, exportMyIpsQr, getIpGroups, createIpGroup, updateIpGroup, deleteIpGroup, addIpsToGroup, removeIpsFromGroup } from '@/api/ips'
import { updateSubscriptionRemark, renewSubscription } from '@/api/subscriptions'
import { resolveConnection } from '@/utils/forward'
import { useAuthStore } from '@/stores/auth'
import QrCell from '@/components/QrCell.vue'
import UpgradeForwardDialog from '@/components/UpgradeForwardDialog.vue'
import SmsNotifyToggle from '@/components/SmsNotifyToggle.vue'

const route = useRoute()
const authStore = useAuthStore()

const loading = ref(false)
const exporting = ref(false)
const tableData = ref([])
const tableRef = ref()
const selectedRows = ref([])
const selectedIds = computed(() => selectedRows.value.map(r => r.id))
const selectedActiveCount = computed(() => selectedRows.value.filter(r => r.active_subscription?.status === 'active').length)
const searchForm = reactive({ keyword: route.query.keyword || '', country: '', product_type: '', sort: '' })
const pagination = reactive({ page: 1, per_page: 20, total: 0 })
const pendingOrders = ref(0)
const remarkInputRef = ref(null)
const editingRemarkId = ref(null)
const editingRemarkValue = ref('')
const editingRemarkRow = ref(null)

// ===== IP 分组 =====
const ipGroups = ref([])
const activeGroupId = ref(null)

const groupDialogVisible = ref(false)
const groupDialogName = ref('')
const groupDialogLoading = ref(false)
const groupEditing = ref(null)

const groupMenuVisible = ref(false)
const groupMenuStyle = ref({})
const groupMenuTarget = ref(null)

async function fetchGroups() {
  try { ipGroups.value = (await getIpGroups()) || [] } catch {}
}

function switchGroup(gid) {
  if (activeGroupId.value === gid) return
  activeGroupId.value = gid
  pagination.page = 1
  fetchData()
}

function openCreateGroup() {
  groupEditing.value = null
  groupDialogName.value = ''
  groupDialogVisible.value = true
}

function openGroupMenu(e, group) {
  groupMenuTarget.value = group
  groupMenuStyle.value = { left: e.clientX + 'px', top: e.clientY + 'px' }
  groupMenuVisible.value = true
}

function startRenameGroup() {
  groupMenuVisible.value = false
  groupEditing.value = groupMenuTarget.value
  groupDialogName.value = groupMenuTarget.value.name
  groupDialogVisible.value = true
}

async function confirmDeleteGroup() {
  groupMenuVisible.value = false
  const g = groupMenuTarget.value
  try {
    await ElMessageBox.confirm(`删除分组「${g.name}」？IP 不会被删除，仅移出分组。`, '确认', { type: 'warning' })
    await deleteIpGroup(g.id)
    ElMessage.success('分组已删除')
    if (activeGroupId.value === g.id) activeGroupId.value = null
    fetchGroups()
    fetchData()
  } catch {}
}

async function submitGroupDialog() {
  const name = groupDialogName.value.trim()
  if (!name) { ElMessage.warning('请输入分组名称'); return }
  groupDialogLoading.value = true
  try {
    if (groupEditing.value) {
      await updateIpGroup(groupEditing.value.id, { name })
      ElMessage.success('已重命名')
    } else {
      await createIpGroup({ name })
      ElMessage.success('分组已创建')
    }
    groupDialogVisible.value = false
    fetchGroups()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  } finally { groupDialogLoading.value = false }
}

async function handleAddToGroup(groupId) {
  const ids = selectedRows.value.map(r => r.id)
  if (!ids.length) return
  try {
    const res = await addIpsToGroup(groupId, ids)
    ElMessage.success(res?.message || '已添加')
    fetchGroups()
    clearSelection()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

async function removeFromCurrentGroup() {
  if (!activeGroupId.value) return
  const ids = selectedRows.value.map(r => r.id)
  if (!ids.length) return
  try {
    await removeIpsFromGroup(activeGroupId.value, ids)
    ElMessage.success('已从分组移出')
    fetchGroups()
    fetchData()
    clearSelection()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

function startEditRemark(row) {
  const sub = row.active_subscription
  if (!sub) return
  if (editingRemarkId.value && editingRemarkId.value !== sub.id) {
    doSaveRemark()
  }
  editingRemarkId.value = sub.id
  editingRemarkValue.value = sub.customer_remark || ''
  editingRemarkRow.value = row
  nextTick(() => remarkInputRef.value?.focus())
}

function saveRemark(row) {
  editingRemarkRow.value = row
  doSaveRemark()
}

async function doSaveRemark() {
  const row = editingRemarkRow.value
  const sub = row?.active_subscription
  if (!sub) return
  const newVal = editingRemarkValue.value.trim()
  const oldVal = sub.customer_remark || ''
  editingRemarkId.value = null
  editingRemarkRow.value = null
  if (newVal === oldVal) return
  try {
    await updateSubscriptionRemark(sub.id, newVal || null)
    sub.customer_remark = newVal || null
  } catch {
    ElMessage.error('备注保存失败')
  }
}

// 从 auth store 读取 forward_certified 状态
const forwardCertified = computed(() => !!authStore.customer?.forward_certified)

// 升级到视频专线
const upgradeDialogVisible = ref(false)
const upgradeSubId = ref(null)

function canUpgrade(row) {
  const sub = row.active_subscription
  if (!sub || sub.status !== 'active') return false
  const module = sub.forward_rule?.forward_plan?.module
  return !module
}

function openUpgrade(row) {
  upgradeSubId.value = row.active_subscription?.id
  upgradeDialogVisible.value = true
}

function onUpgraded() {
  fetchData()
}

// 续费
const renewDialogVisible = ref(false)
const renewTarget = ref(null)
const renewDuration = ref(1)
const renewLoading = ref(false)

function openRenewDialog(row) {
  renewTarget.value = row
  renewDuration.value = 1
  renewDialogVisible.value = true
}

async function submitRenew() {
  if (!renewTarget.value?.active_subscription?.id) return
  renewLoading.value = true
  try {
    await renewSubscription(renewTarget.value.active_subscription.id, { duration: renewDuration.value, unit: 3 })
    ElMessage.success('续费成功')
    renewDialogVisible.value = false
    authStore.fetchMe()
    fetchData()
  } catch (e) {
    ElMessage.error(e?.response?.data?.message || '续费失败')
  } finally { renewLoading.value = false }
}

// 批量续费
const batchRenewVisible = ref(false)
const batchRenewDuration = ref(1)
const batchRenewLoading = ref(false)
const batchRenewDone = ref(false)
const batchRenewIds = ref([])
const batchRenewResults = ref([])

function openBatchRenew() {
  const active = selectedRows.value.filter(r => r.active_subscription?.status === 'active')
  if (!active.length) { ElMessage.warning('没有可续费的IP'); return }
  batchRenewIds.value = active.map(r => ({ id: r.active_subscription.id, name: r.asset_name || r.ip_address }))
  batchRenewDuration.value = 1
  batchRenewResults.value = []
  batchRenewDone.value = false
  batchRenewVisible.value = true
}

async function submitBatchRenew() {
  batchRenewLoading.value = true
  batchRenewResults.value = []
  for (const item of batchRenewIds.value) {
    try {
      await renewSubscription(item.id, { duration: batchRenewDuration.value, unit: 3 })
      batchRenewResults.value.push({ id: item.id, name: item.name, ok: true, msg: '续费成功' })
    } catch (e) {
      batchRenewResults.value.push({ id: item.id, name: item.name, ok: false, msg: e?.response?.data?.message || '失败' })
    }
  }
  batchRenewLoading.value = false
  batchRenewDone.value = true
  authStore.fetchMe()
  fetchData()
  clearSelection()
}

function onSelectionChange(rows) {
  selectedRows.value = rows
}

function clearSelection() {
  tableRef.value?.clearSelection()
  selectedRows.value = []
}

function formatDate(d) { return d ? dayjs(d).format('YYYY-MM-DD') : '-' }
function formatDateTime(d) { return d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '-' }
function daysToExpire(d) { return d ? Math.max(0, dayjs(d).diff(dayjs(), 'day')) : 0 }
function subExpiresAt(row) { return row.active_subscription?.expires_at || row.upstream_expires_at }
function statusTag(s) { return { available: 'success', assigned: 'success', expired: 'danger' }[s] || 'info' }
function statusLabel(s) { return { available: '可用', assigned: '使用中', expired: '已过期' }[s] || s }

function productTypeLabel(row) {
  const m = row.active_subscription?.purchased_module
    || row.active_subscription?.forward_rule?.forward_plan?.module
  if (m === 'video') return 'IPLC视频专线'
  if (m === 'live_mobile') return 'IPLC直播专线'
  if (m === 'live_pc') return 'IPLC直播专线'
  return '静态IP'
}

function forwardPending(row) {
  const fr = row.active_subscription?.forward_rule
  if (!fr) return false
  return fr.status === 'pending' || fr.status === 'processing'
}

function buildSocks5(row) {
  return [row.ip_address, row.port, row.auth_username, row.auth_password].filter(Boolean).join(':')
}

function buildSocksUri(user, pass, host, port, remark) {
  if (!host || !port) return ''
  const authB64 = btoa(`${user || ''}:${pass || ''}`)
  return `socks://${encodeURIComponent(authB64)}@${host}:${port}#${encodeURIComponent(remark)}`
}

function buildSocks5Url(row) {
  if (!row?.ip_address || !row?.port) return ''
  return buildSocksUri(
    row.auth_username, row.auth_password,
    row.ip_address, row.port,
    row.asset_name || `${row.ip_address}:${row.port}`
  )
}

function buildForwardSocks5Url(row) {
  const c = resolveConnection(row)
  if (!c.is_forwarded) return ''
  return buildSocksUri(
    c.username, c.password,
    c.host, c.port,
    row.asset_name || `${c.host}:${c.port}`
  )
}

function forwardOf(row) {
  const c = resolveConnection(row)
  return c.is_forwarded ? c : null
}

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.page, per_page: pagination.per_page }
    if (searchForm.keyword) params.keyword = searchForm.keyword
    if (searchForm.country) params.country = searchForm.country
    if (searchForm.product_type) params.product_type = searchForm.product_type
    if (searchForm.sort) params.sort = searchForm.sort
    if (activeGroupId.value) params.group_id = activeGroupId.value
    const res = await getMyIps(params)
    tableData.value = res?.items || []
    pagination.total = res?.pagination?.total || 0
    pendingOrders.value = res?.pending_orders || 0
    startPollingIfNeeded()
  } catch {}
  finally { loading.value = false }
}

function handleSearch() { pagination.page = 1; fetchData() }
function handleReset() {
  searchForm.keyword = ''
  searchForm.country = ''
  searchForm.product_type = ''
  searchForm.sort = ''
  pagination.page = 1
  fetchData()
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
}

function formatLabel(f) {
  return { qr: 'Excel表格', socks5: '文本文档', csv: 'CSV 表格' }[f] || f
}

// ========== 导出排序确认 ==========
const exportDialogVisible = ref(false)
const exportPendingFormat = ref('')
const exportSortBy = ref('id')

const sortOptions = [
  { value: 'id', label: '按导入顺序（推荐）', desc: '和导入系统时的原始表格顺序一致' },
  { value: 'country', label: '按国家/地区分组', desc: '相同国家排在一起' },
  { value: 'asset_name', label: '按资产名称', desc: '按名称字母排序' },
  { value: 'expires', label: '按到期时间', desc: '即将到期的排前面' },
]

function onExport(format) {
  exportPendingFormat.value = format
  exportSortBy.value = 'id'
  exportDialogVisible.value = true
}

function sortRows(rows, by) {
  const sorted = [...rows]
  switch (by) {
    case 'id':
      sorted.sort((a, b) => a.id - b.id)
      break
    case 'country':
      sorted.sort((a, b) =>
        (a.country_name || '').localeCompare(b.country_name || '') || a.id - b.id
      )
      break
    case 'asset_name':
      sorted.sort((a, b) =>
        (a.asset_name || '').localeCompare(b.asset_name || '') || a.id - b.id
      )
      break
    case 'expires':
      sorted.sort((a, b) => {
        const da = subExpiresAt(a) ? new Date(subExpiresAt(a)).getTime() : Infinity
        const db = subExpiresAt(b) ? new Date(subExpiresAt(b)).getTime() : Infinity
        return da - db || a.id - b.id
      })
      break
  }
  return sorted
}

async function confirmExport() {
  exportDialogVisible.value = false
  const format = exportPendingFormat.value
  const hasSelection = selectedRows.value.length > 0

  exporting.value = true
  try {
    let blob, filename
    const d = dayjs()
    const dateStr = `${d.year()}.${d.month() + 1}.${d.date()}`
    const rand = String(Math.floor(Math.random() * 900) + 100)
    const prefix = `SunIPIP.com-${dateStr}-${rand}`

    if (format === 'qr') {
      ElMessage.info(`正在生成Excel表格${hasSelection ? `（${selectedRows.value.length} 条）` : '（全部）'}...`)
      const params = hasSelection
        ? { ids: selectedIds.value.join(','), sort: exportSortBy.value }
        : { sort: exportSortBy.value }
      blob = await exportMyIpsQr(params)
      filename = `${prefix}.xlsx`
    } else if (hasSelection) {
      const rows = sortRows(selectedRows.value, exportSortBy.value)
      let content = ''
      if (format === 'csv') {
        content = '资产名,IP,端口,用户名,密码,地区,到期时间\n'
        content += rows.map(r => {
          const c = resolveConnection(r)
          return [
            r.asset_name || '', c.host || '', c.port || '',
            c.username || '', c.password || '',
            r.country_name || '', subExpiresAt(r) ? dayjs(subExpiresAt(r)).format('YYYY-MM-DD') : '',
          ].map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')
        }).join('\n')
      } else {
        content = rows.map(r => {
          const c = resolveConnection(r)
          return [c.host, c.port, c.username, c.password].filter(Boolean).join(':')
        }).join('\n')
      }
      blob = new Blob([content], { type: format === 'csv' ? 'text/csv' : 'text/plain' })
      filename = `${prefix}.${format === 'csv' ? 'csv' : 'txt'}`
    } else {
      blob = await exportMyIps(format, exportSortBy.value)
      filename = `${prefix}.${format === 'csv' ? 'csv' : 'txt'}`
    }

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`导出成功${hasSelection ? `（${selectedRows.value.length} 条）` : ''}`)
  } catch (e) {
    ElMessage.error('导出失败' + (e?.message ? ': ' + e.message : ''))
  } finally {
    exporting.value = false
  }
}

let pollTimer = null

function hasPendingItems() {
  if (pendingOrders.value > 0) return true
  return tableData.value.some(row => forwardPending(row))
}

function startPollingIfNeeded() {
  stopPolling()
  if (hasPendingItems()) {
    pollTimer = setInterval(async () => {
      await fetchData()
      if (!hasPendingItems()) stopPolling()
    }, 10000)
  }
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

onBeforeUnmount(() => stopPolling())

onMounted(async () => {
  if (!authStore.customer) await authStore.fetchMe()
  await Promise.all([fetchData(), fetchGroups()])
  startPollingIfNeeded()
})
</script>

<style lang="scss" scoped>
$brand: #4F6AF6;
$brand-light: #EEF1FE;
$accent: #F5A623;
$text-primary: #1E293B;
$text-muted: #94A3B8;
$border: #E2E8F0;

.my-ips-page { display: flex; flex-direction: column; gap: 16px; }
.page-head {
  display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px;
  .page-title { margin: 0 0 4px; font-size: 22px; font-weight: 700; color: $text-primary; }
  .page-sub { margin: 0; font-size: 13px; color: $text-muted; strong { color: $brand; } }
  .head-actions { display: flex; align-items: center; gap: 12px; }
  .selection-hint {
    font-size: 13px; color: $brand;
    padding: 6px 14px; background: $brand-light;
    border: 1px solid #C5CDFC; border-radius: 8px;
    strong { font-size: 16px; font-weight: 700; margin: 0 2px; }
  }
}
.filter-card {
  border-radius: 10px; border: 1px solid $border;
  :deep(.el-card__body) { padding: 12px 18px 2px; }
}

.group-bar {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 0 2px;
  .group-tabs {
    display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
  }
  .group-tab {
    padding: 5px 14px; border-radius: 16px; border: 1px solid $border;
    background: #fff; font-size: 13px; color: #475569; cursor: pointer;
    transition: all .15s; white-space: nowrap;
    &:hover { border-color: $brand; color: $brand; }
    &.active { background: $brand; color: #fff; border-color: $brand; font-weight: 500; }
    .group-count {
      font-size: 11px; margin-left: 4px; padding: 0 5px; border-radius: 8px;
      background: rgba(0,0,0,.08); vertical-align: middle;
    }
    &.active .group-count { background: rgba(255,255,255,.25); }
    &--add {
      padding: 5px 14px; border-style: dashed; color: $text-muted;
      display: inline-flex; align-items: center; gap: 3px; font-size: 12px;
      &:hover { border-color: $brand; color: $brand; background: $brand-light; }
    }
  }
}

.group-context-menu {
  position: fixed; z-index: 9999; background: #fff; border: 1px solid $border;
  border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,.12); padding: 4px 0; min-width: 120px;
  .ctx-item {
    padding: 8px 16px; font-size: 13px; cursor: pointer; color: #334155;
    &:hover { background: #F1F5F9; }
    &.ctx-danger { color: #EF4444; &:hover { background: #FEF2F2; } }
  }
}
.mono { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 12px; color: #475569; }
.country-tag { font-size: 11px; color: $text-muted; margin-top: 2px; }

.remark-inline {
  cursor: pointer; min-height: 24px; display: flex; align-items: center;
  .remark-text {
    font-size: 12px; padding: 2px 6px; border-radius: 4px; line-height: 1.4;
    max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    &.has { color: #8B5A00; background: #FEF3CD; }
    &.empty { color: #C0C4CC; font-size: 11px; }
  }
  &:hover .remark-text.empty { color: $brand; }
}
.empty-hint { color: #C0C4CC; font-size: 12px; }
.creds {
  display: flex; align-items: center; gap: 6px;
  .cred-text {
    flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    background: #F7F8FC; padding: 2px 6px; border-radius: 4px;
  }
  &.sub-link { margin-top: 4px; font-size: 12px; color: #909399; }
}

// ─── 源连接 — 有转发时整体淡化 ───
.source-dimmed {
  opacity: 0.38;
  filter: grayscale(0.3);
  transition: opacity 0.2s, filter 0.2s;
  .cred-text { color: #A0AEC0; background: #F1F5F9; }
  .sub-link { opacity: 0.7; }
  &:hover { opacity: 0.65; filter: none; }
}

// ─── 源二维码淡化 ───
.qr-dimmed {
  opacity: 0.35;
  filter: grayscale(0.3);
  transition: opacity 0.2s, filter 0.2s;
  &:hover { opacity: 0.7; filter: none; }
}

// ─── 转发后连接 — 金属蓝 ───
.forwarded-premium {
  position: relative;
  background: linear-gradient(135deg, #EDF0FF 0%, #DDE3FF 50%, #EDF0FF 100%);
  padding: 6px 10px; border-radius: 6px;
  border: 1px solid rgba(79, 106, 246, 0.3);
  box-shadow: 0 1px 4px rgba(79, 106, 246, 0.1);
  overflow: hidden;

  &::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(
      105deg,
      transparent 40%,
      rgba(140, 165, 255, 0.25) 47%,
      rgba(180, 200, 255, 0.4) 50%,
      rgba(140, 165, 255, 0.25) 53%,
      transparent 60%
    );
    background-size: 400% 100%;
    animation: shimmer-sweep 10s ease-in-out infinite;
    pointer-events: none;
  }

  .cred-text {
    color: #3451B2; font-weight: 600; background: transparent;
    letter-spacing: 0.01em;
  }
}

.premium-badge {
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 600; letter-spacing: 0.3px;
  color: #fff; padding: 2px 8px; border-radius: 3px;
  background: linear-gradient(135deg, #D4A017, #E8B730);
  flex-shrink: 0;
}

.copy-btn-fwd {
  color: #4F6AF6 !important; font-weight: 600;
  &:hover { color: #3451B2 !important; }
}

@keyframes shimmer-sweep {
  0%   { background-position: 0% center; }
  30%  { background-position: 100% center; }
  100% { background-position: 100% center; }
}
.pending-forward {
  display: flex; align-items: center; gap: 8px;
  color: #E6A23C; font-size: 13px; font-weight: 500;
  padding: 6px 10px; background: #FDF6EC; border-radius: 6px;
  border: 1px solid #FAECD8;
}
.pending-qr {
  display: flex; align-items: center; justify-content: center;
  color: #E6A23C; padding: 8px 0;
}
.pending-spin { animation: spin 1.5s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.upgrade-cell {
  display: flex; align-items: center;
  .el-button { font-weight: 600; }
}
.pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }

.export-info {
  display: flex; align-items: center; gap: 6px;
  padding: 10px 14px; background: #F7F8FC; border: 1px solid $border;
  border-radius: 8px; font-size: 13px; color: #475569; margin-bottom: 16px;
}

.sort-section {
  .sort-title { font-size: 14px; font-weight: 600; color: $text-primary; margin-bottom: 10px; }
}

.sort-radio-group { display: flex; flex-direction: column; gap: 6px; width: 100%; }

.sort-option {
  padding: 10px 14px; border: 2px solid $border; border-radius: 10px;
  cursor: pointer; transition: all 0.15s;
  &:hover { border-color: $brand; }
  &.active { border-color: $brand; background: $brand-light; }
  .sort-label { font-weight: 600; color: $text-primary; margin-right: 8px; }
  .sort-desc { font-size: 12px; color: $text-muted; }
}

// 桌面显示表格，手机显示卡片
.mobile-cards { display: none; }

@media (max-width: 768px) {
  .desktop-table { display: none !important; }
  .mobile-cards { display: block; }

  .my-ips-page { gap: 10px; }
  .my-ips-page .page-head {
    flex-direction: column; align-items: flex-start; gap: 8px;
    .page-title { font-size: 18px; }
    .page-sub { font-size: 12px; }
    .head-actions { width: 100%; display: flex; gap: 6px;
      .selection-hint { font-size: 11px; padding: 4px 10px; flex-shrink: 0; }
      .el-button { flex: 1; font-size: 12px; }
      :deep(.el-dropdown) { flex: 1; .el-button { width: 100%; } }
    }
  }
  .filter-card {
    :deep(.el-card__body) { padding: 8px 10px 4px; }
    :deep(.el-form) { display: flex; flex-wrap: wrap; gap: 0; }
    :deep(.el-form-item) { margin-bottom: 6px; width: 100%; margin-right: 0 !important; }
    :deep(.el-input), :deep(.el-select) { width: 100% !important; }
    :deep(.el-form-item:last-child) {
      display: flex; gap: 8px;
      .el-button { flex: 1; }
    }
  }
  .pagination-wrap { justify-content: center; margin-top: 12px; }
  .sort-option { padding: 8px 10px;
    .sort-label { font-size: 13px; }
    .sort-desc { font-size: 11px; }
  }
}

// ─── 手机卡片样式 ───
.mobile-empty {
  text-align: center; padding: 40px 0; color: $text-muted; font-size: 14px;
}
.ip-card {
  background: #fff; border: 1px solid $border; border-radius: 12px;
  padding: 14px; margin-bottom: 10px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);

  .ip-card-head {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 10px; gap: 8px;
  }
  .ip-card-name {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
    .name-text { font-weight: 600; font-size: 14px; color: $text-primary; }
    .country-badge {
      font-size: 11px; color: #6B7280; background: #F3F4F6;
      padding: 1px 8px; border-radius: 10px;
    }
  }
  .ip-card-expiry {
    font-size: 11px; color: $text-muted; white-space: nowrap; flex-shrink: 0;
    &.urgent { color: #F56C6C; font-weight: 600; }
  }

  .ip-card-section {
    background: #F8FAFC; border: 1px solid #EEF2F7; border-radius: 8px;
    padding: 10px 12px; margin-bottom: 8px;
    &:last-child { margin-bottom: 0; }

    .section-label {
      font-size: 11px; font-weight: 600; color: #6B7280;
      margin-bottom: 6px; display: flex; align-items: center; gap: 6px;
    }
    .conn-row {
      display: flex; align-items: center; gap: 8px;
      .conn-text {
        flex: 1; font-size: 12px; word-break: break-all; line-height: 1.5;
        background: #fff; padding: 6px 8px; border-radius: 6px; border: 1px solid #E5E7EB;
      }
    }
    .conn-actions { margin-top: 6px; }
    .qr-row { margin-top: 8px; display: flex; justify-content: center; }
  }

  .forwarded-premium-m {
    background: linear-gradient(135deg, #EDF0FF 0%, #DDE3FF 50%, #EDF0FF 100%);
    border-color: rgba(79, 106, 246, 0.25);
    .section-label { color: #3451B2; }
    .conn-text { color: #3451B2; font-weight: 600; border-color: rgba(79, 106, 246, 0.2); }
  }
  .source-dimmed-m {
    opacity: 0.5;
    .section-label { font-size: 10px; }
  }

  .pending-forward-m {
    background: #FDF6EC !important;
    border-color: #FAECD8 !important;
    .section-label { color: #E6A23C; }
    .pending-forward { justify-content: center; padding: 8px 0; }
  }
  .upgrade-section-m {
    background: linear-gradient(135deg, #EDF0FF 0%, #F0EDFF 100%) !important;
    border-color: rgba(79, 106, 246, 0.2) !important;
    text-align: center;
  }
}
</style>
