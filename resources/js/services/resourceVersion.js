/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client Resource Version và media fields
 * =====================================================================
 */
import { $api } from '@/utils/api'

const unwrap = response => response?.success && 'data' in response ? response.data : response

const normalize = version => {
  if (!version)
    return null

  return {
    ...version,
    media: {
      package: version.media?.package ?? null,
      documentation: Array.isArray(version.media?.documentation) ? version.media.documentation : [],
    },
  }
}

const toPayload = payload => ({
  'resource_id': payload.resourceId ?? payload.resource_id,
  version: payload.version,
  changelog: payload.changelog,
  requirements: payload.requirements,
  'is_default': payload.isDefault ?? payload.is_default,
  media: {
    'package_id': payload.package?.id ?? null,
    'documentation_ids': Array.isArray(payload.documentation)
      ? payload.documentation.map(asset => asset.id).filter(Boolean)
      : [],
  },
})

export const resourceVersionService = {
  async list(params = {}) {
    const response = await $api('/admin/resource-versions', { query: params })
    const payload = unwrap(response)

    return {
      items: Array.isArray(payload) ? payload : payload?.items ?? [],
      itemsLength: response?.meta?.pagination?.total ?? (Array.isArray(payload) ? payload.length : 0),
      pagination: response?.meta?.pagination ?? null,
    }
  },

  async show(id) {
    return normalize(unwrap(await $api(`/admin/resource-versions/${id}`)))
  },

  async create(payload) {
    return normalize(unwrap(await $api('/admin/resource-versions', {
      method: 'POST',
      body: toPayload(payload),
    })))
  },

  async update(id, payload) {
    return normalize(unwrap(await $api(`/admin/resource-versions/${id}`, {
      method: 'PUT',
      body: toPayload(payload),
    })))
  },

  async remove(id) {
    return $api(`/admin/resource-versions/${id}`, { method: 'DELETE' })
  },

  async ready(id) {
    return normalize(unwrap(await $api(`/admin/resource-versions/${id}/ready`, { method: 'POST' })))
  },
}
