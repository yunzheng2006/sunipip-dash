import request from '@/utils/request'
export function getForwardPlans() { return request.get('/forward-plans') }
export function createForwardPlan(data) { return request.post('/forward-plans', data) }
export function updateForwardPlan(id, data) { return request.put(`/forward-plans/${id}`, data) }
export function deleteForwardPlan(id) { return request.delete(`/forward-plans/${id}`) }
