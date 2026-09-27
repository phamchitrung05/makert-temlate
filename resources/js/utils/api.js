import { ofetch } from 'ofetch'

export const getAdminAccessToken = () => {
  if (typeof window === 'undefined')
    return null

  return window.sessionStorage.getItem('admin_access_token')
}

export const $api = ofetch.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  headers: {
    Accept: 'application/json',
  },
  async onRequest({ options }) {
    const accessToken = getAdminAccessToken()
    if (accessToken) {
      options.headers = new Headers(options.headers || {})
      options.headers.set('Authorization', 'Bearer ' + accessToken)
    }
  },
})
