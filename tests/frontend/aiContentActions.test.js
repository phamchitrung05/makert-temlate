/* eslint-disable camelcase -- Fixture theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử action AI Content và các race condition API.
 * CÁC HÀM/METHOD TRONG FILE:
 * - state(): tạo workspace/action trong effect scope với service mock.
 * - beforeEach(): reset service/timer và pending queue trước mỗi test.
 * - afterEach(): hủy scope/timer sau mỗi test.
 * - test cases: kiểm regenerate một dòng/history, queue event, error và race API.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): fake service/timer -> assertion lifecycle
 * và payload; không gọi generation thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useAiContentActions } from '@/composables/useAiContentActions'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { AI_TASK_QUEUED_EVENT } from '@/composables/useAiTaskQueue'
import { buildAiContentRegenerateRequest, buildAiContentRequest, createAiContentSource } from '@/utils/aiContentInput'
import { useAiRunFeedback } from '@/composables/useAiRunFeedback'

const { service } = vi.hoisted(() => ({ service: {
  status: vi.fn(), updateCandidate: vi.fn(), regenerate: vi.fn(), removeSession: vi.fn(), listSessions: vi.fn(), applyCandidate: vi.fn(), cancel: vi.fn(), retry: vi.fn(),
} }))

vi.mock('@/services/aiAgent', () => ({ aiAgentService: service }))
let scope
const parent = { id: 'parent', title: 'Bản gốc', targetType: 'sound', status: 'review' }

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo workspace/action test độc lập form tạo mới.
 * =====================================================================
 * INPUT: effect scope đã tạo trước test.
 * OUTPUT: state workspace, feedback và action composable.
 * SIDE EFFECT: khởi tạo reactive state; API đã mock không gọi provider.
 * =====================================================================
 */
function state() {
  return scope.run(() => {
    const workspace = useAiContentWorkspace()

    workspace.updateSession({ job_id: parent.id, target_type: 'sound', status: 'ready', draft: { title: parent.title } })

    const feedback = useAiRunFeedback()

    return { ...workspace, ...feedback, ...useAiContentActions({ ...workspace, onFeedback: feedback.observeRun }) }
  })
}

beforeEach(() => {
  vi.resetAllMocks(); vi.useFakeTimers(); scope = effectScope(); window.__aiTaskQueuePending = []
  service.status.mockResolvedValue({ job_id: parent.id, target_type: 'sound', status: 'ready', draft_version: 'v1', draft: { title: parent.title } })
})
afterEach(() => { scope.stop(); vi.useRealTimers() })

