/* eslint-disable camelcase */

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
})
