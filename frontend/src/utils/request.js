import axios from 'axios'
import { ElMessage } from 'element-plus'
import { getToken, removeToken } from './auth'
import router from '@/router'

const request = axios.create({
  baseURL: (import.meta.env.VITE_API_URL || '') + '/api/v1',
  timeout: 15000,
})

// Request interceptor
request.interceptors.request.use(
  (config) => {
    const token = getToken()
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor
// 后端统一返回 { success: true, data: {...}, message: "ok" }
// 拦截器自动解包，调用方直接拿到 data 内容
request.interceptors.response.use(
  (response) => {
    const res = response.data
    // 如果是我们的 API 包装格式，自动解包
    if (res !== null && typeof res === 'object' && 'success' in res) {
      if (!res.success) {
        ElMessage.error(res.message || '请求失败')
        return Promise.reject(new Error(res.message))
      }
      return res.data // 直接返回内层 data
    }
    return res
  },
  (error) => {
    if (error.response) {
      const { status, data } = error.response
      if (status === 401) {
        removeToken()
        router.push('/login')
        ElMessage.error('登录已过期，请重新登录')
      } else if (status === 403 && data?.message?.startsWith('无权限')) {
        // 权限不足静默处理，由调用方 catch 决定是否提示
      } else if (status === 422) {
        // 验证错误，显示第一个错误信息
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
