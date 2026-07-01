import request from '@/utils/request'

export function getMarketingData(mode = 'all') {
  return request.get('/analytics/marketing', { params: { mode } })
}

export function getPricingData(days = 30, minSpent = null) {
  const params = { days }
  if (minSpent) params.min_spent = minSpent
  return request.get('/analytics/pricing', { params })
}

export function getProductsData(days = 30) {
  return request.get('/analytics/products', { params: { days } })
}

export function getCustomerDetail(id) {
  return request.get(`/analytics/customer-detail/${id}`)
}
