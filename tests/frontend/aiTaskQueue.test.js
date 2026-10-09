/* eslint-disable camelcase -- Fixtures giữ tên field theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm polling, scope và trạng thái rỗng/lỗi của AI Task Queue.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - task(): tạo DTO task bounded cho từng trạng thái.
 * - flushPromises(): chờ các promise mock hoàn tất trong test.
 * - createQueue(): tạo composable trong effect scope để kiểm lifecycle cleanup.
 * - các test queue(): kiểm empty, polling, terminal, expired, duplicate, cancel và lỗi mạng.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : list/cancel API mock và CustomEvent task queued.
 * - OUTPUT: task center cập nhật tuần tự, filter terminal và lỗi bounded.
 * - SIDE EFFECT: fake timer/effect scope, không gọi API thật.
 * =====================================================================
 */
import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AI_TASK_QUEUED_EVENT, aiTaskStatusMeta, normalizeTask, useAiTaskQueue } from '@/composables/useAiTaskQueue'

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

  it('replays a queued event emitted before the popup starts', async () => {
    mocks.listAnalyses.mockResolvedValueOnce({ success: true, data: [], meta: {} })
    window.__aiTaskQueuePending = [task('queued', 'before-popup')]

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()
    expect(queue.tasks.value[0].id).toBe('before-popup')
    expect(queue.activeCount.value).toBe(1)
    delete window.__aiTaskQueuePending
    scope.stop()
  })

  it('treats expired tasks as terminal and never polls them again', async () => {
    mocks.listAnalyses.mockResolvedValueOnce({ success: true, data: [task('expired')], meta: {} })

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()

    expect(normalizeTask(task('expired')).status).toBe('expired')
    expect(aiTaskStatusMeta('expired').label).toBe('Hết hạn')
    expect(queue.activeCount.value).toBe(0)
    expect(queue.filters.value.find(filter => filter.value === 'expired').count).toBe(1)
    await vi.advanceTimersByTimeAsync(10000)
    expect(mocks.listAnalyses).toHaveBeenCalledTimes(1)
    scope.stop()
  })

  it('keeps parent and media references bounded for image task navigation', () => {
    expect(normalizeTask({ ...task('ready', 'image-task'), task_type: 'image_generation', source: 'ai_image', parent_id: 'article-1', media_asset_id: 42 }))
      .toMatchObject({ id: 'image-task', parent_id: 'article-1', media_asset_id: 42 })
  })

  it('keeps one row when the same task is queued more than once', async () => {
    mocks.listAnalyses.mockResolvedValueOnce({ success: true, data: [], meta: {} })

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()
    queue.addTask(task('queued', 'duplicate'))
    queue.addTask({ ...task('analyzing', 'duplicate'), progress: 42 })

    expect(queue.tasks.value).toHaveLength(1)
    expect(queue.tasks.value[0]).toMatchObject({ id: 'duplicate', status: 'analyzing', progress: 42 })
    scope.stop()
  })

  it('ignores a late list response after the queue has stopped', async () => {
    let resolveList
    mocks.listAnalyses.mockReturnValueOnce(new Promise(resolve => { resolveList = resolve }))

    const { queue, scope } = createQueue()

    queue.start()
    scope.stop()
    resolveList({ success: true, data: [task('queued', 'late')], meta: {} })
    await flushPromises()

    expect(queue.tasks.value).toEqual([])
    expect(queue.polling.value).toBe(false)
  })

  it('keeps active tasks and schedules a retry after a temporary list error', async () => {
    mocks.listAnalyses
      .mockResolvedValueOnce({ success: true, data: [task('queued', 'retryable')], meta: {} })
      .mockRejectedValueOnce(Object.assign(new Error('network'), { status: 503 }))
      .mockResolvedValueOnce({ success: true, data: [task('ready', 'retryable')], meta: {} })

    const { queue, scope } = createQueue()

    queue.start()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(queue.tasks.value[0].status).toBe('queued')
    expect(queue.error.value).toContain('network')
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(queue.tasks.value[0].status).toBe('ready')
    expect(queue.activeCount.value).toBe(0)
    scope.stop()
  })
})
