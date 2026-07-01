import axios from 'axios'
import { ElMessage } from 'element-plus'
import { getToken, removeToken } from './auth'
import router from '@/router'

const request = axios.create({
  baseURL: (import.meta.env.VITE_API_URL || '') + '/api/v1/customer',
  timeout: 20000,
})

// Request interceptor - attach Bearer token
request.interceptors.request.use(
  (config) => {
    const token = getToken()
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => Promise.reject(error)
)

// Response interceptor
// 后端统一返回 { success: true, data: {...}, message: "ok" }
// 拦截器自动解包，调用方直接拿到 data 内容
request.interceptors.response.use(
  (response) => {
    const res = response.data
    if (res !== null && typeof res === 'object' && 'success' in res) {
      if (!res.success) {
        ElMessage.error(res.message || '请求失败')
        return Promise.reject(new Error(res.message))
      }
      return res.data
    }
    return res
  },
  (error) => {
    if (error.response) {
      const { status, data } = error.response
      if (status === 401) {
        removeToken()
        if (router.currentRoute.value.name !== 'Login') {
          router.push('/login')
        }
        ElMessage.error('登录已过期，请重新登录')
      } else if (status === 403 && data?.error_code === 'VERIFICATION_REQUIRED') {
        ElMessage.warning('请先完成实名认证')
        if (router.currentRoute.value.path !== '/dashboard') {
          router.push('/dashboard')
        }
      } else if (status === 403) {
        ElMessage.error(data?.message || '无权访问')
      } else if (status === 422) {
        const errors = data?.errors
        if (errors) {
          const firstError = Object.values(errors)[0]
          ElMessage.error(Array.isArray(firstError) ? firstError[0] : firstError)
        } else {
          ElMessage.error(data?.message || '参数验证失败')
        }
      } else {
        ElMessage.error(data?.message || '请求失败')
      }
    } else {
      ElMessage.error('网络连接失败，请检查网络')
    }
    return Promise.reject(error)
  }
)

export default request
