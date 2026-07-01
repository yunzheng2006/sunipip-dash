import request from '@/utils/request'

export function getApprovals(params) {
  return request.get('/approvals', { params })
}

export function getApprovalStats() {
  return request.get('/approvals/stats')
}

export function submitApproval(data) {
  return request.post('/approvals', data)
}

export function getApproval(id) {
  return request.get(`/approvals/${id}`)
}

export function approveApproval(id, data) {
  return request.post(`/approvals/${id}/approve`, data)
}

export function rejectApproval(id, data) {
  return request.post(`/approvals/${id}/reject`, data)
}

export function cancelApproval(id) {
  return request.post(`/approvals/${id}/cancel`)
}
