<template>
  <div class="roles-page">
    <h2 class="page-title">权限组管理</h2>
    <p class="page-desc">管理系统角色及其权限，权限之间存在依赖关系，系统会自动检测并提示。</p>

    <el-row :gutter="16">
      <!-- 左侧：角色列表 -->
      <el-col :span="7">
        <el-card>
          <template #header>
            <div class="card-header">
              <span><el-icon><UserFilled /></el-icon> 角色列表</span>
              <el-button type="primary" size="small" @click="openCreate">
                <el-icon><Plus /></el-icon>新建
              </el-button>
            </div>
          </template>
          <div class="role-list">
            <div
              v-for="role in roles"
              :key="role.id"
              class="role-item"
              :class="{ active: selectedRoleId === role.id }"
              @click="selectRole(role.id)"
            >
              <div class="role-info">
                <div class="role-name">
                  {{ role.label }}
                  <el-tag v-if="role.is_system" size="small" type="info" effect="plain">系统</el-tag>
                </div>
                <div class="role-meta">
                  <span>{{ role.permissions_count }} 项权限</span>
                  <span>·</span>
                  <span>{{ role.users_count }} 个用户</span>
                </div>
              </div>
              <el-icon v-if="selectedRoleId === role.id" class="arrow"><ArrowRight /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 右侧：权限配置 -->
      <el-col :span="17">
        <el-card v-loading="loading">
          <template #header>
            <div class="card-header">
              <span v-if="selectedRole">
                <el-icon><Key /></el-icon>
                <strong>{{ selectedRole.label }}</strong> 权限配置
                <el-tag size="small" type="info" style="margin-left: 8px">{{ selectedRole.name }}</el-tag>
              </span>
              <span v-else>请选择左侧角色</span>

              <div v-if="selectedRole && !isSuper" class="header-actions">
                <el-button size="small" @click="expandAll">全部展开</el-button>
                <el-button size="small" @click="collapseAll">全部收起</el-button>
                <el-button size="small" @click="selectAllPerms">全选</el-button>
                <el-button size="small" @click="clearAllPerms">清空</el-button>
                <el-button type="primary" size="small" :loading="saving" @click="save">
                  保存权限
                </el-button>
                <el-button
                  v-if="!selectedRole.is_system"
                  type="danger"
                  size="small"
                  @click="handleDelete"
                >
                  删除角色
                </el-button>
              </div>
            </div>
          </template>

          <div v-if="selectedRole">
            <el-alert v-if="isSuper" type="warning" :closable="false" show-icon style="margin-bottom: 16px">
              超级管理员拥有所有权限，不可修改。
            </el-alert>

            <!-- 角色设置 -->
            <div v-if="!isSuper && selectedPermissions.includes('pricing.set_discount')" class="role-settings-section">
              <el-divider content-position="left">角色设置</el-divider>
              <el-form :inline="true" label-width="140px" style="padding: 0 12px">
                <el-form-item label="销售最大折扣">
                  <el-input-number
                    v-model="roleSettings.max_discount_percent"
                    :min="10"
                    :max="99"
                    :step="5"
                    placeholder="如 70 = 七折"
                    style="width: 160px"
                  />
                  <span style="margin-left: 8px; font-size: 12px; color: #909399">
                    {{ roleSettings.max_discount_percent ? `最低 ${roleSettings.max_discount_percent} 折` : '未设置（销售无法使用折扣功能）' }}
                  </span>
                </el-form-item>
              </el-form>
            </div>

            <!-- 权限冲突提示 -->
            <el-alert
              v-if="conflictWarnings.length"
              type="warning"
              :closable="false"
              show-icon
              style="margin-bottom: 16px"
            >
              <template #title>
                <span>检测到 {{ conflictWarnings.length }} 项权限缺少前置依赖</span>
              </template>
              <div class="conflict-list">
                <div v-for="w in conflictWarnings" :key="w.perm" class="conflict-item">
                  <span class="conflict-perm">{{ getPermLabel(w.perm) }}</span>
                  <span class="conflict-arrow">缺少：</span>
                  <span v-for="(m, i) in w.missing" :key="m">
                    {{ getPermLabel(m) }}<span v-if="i < w.missing.length - 1">、</span>
                  </span>
                </div>
              </div>
              <el-button size="small" type="warning" style="margin-top: 8px" @click="fixAllConflicts">
                一键补全所有前置权限
              </el-button>
            </el-alert>

            <!-- 按模块展开式布局 -->
            <el-collapse v-model="expandedModules" class="perm-collapse">
              <el-collapse-item v-for="(module, key) in modules" :key="key" :name="key">
                <template #title>
                  <div class="module-title" @click.stop>
                    <el-checkbox
                      :model-value="isModuleAllSelected(module)"
                      :indeterminate="isModuleIndeterminate(module)"
                      :disabled="isSuper"
                      @change="toggleModule(module, $event)"
                      @click.stop
                    />
                    <span class="module-label">{{ module.label }}</span>
                    <el-tag
                      :type="countSelectedInModule(module) > 0 ? '' : 'info'"
                      size="small"
                      effect="plain"
                      class="module-count-tag"
                    >
                      {{ countSelectedInModule(module) }} / {{ Object.keys(module.permissions).length }}
                    </el-tag>
                  </div>
                </template>

                <div class="permission-list">
                  <div
                    v-for="(label, permKey) in module.permissions"
                    :key="permKey"
                    class="permission-row"
                    :class="{
                      'is-base': !dependencies[permKey]?.length,
                      'has-unmet-deps': hasUnmetDeps(permKey),
                    }"
                  >
                    <el-checkbox
                      :model-value="selectedPermissions.includes(permKey)"
                      :disabled="isSuper"
                      @change="handleToggle(permKey, $event)"
                    >
                      <span class="perm-label">{{ label }}</span>
                    </el-checkbox>
                    <span class="perm-code">{{ permKey }}</span>
                    <span v-if="dependencies[permKey]?.length" class="dep-hint">
                      <el-icon><Connection /></el-icon>
                      {{ formatDepsHint(permKey) }}
                    </span>
                  </div>
                </div>
              </el-collapse-item>
            </el-collapse>
          </div>
          <el-empty v-else description="请从左侧选择角色" />
        </el-card>
      </el-col>
    </el-row>

    <!-- 需要补充前置权限对话框 -->
    <el-dialog v-model="depDialogVisible" title="需要补充前置权限" width="520px" :close-on-click-modal="false">
      <p style="margin-bottom: 16px; color: #606266;">
        开启「<strong>{{ pendingAction?.label }}</strong>」需要以下权限支持：
      </p>
      <div class="dep-dialog-list">
        <div v-for="dep in pendingAction?.missing" :key="dep" class="dep-dialog-item">
          <el-icon class="dep-icon enable"><CircleCheck /></el-icon>
          <div>
            <div class="dep-name">{{ getPermLabel(dep) }}</div>
            <div class="dep-module">{{ getPermModule(dep) }}</div>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="depDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmAutoEnable">一并开启</el-button>
      </template>
    </el-dialog>

    <!-- 关闭权限级联提示对话框 -->
    <el-dialog v-model="cascadeDialogVisible" title="关闭权限影响提示" width="520px" :close-on-click-modal="false">
      <p style="margin-bottom: 16px; color: #606266;">
        关闭「<strong>{{ pendingAction?.label }}</strong>」将导致以下依赖权限失效并一并关闭：
      </p>
      <div class="dep-dialog-list">
        <div v-for="dep in pendingAction?.affected" :key="dep" class="dep-dialog-item">
          <el-icon class="dep-icon disable"><CircleClose /></el-icon>
          <div>
            <div class="dep-name">{{ getPermLabel(dep) }}</div>
            <div class="dep-module">{{ getPermModule(dep) }}</div>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="cascadeDialogVisible = false">取消</el-button>
        <el-button type="danger" @click="confirmCascadeDisable">确认关闭</el-button>
      </template>
    </el-dialog>

    <!-- 创建角色对话框 -->
    <el-dialog v-model="createVisible" title="新建角色" width="460px">
      <el-form :model="createForm" label-width="80px">
        <el-form-item label="角色标识" required>
          <el-input v-model="createForm.name" placeholder="英文/下划线，如 finance_manager" />
          <div style="font-size: 12px; color: #909399; margin-top: 4px;">只能使用小写字母和下划线</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="submitCreate">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Key, UserFilled, ArrowRight, Plus, Connection, CircleCheck, CircleClose } from '@element-plus/icons-vue'
