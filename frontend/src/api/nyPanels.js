import request from '@/utils/request'

export function getNyPanels() {
  return request.get('/ny-panels')
}

export function getNyPanel(id) {
  return request.get(`/ny-panels/${id}`)
}

export function createNyPanel(data) {
  return request.post('/ny-panels', data)
}

export function updateNyPanel(id, data) {
  return request.put(`/ny-panels/${id}`, data)
}

export function deleteNyPanel(id) {
  return request.delete(`/ny-panels/${id}`)
}

export function testNyPanel(id) {
  return request.post(`/ny-panels/${id}/test`)
}

export function syncNyDeviceGroups(id) {
  return request.post(`/ny-panels/${id}/sync-device-groups`)
}

export function updateNyDeviceGroups(id, items) {
  return request.put(`/ny-panels/${id}/device-groups`, { items })
}

export function getNyEnabledDeviceGroups() {
  return request.get('/ny-panels/enabled-device-groups')
}
