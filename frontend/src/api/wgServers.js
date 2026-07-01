import request from '@/utils/request'

export function getWgServers(params) {
  return request.get('/wg-servers', { params })
}

export function getWgServer(id) {
  return request.get(`/wg-servers/${id}`)
}

export function createWgServer(data) {
  return request.post('/wg-servers', data)
}

export function updateWgServer(id, data) {
  return request.put(`/wg-servers/${id}`, data)
}

export function deleteWgServer(id) {
  return request.delete(`/wg-servers/${id}`)
}

export function getWgServerConfig(id) {
  return request.get(`/wg-servers/${id}/config`)
}

export function syncWgServerPeers(id) {
  return request.post(`/wg-servers/${id}/sync-peers`)
}
