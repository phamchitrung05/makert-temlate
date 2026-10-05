/* eslint-disable camelcase -- Fixtures public API. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression Task 2 tại boundary nguồn/brief/profile/candidate API.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: setup(), beforeEach/afterEach(), các test preview/race/regenerate,
 * manual CRUD/default/version/Apply draft; không gọi provider hoặc ghi DB thật.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTTP mock + thao tác composable -> payload,
 * lifecycle, giữ bản sửa và khóa gửi trùng khi kết quả chưa rõ.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, shallowRef } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { useAiSourcePreview } from '@/composables/ai/useAiSourcePreview'
import { useAiPromptManagement } from '@/composables/ai/prompt/useAiPromptManagement'
import { useAiContentActions } from '@/composables/useAiContentActions'
import { buildAiContentRegenerateRequest, buildAiContentRequest, createAiContentSource } from '@/utils/aiContentInput'
import { emptyWritingPreferences, writingOptions } from '@/utils/aiArticleOptions'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: api, getAdminAccessToken: () => 'test' }))
let scope
const profile = { id: 4, name: 'Giải thích trực tiếp', version: 3, origin: 'manual', is_enabled: true, rules_json: { tone: 'Tự nhiên' }, evidence_json: [], style_instructions: 'Nêu vấn đề rồi giải thích bằng ví dụ.', description: '' }

/**
 * =====================================================================
 * Input: callback effect. Output: state scoped để kiểm cleanup và phản hồi muộn.
 * =====================================================================
 */
const setup = factory => scope.run(factory)

beforeEach(() => {
  scope = effectScope()
  api.mockReset()
  api.mockImplementation(async path => ({ success: true, data: path.endsWith('/options') ? { items: [profile], default_writing_profile_id: 4 } : profile }))
})
afterEach(() => { scope.stop() })

