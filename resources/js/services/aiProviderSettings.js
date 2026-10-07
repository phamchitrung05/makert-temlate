/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client cho Settings > AI Providers.
 * METHODS: list(), saveProvider(), disableProvider(), testProvider(), syncProvider(),
 * saveModel(), updateSettings(). INPUT: form data; OUTPUT: catalog/settings envelope.
 * SIDE EFFECT: chỉ gọi HTTP; API key chỉ đi chiều ghi và không lưu ở frontend.
 * =====================================================================
 */
import { $api } from '@/utils/api'
import { SETTINGS_REQUEST_TIMEOUT_MS } from '@/services/settings'
/* eslint-disable camelcase -- Request fields follow the Laravel API contract. */

const unwrap = response => response?.success && 'data' in response ? response.data : response
const settingsRequestOptions = { timeout: SETTINGS_REQUEST_TIMEOUT_MS, retry: 0 }

export const aiProviderSettingsService = {
  /**
   * =====================================================================
   * CHỨC NĂNG: providers, presets và defaults.
   * =====================================================================
   * INPUT: không có.
   * OUTPUT: providers, presets và defaults.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async list() {
    return unwrap(await $api('/admin/settings/ai', settingsRequestOptions))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: saved provider.
   * =====================================================================
   * INPUT: metadata + optional write-only api_key.
   * OUTPUT: saved provider.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async saveProvider(payload, providerId = null) {
    return unwrap(await $api(providerId ? `/admin/settings/ai/providers/${providerId}` : '/admin/settings/ai/providers', {
      method: providerId ? 'PUT' : 'POST',
      body: payload,
    }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: disabled provider.
   * =====================================================================
   * INPUT: provider ID.
   * OUTPUT: disabled provider.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async disableProvider(providerId) {
    return unwrap(await $api(`/admin/settings/ai/providers/${providerId}/disable`, { method: 'POST' }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: safe connection test status.
   * =====================================================================
   * INPUT: provider/model optional.
   * OUTPUT: safe connection test status.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async testProvider(providerId, modelId = null) {
    return unwrap(await $api(`/admin/settings/ai/providers/${providerId}/test`, {
      method: 'POST',
      body: modelId ? { model_id: modelId } : {},
    }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: imported/unavailable counts.
   * =====================================================================
   * INPUT: provider ID.
   * OUTPUT: imported/unavailable counts.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async syncProvider(providerId) {
    return unwrap(await $api(`/admin/settings/ai/providers/${providerId}/sync`, { method: 'POST' }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: saved model.
   * =====================================================================
   * INPUT: provider ID + manual model metadata.
   * OUTPUT: saved model.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async saveModel(providerId, payload, modelId = null) {
    return unwrap(await $api(modelId
      ? `/admin/settings/ai/providers/${providerId}/models/${modelId}`
      : `/admin/settings/ai/providers/${providerId}/models`, {
      method: modelId ? 'PUT' : 'POST',
      body: payload,
    }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: updated settings.
   * =====================================================================
   * INPUT: typed defaults.
   * OUTPUT: updated settings.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  async updateSettings(payload) {
    return unwrap(await $api('/admin/settings/ai/settings', { ...settingsRequestOptions, method: 'PUT', body: payload }))
  },
}
