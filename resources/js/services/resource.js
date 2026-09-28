/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp API client cho nghiệp vụ Resource admin
 * =====================================================================
 *
 * Service này là boundary duy nhất giữa Pinia Resource store và Laravel API
 * (hoặc MSW fake API trong local). Service chuẩn hóa response envelope và đổi
 * tên field giữa camelCase của form Vue với snake_case của API.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - list(): lấy danh sách resource theo query server-side
 * - show(): lấy chi tiết một resource
 * - create(): tạo resource mới
 * - update(): cập nhật resource
 * - remove(): xoá mềm resource
 * - publish(): chuyển resource sang published
 * - archive(): chuyển resource sang archived
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id và payload resource từ Pinia store
 * - OUTPUT: payload đã unwrap từ BaseResponse hoặc lỗi HTTP do $api ném ra
 * =====================================================================
 */
import { $api } from '@/utils/api'

/**
 * Lấy data payload từ response envelope chung của Laravel.
 *
 * Input: response JSON có thể là envelope `{ success, data }` hoặc payload phẳng.
 * Output: object data bên trong envelope hoặc chính response nếu đã phẳng.
 */
const unwrapApiResponse = response => {
  if (response && typeof response === 'object' && 'success' in response && 'data' in response)
    return response.data

  return response
}

/**
 * Chuẩn hóa resource detail về tên field mà form Vue sử dụng.
 *
 * Input: resource detail từ Laravel ResourceItem hoặc fake API.
 * Output: object detail có các field camelCase ổn định cho component.
 */
const normalizeResource = resource => {
  if (!resource)
    return null

  return {
    ...resource,
    shortDescription: resource.shortDescription ?? resource.short_description ?? '',
    demoUrl: resource.demoUrl ?? resource.demo_url ?? '',
    documentationUrl: resource.documentationUrl ?? resource.documentation_url ?? '',
    isFeatured: resource.isFeatured ?? resource.is_featured ?? false,
    media: {
      cover: resource.media?.cover ?? null,
      preview: Array.isArray(resource.media?.preview) ? resource.media.preview : [],
    },
  }
}

/**
 * Đổi payload form camelCase thành contract snake_case của Laravel.
 *
 * Input: form payload có shortDescription/demoUrl/documentationUrl/isFeatured.
 * Output: payload API không còn các key camelCase tương ứng.
 */
const toApiPayload = payload => {
  const apiPayload = {
    ...payload,
    'short_description': payload.shortDescription,
    'demo_url': payload.demoUrl,
    'documentation_url': payload.documentationUrl,
    'is_featured': payload.isFeatured,
    media: {
      'cover_id': payload.cover?.id ?? null,
      'preview_ids': Array.isArray(payload.preview)
        ? payload.preview.map(asset => asset.id).filter(Boolean)
        : [],
    },
  }

  delete apiPayload.shortDescription
  delete apiPayload.demoUrl
  delete apiPayload.documentationUrl
  delete apiPayload.isFeatured
  delete apiPayload.cover
  delete apiPayload.preview

  return apiPayload
}

export const resourceService = {
  /**
   * Lấy danh sách resource phục vụ VDataTableServer.
   *
   * Input: params gồm search, status, type, page, per_page, sortBy và orderBy.
   * Output: `{ items, itemsLength, pagination }` đã map khỏi response envelope.
   */
  async list(params = {}) {
    const response = await $api('/admin/resources', { query: params })
    const payload = unwrapApiResponse(response)

    return {
      items: payload?.items ?? [],
      itemsLength: payload?.itemsLength ?? 0,
      pagination: response?.meta?.pagination ?? null,
    }
  },

  /**
   * Lấy chi tiết resource để mở form Add/Edit.
   *
   * Input: id resource.
   * Output: ResourceItem đã chuẩn hóa field cho Vue form.
   */
  async show(id) {
    const response = await $api(`/admin/resources/${id}`)

    return normalizeResource(unwrapApiResponse(response))
  },

  /**
   * Tạo resource mới.
   *
   * Input: payload resource từ ResourceForm.
   * Output: ResourceItem vừa tạo.
   */
  async create(payload) {
    const response = await $api('/admin/resources', {
      method: 'POST',
      body: toApiPayload(payload),
    })

    return normalizeResource(unwrapApiResponse(response))
  },

  /**
   * Cập nhật resource hiện có.
   *
   * Input: id resource và payload thay đổi.
   * Output: ResourceItem sau cập nhật.
   */
  async update(id, payload) {
    const response = await $api(`/admin/resources/${id}`, {
      method: 'PUT',
      body: toApiPayload(payload),
    })

    return normalizeResource(unwrapApiResponse(response))
  },

  /**
   * Xoá mềm resource.
   *
   * Input: id resource.
   * Output: response rỗng hoặc envelope thành công tùy backend.
   */
  async remove(id) {
    return $api(`/admin/resources/${id}`, { method: 'DELETE' })
  },

  /**
   * Publish resource qua workflow nghiệp vụ riêng của backend.
   *
   * Input: id resource đang ở trạng thái được phép publish.
   * Output: ResourceItem sau khi publish.
   */
  async publish(id) {
    const response = await $api(`/admin/resources/${id}/publish`, { method: 'POST' })

    return normalizeResource(unwrapApiResponse(response))
  },

  /**
   * Archive resource qua workflow nghiệp vụ riêng của backend.
   *
   * Input: id resource.
   * Output: ResourceItem sau khi archive.
   */
  async archive(id) {
    const response = await $api(`/admin/resources/${id}/archive`, { method: 'POST' })

    return normalizeResource(unwrapApiResponse(response))
  },
}
