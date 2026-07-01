import request from '@/utils/request'

export function getProductPricing(params) {
  return request.get('/product-pricing', { params })
}

export function createProductPricing(data) {
  return request.post('/product-pricing', data)
}

export function updateProductPricing(id, data) {
  return request.put(`/product-pricing/${id}`, data)
}

export function deleteProductPricing(id) {
  return request.delete(`/product-pricing/${id}`)
}

export function getCountryPricing(code) {
  return request.get(`/product-pricing/country/${code}`)
}

export function saveCountryPricing(data) {
  return request.post('/product-pricing/save-country', data)
}

export function batchSetProductPricing(data) {
  return request.post('/product-pricing/batch-set', data)
}

export function syncSparkCost() {
  return request.post('/product-pricing/sync-spark-cost')
}

export function getCountriesOverview() {
  return request.get('/product-pricing/countries-overview')
}
