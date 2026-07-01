import request from '@/utils/request'

export function getActivityLogs(params) {
  return request.get('/activity-logs', { params })
}

export function getActivityLog(id) {
  return request.get(`/activity-logs/${id}`)
}

export function cleanActivityLogs(data) {
  return request.post('/activity-logs/clean', data)
}