import { getRoles, getRole, createRole, updateRole, deleteRole, getAllPermissions } from '@/api/roles'

const roles = ref([])
const modules = ref({})
const dependencies = ref({})
const selectedRoleId = ref(null)
const selectedRole = ref(null)
const selectedPermissions = ref([])
const expandedModules = ref([])
const loading = ref(false)
const saving = ref(false)

const roleSettings = reactive({ max_discount_percent: null })

const createVisible = ref(false)
const creating = ref(false)
const createForm = reactive({ name: '' })

const depDialogVisible = ref(false)
const cascadeDialogVisible = ref(false)
const pendingAction = ref(null)

const isSuper = computed(() => selectedRole.value?.name === 'super_admin')

// ─── 权限标签/模块 查找辅助 ───

function getPermLabel(permKey) {
  for (const mod of Object.values(modules.value)) {
    if (mod.permissions[permKey]) return mod.permissions[permKey]
  }
  return permKey
}

function getPermModule(permKey) {
  for (const mod of Object.values(modules.value)) {
    if (mod.permissions[permKey]) return mod.label
  }
  return ''
}

function formatDepsHint(permKey) {
  const deps = dependencies.value[permKey] || []
  if (!deps.length) return ''
  return '需要：' + deps.map(d => getPermLabel(d)).join('、')
}

