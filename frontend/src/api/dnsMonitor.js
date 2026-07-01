import request from '@/utils/request'

// ========== Agents ==========
export function getDnsAgents() {
  return request.get('/dns-agents')
}

export function createDnsAgent(data) {
  return request.post('/dns-agents', data)
}

export function deleteDnsAgent(id) {
  return request.delete(`/dns-agents/${id}`)
}

export function regenerateAgentKey(id) {
  return request.post(`/dns-agents/${id}/regenerate-key`)
}

// ========== Targets ==========
export function getDnsTargets() {
  return request.get('/dns-targets')
}

export function getDnsTarget(id) {
  return request.get(`/dns-targets/${id}`)
}

export function createDnsTarget(data) {
  return request.post('/dns-targets', data)
}

export function updateDnsTarget(id, data) {
  return request.put(`/dns-targets/${id}`, data)
}

export function deleteDnsTarget(id) {
  return request.delete(`/dns-targets/${id}`)
}

export function getDnsTargetProbes(id, limit = 100) {
  return request.get(`/dns-targets/${id}/probes`, { params: { limit } })
}

export function getDnsTargetEvents(id) {
  return request.get(`/dns-targets/${id}/events`)
}

export function manualFailover(id, reason) {
  return request.post(`/dns-targets/${id}/failover`, { reason })
}

export function manualFailback(id, reason) {
  return request.post(`/dns-targets/${id}/failback`, { reason })
}
