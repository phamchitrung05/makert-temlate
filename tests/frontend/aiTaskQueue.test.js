/* eslint-disable camelcase -- Fixtures giữ tên field theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm polling, scope và trạng thái rỗng/lỗi của AI Task Queue.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: createQueue(), flushPromises() và các test lifecycle.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : list/cancel API mock và CustomEvent task queued.
 * - OUTPUT: task center cập nhật tuần tự, filter terminal và lỗi bounded.
 * - SIDE EFFECT: fake timer/effect scope, không gọi API thật.
 * =====================================================================
 */
import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AI_TASK_QUEUED_EVENT, useAiTaskQueue } from '@/composables/useAiTaskQueue'

const mocks = vi.hoisted(() => ({ listAnalyses: vi.fn(), cancel: vi.fn() }))

vi.mock('@/services/aiWritingProfiles', () => ({
  aiWritingProfilesService: mocks,
}))

const task = (status = 'queued', id = 'analysis-id') => ({
  id,
  task_type: 'writing_profile_analysis',
  source: 'ai_writing_profile',
  name: 'Văn phong gần gũi',
  status,
  created_at: '2026-10-08T10:00:00Z',
  error_message: status === 'failed' ? 'Lỗi bounded' : null,
})

const flushPromises = async () => {
  await Promise.resolve()
  await Promise.resolve()
}

function createQueue() {
  const scope = effectScope()
  let queue
  scope.run(() => { queue = useAiTaskQueue() })

  return { queue, scope }
}

describe('AI task queue', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.listAnalyses.mockReset()
    mocks.cancel.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads an empty list and does not schedule polling without active tasks', async () => {
    mocks.listAnalyses.mockResolvedValueOnce({ success: true, data: [], meta: { pagination: { total: 0 } } })

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()
    expect(queue.tasks.value).toEqual([])
    expect(queue.activeCount.value).toBe(0)
    await vi.advanceTimersByTimeAsync(10000)
    expect(mocks.listAnalyses).toHaveBeenCalledTimes(1)
    scope.stop()
  })

  it('polls active tasks and stops after the server returns a terminal status', async () => {
    mocks.listAnalyses
      .mockResolvedValueOnce({ success: true, data: [task('queued')], meta: {} })
      .mockResolvedValueOnce({ success: true, data: [task('ready')], meta: {} })

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()
    expect(queue.activeCount.value).toBe(1)
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(queue.tasks.value[0].status).toBe('ready')
    expect(queue.activeCount.value).toBe(0)
    await vi.advanceTimersByTimeAsync(10000)
    expect(mocks.listAnalyses).toHaveBeenCalledTimes(2)
    scope.stop()
  })

  it('adds a queued task from the create event and exposes bounded failure state', async () => {
    mocks.listAnalyses.mockResolvedValueOnce({ success: true, data: [], meta: {} })

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()

    window.dispatchEvent(new CustomEvent(AI_TASK_QUEUED_EVENT, { detail: task('queued', 'new-analysis') }))
    expect(queue.tasks.value[0].id).toBe('new-analysis')
    expect(queue.activeCount.value).toBe(1)

    queue.addTask(task('failed', 'failed-analysis'))
    expect(queue.filters.value.find(filter => filter.value === 'failed').count).toBe(1)
    expect(queue.filteredTasks.value).toHaveLength(2)
    queue.currentFilter.value = 'failed'
    expect(queue.filteredTasks.value).toHaveLength(1)
    expect(queue.filteredTasks.value[0].error_message).toBe('Lỗi bounded')
    scope.stop()
  })

  it('cancels only active tasks and reflects the server response', async () => {
    mocks.listAnalyses.mockResolvedValueOnce({ success: true, data: [task('queued')], meta: {} })
    mocks.cancel.mockResolvedValueOnce(task('cancelled'))

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()
    await queue.cancelTask('analysis-id')
    expect(mocks.cancel).toHaveBeenCalledWith('analysis-id')
    expect(queue.tasks.value[0].status).toBe('cancelled')
    expect(queue.activeCount.value).toBe(0)
    scope.stop()
  })
})
