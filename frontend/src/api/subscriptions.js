import request from '@/utils/request'

export function getSubscriptions(params) {
  return request.get('/subscriptions', { params })
}

export function getSubscription(id) {
  return request.get(`/subscriptions/${id}`)
}

export function renewSubscription(id, data) {
  return request.post(`/subscriptions/${id}/renew`, data)
}

export function bulkRenewSubscriptions(data) {
  return request.post('/subscriptions/bulk-renew', data)
}

export function batchAttachForward(data) {
  // 只是排队入库，应该 1-2 秒返回
  return request.post('/subscriptions/batch-attach-forward', data)
}

export function getBatchForwardStatus(batchId) {
  return request.get(`/subscriptions/batch-forward-status/${batchId}`)
}

export function batchUpdateExpiry(data) {
  return request.post('/subscriptions/batch-update-expiry', data)
}

export function batchAttachXuiForward(data) {
  return request.post('/subscriptions/batch-attach-xui-forward', data)
}

export function getBatchXuiForwardStatus(batchId) {
  return request.get(`/subscriptions/batch-xui-forward-status/${batchId}`)
}

export function cancelSubscription(id, data = {}) {
  return request.post(`/subscriptions/${id}/cancel`, data, { timeout: 60000 })
}

export function refundSubscription(id, data) {
  return request.post(`/subscriptions/${id}/refund`, data, { timeout: 60000 })
}

export function partialRefundSubscription(id, data) {
  return request.post(`/subscriptions/${id}/partial-refund`, data)
}

export function getExpiringSubscriptions(days) {
  return request.get('/subscriptions/expiring', { params: { days } })
}

export function createOrder(data) {
  return request.post('/subscriptions/create-order', data)
}

export function getAvailableIps(params) {
  return request.get('/subscriptions/available-ips', { params })
}

export function updateSubscriptionRemark(id, remark) {
  return request.patch(`/subscriptions/${id}/remark`, { remark })
}

export function convertTestSubscription(id, data) {
  return request.post(`/subscriptions/${id}/convert-test`, data)
}

export function downgradeSubscription(id, data) {
  return request.post(`/subscriptions/${id}/downgrade`, data, { timeout: 60000 })
}

export function transferSubscription(id, data) {
  return request.post(`/subscriptions/${id}/transfer`, data)
}

export function batchUpdatePrice(data) {
  return request.post('/subscriptions/batch-update-price', data)
}