// ─── 依赖检测 ───

function getMissingDeps(permKey) {
  const deps = dependencies.value[permKey] || []
  return deps.filter(d => !selectedPermissions.value.includes(d))
}

function hasUnmetDeps(permKey) {
  if (!selectedPermissions.value.includes(permKey)) return false
  return getMissingDeps(permKey).length > 0
}

function getAllDependents(permKey) {
  const result = new Set()
  const queue = [permKey]
  while (queue.length) {
    const current = queue.shift()
    for (const [perm, deps] of Object.entries(dependencies.value)) {
      if (deps.includes(current) && selectedPermissions.value.includes(perm) && !result.has(perm)) {
        result.add(perm)
        queue.push(perm)
      }
    }
  }
  return [...result]
}

function getAllRequiredDeps(permKey) {
  const result = new Set()
  const queue = [...(dependencies.value[permKey] || [])]
  while (queue.length) {
    const dep = queue.shift()
    if (!result.has(dep)) {
      result.add(dep)
      const transitive = dependencies.value[dep] || []
      for (const t of transitive) {
        if (!result.has(t)) queue.push(t)
      }
    }
  }
  return [...result]
}

const conflictWarnings = computed(() => {
  const warnings = []
  for (const perm of selectedPermissions.value) {
    const missing = getMissingDeps(perm)
    if (missing.length) {
      warnings.push({ perm, missing })
    }
  }
  return warnings
})

// ─── 权限切换逻辑 ───

function handleToggle(permKey, checked) {
  if (checked) {
    const allDeps = getAllRequiredDeps(permKey)
    const missing = allDeps.filter(d => !selectedPermissions.value.includes(d))

    if (missing.length > 0) {
      pendingAction.value = {
        perm: permKey,
        label: getPermLabel(permKey),
        missing,
      }
      depDialogVisible.value = true
    } else {
      addPerm(permKey)
    }
  } else {
    const affected = getAllDependents(permKey)

    if (affected.length > 0) {
      pendingAction.value = {
        perm: permKey,
        label: getPermLabel(permKey),
        affected,
      }
      cascadeDialogVisible.value = true
    } else {
      removePerm(permKey)
    }
  }
}

function confirmAutoEnable() {
  if (!pendingAction.value) return
  const { perm, missing } = pendingAction.value
  for (const m of missing) addPerm(m)
  addPerm(perm)
  depDialogVisible.value = false
  pendingAction.value = null
}

function confirmCascadeDisable() {
  if (!pendingAction.value) return
  const { perm, affected } = pendingAction.value
  for (const a of affected) removePerm(a)
  removePerm(perm)
  cascadeDialogVisible.value = false
  pendingAction.value = null
}

function fixAllConflicts() {
  for (const w of conflictWarnings.value) {
    for (const m of w.missing) addPerm(m)
  }
  ElMessage.success('已补全所有前置权限')
}

