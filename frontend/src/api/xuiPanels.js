import request from '@/utils/request'

export function getXuiPanels() {
  return request.get('/xui-panels')
}

// 业务员可访问：只返回启用中的主面板，用于创建订单和批量转发（不需要 setting.manage 权限）
export function getUsableXuiPanels() {
  return request.get('/xui-panels/usable')
}

export function getXuiPanel(id) {
  return request.get(`/xui-panels/${id}`)
}

export function createXuiPanel(data) {
  return request.post('/xui-panels', data)
}

export function updateXuiPanel(id, data) {
  return request.put(`/xui-panels/${id}`, data)
}

export function deleteXuiPanel(id) {
  return request.delete(`/xui-panels/${id}`)
}

export function testXuiPanel(id) {
  return request.post(`/xui-panels/${id}/test`)
}

export function createXuiForward(panelId, data) {
  return request.post(`/xui-panels/${panelId}/create-forward`, data)
}

export function getXuiInbounds(panelId) {
  return request.get(`/xui-panels/${panelId}/inbounds`)
}

export function deleteXuiInbound(inboundId) {
  return request.delete(`/xui-panels/inbounds/${inboundId}`)
}

export function batchCreateXuiForward(panelId, data) {
  return request.post(`/xui-panels/${panelId}/batch-create-forward`, data)
}

export function getXuiBatchStatus(panelId, batchId) {
  return request.get(`/xui-panels/${panelId}/batch-status/${batchId}`)
}

export function syncAllXuiToMirror(panelId) {
  return request.post(`/xui-panels/${panelId}/sync-all-to-mirror`)
}

export function resyncXuiInboundToMirror(inboundId) {
  return request.post(`/xui-panels/inbounds/${inboundId}/resync-mirror`)
}
