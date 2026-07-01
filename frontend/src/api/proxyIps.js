import request from '@/utils/request'

export function getProxyIps(params) {
  return request.get('/proxy-ips', { params })
}

export function getProxyIp(id) {
  return request.get(`/proxy-ips/${id}`)
}

export function createProxyIp(data) {
  return request.post('/proxy-ips', data)
}

export function updateProxyIp(id, data) {
  return request.put(`/proxy-ips/${id}`, data)
}

export function deleteProxyIp(id) {
  return request.delete(`/proxy-ips/${id}`)
}

export function batchCreateProxyIps(data) {
  return request.post('/proxy-ips/batch', data)
}

export function batchAssignProxyIps(data) {
  return request.post('/proxy-ips/batch-assign', data)
}

export function batchReleaseProxyIps(data) {
  return request.post('/proxy-ips/batch-release', data)
}

export function batchDeleteProxyIps(data) {
  return request.post('/proxy-ips/batch-delete', data)
}

export function batchMoveGroupProxyIps(data) {
  return request.post('/proxy-ips/batch-move-group', data)
}

export function assignProxyIp(id, data) {
  return request.post(`/proxy-ips/${id}/assign`, data)
}

export function unassignProxyIp(id) {
  return request.post(`/proxy-ips/${id}/unassign`)
}

export function getProxyIpStats() {
  return request.get('/proxy-ips/stats')
}

export function importProxyIps(formData) {
  return request.post('/proxy-ips/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export function releaseProxyIp(id, data) {
  return request.post(`/proxy-ips/${id}/release`, data)
}

export function verifySparkRelease(id) {
  return request.post(`/proxy-ips/${id}/verify-spark-release`)
}

export function retrySparkRelease(id) {
  return request.post(`/proxy-ips/${id}/retry-spark-release`)
}

export function batchAddToTestPool(data) {
  return request.post('/proxy-ips/batch-test-pool', data)
}

export function batchRemoveFromTestPool(data) {
  return request.post('/proxy-ips/batch-remove-test-pool', data)
}

export function getTestPoolIps(params) {
  return request.get('/proxy-ips/test-pool', { params })
}

export function testPoolAssign(data) {
  return request.post('/proxy-ips/test-pool-assign', data)
}

export function testPoolUnassign(data) {
  return request.post('/proxy-ips/test-pool-unassign', data)
}
