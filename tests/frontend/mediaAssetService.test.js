/* eslint-disable camelcase */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử API mapping của Media Asset service.
 * =====================================================================
 *
 * CÁC HÀM/COMPUTED/WATCHER TRONG FILE: các test unwrap/list/filter/upload
 * và mutation; không có component watcher.
 * INPUT/OUTPUT CỦA TEST (tổng thể):
 * - INPUT : BaseResponse giả lập và query/filter media.
 * - OUTPUT: mapping đúng envelope/pagination và request method/body.
 * - SIDE EFFECT: chỉ mock `$api`; không gọi mạng hoặc ghi database.
 * =====================================================================
 */

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

  it('omits empty list filters before calling the real API', async () => {
    apiMocks.$api.mockResolvedValue({
      success: true,
      data: { items: [], itemsLength: 0 },
      meta: { pagination: null },
    })

    await mediaAssetService.list({
      search: '',
      kind: null,
      owner: 99,
      conversion_status: null,
      page: 1,
      per_page: 20,
    })

    expect(apiMocks.$api).toHaveBeenCalledWith('/admin/media-assets', {
      query: {
        page: 1,
        per_page: 20,
      },
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
