import { effectScope, nextTick, shallowRef } from 'vue'
import { describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ list: vi.fn(), show: vi.fn(), remove: vi.fn() }))

vi.mock('@/services/mediaAsset', () => ({ mediaAssetService: api }))
vi.mock('@/utils/api', () => ({ $api: { raw: vi.fn() } }))
import { useMediaAssetManager } from '@/composables/useMediaAssetManager'

describe('Media Asset manager', () => {
  it('keeps the newest server page when responses arrive out of order', async () => {
    let first
    api.list.mockImplementationOnce(() => new Promise(resolve => { first = resolve }))
      .mockResolvedValueOnce({ items: [{ id: 2 }], itemsLength: 25 })

    const scope = effectScope()
    const query = shallowRef({ page: 1 })
    const manager = scope.run(() => useMediaAssetManager(query))

    query.value = { page: 2 }
    await nextTick()
    await nextTick()
    first({ items: [{ id: 1 }], itemsLength: 1 })
    await nextTick()
    expect(manager.items.value).toEqual([{ id: 2 }])
    expect(manager.total.value).toBe(25)
    scope.stop()
  })

  it('preserves selected asset and shows backend delete rejection', async () => {
    api.list.mockResolvedValue({ items: [{ id: 8 }], itemsLength: 1 })
    api.show.mockResolvedValue({ id: 8, title: 'In use' })
    api.remove.mockRejectedValue({ data: { message: 'Asset đang được sử dụng.' } })

    const scope = effectScope()
    const manager = scope.run(() => useMediaAssetManager(shallowRef({ page: 1 })))

    await manager.select(8)
    expect(await manager.remove(8)).toBe(false)
    expect(manager.selected.value.id).toBe(8)
    expect(manager.error.value).toBe('Asset đang được sử dụng.')
    scope.stop()
  })

  it('keeps a selected fallback visible while detail is loading', async () => {
    let resolveDetail

    api.list.mockResolvedValue({ items: [], itemsLength: 0 })
    api.show.mockImplementationOnce(() => new Promise(resolve => { resolveDetail = resolve }))

    const scope = effectScope()
    const manager = scope.run(() => useMediaAssetManager(shallowRef({ page: 1 })))
    const pending = manager.select(9, { id: 9, title: 'Ảnh đang chọn' })

    expect(manager.selected.value).toEqual({ id: 9, title: 'Ảnh đang chọn' })

    resolveDetail({ id: 9, title: 'Ảnh đầy đủ' })
    await pending

    expect(manager.selected.value).toEqual({ id: 9, title: 'Ảnh đầy đủ' })
    scope.stop()
  })
})
