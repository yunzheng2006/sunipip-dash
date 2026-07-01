import request from '@/utils/request'

export function getQueueStats() {
  return request.get('/queue-monitor/stats')
}

export function getFailedJobs(limit = 50) {
  return request.get('/queue-monitor/failed', { params: { limit } })
}

export function retryAllFailed() {
  return request.post('/queue-monitor/retry-all-failed')
}

export function flushFailed() {
  return request.post('/queue-monitor/flush-failed')
}
