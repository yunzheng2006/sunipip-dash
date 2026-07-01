import request from '@/utils/request'

export function getMyIps(params) {
  return request.get('/ips', { params })
}

export function getMyIp(id) {
  return request.get(`/ips/${id}`)
}

// 导出返回二进制流
export function exportMyIps(format = 'socks5', sort = 'id') {
  return request.get('/ips/export', {
    params: { format, sort },
    responseType: 'blob',
  })
}

// IP 分组
export function getIpGroups() { return request.get('/ip-groups') }
export function createIpGroup(data) { return request.post('/ip-groups', data) }
export function updateIpGroup(id, data) { return request.put(`/ip-groups/${id}`, data) }
export function deleteIpGroup(id) { return request.delete(`/ip-groups/${id}`) }
export function addIpsToGroup(groupId, proxyIpIds) { return request.post(`/ip-groups/${groupId}/add-ips`, { proxy_ip_ids: proxyIpIds }) }
export function removeIpsFromGroup(groupId, proxyIpIds) { return request.post(`/ip-groups/${groupId}/remove-ips`, { proxy_ip_ids: proxyIpIds }) }

// 导出成可扫描的 xlsx（含二维码）
// params.ids: 逗号分隔的 IP id 列表（可选，不传则导出全部）
export function exportMyIpsQr(params = {}) {
  return request.get('/ips/export-qr', {
    params,
    responseType: 'blob',
    timeout: 5 * 60 * 1000,
  })
}
