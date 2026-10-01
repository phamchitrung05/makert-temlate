import { computed, ref } from 'vue'
/* eslint-disable camelcase -- Catalog DTOs keep Laravel API field names. */
import { aiProviderSettingsService } from '@/services/aiProviderSettings'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: State orchestration dùng chung cho AI settings page.
 * METHODS: load(), saveProvider(), disableProvider(), testProvider(), syncProvider(),
 * saveModel(), updateSettings(). INPUT: form payload; OUTPUT: reactive catalog.
 * SIDE EFFECT: gọi service API; không chứa markup hoặc quy tắc capability.
 * =====================================================================
 */
export function useAiProviderSettings() {
  const providers = ref([])
  const presets = ref([])
  const settings = ref({})
  const loading = ref(false)
  const error = ref('')
  const saving = ref(false)

  const modelOptions = computed(() => providers.value
    .filter(provider => provider.is_active && provider.has_api_key)
    .flatMap(provider => (provider.models ?? []).filter(model => model.is_enabled && model.is_available).map(model => ({
      ...model,
      provider_id: provider.id,
      provider_key: provider.key,
      provider_label: provider.name,
    }))))

  /**
   * =====================================================================
   * CHỨC NĂNG: fresh server catalog.
   * =====================================================================
   * INPUT: none.
   * OUTPUT: fresh server catalog.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async function load() {
    loading.value = true
    error.value = ''
    try {
      const data = await aiProviderSettingsService.list()

      providers.value = data.providers ?? []
      presets.value = data.presets ?? []
      settings.value = { ...settings.value, ...(data.settings ?? {}) }
    }
    catch (reason) {
      error.value = reason?.data?.message ?? reason?.message ?? 'Không thể tải cấu hình AI.'
      throw reason
    }
    finally {
      loading.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: provider replaces local row after save.
   * =====================================================================
   * INPUT: provider form.
   * OUTPUT: provider replaces local row after save.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async function saveProvider(payload, providerId = null) {
    saving.value = true
    try {
      const provider = await aiProviderSettingsService.saveProvider(payload, providerId)
      const value = provider?.data ?? provider
      const index = providers.value.findIndex(item => item.id === value.id)
      if (index === -1) providers.value.push(value)
      else providers.value[index] = value
      
      return value
    }
    finally {
      saving.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: row disabled and retained locally.
   * =====================================================================
   * INPUT: provider ID.
   * OUTPUT: row disabled and retained locally.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async function disableProvider(providerId) {
    const value = await aiProviderSettingsService.disableProvider(providerId)
    const provider = value?.data ?? value
    const index = providers.value.findIndex(item => item.id === provider.id)
    if (index !== -1) providers.value[index] = provider
    
    return provider
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: test result; error remains visible to caller.
   * =====================================================================
   * INPUT: provider/model.
   * OUTPUT: test result; error remains visible to caller.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  function testProvider(providerId, modelId = null) {
    return aiProviderSettingsService.testProvider(providerId, modelId)
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: sync counts, then reloads catalog.
   * =====================================================================
   * INPUT: provider ID.
   * OUTPUT: sync counts, then reloads catalog.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async function syncProvider(providerId) {
    const result = await aiProviderSettingsService.syncProvider(providerId)

    await load()
    
    return result
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: model row + provider reload.
   * =====================================================================
   * INPUT: model form.
   * OUTPUT: model row + provider reload.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async function saveModel(providerId, payload, modelId = null) {
    const result = await aiProviderSettingsService.saveModel(providerId, payload, modelId)

    await load()
    
    return result
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: settings state.
   * =====================================================================
   * INPUT: typed defaults.
   * OUTPUT: settings state.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async function updateSettings(payload) {
    const result = await aiProviderSettingsService.updateSettings(payload)

    settings.value = { ...settings.value, ...(result?.settings ?? result) }
    
    return settings.value
  }

  return { providers, presets, settings, modelOptions, loading, saving, error, load, saveProvider, disableProvider, testProvider, syncProvider, saveModel, updateSettings }
}
