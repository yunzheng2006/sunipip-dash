import request from '@/utils/request'

export function getPaymentOrders(params) {
  return request.get('/payment-orders', { params })
}

export function refundPaymentOrder(id, data) {
  return request.post(`/payment-orders/${id}/refund`, data)
}

export function getPaymentRefunds(params) {
  return request.get('/payment-refunds', { params })
}

export function getRefundableOrders(customerId) {
  return request.get(`/customers/${customerId}/refundable-orders`)
}
