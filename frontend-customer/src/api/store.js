import request from '@/utils/request'

export function getStoreProducts(params) {
  return request.get('/store/products', { params })
}

// Keep for backwards compat
export function getStoreCountries(params) {
  return request.get('/store/products', { params })
}

export function checkout(data) {
  return request.post('/store/checkout', data)
}
