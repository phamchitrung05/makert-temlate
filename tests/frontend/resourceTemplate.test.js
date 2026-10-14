/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử contract service/composable Resource Template
 * =====================================================================
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - service methods: giữ endpoint/payload processing/settings/preview.
 * - composable lifecycle: dừng terminal polling và bỏ qua response stale.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể): mock HTTP -> state frontend bounded.
 * =====================================================================
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))
vi.mock('@/utils/api', () => ({ $api: api }))

import { resourceTemplateService } from '@/services/resourceTemplate'
import { useResourceTemplate } from '@/composables/useResourceTemplate'

describe('Resource Template service contract', () => {
  beforeEach(() => api.mockReset())

  it('unwraps process response and sends bounded force payload', async () => {
    api.mockResolvedValue({ success: true, data: { id: 'run-1', status: 'queued' } })

    await expect(resourceTemplateService.process(8, { force: true })).resolves.toEqual({ id: 'run-1', status: 'queued' })
    expect(api).toHaveBeenCalledWith('/admin/resource-versions/8/template/process', {
      method: 'POST', body: { force: true },
    })
  })

  it('keeps the newest detail when an older response resolves later', async () => {
    let resolveOld
    let resolveNew
    const service = {
      show: vi.fn()
        .mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
        .mockImplementationOnce(() => new Promise(resolve => { resolveNew = resolve })),
    }
    const state = useResourceTemplate(ref(3), service)
    const old = state.load()
    const newer = state.load()
    resolveNew({ template: { processing_status: 'ready', entry_screen_slug: 'new' } })
    await newer
    resolveOld({ template: { processing_status: 'failed', entry_screen_slug: 'old' } })
    await old
    await nextTick()

    expect(state.template.value.entry_screen_slug).toBe('new')
  })

  it('stops polling after a terminal failed state', async () => {
    vi.useFakeTimers()
    const service = { show: vi.fn().mockResolvedValue({ template: { processing_status: 'failed' } }) }
    const state = useResourceTemplate(ref(4), service)
    await state.load()
    state.startPolling()
    await vi.runOnlyPendingTimersAsync()

    expect(state.polling.value).toBe(false)
    vi.useRealTimers()
  })
})
