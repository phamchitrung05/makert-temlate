/* eslint-disable camelcase -- fixtures theo API Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa response race khi dialog AI đổi run hoặc mở lại.
 * CÁC HÀM/METHOD: beforeEach(), deferred(), test cases.
 * INPUT/OUTPUT: response đến muộn -> không ghi đè session/options mới.
 * SIDE EFFECT: Pinia thật, service mock; không gọi provider.
 * =====================================================================
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAiAgentStore } from '@/stores/aiAgent'
import { aiAgentService } from '@/services/aiAgent'

vi.mock('@/services/aiAgent', () => ({ aiAgentService: { capabilities: vi.fn(), createSession: vi.fn(), status: vi.fn(), cancel: vi.fn(), regenerate: vi.fn(), retry: vi.fn() } }))

/** Input: không có. Output: promise để mô phỏng response đến muộn. */
function deferred() {
  let resolve
  const promise = new Promise(_resolve => { resolve = _resolve })

  return { resolve, promise }
}

describe('AI Agent response lifecycle', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.resetAllMocks() })

  it('keeps a successful parent when a failed child response contains an invalid draft', async () => {
    const store = useAiAgentStore()

    aiAgentService.createSession.mockResolvedValue({ job_id: 'parent', status: 'ready', draft: { title: 'Valid parent' } })
    await store.start({})
    aiAgentService.regenerate.mockResolvedValue({ job_id: 'child', parent_id: 'parent', status: 'failed', draft: { title: 'Invalid child' } })
    await store.regenerate('parent', {})
    expect(store.session.job_id).toBe('child')
    expect(store.candidates).toHaveLength(1)
    expect(store.candidates[0]).toMatchObject({ id: 'parent', status: 'ready', outputs: { title: 'Valid parent' } })
    aiAgentService.status.mockResolvedValue({ job_id: 'child', status: 'failed', draft: { title: 'Still invalid' } })
    await store.poll('child', 'post')
    expect(store.candidates).toHaveLength(1)
    expect(store.candidates[0].outputs.title).toBe('Valid parent')
  })

  it('only syncs successful run candidates and rejects a failed entry inside a successful response', async () => {
    const store = useAiAgentStore()

    aiAgentService.createSession.mockResolvedValue({ job_id: 'run', status: 'rewriting', draft: { title: 'Not ready' } })
    await store.start({})
    expect(store.candidates).toEqual([])
    aiAgentService.status.mockResolvedValue({ job_id: 'run', status: 'ready', candidates: [
      { id: 'run', outputs: { title: 'Valid' } }, { id: 'failed', status: 'failed', outputs: { title: 'Invalid' } },
    ] })
    await store.poll('run', 'post')
    expect(store.candidates).toHaveLength(1)
    expect(store.candidates[0].outputs.title).toBe('Valid')
  })

  it('does not restore an old run after reset and a new creation', async () => {
    const oldResponse = deferred()
    const store = useAiAgentStore()

    aiAgentService.createSession.mockReturnValueOnce(oldResponse.promise)
      .mockResolvedValueOnce({ job_id: 'new', status: 'queued' })

    const oldStart = store.start({})

    store.reset()
    await store.start({})
    oldResponse.resolve({ job_id: 'old', status: 'ready', draft: { title: 'Old' } })
    await oldStart
    expect(store.session.job_id).toBe('new')
    expect(store.candidates).toEqual([])
    expect(store.isLoading).toBe(false)
  })

  it('ignores a polling response arriving after cancellation', async () => {
    const pending = deferred()
    const store = useAiAgentStore()

    aiAgentService.createSession.mockResolvedValue({ job_id: 'run', status: 'queued' })
    await store.start({})
    aiAgentService.status.mockReturnValue(pending.promise)

    const polling = store.poll('run', 'post')

    aiAgentService.cancel.mockResolvedValue({ job_id: 'run', status: 'cancelled' })
    await store.cancel('run')
    pending.resolve({ job_id: 'run', status: 'rewriting' })
    await polling
    expect(store.session.status).toBe('cancelled')
  })

  it('retains the latest capability catalog when an earlier request arrives late', async () => {
    const pending = deferred()
    const store = useAiAgentStore()

    aiAgentService.capabilities.mockReturnValueOnce(pending.promise)
      .mockResolvedValueOnce({ providers: [{ key: 'new' }] })

    const oldLoad = store.loadCapabilities('post')

    await store.loadCapabilities('post')
    pending.resolve({ providers: [{ key: 'old' }] })
    await oldLoad
    expect(store.capabilities.providers[0].key).toBe('new')
  })
})
