/* eslint-disable camelcase */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử state và mutation của Media Asset Pinia store.
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE: các test list/error/upload/update/retry và
 * attach/detach usage không làm nhầm usage response thành MediaAsset.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : response giả của mediaAssetService.
 * - OUTPUT: state Pinia đúng sau query/mutation; không gọi HTTP thật.
 * =====================================================================
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const serviceMocks = vi.hoisted(() => ({
  service: {
    list: vi.fn(),
    show: vi.fn(),
    upload: vi.fn(),
    update: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    reorder: vi.fn(),
    remove: vi.fn(),
    retry: vi.fn(),
    download: vi.fn(),
    downloadFile: vi.fn(),
  },
}))

vi.mock('@/services/mediaAsset', () => ({ mediaAssetService: serviceMocks.service }))

import { useMediaAssetStore } from '@/stores/mediaAsset'

describe('useMediaAssetStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    Object.values(serviceMocks.service).forEach(mock => mock.mockReset())
  })

  it('tracks loading state and applies list pagination', async () => {
    let resolveList
    serviceMocks.service.list.mockReturnValue(new Promise(resolve => { resolveList = resolve }))

    const store = useMediaAssetStore()
    const request = store.fetchMediaAssets({ kind: 'image', page: 2, per_page: 12 })

    expect(store.isLoading).toBe(true)
    resolveList({
      items: [{ id: 1 }],
      itemsLength: 1,
      pagination: { current_page: 2, per_page: 12, total: 1, last_page: 1 },
    })
    await request

    expect(store.isLoading).toBe(false)
    expect(store.items).toEqual([{ id: 1 }])
    expect(store.pagination.current_page).toBe(2)
    expect(store.pagination.per_page).toBe(12)
    expect(serviceMocks.service.list).toHaveBeenCalledWith(expect.objectContaining({
      kind: 'image',
      page: 2,
      per_page: 12,
    }))
  })

  it('keeps list loading separate while a selected detail is loading', async () => {
    let resolveDetail

    serviceMocks.service.show.mockReturnValue(new Promise(resolve => { resolveDetail = resolve }))

    const store = useMediaAssetStore()
    const request = store.fetchMediaAsset(9, { id: 9, title: 'Ảnh đang chọn' })

    expect(store.selectedAsset).toEqual({ id: 9, title: 'Ảnh đang chọn' })
    expect(store.isLoading).toBe(false)
    expect(store.isDetailLoading).toBe(true)

    resolveDetail({ id: 9, title: 'Ảnh đầy đủ' })
    await request

    expect(store.selectedAsset).toEqual({ id: 9, title: 'Ảnh đầy đủ' })
    expect(store.isLoading).toBe(false)
    expect(store.isDetailLoading).toBe(false)
  })

  it('keeps request errors in state and supports clearing them', async () => {
    const error = new Error('network unavailable')

    serviceMocks.service.list.mockRejectedValue(error)

    const store = useMediaAssetStore()

    await expect(store.fetchMediaAssets()).resolves.toBeNull()
    expect(store.error).toBe(error)

    store.clearError()
    expect(store.error).toBeNull()
  })

  it('tracks upload progress and prepends the uploaded asset', async () => {
    const asset = { id: 8, title: 'Preview' }

    serviceMocks.service.upload.mockImplementation(async (payload, onProgress) => {
      expect(payload.title).toBe('Preview')
      onProgress(35)

      return asset
    })

    const store = useMediaAssetStore()

    await store.uploadMediaAsset({ title: 'Preview' })

    expect(store.uploadProgress).toBe(100)
    expect(store.items).toEqual([asset])
    expect(store.itemsLength).toBe(1)
    expect(store.selectedAsset).toEqual(asset)
    expect(store.isMutating).toBe(false)
  })

  it('surfaces mutation errors and updates selected asset on retry', async () => {
    const error = new Error('retry failed')

    serviceMocks.service.update.mockRejectedValue(error)

    const store = useMediaAssetStore()

    await expect(store.updateMediaAsset(4, { title: 'x' })).rejects.toBe(error)
    expect(store.error).toBe(error)
    expect(store.isMutating).toBe(false)

    const retried = { id: 4, title: 'x', file: { scan_status: 'pending' } }

    serviceMocks.service.retry.mockResolvedValue(retried)
    await store.retryMediaAsset(4)

    expect(store.selectedAsset).toEqual(retried)
    expect(store.error).toBeNull()
  })

  it('refreshes the asset after attach and detach responses without asset IDs', async () => {
    const store = useMediaAssetStore()
    const initial = { id: 4, title: 'Cover', usages: [] }
    const attached = { ...initial, usages: [{ id: 91, field: 'post.thumbnail' }] }

    serviceMocks.service.show.mockResolvedValueOnce(initial).mockResolvedValueOnce(attached).mockResolvedValueOnce(initial)
    serviceMocks.service.attach.mockResolvedValue({ id: 91, field: 'post.thumbnail' })
    serviceMocks.service.detach.mockResolvedValue(null)

    await store.fetchMediaAsset(4)
    await store.attachMediaAsset(4, { field: 'post.thumbnail', linkable_type: 'post', linkable_id: 7 })

    expect(store.selectedAsset).toEqual(attached)
    expect(store.selectedAsset.id).toBe(4)

    await store.detachMediaAsset(4, 91)

    expect(store.selectedAsset).toEqual(initial)
    expect(serviceMocks.service.detach).toHaveBeenCalledWith(4, 91)
  })
})
