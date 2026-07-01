import request from '@/utils/request'

// Options (dropdown data)
export function getRouterCatalogOptions() {
  return request.get('/router-catalog/options')
}

// Router Models
export function getRouterModels(params) {
  return request.get('/router-catalog/router-models', { params })
}
export function createRouterModel(data) {
  return request.post('/router-catalog/router-models', data)
}
export function updateRouterModel(id, data) {
  return request.put(`/router-catalog/router-models/${id}`, data)
}
export function deleteRouterModel(id) {
  return request.delete(`/router-catalog/router-models/${id}`)
}

// AP Models
export function getApModels(params) {
  return request.get('/router-catalog/ap-models', { params })
}
export function createApModel(data) {
  return request.post('/router-catalog/ap-models', data)
}
export function updateApModel(id, data) {
  return request.put(`/router-catalog/ap-models/${id}`, data)
}
export function deleteApModel(id) {
  return request.delete(`/router-catalog/ap-models/${id}`)
}

// Bundles
export function getBundles(params) {
  return request.get('/router-catalog/bundles', { params })
}
export function createBundle(data) {
  return request.post('/router-catalog/bundles', data)
}
export function updateBundle(id, data) {
  return request.put(`/router-catalog/bundles/${id}`, data)
}
export function deleteBundle(id) {
  return request.delete(`/router-catalog/bundles/${id}`)
}
