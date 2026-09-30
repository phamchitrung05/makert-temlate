/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử API slug dùng chung và nhiều caller đồng thời.
 * CÁC HÀM/METHOD TRONG FILE: beforeEach() và các test payload/concurrency/failure.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request giả lập -> kết quả riêng và trạng thái loading.
 * =====================================================================
 */
/* eslint-disable camelcase -- Laravel API payload uses snake_case fields. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSlugStore } from '@/stores/slug'

const mocks = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: mocks.api }))

describe('Shared slug store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.api.mockReset()
  })

  it.each([undefined, '<html>Login</html>', { success: true, data: {} }, { slug: '' }, { success: false, slug: 'invalid' }])('rejects invalid API payloads instead of clearing the field silently: %s', async payload => {
    mocks.api.mockResolvedValue(payload)

    const store = useSlugStore()

    await expect(store.generateSlug({ title: 'Title', modelType: 'post' })).rejects.toThrow('SLUG_RESPONSE_INVALID')
    expect(store.pendingRequests).toBe(0)
  })

  it('sends the model, edit ID and abort signal to the common endpoint', async () => {
    mocks.api.mockResolvedValue({ success: true, data: { slug: 'article-1', model_type: 'post' } })

    const store = useSlugStore()
    const signal = new AbortController().signal

    expect(await store.generateSlug({ title: 'Article', modelType: 'post', modelId: 5 }, { signal })).toEqual({ slug: 'article-1', model_type: 'post' })
    expect(mocks.api).toHaveBeenCalledWith('/admin/slugs/preview', { method: 'POST', body: { title: 'Article', model_type: 'post', model_id: 5 }, signal })
    expect(store.pendingRequests).toBe(0)
    expect(store.isLoading).toBe(false)
  })

  it('keeps simultaneous results separate and counts pending requests', async () => {
    let resolveFirst
    mocks.api.mockReturnValueOnce(new Promise(resolve => { resolveFirst = resolve }))
    mocks.api.mockResolvedValueOnce({ slug: 'resource' })

    const store = useSlugStore()
    const first = store.generateSlug({ title: 'Post', modelType: 'post' })
    const second = store.generateSlug({ title: 'Resource', modelType: 'resource' })

    expect(store.pendingRequests).toBe(2)
    expect(await second).toEqual({ slug: 'resource' })
    expect(store.isLoading).toBe(true)
    expect(store.pendingRequests).toBe(1)
    resolveFirst({ slug: 'post' })
    expect(await first).toEqual({ slug: 'post' })
    expect(store.isLoading).toBe(false)
    expect(store).not.toHaveProperty('slug')
  })

  it.each([new Error('offline'), new DOMException('Cancelled', 'AbortError')])('cleans up loading and propagates failure: %s', async error => {
    mocks.api.mockRejectedValue(error)

    const store = useSlugStore()

    await expect(store.generateSlug({ title: 'Title', modelType: 'category' })).rejects.toBe(error)
    expect(store.pendingRequests).toBe(0)
    expect(store.isLoading).toBe(false)
  })
})
