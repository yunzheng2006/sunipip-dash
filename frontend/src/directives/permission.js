/**
 * 权限指令
 * 用法:
 *   v-perm="'customer.create'"                    // 单个权限
 *   v-perm="['customer.create', 'customer.edit']"  // 任意一个
 *
 * 没有权限时直接移除 DOM 节点
 */
import { useAuthStore } from '@/stores/auth'

function hasPermission(value) {
  const auth = useAuthStore()
  if (!auth.user) return false

  // 超级管理员放行
  if (auth.user.roles?.includes('super_admin')) return true

  const perms = auth.user.permissions || []
  if (Array.isArray(value)) {
    return value.some(p => perms.includes(p))
  }
  return perms.includes(value)
}

export default {
  mounted(el, binding) {
    if (!hasPermission(binding.value)) {
      el.parentNode?.removeChild(el)
    }
  },
}
