import request from '@/utils/request'

export function getRoles() {
  return request.get('/roles')
}

export function getRole(id) {
  return request.get(`/roles/${id}`)
}

export function createRole(data) {
  return request.post('/roles', data)
}

export function updateRole(id, data) {
  return request.put(`/roles/${id}`, data)
}

export function deleteRole(id) {
  return request.delete(`/roles/${id}`)
}

export function getAllPermissions() {
  return request.get('/roles/all-permissions')
}
