import request from '@/utils/request'

export function getMyDevices() {
  return request.get('/router/devices')
}

export function getDevice(id) {
  return request.get(`/router/devices/${id}`)
}

export function activateDevice(data) {
  return request.post('/router/activate', data)
}

export function getWifiAccounts(deviceId) {
  return request.get(`/router/devices/${deviceId}/wifi-accounts`)
}

export function createWifiAccount(deviceId, data) {
  return request.post(`/router/devices/${deviceId}/wifi-accounts`, data)
}

export function updateWifiAccount(accountId, data) {
  return request.put(`/router/wifi-accounts/${accountId}`, data)
}

export function deleteWifiAccount(accountId) {
  return request.delete(`/router/wifi-accounts/${accountId}`)
}

export function getAvailableSubscriptions(deviceId) {
  return request.get(`/router/devices/${deviceId}/available-subscriptions`)
}

export function getDeviceStatus(deviceId) {
  return request.get(`/router/devices/${deviceId}/status`)
}

export function getWifiProfile(accountId) {
  return request.get(`/router/wifi-accounts/${accountId}/ios-profile`, { responseType: 'blob' })
}

export function cleanStaleConnections(deviceId) {
  return request.post(`/router/devices/${deviceId}/clean-stale-connections`)
}
