import request from '@/utils/request'

export function getSparkProducts(params) {
  return request.get('/spark/products', { params })
}

export function sparkProvision(data) {
  return request.post('/spark/provision', data)
}

export function sparkRenew(data) {
  return request.post('/spark/renew', data)
}

export function sparkRelease(data) {
  return request.post('/spark/release', data)
}

export function getSparkOrders(params) {
  return request.get('/spark/orders', { params })
}

export function syncSparkOrder(sparkOrderId) {
  return request.post(`/spark/sync-order/${sparkOrderId}`)
}

export function getSparkBalance() {
  return request.get('/spark/balance')
}

export function resetSparkPassword(data) {
  return request.post('/spark/reset-password', data)
}

export function getSparkIpSegments(params) {
  return request.get('/spark/ip-segments', { params })
}

export function getProductBlocks() {
  return request.get('/spark/product-blocks')
}

export function getAllProductsForBlock(params) {
  return request.get('/spark/product-blocks/all-products', { params })
}

export function addProductBlocks(data) {
  return request.post('/spark/product-blocks', data)
}

export function removeProductBlock(id) {
  return request.delete(`/spark/product-blocks/${id}`)
}

export function bulkRemoveProductBlocks(ids) {
  return request.post('/spark/product-blocks/bulk-destroy', { ids })
}
