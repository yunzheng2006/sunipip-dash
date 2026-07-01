import request from '@/utils/request'

export function register(data) {
  return request.post('/auth/register', data)
}

export function login(data) {
  return request.post('/auth/login', data)
}

export function loginBySms(data) {
  return request.post('/auth/login-sms', data)
}

export function getMe() {
  return request.get('/auth/me')
}

export function logout() {
  return request.post('/auth/logout')
}

export function changePassword(data) {
  return request.put('/auth/password', data)
}

// SMS verification
export function getSmsCaptcha() {
  return request.get('/sms/captcha')
}

export function sendSmsCode(data) {
  return request.post('/sms/send-code', data)
}
