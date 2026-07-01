import request from '@/utils/request'
export function getCustomerSpecialPrices(params) { return request.get('/customer-special-prices', { params }) }
export function createCustomerSpecialPrice(data) { return request.post('/customer-special-prices', data) }
export function updateCustomerSpecialPrice(id, data) { return request.put(`/customer-special-prices/${id}`, data) }
export function deleteCustomerSpecialPrice(id) { return request.delete(`/customer-special-prices/${id}`) }
