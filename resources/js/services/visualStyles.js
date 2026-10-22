/* eslint-disable camelcase -- Request keys follow the Laravel Visual Style API contract. */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client cho Visual Style Engine (Style DNA + Shot Recipes).
 * =====================================================================
 * Service là boundary duy nhất giữa các page/component Visual Styles và
 * Laravel API. Mọi response BaseResponse được unwrap tại đây; upload ảnh
 * phân tích dùng FormData multipart còn generate theo dõi qua task run.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - unwrap(): lấy data khỏi BaseResponse envelope.
 * - list()/show()/create()/update()/remove(): CRUD style.
 * - analyze(): queue phân tích ảnh mẫu → DNA (multipart).
 * - updateDna(): lưu DNA đã chỉnh với optimistic lock expected_version.
 * - activate()/archive()/duplicate(): lifecycle style.
 * - listShots()/createShot()/updateShot()/deleteShot()/reorderShots(): shot recipes.
 * - generate()/getGeneration(): queue generation và đọc trạng thái task run.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/id/payload/File từ Vue.
 * - OUTPUT: payload đã unwrap hoặc lỗi HTTP do $api ném.
 * - SIDE EFFECT: chỉ gọi Admin API; không gọi provider trực tiếp, không lưu key.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: API response. Output: envelope data hoặc response phẳng. */
const unwrap = response => response?.success && 'data' in response ? response.data : response

const base = '/admin/ai/visual-styles'

export const visualStylesService = {
  /** Input: filters (search/status/page/per_page). Output: `{ items, pagination }`. */
  async list(query = {}) {
    const response = await $api(base, { query, retry: 0 })

    return {
      items: unwrap(response) ?? [],
      pagination: response?.meta?.pagination ?? null,
    }
  },

  /** Input: style id. Output: style detail có `shots` đã eager load. */
  async show(id) {
    return unwrap(await $api(`${base}/${id}`, { retry: 0 }))
  },

  /** Input: `{ name, category?, dna? }`. Output: style draft vừa tạo. */
  async create(payload) {
    return unwrap(await $api(base, { method: 'POST', body: payload, retry: 0 }))
  },

  /** Input: id + `{ name?, category?, is_enabled?, category_ids?, tag_ids? }`. Output: style đã refresh. */
  async update(id, payload) {
    return unwrap(await $api(`${base}/${id}`, { method: 'PUT', body: payload, retry: 0 }))
  },

  /** Input: id. Output: response xóa (style đang queued/analyzing bị 409). */
  async remove(id) {
    return unwrap(await $api(`${base}/${id}`, { method: 'DELETE', retry: 0 }))
  },

  /** Input: không có. Output: `{ enums, labels, lists, defaults }` Style DNA. */
  async dnaMeta() {
    return unwrap(await $api(`${base}/dna-meta`, { retry: 0 }))
  },

  /** Input: `{ name, model_id, image: File, category? }`. Output: style status=queued (202). */
  async analyze({ name, model_id, image, category }) {
    const formData = new FormData()

    formData.append('name', name)
    formData.append('model_id', String(model_id))
    if (category)
      formData.append('category', category)
    formData.append('image', image)

    return unwrap(await $api(`${base}/analyze`, { method: 'POST', body: formData, retry: 0 }))
  },

  /** Input: id + `{ dna, expected_version }`. Output: style version++. */
  async updateDna(id, { dna, expected_version }) {
    return unwrap(await $api(`${base}/${id}/dna`, {
      method: 'PUT',
      body: { dna, expected_version },
      retry: 0,
    }))
  },

  /** Input: id. Output: style status=active + is_enabled. */
  async activate(id) {
    return unwrap(await $api(`${base}/${id}/activate`, { method: 'POST', retry: 0 }))
  },

  /** Input: id. Output: style status=archived + is_enabled=false. */
  async archive(id) {
    return unwrap(await $api(`${base}/${id}/archive`, { method: 'POST', retry: 0 }))
  },

  /** Input: id. Output: style draft nhân bản (201). */
  async duplicate(id) {
    return unwrap(await $api(`${base}/${id}/duplicate`, { method: 'POST', retry: 0 }))
  },

  /** Input: style id. Output: danh sách shot recipe theo sort_order. */
  async listShots(styleId) {
    return unwrap(await $api(`${base}/${styleId}/shots`, { retry: 0 }))
  },

  /** Input: style id + payload shot. Output: shot vừa tạo (201). */
  async createShot(styleId, payload) {
    return unwrap(await $api(`${base}/${styleId}/shots`, { method: 'POST', body: payload, retry: 0 }))
  },

  /** Input: style id + shot id + payload partial. Output: shot đã refresh. */
  async updateShot(styleId, shotId, payload) {
    return unwrap(await $api(`${base}/${styleId}/shots/${shotId}`, { method: 'PUT', body: payload, retry: 0 }))
  },

  /** Input: style id + shot id. Output: response xóa. */
  async deleteShot(styleId, shotId) {
    return unwrap(await $api(`${base}/${styleId}/shots/${shotId}`, { method: 'DELETE', retry: 0 }))
  },

  /** Input: style id + `ordered_ids` đủ toàn bộ shot. Output: shots đã sắp lại. */
  async reorderShots(styleId, orderedIds) {
    return unwrap(await $api(`${base}/${styleId}/shots/reorder`, {
      method: 'POST',
      body: { ordered_ids: orderedIds },
      retry: 0,
    }))
  },

  /** Input: generate payload (style_id, shot_recipe_ids[1-4], product{media_ids,description,category}, aspect_ratio, image_count, instructions[], model_id?). Output: queued generation resource (id = AiImport UUID). */
  async generate(payload) {
    return unwrap(await $api(`${base}/generate`, { method: 'POST', body: payload, retry: 0 }))
  },

  /** Input: AiImport UUID. Output: trạng thái generation + outputs object theo recipe_code. */
  async getGeneration(importId) {
    return unwrap(await $api(`${base}/generations/${importId}`, { retry: 0 }))
  },
}
