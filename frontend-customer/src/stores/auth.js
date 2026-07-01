import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as loginApi, loginBySms as loginBySmsApi, logout as logoutApi, getMe, register as registerApi } from '@/api/auth'
import { getToken, setToken, removeToken } from '@/utils/auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(getToken() || '')
  const customer = ref(null)

  const isLoggedIn = computed(() => !!token.value)
  const balance = computed(() => Number(customer.value?.balance ?? 0))
  const commissionBalance = computed(() => Number(customer.value?.commission_balance ?? 0))

  async function register(data) {
    const res = await registerApi(data)
    token.value = res.token
    setToken(res.token)
    customer.value = res.customer
    return res
  }

  async function login(credentials) {
    const res = await loginApi(credentials)
    token.value = res.token
    setToken(res.token)
    customer.value = res.customer
    return res
  }

  async function loginBySms(credentials) {
    const res = await loginBySmsApi(credentials)
    token.value = res.token
    setToken(res.token)
    customer.value = res.customer
    return res
  }

  async function logout() {
    try {
      await logoutApi()
    } catch { /* ignore */ }
    token.value = ''
    customer.value = null
    removeToken()
  }

  async function fetchMe() {
    if (!token.value) return null
    try {
      const res = await getMe()
      customer.value = res
      return res
    } catch {
      // 401 会被 request 拦截器处理
      return null
    }
  }

  function updateBalance(newBalance) {
    if (customer.value) {
      customer.value.balance = newBalance
    }
  }

  function updateCommissionBalance(newVal) {
    if (customer.value) {
      customer.value.commission_balance = newVal
    }
  }

  return {
    token,
    customer,
    isLoggedIn,
    balance,
    commissionBalance,
    register,
    login,
    loginBySms,
    logout,
    fetchMe,
    updateBalance,
    updateCommissionBalance,
  }
})
