// 客户门户 token 存取
// 注意：key 故意与 admin 面板不同（sunipip_token），防止同浏览器串 token
const TOKEN_KEY = 'sunipip_customer_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function removeToken() {
  localStorage.removeItem(TOKEN_KEY)
}

export function isLoggedIn() {
  return !!getToken()
}
