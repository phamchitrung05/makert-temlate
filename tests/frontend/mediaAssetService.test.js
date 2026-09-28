/* eslint-disable camelcase */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
  $api: vi.fn(),
  getAdminAccessToken: vi.fn(() => null),
}))

vi.mock('@/utils/api', () => apiMocks)

import { mediaAssetService, unwrapApiResponse } from '@/services/mediaAsset'

describe('mediaAssetService', () => {
  beforeEach(() => {
    apiMocks.$api.mockReset()
  })

  it('unwraps BaseResponse while preserving a flat payload', () => {
    expect(unwrapApiResponse({ success: true, data: { id: 7 } })).toEqual({ id: 7 })
    expect(unwrapApiResponse({ id: 7 })).toEqual({ id: 7 })
  })

  it('maps list payload and forwards filters and pagination', async () => {
    apiMocks.$api.mockResolvedValue({
      success: true,
      data: { items: [{ id: 3 }], itemsLength: 1 },
      meta: { pagination: { current_page: 2, per_page: 12, total: 25 } },
    })

    const result = await mediaAssetService.list({
      kind: 'image',
      field: 'resource.preview',
      page: 2,
      per_page: 12,
    })

    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/media-assets', {
      query: {
        kind: 'image',
        field: 'resource.preview',
        page: 2,
        per_page: 12,
      },
    })
    expect(result).toEqual({
      items: [{ id: 3 }],
      itemsLength: 1,
      pagination: { current_page: 2, per_page: 12, total: 25 },
    })
  })

  it('builds upload FormData and maps the upload response', async () => {
    vi.stubGlobal('XMLHttpRequest', undefined)
    apiMocks.$api.mockResolvedValue({
      success: true,
      data: { id: 9, kind: 'image' },
      meta: {},
    })

    const file = new File(['image'], 'cover.png', { type: 'image/png' })

    const result = await mediaAssetService.upload({
      file,
      kind: 'image',
      field: 'resource.cover',
      title: 'Cover',
      visibility: 'public',
    })

    const [endpoint, options] = apiMocks.$api.mock.calls[0]
    const body = options.body

    expect(endpoint).toBe('/admin/media-assets')
    expect(options.method).toBe('POST')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file').name).toBe('cover.png')
    expect(body.get('kind')).toBe('image')
    expect(body.get('field')).toBe('resource.cover')
    expect(body.get('title')).toBe('Cover')
    expect(result).toEqual({ id: 9, kind: 'image' })
  })

  it('uses the same mutation boundary for metadata updates', async () => {
    apiMocks.$api.mockResolvedValue({ success: true, data: { id: 4, title: 'Updated' } })

    await expect(mediaAssetService.update(4, { title: 'Updated' })).resolves.toEqual({
      id: 4,
      title: 'Updated',
    })
    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/media-assets/4', {
      method: 'PATCH',
      body: { title: 'Updated' },
    })
  })
})
