import { platformApi } from './index'

/**
 * 登录
 * @param {string} identifier - 邮箱或手机号
 * @param {string} password - 密码
 */
export function login(identifier, password) {
  return platformApi.post('/auth/login', { identifier, password })
}

/**
 * 获取当前用户信息
 */
export function getMe() {
  return platformApi.get('/auth/me')
}
