import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'

const API_BASE = import.meta.env.VITE_API_URL || ''
function absUrl(path) {
  if (!path) return path
  if (path.startsWith('http')) return path
  return API_BASE + path
}

export const useAppStore = defineStore('app', () => {
  const sidebarCollapsed = ref(false)
  const mobileSidebarOpen = ref(false)
  const platformName = ref('SuniPIP 管理平台')

  const siteName = ref('SuniPIP')
  const siteLogo = ref(null)
  let _fetching = false

  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  function toggleMobileSidebar() {
    mobileSidebarOpen.value = !mobileSidebarOpen.value
  }

  function closeMobileSidebar() {
    mobileSidebarOpen.value = false
  }

  async function fetchSiteInfo() {
    if (_fetching) return
    _fetching = true
    try {
      const res = await request.get('/site-info')
      siteName.value = res.site_name || 'SuniPIP'
      siteLogo.value = absUrl(res.site_logo) || null
      if (res.site_favicon) {
        let link = document.querySelector("link[rel~='icon']")
        if (!link) {
          link = document.createElement('link')
          link.rel = 'icon'
          document.head.appendChild(link)
        }
        link.href = absUrl(res.site_favicon)
      }
      if (res.site_name) document.title = res.site_name + ' - 管理后台'
    } catch {} finally { _fetching = false }
  }

  /** 设置页保存后立即刷新 */
  function updateSiteInfo(name, logo) {
    if (name !== undefined) siteName.value = name
    if (logo !== undefined) siteLogo.value = logo
  }

  return {
    sidebarCollapsed,
    mobileSidebarOpen,
    platformName,
    siteName,
    siteLogo,
    toggleSidebar,
    toggleMobileSidebar,
    closeMobileSidebar,
    fetchSiteInfo,
    updateSiteInfo,
  }
})
