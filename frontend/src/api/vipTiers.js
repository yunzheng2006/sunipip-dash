import request from '@/utils/request'

export function getVipTiers() { return request.get('/vip-tiers') }
export function createVipTier(data) { return request.post('/vip-tiers', data) }
export function updateVipTier(id, data) { return request.put(`/vip-tiers/${id}`, data) }
export function deleteVipTier(id) { return request.delete(`/vip-tiers/${id}`) }
export function recalculateAllVip() { return request.post('/vip-tiers/recalculate-all') }
