import request from '@/utils/request'

export function getVipInfo() {
  return request.get('/vip')
}
