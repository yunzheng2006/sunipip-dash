import request from '@/utils/request'

export function getFeishuSyncConfigs() {
  return request.get('/feishu-sync')
}
export function getFeishuSyncConfig(id) {
  return request.get(`/feishu-sync/${id}`)
}
export function createFeishuSyncConfig(data) {
  return request.post('/feishu-sync', data)
}
export function updateFeishuSyncConfig(id, data) {
  return request.put(`/feishu-sync/${id}`, data)
}
export function deleteFeishuSyncConfig(id) {
  return request.delete(`/feishu-sync/${id}`)
}
export function testFeishuConnection(id) {
  return request.post(`/feishu-sync/${id}/test`)
}
export function triggerFeishuSync(id, deleteOrphans = false) {
  return request.post(`/feishu-sync/${id}/sync`, { delete_orphans: deleteOrphans }, { timeout: 5 * 60 * 1000 })
}
export function previewFeishuSync(id) {
  return request.get(`/feishu-sync/${id}/preview`)
}