describe('Task 2 source and writing contract', () => {
  it('keeps raw HTML, custom instructions, approved profile/brief and manual IDs in create payload', async () => {
    const source = { ...createAiContentSource(), type: 'html', html: '<article><pre><code>if (true) {\n  run();\n}</code></pre><table><tr><td>42</td></tr></table></article>', outputs: ['title', 'content', 'taxonomy'], instructions: 'Giữ code, giải thích cho người mới.', category_ids: [7], tag_ids: [9], writing: { profile: 4, overrideBrief: true, brief: { audience: ' Người mới ', angle: 'Ý nghĩa thay đổi', invented: 'Không gửi' } } }
    const request = await buildAiContentRequest(source, { id: 8 })

    expect(request.input.html).toBe(source.html)
    expect(request).toMatchObject({ writing_profile_id: 4, writing_brief: { audience: 'Người mới', angle: 'Ý nghĩa thay đổi' }, category_ids: [7], tag_ids: [9], requested_outputs: ['title', 'content'] })
    expect(request.instructions).toContain(source.instructions)
    expect(request.instructions).toContain('không lặp ý')
  })
  it('distinguishes inherited snapshots from explicit website default and empty brief/taxonomy overrides', () => {
    expect(writingOptions(emptyWritingPreferences(true), true)).toEqual({})

    const override = writingOptions({ profile: 'default', brief: {}, overrideBrief: true }, true)

    expect(buildAiContentRegenerateRequest({ fields: ['content_html'], ...override, category_ids: [], tag_ids: [], refresh_source: true }))
      .toEqual({ fields: ['content'], writing_profile_id: null, writing_brief: {}, category_ids: [], tag_ids: [], refresh_source: true })
  })
  it('previews the original file with its encoding without calling generation and invalidates late results', async () => {
    let resolvePreview
    api.mockReturnValueOnce(new Promise(resolve => { resolvePreview = resolve }))

    const file = new File(['<article><pre>  code</pre></article>'], 'source.html', { type: 'text/html' })
    const source = shallowRef({ ...createAiContentSource(), type: 'file', file, sourceEncoding: 'Windows-1252' })
    const state = setup(() => useAiSourcePreview(source))
    const pending = state.read()
    const [path, options] = api.mock.calls[0]

    expect(path).toBe('/admin/ai-agent/source-preview')
    expect(options.body.get('html_file')).toBe(file)
    expect(options.body.get('source_encoding')).toBe('Windows-1252')
    source.value = { ...source.value, type: 'url', url: 'https://example.org/new' }
    resolvePreview({ success: true, data: { title: 'Nguồn cũ', blocks: [] } })
    await pending
    expect(state.snapshot.value).toBeNull()
    expect(state.busy.value).toBe(false)
    expect(options.signal.aborted).toBe(true)
    expect(api).toHaveBeenCalledOnce()
  })
  it('keeps a selected source on encoding/budget errors and stops a response after unmount', async () => {
    const source = shallowRef({ ...createAiContentSource(), type: 'html', html: '<article>source</article>' })
    const state = setup(() => useAiSourcePreview(source))

    api.mockRejectedValueOnce({ status: 422, data: { errors: { source_encoding: ['Nguồn không phải UTF-8.'] } } })
    await state.read()
    expect(state.error.value).toContain('UTF-8')
    expect(source.value.html).toContain('source')
    let resolvePreview
    api.mockReturnValueOnce(new Promise(resolve => { resolvePreview = resolve }))

    const pending = state.read()

    scope.stop()
    resolvePreview({ success: true, data: { title: 'Late' } })
    await pending
    expect(state.snapshot.value).toBeNull()
  })
  it('keeps source preview when only profile, brief or taxonomy changes', async () => {
    const source = shallowRef({ ...createAiContentSource(), type: 'text', text: 'Nguồn đã đọc' })
    const state = setup(() => useAiSourcePreview(source))

    api.mockResolvedValueOnce({ success: true, data: { title: 'Nguồn đã đọc', blocks: [] } })
    await state.read()
    source.value = { ...source.value, writing: { profile: 4, brief: { purpose: 'Giải thích' } }, category_ids: [7] }
    expect(state.snapshot.value.title).toBe('Nguồn đã đọc')
    source.value = { ...source.value, text: 'Nguồn khác' }
    expect(state.snapshot.value).toBeNull()
  })
})

