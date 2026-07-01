import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as loginApi, getMe, logout as logoutApi } from '@/api/auth'
import { getToken, setToken, removeToken } from '@/utils/auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(getToken() || '')
  const user = ref(null)

  const isLoggedIn = computed(() => !!token.value)

  const userName = computed(() => {
    if (!user.value) return ''
    return user.value.name || user.value.username || ''
  })

  function hasPermission(permission) {
    if (!user.value || !user.value.permissions) return false
    return user.value.permissions.includes(permission)
  }

  function hasRole(role) {
    if (!user.value || !user.value.roles) return false
    return user.value.roles.includes(role)
  }

  // 拦截器已自动解包，res 就是 { token, user }
  async function login(credentials) {
    const res = await loginApi(credentials)
    token.value = res.token
    setToken(res.token)
    user.value = res.user || null
    return res
  }

  async function logout() {
    try {
      await logoutApi()
    } catch {
      // Ignore logout API errors
    }
    token.value = ''
    user.value = null
    removeToken()
  }

  // res 就是 { id, username, name, roles, permissions }
  async function fetchUser() {
    try {
      const res = await getMe()
      user.value = res
    } catch {
      token.value = ''
      user.value = null
      removeToken()
    }
  }

  return {
    token,
    user,
    isLoggedIn,
    userName,
    hasPermission,
    hasRole,
    login,
    logout,
    fetchUser,
  }
})
