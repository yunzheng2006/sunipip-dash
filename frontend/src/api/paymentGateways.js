import request from '@/utils/request'

export function getPaymentGateways() {
  return request.get('/payment-gateways')
}

export function getPaymentGateway(id) {
  return request.get(`/payment-gateways/${id}`)
}

export function createPaymentGateway(data) {
  return request.post('/payment-gateways', data)
}

export function updatePaymentGateway(id, data) {
  return request.put(`/payment-gateways/${id}`, data)
}

export function deletePaymentGateway(id) {
  return request.delete(`/payment-gateways/${id}`)
}

export function testPaymentGatewaySign(id) {
  return request.post(`/payment-gateways/${id}/test-sign`)
}

export function getDomainSettings() {
  return request.get('/payment-gateways/domain-settings')
}

export function updateDomainSettings(data) {
  return request.put('/payment-gateways/domain-settings', data)
}
