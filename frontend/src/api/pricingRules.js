import request from '@/utils/request'

export function getPricingRules(params) {
  return request.get('/pricing-rules', { params })
}

export function getPricingRule(id) {
  return request.get(`/pricing-rules/${id}`)
}

export function createPricingRule(data) {
  return request.post('/pricing-rules', data)
}

export function updatePricingRule(id, data) {
  return request.put(`/pricing-rules/${id}`, data)
}

export function deletePricingRule(id) {
  return request.delete(`/pricing-rules/${id}`)
}

export function lookupPricing(params) {
  return request.get('/pricing-rules/lookup', { params })
}

// ========= Spark 定价 =========
export function getSparkPricingRules(params) {
  return request.get('/spark-pricing', { params })
}

export function getSparkPricingRule(id) {
  return request.get(`/spark-pricing/${id}`)
}

export function createSparkPricingRule(data) {
  return request.post('/spark-pricing', data)
}

export function updateSparkPricingRule(id, data) {
  return request.put(`/spark-pricing/${id}`, data)
}

export function deleteSparkPricingRule(id) {
  return request.delete(`/spark-pricing/${id}`)
}

export function getSparkPricingCountries() {
  return request.get('/spark-pricing/countries')
}
