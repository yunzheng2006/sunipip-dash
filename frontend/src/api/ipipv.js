import request from '@/utils/request'

export function getIpipvProducts(params) {
  return request.get('/ipipv/products', { params })
}

export function ipipvProvision(data) {
  return request.post('/ipipv/provision', data)
}

export function getIpipvOrders(params) {
  return request.get('/ipipv/orders', { params })
}

export function syncIpipvOrder(ipipvOrderId) {
  return request.post(`/ipipv/sync-order/${ipipvOrderId}`)
}
