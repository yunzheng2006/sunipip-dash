import request from '@/utils/request'

export function getBalance() {
  return request.get('/balance')
}

export function getTransactions(params) {
  return request.get('/transactions', { params })
}

// ========= 充值 =========
export function getTopupMethods() {
  return request.get('/topup/methods')
}

export function createTopup(data) {
  return request.post('/topup/create', data)
}

export function getTopupOrders(params) {
  return request.get('/topup/orders', { params })
}

export function getTopupOrder(orderNo) {
  return request.get(`/topup/orders/${orderNo}`)
}
