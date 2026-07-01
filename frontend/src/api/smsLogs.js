import request from '@/utils/request'

export function getSmsLogs(params) {
  return request.get('/sms-logs', { params })
}

export function getSmsLogStats() {
  return request.get('/sms-logs/stats')
}
