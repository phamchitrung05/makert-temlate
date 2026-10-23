/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store chia sẻ catalog AI Providers (dùng chung nhiều màn hình).
 * =====================================================================
 *
 * Trước đây useAiProviderSettings là composable tạo state mới mỗi lần gọi, nên
 * Settings page, StyleAnalyzeDialog và VisualStyleCreateDialog mỗi nơi fetch
 * catalog riêng. Store này giữ catalog một lần và share cho mọi consumer,
 * đồng thời cung cấp mutation provider/model/settings đi qua service.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - load(): tải providers/presets/writingProfiles/settings; bỏ response cũ.
 * - saveProvider(payload, providerId)/deleteProvider/disableProvider: mutation.
 * - testProvider(providerId, modelId): test kết nối (không đổi state).
 * - syncProvider(providerId): sync rồi reload catalog.
 * - saveModel(providerId, payload, modelId)/updateSettings(payload): mutation.
 * - ensureLoaded(): chỉ tải khi catalog chưa có (dùng cho dialog mở nhanh).
 * - modelOptions: computed các model enabled+available của provider active.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : payload form và id provider/model.
 * - OUTPUT: state readonly (providers/presets/writingProfiles/settings) + actions.
 * - SIDE EFFECT: gọi aiProviderSettingsService; không gọi provider trực tiếp.
 * =====================================================================
 */
/* eslint-disable camelcase -- Laravel AI Settings API fields keep snake_case. */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { aiProviderSettingsService } from '@/services/aiProviderSettings'

export const useAiCatalogStore = defineStore('aiCatalog', () => {
  const providers = shallowRef([])
  const presets = shallowRef([])
  const writingProfiles = shallowRef([])
  const settings = shallowRef({})
  const isLoading = shallowRef(false)
  const isSaving = shallowRef(false)
  const error = shallowRef('')
  const loaded = shallowRef(false)
  let loadRequestId = 0

  /** Computed các model enabled+available thuộc provider active (cho select). */
  const modelOptions = computed(() => providers.value
    .filter(provider => provider.is_active && provider.has_api_key)
    .flatMap(provider => (provider.models ?? [])
      .filter(model => model.is_enabled && model.is_available)
      .map(model => ({
        ...model,
        provider_id: provider.id,
        provider_key: provider.key,
        provider_label: provider.name,
      }))))

  /** Output: tải catalog mới nhất; bỏ response đến trễ. */
  const load = async () => {
    const requestId = ++loadRequestId

    isLoading.value = true
    error.value = ''

    try {
      const data = await aiProviderSettingsService.list()

      if (requestId !== loadRequestId) return null

      providers.value = data?.providers ?? []
      presets.value = data?.presets ?? []
      writingProfiles.value = data?.writing_profiles ?? []
      settings.value = { ...settings.value, ...(data?.settings ?? {}) }
      loaded.value = true

      return data
    }
    catch (reason) {
      if (requestId === loadRequestId)
        error.value = reason?.data?.message ?? 'Không thể tải cấu hình AI. Hãy thử lại.'

      throw reason
    }
    finally {
      if (requestId === loadRequestId) isLoading.value = false
    }
  }

  /** Output: chỉ tải khi catalog chưa load; dialog gọi nhanh không fetch lại. */
  const ensureLoaded = async () => {
    if (loaded.value || isLoading.value) return
    await load()
  }

  const runSave = async callback => {
    isSaving.value = true
    try {
      return await callback()
    }
    finally {
      isSaving.value = false
    }
  }

  /** Input: provider form + optional id. Output: provider mới nhất trong list. */
  const saveProvider = (payload, providerId = null) => runSave(async () => {
    const provider = await aiProviderSettingsService.saveProvider(payload, providerId)
    const value = provider?.data ?? provider
    const index = providers.value.findIndex(item => item.id === value.id)

    if (index === -1) providers.value = [...providers.value, value]
    else {
      const next = [...providers.value]

      next[index] = value
      providers.value = next
    }

    return value
  })

  /** Input: provider id. Output: gỡ provider khỏi list sau khi xóa server. */
  const deleteProvider = async providerId => {
    const result = await aiProviderSettingsService.deleteProvider(providerId)

    providers.value = providers.value.filter(provider => provider.id !== providerId)

    return result
  }

  /** Input: provider id. Output: provider đã disabled cập nhật trong list. */
  const disableProvider = async providerId => {
    const value = await aiProviderSettingsService.disableProvider(providerId)
    const provider = value?.data ?? value
    const index = providers.value.findIndex(item => item.id === provider.id)

    if (index !== -1) {
      const next = [...providers.value]

      next[index] = provider
      providers.value = next
    }

    return provider
  }

  /** Input: provider/model. Output: test status; không mutate state. */
  const testProvider = (providerId, modelId = null) =>
    aiProviderSettingsService.testProvider(providerId, modelId)

  /** Input: provider id. Output: sync counts; reload catalog sau sync. */
  const syncProvider = async providerId => {
    const result = await aiProviderSettingsService.syncProvider(providerId)

    await load()

    return result
  }

  /** Input: provider/model payload. Output: model đã lưu; reload catalog. */
  const saveModel = async (providerId, payload, modelId = null) => {
    const result = await aiProviderSettingsService.saveModel(providerId, payload, modelId)

    await load()

    return result
  }

  /** Input: typed defaults. Output: settings đã merge version. */
  const updateSettings = async payload => {
    const result = await aiProviderSettingsService.updateSettings({
      ...payload,
      ...(settings.value.settings_version ? { settings_version: settings.value.settings_version } : {}),
    })

    settings.value = { ...settings.value, ...(result?.settings ?? result) }

    return settings.value
  }

  /** Output: dọn state khi cần (ví dụ logout hoặc reset settings page). */
  const reset = () => {
    loadRequestId++
    providers.value = []
    presets.value = []
    writingProfiles.value = []
    settings.value = {}
    isLoading.value = false
    isSaving.value = false
    error.value = ''
    loaded.value = false
  }

  return {
    providers: readonly(providers),
    presets: readonly(presets),
    writingProfiles: readonly(writingProfiles),
    settings: readonly(settings),
    isLoading: readonly(isLoading),
    isSaving: readonly(isSaving),
    error: readonly(error),
    loaded: readonly(loaded),
    modelOptions,
    load,
    ensureLoaded,
    saveProvider,
    deleteProvider,
    disableProvider,
    testProvider,
    syncProvider,
    saveModel,
    updateSettings,
    reset,
  }
})
