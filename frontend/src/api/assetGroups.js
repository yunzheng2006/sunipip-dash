import request from '@/utils/request'

export function getAssetGroups(params) {
  return request.get('/asset-groups', { params })
}

export function getAssetGroup(id) {
  return request.get(`/asset-groups/${id}`)
}

export function createAssetGroup(data) {
  return request.post('/asset-groups', data)
}

export function updateAssetGroup(id, data) {
  return request.put(`/asset-groups/${id}`, data)
}

export function deleteAssetGroup(id) {
  return request.delete(`/asset-groups/${id}`)
}

// 不分页，下拉选择用
export function getAllAssetGroups() {
  return request.get('/asset-groups/all')
}

export function mergeAssetGroups(data) {
  return request.post('/asset-groups/merge', data)
}
