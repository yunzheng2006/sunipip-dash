import request from '@/utils/request'

export function getPricingMultipliers() {
  return request.get('/pricing-multipliers')
}

export function createPricingMultiplier(data) {
  return request.post('/pricing-multipliers', data)
}

export function updatePricingMultiplier(id, data) {
  return request.put(`/pricing-multipliers/${id}`, data)
}

export function deletePricingMultiplier(id) {
  return request.delete(`/pricing-multipliers/${id}`)
}

export function batchSetMultipliers(data) {
  return request.post('/pricing-multipliers/batch-set', data)
}

export function previewPricing(params) {
  return request.get('/pricing-multipliers/preview', { params })
}

export function getProductPriceList(params) {
  return request.get('/pricing-multipliers/product-list', { params })
}
