import request from '@/utils/request'

export function getSmsProviders() { return request.get('/sms-providers') }
export function createSmsProvider(data) { return request.post('/sms-providers', data) }
export function updateSmsProvider(id, data) { return request.put(`/sms-providers/${id}`, data) }
export function deleteSmsProvider(id) { return request.delete(`/sms-providers/${id}`) }
export function testSmsProvider(id, data) { return request.post(`/sms-providers/${id}/test`, data) }
export function testExpirySms(id, data) { return request.post(`/sms-providers/${id}/test-expiry`, data) }
export function getRegistrationSettings() { return request.get('/settings/registration') }
export function updateRegistrationSettings(data) { return request.put('/settings/registration', data) }