describe('Task 2 profile management', () => {
  it('creates a manual profile without analysis, saves the requested default and refreshes List', async () => {
    const changed = vi.fn()
    const state = setup(() => useAiPromptManagement(changed))

    state.open('create')
    await flushPromises()
    state.form.value = { ...state.form.value, name: 'Mẫu thủ công', style_instructions: 'Giải thích dễ hiểu.', rules_json: { tone: 'Tự nhiên' }, setAsDefault: true }
    api.mockResolvedValueOnce({ success: true, data: { ...profile, id: 12, name: 'Mẫu thủ công', version: 1 } })
      .mockResolvedValueOnce({ success: true, data: { default_writing_profile_id: 12 } })
    await state.save()
    expect(api).toHaveBeenCalledWith('/admin/ai/writing-profiles', expect.objectContaining({ method: 'POST', retry: 0, body: expect.objectContaining({ analysis_id: null, rules_json: { tone: 'Tự nhiên' } }) }))
    expect(api).toHaveBeenLastCalledWith('/admin/settings/ai/settings', { method: 'PUT', retry: 0, body: { default_writing_profile_id: 12 } })
    expect(api.mock.calls.some(([path]) => path.endsWith('/analyses'))).toBe(false)
    expect(changed).toHaveBeenCalledOnce()
    expect(state.isOpen.value).toBe(false)
    expect(state.form.value.name).toBe('Mẫu thủ công')
    state.finishClose('create')
    expect(state.action.value).toBeNull()
  })
  it('preserves the edit on 409, loads the latest version only explicitly, then sends that version', async () => {
    const state = setup(() => useAiPromptManagement())

    state.open('edit', profile)
    await flushPromises()
    state.form.value = { ...state.form.value, name: 'Tên đang sửa' }
    api.mockRejectedValueOnce({ status: 409, data: { message: 'Mẫu đã đổi phiên bản.' } })
    await state.save()
    expect(state.form.value.name).toBe('Tên đang sửa')
    expect(state.conflict.value).toBe(true)
    expect(state.canSave.value).toBe(false)
    api.mockImplementation(async path => ({ success: true, data: path.endsWith('/options') ? { items: [profile], default_writing_profile_id: 4 } : { ...profile, version: 5 } }))
    await state.load()
    expect(state.profile.value.version).toBe(5)
    expect(state.form.value.name).toBe(profile.name)
    await state.save()
    expect(api).toHaveBeenLastCalledWith('/admin/ai/writing-profiles/4', expect.objectContaining({ method: 'PUT', body: expect.objectContaining({ version: 5 }) }))
  })
  it('uses versioned toggle/delete and leaves historic run snapshots to the backend', async () => {
    const state = setup(() => useAiPromptManagement())

    state.open('toggle', profile)
    await flushPromises()
    await state.confirm()
    expect(api).toHaveBeenLastCalledWith('/admin/ai/writing-profiles/4', { method: 'PUT', body: { version: 3, is_enabled: false }, retry: 0 })
    state.finishClose('toggle')
    state.open('delete', profile)
    await flushPromises()
    await state.confirm()
    expect(api).toHaveBeenLastCalledWith('/admin/ai/writing-profiles/4', { method: 'DELETE', body: { version: 3 }, retry: 0 })
  })
  it('keeps a saved profile when default update fails and blocks repeating an uncertain create', async () => {
    const state = setup(() => useAiPromptManagement())

    state.open('create')
    await flushPromises()
    state.form.value = { ...state.form.value, name: 'Mẫu mới', style_instructions: 'Viết tự nhiên.', rules_json: { tone: 'Tự nhiên' }, setAsDefault: true }
    api.mockResolvedValueOnce({ success: true, data: { ...profile, id: 12 } }).mockRejectedValueOnce(new Error('Default offline'))
    await state.save()
    expect(state.profile.value.id).toBe(12)
    expect(state.defaultError.value).toContain('offline')
    expect(state.action.value).not.toBeNull()
    state.close()
    state.finishClose('create')
    state.open('create')
    await flushPromises()
    state.form.value = { ...state.form.value, name: 'Kết quả chưa rõ', style_instructions: 'Tự nhiên.', rules_json: { tone: 'Tự nhiên' } }
    api.mockRejectedValueOnce(new Error('Lost response'))
    await state.save()
    expect(state.uncertain.value).toBe(true)

    const calls = api.mock.calls.length

    await state.save()
    expect(api.mock.calls).toHaveLength(calls)
  })
  it('keeps the closing form and ignores stale detail before cleaning up after the transition', async () => {
    let resolveDetail
    api.mockImplementation(path => path.endsWith('/options') ? Promise.resolve({ success: true, data: { items: [] } }) : new Promise(resolve => { resolveDetail = resolve }))

    const state = setup(() => useAiPromptManagement())

    state.open('edit', profile)

    const signal = api.mock.calls.find(([path]) => path.endsWith('/4'))[1].signal

    state.close()
    expect(signal.aborted).toBe(true)
    resolveDetail({ success: true, data: { ...profile, name: 'Phản hồi muộn' } })
    await flushPromises()
    expect(state.isOpen.value).toBe(false)
    expect(state.action.value.kind).toBe('edit')
    expect(state.form.value.name).toBe(profile.name)
    expect(state.loading.value).toBe(true)
    state.finishClose('edit')
    expect(state.action.value).toBeNull()
    expect(state.profile.value).toBeNull()
  })
  it('preserves an unchecked default when clearing the default fails, so retry still clears it', async () => {
    const state = setup(() => useAiPromptManagement())

    state.open('edit', profile)
    await flushPromises()
    state.form.value = { ...state.form.value, setAsDefault: false }
    api.mockResolvedValueOnce({ success: true, data: { ...profile, version: 4 } }).mockRejectedValueOnce(new Error('Default offline'))
    await state.save()
    expect(state.form.value.setAsDefault).toBe(false)
    expect(state.action.value.kind).toBe('edit')
    api.mockResolvedValueOnce({ success: true, data: { ...profile, version: 5 } }).mockResolvedValueOnce({ success: true, data: { default_writing_profile_id: null } })
    await state.save()
    expect(api).toHaveBeenLastCalledWith('/admin/settings/ai/settings', expect.objectContaining({ body: { default_writing_profile_id: null } }))
    expect(state.isOpen.value).toBe(false)
    state.finishClose('edit')
    expect(state.action.value).toBeNull()
  })
})