describe('AI Content actions', () => {
  it('retries only the image child and monitors its parent without adding an image row', async () => {
    const s = state()
    const item = { ...parent, thumbnailGeneration: { job_id: 'image-1', status: 'failed' } }
    const ready = { job_id: parent.id, status: 'ready', target_type: 'sound', draft: { title: parent.title }, thumbnail_generation: { job_id: 'image-1', status: 'queued' } }

    service.retry.mockResolvedValue({ job_id: 'image-1', status: 'queued', operation: 'image' })
    service.status.mockResolvedValueOnce(ready).mockResolvedValueOnce({ ...ready, thumbnail: { id: 88 }, thumbnail_generation: { job_id: 'image-1', status: 'ready' } })
    await s.thumbnailAction(item)
    expect(service.retry).toHaveBeenCalledExactlyOnceWith('image-1')
    expect(s.items.value).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(1500)
    expect(s.items.value[0].thumbnail.id).toBe(88)
    expect(service.regenerate).not.toHaveBeenCalled()
    expect(service.status).toHaveBeenLastCalledWith(parent.id, 'sound')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('resumes pending thumbnail after reload and cancels only its child', async () => {
    const s = state()
    const item = { ...parent, thumbnailGeneration: { job_id: 'image-1', status: 'queued' } }

    service.status.mockResolvedValue({ job_id: parent.id, status: 'ready', thumbnail_generation: { job_id: 'image-1', status: 'generating' } })
    s.trackRuns([item])
    s.trackRuns([item])
    await vi.advanceTimersByTimeAsync(1000)
    expect(service.status).toHaveBeenCalledOnce()
    service.cancel.mockResolvedValue({ job_id: 'image-1', status: 'cancelled' })
    service.status.mockResolvedValue({ job_id: parent.id, status: 'ready', thumbnail_generation: { job_id: 'image-1', status: 'cancelled' } })
    await s.thumbnailAction(item, 'cancel')
    expect(service.cancel).toHaveBeenCalledExactlyOnceWith('image-1')
    expect(s.items.value[0].thumbnailGeneration.status).toBe('cancelled')
    expect(service.retry).not.toHaveBeenCalled()
    scope.stop()
    expect(vi.getTimerCount()).toBe(0)
  })
  it('reports an immediately failed child without a queued success notice and preserves the parent', async () => {
    const s = state()

    service.regenerate.mockResolvedValue({ job_id: 'child', parent_id: parent.id, status: 'failed', error: 'AI trả nội dung rỗng' })
    await s.requestAction('regenerate', parent)
    service.status.mockClear()
    await s.confirmAction()
    expect(s.notice.value).toEqual({ type: 'error', message: 'AI trả nội dung rỗng' })
    expect(s.snackbar.value.visible).toBe(true)
    expect(s.items.value.find(item => item.id === parent.id)).toMatchObject({ title: parent.title, status: 'review' })
    expect(s.items.value).toHaveLength(1)
    expect(s.items.value.find(item => item.id === parent.id)).toMatchObject({ error: 'AI trả nội dung rỗng', latestAttemptStatus: 'failed' })
    await vi.advanceTimersByTimeAsync(20000)
    expect(service.status).not.toHaveBeenCalled()
  })

  it('keeps a child running after a GET failure and checks the same UUID manually without generating again', async () => {
    const s = state()

    service.regenerate.mockResolvedValue({ job_id: 'child', parent_id: parent.id, target_type: 'sound', status: 'queued' })
    await s.requestAction('regenerate', parent)
    service.status.mockClear()
    service.status.mockRejectedValueOnce(new Error('network'))
    await s.confirmAction()
    await vi.advanceTimersByTimeAsync(1000)
    expect(s.notice.value.type).toBe('warning')
    expect(s.items.value).toHaveLength(1)
    expect(s.items.value[0]).toMatchObject({ id: parent.id, status: 'generating', activeRunId: 'child' })
    service.status.mockResolvedValue({ job_id: 'child', parent_id: parent.id, session_id: parent.id, status: 'failed', error: 'AI thiếu tiêu đề' })
    await s.resumeRun(s.items.value[0])
    expect(service.status).toHaveBeenLastCalledWith('child', 'sound')
    expect(s.snackbar.value.visible).toBe(true)
    expect(s.items.value.find(item => item.id === parent.id).title).toBe(parent.title)
    expect(service.regenerate).toHaveBeenCalledOnce()
  })

  it('announces a regenerate run to the shared queue immediately after acceptance', async () => {
    const s = state()
    const listener = vi.fn()
    window.addEventListener(AI_TASK_QUEUED_EVENT, listener)
    service.regenerate.mockResolvedValue({ job_id: 'child', task_run_id: 'tracker-child', session_id: parent.id, parent_id: parent.id, status: 'queued' })

    await s.requestAction('regenerate', parent)
    await s.confirmAction()

    expect(listener).toHaveBeenCalledWith(expect.objectContaining({ detail: expect.objectContaining({
      task_run_id: 'tracker-child', taskable_id: 'child', task_type: 'article_generation', source: 'ai_content',
    }) }))
    window.removeEventListener(AI_TASK_QUEUED_EVENT, listener)
  })
  it('builds separate regenerate groups and omits empty overrides', async () => {
    expect(buildAiContentRegenerateRequest({ fields: ['title', 'content_html', 'seo_title', 'focus_keyword', 'tag_ids', 'thumbnail_prompt'], prompt_key: null, provider: '', model_id: null }))
      .toEqual({ fields: ['title', 'content', 'seo', 'thumbnail'] })
    for (const targetType of ['post', 'resource', 'sound', 'lesson']) {
      const payload = await buildAiContentRequest({ ...createAiContentSource(), targetType, type: 'text', text: 'Nguồn' }, { id: 1 })

      expect(payload.target_type).toBe(targetType)
    }
  })

  it('rejects taxonomy-only regeneration instead of creating an entire article', () => {
    expect(() => buildAiContentRegenerateRequest({ fields: ['taxonomy', 'category_ids', 'suggested_tag_ids'] }))
      .toThrow('danh mục và tag được chọn thủ công')
    expect(buildAiContentRegenerateRequest()).toEqual({ fields: [] })
  })

  it('removes stale taxonomy requests without changing manual category and tag selections', async () => {
    const source = { ...createAiContentSource(), type: 'text', text: 'Nguồn', outputs: ['title', 'content', 'taxonomy', 'category_ids', 'suggested_tag_ids'], categories: [7], tags: [8] }
    const payload = await buildAiContentRequest(source, { id: 1 })

    expect(payload.requested_outputs).toEqual(['title', 'content'])
    expect(source.categories).toEqual([7])
    expect(source.tags).toEqual([8])
  })

  it('saves versioned edits and preserves the creation form; errors keep the dialog open', async () => {
    const s = state()
    const source = s.source.value

    service.status.mockResolvedValue({ job_id: parent.id, status: 'ready', draft_version: 'version', draft: { title: parent.title } })
    await s.openEditor(parent)
    service.updateCandidate.mockRejectedValueOnce({ data: { message: 'Nội dung đã thay đổi' } })
    await s.saveEditor({ title: 'Sửa', content_html: '<p>Sửa</p>' })
    expect(s.editorError.value).toContain('đã thay đổi')
    expect(s.editor.value.job_id).toBe(parent.id)
    service.updateCandidate.mockResolvedValue({ job_id: parent.id, status: 'ready', target_type: 'sound', draft: { title: 'Sửa' } })
    await s.saveEditor({ title: 'Sửa', content_html: '<p>Sửa</p>' })
    expect(service.updateCandidate).toHaveBeenLastCalledWith(parent.id, { title: 'Sửa', content_html: '<p>Sửa</p>', expected_version: 'version' })
    expect(s.editor.value).toBeNull()
    expect(s.items.value[0].title).toBe('Sửa')
    expect(s.source.value).toBe(source)
  })

  it('ignores detail responses when closed or disposed', async () => {
    const s = state()
    let resolveRequest

    service.status.mockReturnValue(new Promise(resolve => { resolveRequest = resolve }))

    const pending = s.openEditor(parent)

    expect(s.editorLoading.value).toBe(true)
    expect(s.editor.value).toMatchObject({ job_id: parent.id, target_type: 'sound', draft: {} })
    await s.saveEditor({ title: 'Chưa tải xong' })
    expect(service.updateCandidate).not.toHaveBeenCalled()
    s.closeEditor()
    resolveRequest({ job_id: parent.id, status: 'ready', draft: { title: 'Late' } })
    await pending
    expect(s.editor.value).toBeNull()
    expect(s.items.value[0].title).toBe(parent.title)
  })

  it('retains the target type after a detail failure so retry reads the same content resource', async () => {
    const s = state()

    service.status.mockRejectedValueOnce(new Error('Không tải được nội dung'))
    await s.openEditor(parent)
    expect(s.editorLoading.value).toBe(false)
    expect(s.editorError.value).toContain('Không tải được nội dung')
    await s.saveEditor({ title: 'Chưa có version' })
    expect(service.updateCandidate).not.toHaveBeenCalled()
    await s.openEditor({ id: s.editor.value.job_id, targetType: s.editor.value.target_type, status: 'review' })
    expect(service.status).toHaveBeenLastCalledWith(parent.id, 'sound')
    expect(s.editor.value.draft_version).toBe('v1')
    expect(s.editorError.value).toBe('')
  })

  it('creates one child, polls its job ID and keeps the parent/form unchanged', async () => {
    const s = state()
    const source = s.source.value
    let resolveRequest

    service.regenerate.mockReturnValue(new Promise(resolve => { resolveRequest = resolve }))
    await s.requestAction('regenerate', parent)
    service.status.mockClear()
    service.status.mockResolvedValue({ job_id: 'child', target_type: 'sound', status: 'ready', parent_id: parent.id, draft: { title: 'Bản mới' } })

    const pending = s.confirmAction({ fields: ['content_html'], instructions: '' })

    await s.confirmAction()
    resolveRequest({ job_id: 'child', session_id: parent.id, parent_id: parent.id, target_type: 'sound', status: 'queued' })
    await pending
    expect(service.regenerate).toHaveBeenCalledExactlyOnceWith(parent.id, { fields: ['content'] })
    await vi.advanceTimersByTimeAsync(1000)
    expect(service.status).toHaveBeenCalledExactlyOnceWith('child', 'sound')
    expect(s.items.value).toHaveLength(1)
    expect(s.items.value[0].versionCount).toBe(2)
    expect(s.items.value.find(item => item.id === parent.id).title).toBe('Bản mới')
    expect(s.source.value).toBe(source)
    await vi.advanceTimersByTimeAsync(10000)
    expect(service.status).toHaveBeenCalledOnce()
  })

  it('does not remove on API failure and prevents an older list response restoring a deleted item', async () => {
    const s = state()

    service.removeSession.mockRejectedValueOnce({ data: { message: 'Không thể xóa' } })
    s.requestAction('remove', parent)
    await s.confirmAction()
    expect(s.items.value).toHaveLength(1)
    expect(s.actionError.value).toBe('Không thể xóa')
    let resolveRequest

    service.listSessions.mockReturnValue(new Promise(resolve => { resolveRequest = resolve }))

    const loading = s.loadItems()

    service.removeSession.mockResolvedValue(null)
    await s.confirmAction()
    resolveRequest({ data: [{ id: parent.id, title: parent.title, status: 'ready' }], meta: { pagination: { last_page: 1 } } })
    await loading
    expect(s.items.value).toHaveLength(0)
    expect(s.action.value).toBeNull()
  })

  it('stops child monitoring on scope disposal', async () => {
    const s = state()

    service.regenerate.mockResolvedValue({ job_id: 'child', target_type: 'sound', status: 'queued' })
    await s.requestAction('regenerate', parent)
    service.status.mockClear()
    await s.confirmAction()
    scope.stop()
    await vi.advanceTimersByTimeAsync(20000)
    expect(service.status).not.toHaveBeenCalled()
  })

  it('ignores a failed polling response after scope disposal', async () => {
    const s = state()
    let resolvePoll

    service.regenerate.mockResolvedValue({ job_id: 'child', target_type: 'sound', status: 'queued' })
    await s.requestAction('regenerate', parent)
    service.status.mockClear()
    service.status.mockReturnValue(new Promise(resolve => { resolvePoll = resolve }))
    await s.confirmAction()
    await vi.advanceTimersByTimeAsync(1000)
    scope.stop()
    resolvePoll({ job_id: 'child', status: 'failed', error: 'Late failure' })
    await Promise.resolve()
    expect(s.snackbar.value.visible).toBe(false)
    expect(s.items.value.find(item => item.id === 'child').status).toBe('generating')
    expect(s.items.value.find(item => item.id === parent.id).title).toBe(parent.title)
  })
})
