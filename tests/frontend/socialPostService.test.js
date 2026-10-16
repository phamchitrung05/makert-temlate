/* eslint-disable camelcase -- Social Post API fields follow the Laravel contract. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { socialPostService } from '@/services/socialPost'

const mocks = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: mocks.api }))

describe('Social Post API client', () => {
  beforeEach(() => mocks.api.mockReset())

  it('normalizes canonical caption/status fields and preserves list metadata', async () => {
    mocks.api.mockResolvedValue({
      success: true,
      data: [{ id: 4, caption: 'Caption chung', review_status: 'draft', generation_status: 'partial' }],
      meta: { pagination: { total: 1 }, stats: { draft: 1 } },
    })

    const result = await socialPostService.list({ review_status: 'draft' })

    expect(result.items[0]).toMatchObject({ id: 4, caption: 'Caption chung', reviewStatus: 'draft', generationStatus: 'partial' })
    expect(result.stats).toEqual({ draft: 1 })
    expect(mocks.api).toHaveBeenCalledWith('/admin/social-posts', { query: { review_status: 'draft' } })
  })

  it('sends one shared caption and target account IDs when creating a draft', async () => {
    mocks.api.mockResolvedValue({ success: true, data: { post: { id: 9 }, generation: { generation_no: 1 } } })

    await socialPostService.create({ caption: 'Dùng chung', target_account_ids: [2, 3], generate: true })

    expect(mocks.api).toHaveBeenCalledWith('/admin/social-posts', {
      method: 'POST',
      body: { caption: 'Dùng chung', target_account_ids: [2, 3], generate: true },
    })
  })
})
