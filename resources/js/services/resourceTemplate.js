/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client cho Resource Template processing/preview
 * =====================================================================
 * Chỉ unwrap BaseResponse và giữ contract snake_case từ backend; polling và
 * state lifecycle nằm ở composable để service vẫn dễ kiểm thử.
 */
import { $api } from '@/utils/api'

const unwrap = response => response?.success && 'data' in response ? response.data : response

const normalize = payload => {
  const value = unwrap(payload)

  return value && typeof value === 'object' ? value : null
}

/** Input: resource version id. Output: template detail/capabilities. */
export const resourceTemplateService = {
  async show(id) {
    return normalize(await $api(`/admin/resource-versions/${id}/template`))
  },

  async process(id, payload = {}) {
    return normalize(await $api(`/admin/resource-versions/${id}/template/process`, {
      method: 'POST',
      body: payload,
    }))
  },

  async update(id, payload) {
    return normalize(await $api(`/admin/resource-versions/${id}/template`, {
      method: 'PATCH',
      body: payload,
    }))
  },

  async preview(id) {
    return normalize(await $api(`/admin/resource-versions/${id}/template/preview`, { method: 'POST' }))
  },

  async screenPreview(id, screenId, mediaAssetId) {
    return normalize(await $api(`/admin/resource-versions/${id}/template/screens/${screenId}/preview`, {
      method: 'POST',
      body: { media_asset_id: mediaAssetId },
    }))
  },
}
