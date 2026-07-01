import request from '@/utils/request'

export function getWebhooks(params) {
  return request.get('/webhooks', { params })
}

export function getWebhookEvents() {
  return request.get('/webhooks/events')
}

export function getWebhook(id) {
  return request.get(`/webhooks/${id}`)
}

export function createWebhook(data) {
  return request.post('/webhooks', data)
}

export function updateWebhook(id, data) {
  return request.put(`/webhooks/${id}`, data)
}

export function deleteWebhook(id) {
  return request.delete(`/webhooks/${id}`)
}

export function testWebhook(id) {
  return request.post(`/webhooks/${id}/test`)
}

export function getNotificationLogs(params) {
  return request.get('/webhooks/logs', { params })
}
