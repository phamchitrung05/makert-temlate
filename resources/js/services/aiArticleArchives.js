/* eslint-disable camelcase -- Query keys follow the Laravel archive API contract. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kết nối API đọc kho bài AI đã được duyệt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), list(), show().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bộ lọc phân trang và ID archive.
 * - OUTPUT: DTO list/detail đã bỏ envelope; không có mutation.
 * - SIDE EFFECT: chỉ GET Admin API.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: envelope API. Output: payload data hoặc response cũ để caller báo lỗi. */
const unwrap = response => response?.success && 'data' in response ? response.data : response

export const aiArticleArchivesService = {
  /** Input: filters và AbortSignal. Output: rows, tổng số và pagination. */
  async list(filters = {}, signal) {
    const response = await $api('/admin/ai-agent/approved-archives', { query: filters, signal, retry: 0 })
    const payload = unwrap(response)

    return {
      items: Array.isArray(payload) ? payload : payload?.items ?? [],
      totalItems: Number(response?.meta?.pagination?.total ?? payload?.itemsLength ?? 0),
      pagination: response?.meta?.pagination ?? null,
    }
  },

  /** Input: numeric archive ID và AbortSignal. Output: snapshot detail read-only. */
  async show(id, signal) {
    return unwrap(await $api(`/admin/ai-agent/approved-archives/${id}`, { signal, retry: 0 }))
  },
}
