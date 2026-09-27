import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { adminAuthService } from '@/services/adminAuth'

const TOKEN_KEY = 'admin_access_token'

const readStoredToken = () => {
  if (typeof window === 'undefined')
    return null

  return window.sessionStorage.getItem(TOKEN_KEY)
}

export const useAdminAuthStore = defineStore('adminAuth', () => {
  const token = shallowRef(readStoredToken())
  const user = shallowRef(null)
  const roles = shallowRef([])
  const permissions = shallowRef([])
  const isInitializing = shallowRef(false)
  const initialized = shallowRef(false)
  let initializePromise = null

  const isAuthenticated = computed(() => Boolean(token.value && user.value))

  const applySession = response => {
    token.value = response.accessToken ?? token.value
    user.value = response.user ?? null
    roles.value = response.roles ?? []
    permissions.value = response.permissions ?? []

    if (token.value && typeof window !== 'undefined')
      window.sessionStorage.setItem(TOKEN_KEY, token.value)

    initialized.value = true
  }

  const clearSession = () => {
    token.value = null
    user.value = null
    roles.value = []
    permissions.value = []
    initialized.value = true
    if (typeof window !== 'undefined')
      window.sessionStorage.removeItem(TOKEN_KEY)
  }

  const initialize = async () => {
    if (initialized.value)
      return isAuthenticated.value

    if (initializePromise)
      return initializePromise

    isInitializing.value = true
    initializePromise = (async () => {
      if (!token.value) {
        clearSession()
        isInitializing.value = false
        initializePromise = null
        
        return false
      }

      try {
        const response = await adminAuthService.me()

        applySession({ ...response, accessToken: token.value })
        
        return true
      }
      catch {
        clearSession()
        
        return false
      }
      finally {
        isInitializing.value = false
        initializePromise = null
      }
    })()

    return initializePromise
  }

  const login = async credentials => {
    const response = await adminAuthService.login({
      ...credentials,
      deviceName: credentials.deviceName ?? 'admin-web',
    })

    applySession(response)
    
    return response
  }

  const logout = async () => {
    try {
      if (token.value)
        await adminAuthService.revokeCurrentToken()
    }
    finally {
      clearSession()
    }
  }

  return {
    token: readonly(token),
    user: readonly(user),
    roles: readonly(roles),
    permissions: readonly(permissions),
    isInitializing: readonly(isInitializing),
    initialized: readonly(initialized),
    isAuthenticated,
    initialize,
    login,
    logout,
    clearSession,
  }
})
