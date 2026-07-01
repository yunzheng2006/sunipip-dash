import axios from 'axios'
import { getToken, removeToken } from '../utils/auth'
import router from '../router'

// 平台 API 基础地址
const PLATFORM_URL = window.__PLATFORM_URL__ || 'https://user.sunipip.com'

// 本地 Agent API（Nginx 代理到 172.10.0.1:8080）
export const localApi = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: { 'Content-Type': 'application/json' }
})

// 平台客户 API
export const platformApi = axios.create({
  baseURL: `${PLATFORM_URL}/api/v1/customer`,
  timeout: 15000,
  headers: { 'Content-Type': 'application/json' }
})

// 请求拦截器：附加 Token
function attachToken(config) {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
}

localApi.interceptors.request.use(attachToken, Promise.reject)
platformApi.interceptors.request.use(attachToken, Promise.reject)

// 响应拦截器：处理 401
function handleResponseError(error) {
  if (error.response && error.response.status === 401) {
    removeToken()
    router.push('/login')
  }
  return Promise.reject(error)
}

localApi.interceptors.response.use((res) => res, handleResponseError)
platformApi.interceptors.response.use((res) => res, handleResponseError)
