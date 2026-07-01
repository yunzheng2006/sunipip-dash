import request from '@/utils/request'

export function getDashboardStats() {
  return request.get('/dashboard/stats')
}

export function getExpiringList() {
  return request.get('/dashboard/expiring')
}

export function getRecentActivities() {
  return request.get('/dashboard/activities')
}
