import request from '@/utils/request'

export function getRouterDeviceStats() {
  return request.get('/router-devices/stats')
}

export function getRouterDevices(params) {
  return request.get('/router-devices', { params })
}

export function getRouterDevice(id) {
  return request.get(`/router-devices/${id}`)
}

export function createRouterDevice(data) {
  return request.post('/router-devices', data)
}

export function updateRouterDevice(id, data) {
  return request.put(`/router-devices/${id}`, data)
}

export function deleteRouterDevice(id) {
  return request.delete(`/router-devices/${id}`)
}

export function generateInstallToken(id) {
  return request.post(`/router-devices/${id}/install-token`)
}

export function bindDevice(id, data) {
  return request.post(`/router-devices/${id}/bind`, data)
}

export function unbindDevice(id) {
  return request.post(`/router-devices/${id}/unbind`)
}

export function pushConfig(id) {
  return request.post(`/router-devices/${id}/push-config`)
}

export function getDeviceEvents(id, params) {
  return request.get(`/router-devices/${id}/events`, { params })
}

export function getDeviceWifiAccounts(id) {
  return request.get(`/router-devices/${id}/wifi-accounts`)
}

export function getAvailableSubscriptions(deviceId) {
  return request.get(`/router-devices/${deviceId}/available-subscriptions`)
}

export function createWifiAccount(deviceId, data) {
  return request.post(`/router-devices/${deviceId}/wifi-accounts`, data)
}

export function updateWifiAccount(accountId, data) {
  return request.put(`/router-wifi-accounts/${accountId}`, data)
}

export function deleteWifiAccount(accountId) {
  return request.delete(`/router-wifi-accounts/${accountId}`)
}

export function rebootDevice(id) {
  return request.post(`/router-devices/${id}/reboot`)
}

export function restartService(id, data) {
  return request.post(`/router-devices/${id}/restart-service`, data)
}


export function toggleTrunkDhcp(id, data) {
  return request.post(`/router-devices/${id}/toggle-trunk-dhcp`, data)
}

export function getAgentVersion() {
  return request.get('/router-devices/agent-version')
}

export function uploadAgentBinary(formData) {
  return request.post('/router-devices/agent-upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120000,
  })
}
