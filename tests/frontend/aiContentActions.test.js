/* eslint-disable camelcase -- Fixture theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử action AI Content và các race condition API.
 * CÁC HÀM/METHOD TRONG FILE: beforeEach(), afterEach(), state(), các test_*.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): fake service/timer -> assertion lifecycle
 * và payload; không gọi generation thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useAiContentActions } from '@/composables/useAiContentActions'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { buildAiContentRegenerateRequest, buildAiContentRequest, createAiContentSource } from '@/utils/aiContentInput'

const { service } = vi.hoisted(() => ({ service: {
  status: vi.fn(), updateCandidate: vi.fn(), regenerate: vi.fn(), removeSession: vi.fn(), listSessions: vi.fn(),
} }))

vi.mock('@/services/aiAgent', () => ({ aiAgentService: service }))
let scope
const parent = { id: 'parent', title: 'Bản gốc', targetType: 'sound', status: 'review' }

/** Input: không có. Output: workspace/actions thuộc effect scope, độc lập form tạo mới. */
function state() {
  return scope.run(() => {
    const workspace = useAiContentWorkspace()

    workspace.updateSession({ job_id: parent.id, target_type: 'sound', status: 'ready', draft: { title: parent.title } })

    return { ...workspace, ...useAiContentActions(workspace) }
  })
}

beforeEach(() => { vi.resetAllMocks(); vi.useFakeTimers(); scope = effectScope() })
afterEach(() => { scope.stop(); vi.useRealTimers() })

describe('AI Content actions', () => {
  it('builds separate regenerate groups and omits empty overrides', async () => {
    expect(buildAiContentRegenerateRequest({ fields: ['title', 'content_html', 'seo_title', 'focus_keyword', 'tag_ids', 'thumbnail_prompt'], prompt_key: null, provider: '', model_id: null }))
      .toEqual({ fields: ['title', 'content', 'seo', 'taxonomy', 'thumbnail'] })
    for (const targetType of ['post', 'resource', 'sound', 'lesson']) {
      const payload = await buildAiContentRequest({ ...createAiContentSource(), targetType, type: 'text', text: 'Nguồn' }, { id: 1 })

      expect(payload.target_type).toBe(targetType)
    }
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

    s.closeEditor()
    resolveRequest({ job_id: parent.id, status: 'ready', draft: { title: 'Late' } })
    await pending
    expect(s.editor.value).toBeNull()
    expect(s.items.value[0].title).toBe(parent.title)
  })

  it('creates one child, polls its job ID and keeps the parent/form unchanged', async () => {
    const s = state()
    const source = s.source.value
    let resolveRequest

    service.regenerate.mockReturnValue(new Promise(resolve => { resolveRequest = resolve }))
    service.status.mockResolvedValue({ job_id: 'child', target_type: 'sound', status: 'ready', parent_id: parent.id, draft: { title: 'Bản mới' } })
    s.requestAction('regenerate', parent)

    const pending = s.confirmAction({ fields: ['content_html'], instructions: '' })

    await s.confirmAction()
    resolveRequest({ job_id: 'child', session_id: parent.id, parent_id: parent.id, target_type: 'sound', status: 'queued' })
    await pending
    expect(service.regenerate).toHaveBeenCalledExactlyOnceWith(parent.id, { fields: ['content'] })
    await vi.advanceTimersByTimeAsync(1000)
    expect(service.status).toHaveBeenCalledExactlyOnceWith('child', 'sound')
    expect(s.items.value).toHaveLength(2)
    expect(s.items.value.find(item => item.id === parent.id).title).toBe(parent.title)
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
    s.requestAction('regenerate', parent)
    await s.confirmAction()
    scope.stop()
    await vi.advanceTimersByTimeAsync(20000)
    expect(service.status).not.toHaveBeenCalled()
  })
})
