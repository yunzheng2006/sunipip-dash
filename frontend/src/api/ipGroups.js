import request from '@/utils/request'

export function getIpGroups(params) {
  return request.get('/ip-groups', { params })
}

export function getIpGroup(id) {
  return request.get(`/ip-groups/${id}`)
}

export function createIpGroup(data) {
  return request.post('/ip-groups', data)
}

export function updateIpGroup(id, data) {
  return request.put(`/ip-groups/${id}`, data)
}

export function deleteIpGroup(id) {
  return request.delete(`/ip-groups/${id}`)
}

// 不分页，下拉选择用
export function getAllIpGroups() {
  return request.get('/ip-groups/all')
}
