/* eslint-disable camelcase -- API query keys follow the Laravel contract. */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({ $api: vi.fn() }))

vi.mock('@/utils/api', () => apiMocks)

import { aiArticleArchivesService } from '@/services/aiArticleArchives'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm API client đọc kho AI Approved.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: beforeEach(), list/show tests.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : envelope list/detail và query đa model.
 * - OUTPUT: DTO đã unwrap đúng; lỗi network truyền nguyên vẹn.
 * - SIDE EFFECT: chỉ mock $api, không gọi network.
 * =====================================================================
 */
describe('aiArticleArchivesService', () => {
  beforeEach(() => apiMocks.$api.mockReset())

  /** Input: pagination response có target resource. Output: rows/total giữ đúng envelope. */
  it('unwraps paginated archives across target models', async () => {
    const signal = new AbortController().signal

    apiMocks.$api.mockResolvedValue({
      success: true,
      data: { items: [{ id: 1, target_type: 'resource', target_id: 8 }], itemsLength: 1 },
      meta: { pagination: { total: 1, last_page: 1 } },
    })

    await expect(aiArticleArchivesService.list({ search: 'guide', page: 1, per_page: 15 }, signal)).resolves.toEqual({
      items: [{ id: 1, target_type: 'resource', target_id: 8 }],
      totalItems: 1,
      pagination: { total: 1, last_page: 1 },
    })
    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/ai-agent/approved-archives', {
      query: { search: 'guide', page: 1, per_page: 15 }, signal, retry: 0,
    })
  })

  /** Input: detail envelope. Output: snapshot read-only và signal truyền xuống API. */
  it('unwraps archive detail without mutation methods', async () => {
    const signal = new AbortController().signal
    const detail = { id: 4, target_type: 'post', original_available: true }

    apiMocks.$api.mockResolvedValue({ success: true, data: detail })

    await expect(aiArticleArchivesService.show(4, signal)).resolves.toEqual(detail)
    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/ai-agent/approved-archives/4', { signal, retry: 0 })
  })
})
