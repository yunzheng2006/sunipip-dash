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

export function refundSubscription(id, data) {
  return request.post(`/subscriptions/${id}/refund`, data)
}

export function toggleAutoRenew(id, enabled) {
  return request.put(`/subscriptions/${id}/auto-renew`, { enabled })
}

export function redeemSubscription(id) {
  return request.post(`/subscriptions/${id}/redeem`)
}

export function batchToggleAutoRenew(ids, enabled) {
  return request.put('/subscriptions/batch-auto-renew', { ids, enabled })
}

export function identifyIps(ips) {
  return request.post('/subscriptions/identify-ips', { ips })
}

export function batchRenewByIp(data) {
  return request.post('/subscriptions/batch-renew-by-ip', data)
}

export function updateSubscriptionRemark(id, customerRemark) {
  return request.patch(`/subscriptions/${id}/remark`, { customer_remark: customerRemark })
}

export function getUpgradeForwardPreview(id) {
  return request.get(`/subscriptions/${id}/upgrade-forward-preview`)
}

export function upgradeForward(id) {
  return request.post(`/subscriptions/${id}/upgrade-forward`)
}
