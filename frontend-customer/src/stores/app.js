import { defineStore } from 'pinia'
import { ref } from 'vue'

const API_BASE = import.meta.env.VITE_API_URL || ''

function absUrl(path) {
  if (!path) return path
  if (path.startsWith('http')) return path
  return API_BASE + path
}

export const useAppStore = defineStore('app', () => {
  const siteName = ref('SuniPIP')
  const siteLogo = ref(null)
  const storeBanner = ref({ enabled: false, promises: [], buttons: [] })
  const floatContact = ref([])
  const supportWechat = ref('')
  const supportPhone = ref('')
  const supportQrImage = ref(null)
  const selfRefundEnabled = ref(false)
  const partnershipContactImage = ref(null)
  const vipDetailImage = ref(null)
  let _fetching = false

  async function fetchSiteInfo() {
    if (_fetching) return
    _fetching = true
    try {
      const resp = await fetch(API_BASE + '/api/v1/site-info')
      const data = await resp.json()
      const info = data?.data || data
      siteName.value = info.site_name || 'SuniPIP'
      siteLogo.value = absUrl(info.site_logo) || null
      if (info.store_banner) {
        const banner = { ...info.store_banner }
        if (Array.isArray(banner.buttons)) {
          banner.buttons = banner.buttons.map(b => ({
            ...b,
            image_url: absUrl(b.image_url),
          }))
        }
        storeBanner.value = banner
      }
      if (Array.isArray(info.float_contact)) {
        floatContact.value = info.float_contact.map(c => ({
          ...c,
          image_url: absUrl(c.image_url),
        }))
      }
      supportWechat.value = info.support_wechat || ''
      supportPhone.value = info.support_phone || ''
      supportQrImage.value = absUrl(info.support_qr_image) || null
      selfRefundEnabled.value = !!info.self_refund_enabled
      partnershipContactImage.value = absUrl(info.partnership_contact_image) || null
      vipDetailImage.value = absUrl(info.vip_detail_image) || null
      if (info.site_favicon) {
        let link = document.querySelector("link[rel~='icon']")
        if (!link) {
          link = document.createElement('link')
          link.rel = 'icon'
          document.head.appendChild(link)
        }
        link.href = absUrl(info.site_favicon)
      }
      if (info.site_name) document.title = info.site_name
    } catch {} finally { _fetching = false }
  }

  return { siteName, siteLogo, storeBanner, floatContact, supportWechat, supportPhone, supportQrImage, selfRefundEnabled, partnershipContactImage, vipDetailImage, fetchSiteInfo }
})