function addPerm(key) {
  if (!selectedPermissions.value.includes(key)) {
    selectedPermissions.value.push(key)
  }
}

function removePerm(key) {
  selectedPermissions.value = selectedPermissions.value.filter(p => p !== key)
}

// ─── 模块级操作 ───

function isModuleAllSelected(module) {
  const keys = Object.keys(module.permissions)
  return keys.length > 0 && keys.every(k => selectedPermissions.value.includes(k))
}

function isModuleIndeterminate(module) {
  const keys = Object.keys(module.permissions)
  const count = keys.filter(k => selectedPermissions.value.includes(k)).length
  return count > 0 && count < keys.length
}

function countSelectedInModule(module) {
  return Object.keys(module.permissions).filter(k => selectedPermissions.value.includes(k)).length
}

function toggleModule(module, checked) {
  const keys = Object.keys(module.permissions)
  if (checked) {
    const toAdd = new Set(keys)
    for (const k of keys) {
      const allDeps = getAllRequiredDeps(k)
      for (const d of allDeps) toAdd.add(d)
    }
    selectedPermissions.value = [...new Set([...selectedPermissions.value, ...toAdd])]
  } else {
    const toRemove = new Set(keys)
    for (const k of keys) {
      const dependents = getAllDependents(k)
      for (const d of dependents) toRemove.add(d)
    }
    selectedPermissions.value = selectedPermissions.value.filter(p => !toRemove.has(p))
  }
}

function selectAllPerms() {
  const all = []
  Object.values(modules.value).forEach(m => all.push(...Object.keys(m.permissions)))
  selectedPermissions.value = all
}

function clearAllPerms() {
  selectedPermissions.value = []
}

// ─── 展开/收起 ───

function expandAll() {
  expandedModules.value = Object.keys(modules.value)
}

function collapseAll() {
  expandedModules.value = []
}

// ─── 数据加载 ───

async function loadRoles() {
  try {
    const res = await getRoles()
    roles.value = Array.isArray(res) ? res : []
    if (roles.value.length && !selectedRoleId.value) {
      selectRole(roles.value[0].id)
    }
  } catch { /* handled */ }
}

async function loadModules() {
  try {
    const res = await getAllPermissions()
    modules.value = res?.modules || {}
    dependencies.value = res?.dependencies || {}
    expandedModules.value = Object.keys(modules.value)
  } catch { /* handled */ }
}

async function selectRole(id) {
  selectedRoleId.value = id
  loading.value = true
  try {
    const res = await getRole(id)
    selectedRole.value = {
      ...res,
      is_super: res.name === 'super_admin',
    }
    selectedPermissions.value = res.permissions || []
    const s = res.settings || {}
    roleSettings.max_discount_percent = s.max_discount_percent ?? null
  } catch { /* handled */ }
  finally { loading.value = false }
}

// ─── 保存/创建/删除 ───

async function save() {
  if (conflictWarnings.value.length) {
    try {
      await ElMessageBox.confirm(
        `当前有 ${conflictWarnings.value.length} 项权限缺少前置依赖，是否自动补全后保存？`,
        '权限依赖检查',
        { confirmButtonText: '补全并保存', cancelButtonText: '取消', type: 'warning' }
      )
      fixAllConflicts()
    } catch { return }
  }

  saving.value = true
  try {
    await updateRole(selectedRoleId.value, {
      permissions: selectedPermissions.value,
      settings: { max_discount_percent: roleSettings.max_discount_percent || null },
    })
    ElMessage.success('权限保存成功')
    loadRoles()
  } catch { /* handled */ }
  finally { saving.value = false }
}

function openCreate() {
  createForm.name = ''
  createVisible.value = true
}

async function submitCreate() {
  if (!createForm.name) { ElMessage.warning('请输入角色标识'); return }
  if (!/^[a-z_]+$/.test(createForm.name)) {
    ElMessage.warning('只能使用小写字母和下划线')
    return
  }
  creating.value = true
  try {
    await createRole({ name: createForm.name, permissions: [] })
    ElMessage.success('角色创建成功')
    createVisible.value = false
    loadRoles()
  } catch { /* handled */ }
  finally { creating.value = false }
}