describe('Task 2 Apply and cancellation boundary', () => {
  it('reads candidate detail before Apply and sends the viewed version with manual taxonomy once', async () => {
    const updateSession = vi.fn()
    const state = setup(() => useAiContentActions({ updateSession, removeItem: vi.fn() }))

    api.mockResolvedValueOnce({ success: true, data: { job_id: 'run1', status: 'ready', draft_version: 'server-v4', draft: { title: 'Bài', taxonomy_origin: 'manual' } } })
    await state.requestAction('apply', { id: 'run1', targetType: 'post', status: 'review' })
    expect(api).toHaveBeenCalledOnce()
    expect(api.mock.calls[0][0]).toBe('/admin/ai-agent/sessions/run1')
    api.mockResolvedValueOnce({ success: true, data: { post_id: 17, fields: ['title', 'content', 'taxonomy'] } })
    await state.confirmAction({ fields: ['title', 'content', 'taxonomy'], category_ids: [7], tag_ids: [9] })
    expect(api).toHaveBeenLastCalledWith('/admin/ai-agent/candidates/run1/apply', expect.objectContaining({ method: 'POST', body: { fields: ['title', 'content', 'taxonomy'], category_ids: [7], tag_ids: [9], expected_version: 'server-v4' } }))
    expect(updateSession).toHaveBeenLastCalledWith(expect.objectContaining({ applied_target_id: 17 }))
    expect(state.notice.value.message).toContain('nháp #17')
  })
  it('does not Apply or regenerate after a failed detail GET and retains the action on version conflict', async () => {
    const state = setup(() => useAiContentActions({ updateSession: vi.fn(), removeItem: vi.fn() }))

    api.mockRejectedValueOnce({ status: 403, data: { message: 'Không có quyền.' } })
    await state.requestAction('apply', { id: 'run1', targetType: 'post', status: 'review' })
    await state.confirmAction({ fields: ['content'] })
    expect(api).toHaveBeenCalledOnce()
    expect(state.actionError.value).toContain('quyền')
    state.closeAction()
    api.mockResolvedValueOnce({ success: true, data: { job_id: 'run1', status: 'ready', draft_version: 'v1' } })
    await state.requestAction('apply', { id: 'run1', targetType: 'post', status: 'review' })
    api.mockRejectedValueOnce({ status: 409, data: { message: 'Candidate đã thay đổi.' } })
    await state.confirmAction({ fields: ['content'] })
    expect(state.action.value.kind).toBe('apply')
    expect(state.actionError.value).toContain('thay đổi')
  })
  it('allows an active run to be cancelled without sending a new generation request', async () => {
    const updateSession = vi.fn()
    const state = setup(() => useAiContentActions({ updateSession, removeItem: vi.fn() }))

    await state.requestAction('cancel', { id: 'run1', status: 'generating', targetType: 'post' })
    api.mockResolvedValueOnce({ success: true, data: { job_id: 'run1', status: 'cancelled' } })
    await state.confirmAction()
    expect(api).toHaveBeenCalledExactlyOnceWith('/admin/ai-agent/sessions/run1/cancel', expect.objectContaining({ method: 'POST' }))
    expect(updateSession).toHaveBeenCalledWith({ job_id: 'run1', status: 'cancelled' })
    await nextTick()
  })
})
