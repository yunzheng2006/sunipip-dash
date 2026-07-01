import { platformApi } from './index'

/**
 * 获取我的设备列表
 */
export function getMyDevices() {
  return platformApi.get('/router/devices')
}

/**
 * 获取单个设备详情
 * @param {number|string} id
 */
export function getDevice(id) {
  return platformApi.get(`/router/devices/${id}`)
}

/**
 * 获取设备的 WiFi 账号列表
 * @param {number|string} deviceId
 */
export function getWifiAccounts(deviceId) {
  return platformApi.get(`/router/devices/${deviceId}/wifi-accounts`)
}

/**
 * 创建 WiFi 账号
 * @param {number|string} deviceId
 * @param {object} data
 */
export function createWifiAccount(deviceId, data) {
  return platformApi.post(`/router/devices/${deviceId}/wifi-accounts`, data)
}

/**
 * 更新 WiFi 账号
 * @param {number|string} id
 * @param {object} data
 */
export function updateWifiAccount(id, data) {
  return platformApi.put(`/router/wifi-accounts/${id}`, data)
}

/**
 * 删除 WiFi 账号
 * @param {number|string} id
 */
export function deleteWifiAccount(id) {
  return platformApi.delete(`/router/wifi-accounts/${id}`)
}

/**
 * 获取设备可用订阅列表
 * @param {number|string} deviceId
 */
export function getAvailableSubscriptions(deviceId) {
  return platformApi.get(`/router/devices/${deviceId}/available-subscriptions`)
}

/**
 * 获取设备实时状态（来自平台）
 * @param {number|string} deviceId
 */
export function getDeviceStatus(deviceId) {
  return platformApi.get(`/router/devices/${deviceId}/status`)
}
