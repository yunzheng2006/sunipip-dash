import request from '@/utils/request'

export function getFinanceOverview() {
  return request.get('/finance/overview')
}

export function getFinanceTrend(days = 30) {
  return request.get('/finance/trend', { params: { days } })
}

export function getFinanceRanking(limit = 20) {
  return request.get('/finance/ranking', { params: { limit } })
}

export function getSalesStats(params) {
  return request.get('/sales-stats', { params })
}

export function getManualPerformances(params) {
  return request.get('/manual-performances', { params })
}

export function addManualPerformance(data) {
  return request.post('/manual-performances', data)
}

export function deleteManualPerformance(id) {
  return request.delete(`/manual-performances/${id}`)
}

export function getSalesStatsNew(params) {
  return request.get('/sales-stats-new', { params })
}

export function getManualStatEntries(params) {
  return request.get('/sales-stats-new/manual-entries', { params })
}

export function addManualStatEntry(data) {
  return request.post('/sales-stats-new/manual-entries', data)
}

export function deleteManualStatEntry(id) {
  return request.delete(`/sales-stats-new/manual-entries/${id}`)
}
