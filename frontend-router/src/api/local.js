import { localApi } from './index'

/**
 * 获取设备状态（CPU、内存、磁盘、运行时间等）
 */
export function getStatus() {
  return localApi.get('/status')
}

/**
 * 获取网络接口信息
 */
export function getNetwork() {
  return localApi.get('/network')
}

/**
 * 获取服务状态列表
 */
export function getServices() {
  return localApi.get('/services')
}

/**
 * 获取已连接设备列表
 */
export function getConnectedDevices() {
  return localApi.get('/connected-devices')
}

/**
 * 重启指定服务
 * @param {string} service - 服务名称
 */
export function restartService(service) {
  return localApi.post('/restart-service', { service })
}
