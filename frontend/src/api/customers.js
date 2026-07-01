import request from '@/utils/request'

export function getCustomers(params) {
  return request.get('/customers', { params })
}

export function getCustomer(id) {
  return request.get(`/customers/${id}`)
}

export function createCustomer(data) {
  return request.post('/customers', data)
}

export function updateCustomer(id, data) {
  return request.put(`/customers/${id}`, data)
}

export function deleteCustomer(id) {
  return request.delete(`/customers/${id}`)
}

export function topupCustomer(id, data) {
  return request.post(`/customers/${id}/topup`, data)
}

export function resetCustomerPassword(id, data) {
  return request.post(`/customers/${id}/reset-password`, data)
}

export function mergeCustomers(data) {
  return request.post('/customers/merge', data)
}

export function impersonateCustomer(id) {
  return request.post(`/customers/${id}/impersonate`)
}

export function setCustomerReferrer(id, data) {
  return request.post(`/customers/${id}/set-referrer`, data)
}

export function clearCustomerReferrer(id) {
  return request.post(`/customers/${id}/clear-referrer`)
}

export function transferCustomerReferrer(id, data) {
  return request.post(`/customers/${id}/transfer-referrer`, data)
}

export function adjustCustomerBalance(id, data) {
  return request.post(`/customers/${id}/adjust-balance`, data)
}

export function changeSalesPerson(id, data) {
  return request.post(`/customers/${id}/change-sales`, data)
}

export function getVerificationInfo(id) {
  return request.get(`/customers/${id}/verification-info`)
}

export function resetVerification(id) {
  return request.post(`/customers/${id}/reset-verification`)
}

export function manualVerify(id, data) {
  return request.post(`/customers/${id}/manual-verify`, data)
}
