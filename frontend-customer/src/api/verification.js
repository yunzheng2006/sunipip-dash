import request from '@/utils/request'

export function getVerificationStatus() {
  return request.get('/verification/status')
}

export function initFaceVerification(data) {
  return request.post('/verification/personal/init', data)
}

export function confirmFaceVerification(data) {
  return request.post('/verification/personal/confirm', data)
}

export function pollPersonalVerification(data) {
  return request.post('/verification/personal/poll', data)
}

export function ocrBusinessLicense(data) {
  return request.post('/verification/enterprise/ocr', data)
}

export function verifyEnterprise(data) {
  return request.post('/verification/enterprise/verify', data)
}

export function confirmEnterprise(data) {
  return request.post('/verification/enterprise/confirm', data)
}

export function pollEnterpriseVerification(data) {
  return request.post('/verification/enterprise/poll', data)
}

export function resumePersonalVerification() {
  return request.post('/verification/personal/resume')
}

export function getVerificationInfo() {
  return request.get('/verification/info')
}

export function upgradeEnterpriseOcr(data) {
  return request.post('/verification/upgrade-enterprise/ocr', data)
}

export function upgradeEnterpriseVerify(data) {
  return request.post('/verification/upgrade-enterprise/verify', data)
}

export function upgradeEnterprisePoll(data) {
  return request.post('/verification/upgrade-enterprise/poll', data)
}