async function handleDelete() {
  try {
    await ElMessageBox.confirm(`确定删除角色「${selectedRole.value.label}」？`, '确认', { type: 'warning' })
    await deleteRole(selectedRoleId.value)
    ElMessage.success('已删除')
    selectedRoleId.value = null
    selectedRole.value = null
    loadRoles()
  } catch { /* cancelled */ }
}

onMounted(() => {
  loadRoles()
  loadModules()
})
</script>

<style lang="scss" scoped>
.roles-page {
  .page-title { margin: 0 0 4px; font-size: 20px; font-weight: 600; color: #2C3E50; }
  .page-desc { color: #909399; margin: -8px 0 20px; font-size: 13px; }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    .el-icon { margin-right: 6px; vertical-align: middle; }
  }
  .header-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
  }

  // ─── 角色列表 ───
  .role-list {
    .role-item {
      padding: 14px 16px;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border: 1px solid transparent;

      &:hover { background: #FEF7F0; }
      &.active {
        background: linear-gradient(135deg, #FFF8F0, #FDF0E2);
        border-color: #F5D9B5;
      }

      .role-info {
        flex: 1;
        .role-name {
          font-size: 15px;
          font-weight: 600;
          color: #2C3E50;
          display: flex;
          align-items: center;
          gap: 8px;
        }
        .role-meta {
          font-size: 12px;
          color: #909399;
          margin-top: 4px;
          display: flex;
          gap: 4px;
        }
      }
      .arrow { color: #E8913A; }
    }
  }

  // ─── 折叠面板 ───
  .perm-collapse {
    border: none;

    :deep(.el-collapse-item) {
      margin-bottom: 8px;
      border: 1px solid #F0E6DA;
      border-radius: 10px;
      overflow: hidden;

      .el-collapse-item__header {
        padding: 0 16px;
        height: 52px;
        background: #FDFBF8;
        border-bottom: none;
        font-size: 14px;
        transition: background 0.2s;

        &:hover { background: #FEF7F0; }
        &.is-active { border-bottom: 1px solid #F0E6DA; }
      }

      .el-collapse-item__wrap {
        border-bottom: none;
      }
      .el-collapse-item__content {
        padding: 8px 0;
      }
    }
  }

  .module-title {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;

    .module-label {
      font-weight: 600;
      color: #2C3E50;
    }
    .module-count-tag {
      font-size: 12px;
      margin-left: auto;
      margin-right: 12px;
    }
  }

  // ─── 权限行 ───
  .permission-list {
    padding: 0 16px;
  }

  .permission-row {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 8px;
    transition: background 0.15s;
    gap: 8px;

    &:hover { background: #FAFAF8; }

    &.is-base {
      :deep(.el-checkbox__label) .perm-label {
        font-weight: 600;
      }
    }

    &.has-unmet-deps {
      background: #FFF9F0;
      border: 1px dashed #E6A23C;

      .perm-label { color: #E6A23C; }
    }

    :deep(.el-checkbox) {
      margin-right: 0;
      .el-checkbox__label {
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 4px;
      }
    }

    .perm-code {
      font-size: 11px;
      color: #C0C4CC;
      font-family: 'SF Mono', Consolas, monospace;
      flex-shrink: 0;
    }

    .dep-hint {
      margin-left: auto;
      font-size: 11px;
      color: #A8ABB2;
      display: flex;
      align-items: center;
      gap: 3px;
      white-space: nowrap;

      .el-icon { font-size: 12px; }
    }
  }

  // ─── 角色设置 ───
  .role-settings-section {
    margin-bottom: 16px;
    padding: 0 4px;
  }

  // ─── 冲突提示 ───
  .conflict-list {
    margin-top: 8px;
    .conflict-item {
      font-size: 13px;
      padding: 2px 0;
      .conflict-perm { font-weight: 600; color: #E6A23C; }
      .conflict-arrow { color: #909399; margin: 0 4px; }
    }
  }
}

// ─── 对话框样式 ───
.dep-dialog-list {
  .dep-dialog-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    background: #FAFAFA;
    margin-bottom: 8px;

    .dep-icon {
      font-size: 20px;
      flex-shrink: 0;
      &.enable { color: #67C23A; }
      &.disable { color: #F56C6C; }
    }

    .dep-name {
      font-size: 14px;
      font-weight: 500;
      color: #303133;
    }
    .dep-module {
      font-size: 12px;
      color: #909399;
    }
  }
}
</style>
