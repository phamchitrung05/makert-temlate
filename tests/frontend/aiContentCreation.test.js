/* eslint-disable camelcase -- Fixtures theo DTO Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra nguồn, lifecycle và form tạo bài AI độc lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: createState(), test validation/input/queue/error/cleanup/list/reset.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): nguồn và API giả -> assertion contract/lifecycle.
 * SIDE EFFECT: fake timer/service; không gọi provider hoặc DB thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, shallowRef } from 'vue'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { useAiContentGeneration } from '@/composables/useAiContentGeneration'
import { buildAiContentRequest, createAiContentSource, validateAiContentSource } from '@/utils/aiContentInput'
import { useAiRunFeedback } from '@/composables/useAiRunFeedback'

const { service } = vi.hoisted(() => ({ service: { createSession: vi.fn(), status: vi.fn(), listSessions: vi.fn(), retry: vi.fn() } }))

vi.mock('@/services/aiAgent', () => ({ aiAgentService: service }))

const textModel = { id: 30, capabilities: ['text_generation'] }
const outputKeys = ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail']
const catalog = () => ({ loading: false, error: '', selectedModel: textModel, outputOptions: outputKeys.map(value => ({ value, title: value })) })
const validSource = () => ({ ...createAiContentSource({ outputs: ['title', 'excerpt', 'content', 'taxonomy', 'thumbnail'] }), type: 'prompt', prompt: 'Viết bài hướng dẫn Laravel', provider: 'content', model: 'text-model' })
let scope

/** Input: không có. Output: composables thuộc scope test với source/catalog hợp lệ. */
function createState() {
  return scope.run(() => {
    const workspace = useAiContentWorkspace()

    workspace.source.value = validSource()

    const feedback = useAiRunFeedback()

    return { ...workspace, ...feedback, ...useAiContentGeneration(workspace.source, shallowRef(catalog()), workspace.updateSession, feedback.observeRun) }
  })
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.useFakeTimers()
  scope = effectScope()
  service.listSessions.mockResolvedValue({ data: [], meta: { pagination: { last_page: 1 } } })
})
afterEach(() => { scope.stop(); vi.useRealTimers() })

describe('Ai Content creation input', () => {
  it('requires a source and a known text capability, with a visible reason', () => {
    const source = validSource()

    expect(validateAiContentSource(source, catalog())).toBe('')
    expect(validateAiContentSource({ ...source, prompt: ' ' }, catalog())).toContain('yêu cầu')
    expect(validateAiContentSource(source, { ...catalog(), selectedModel: { capabilities: ['image_generation'] } })).toContain('không hỗ trợ')
    expect(validateAiContentSource(source, { ...catalog(), selectedModel: { capabilities: [] } })).toContain('không hỗ trợ')
    expect(validateAiContentSource(source, { ...catalog(), loading: true })).toContain('Đang tải')
  })

  it.each(['javascript:alert(1)', 'https://user:pass@example.test', 'bad url'])('blocks invalid URL %s', url => {
    expect(validateAiContentSource({ ...validSource(), type: 'url', url }, catalog())).toContain('URL')
  })

  it('maps a URL to the existing API with source thumbnail and exact model identity', async () => {
    const payload = await buildAiContentRequest({ ...validSource(), type: 'url', url: ' https://example.test/article ' }, textModel)

    expect(payload).toMatchObject({ target_type: 'post', operation: 'create', input: { type: 'url', url: 'https://example.test/article' }, model_id: 30, thumbnail_mode: 'source', generate_thumbnail: true })
    expect(payload.requested_outputs).not.toContain('seo')
    expect(payload.requested_outputs).toContain('thumbnail')
  })

  it('sends exactly the selected output tags with SEO and thumbnail flags derived from them', async () => {
    const source = { ...validSource(), outputs: ['title', 'content'], type: 'url', url: 'https://example.test/article' }
    const payload = await buildAiContentRequest(source, textModel)

    expect(payload.requested_outputs).toEqual(['title', 'content'])
    expect(payload).toMatchObject({ generate_thumbnail: false, generate_seo: false, thumbnail_mode: 'source' })
  })

  it('maps free writing to text and respects length/language/manual title/SEO', async () => {
    const payload = await buildAiContentRequest({ ...validSource(), language: 'en', length: 'long', outputs: ['excerpt', 'content', 'seo'], title: 'My title' }, textModel)

    expect(payload.input).toEqual({ type: 'text', text: 'Viết bài hướng dẫn Laravel', title: 'My title' })
    expect(payload.instructions).toContain('đề bài')
    expect(payload.instructions).toContain('tiếng Anh')
    expect(payload.instructions).toContain('1.500–2.000')
    expect(payload.instructions).toContain('My title')
    expect(payload.instructions).toContain('SEO')
    expect(payload.requested_outputs).toContain('seo')
    expect(payload.generate_thumbnail).toBe(false)
    expect(payload.generate_seo).toBe(true)
    expect(payload.requested_outputs).not.toContain('title')
  })

  it('requires selected supported outputs and a manual title when title generation is not selected', () => {
    const source = validSource()

    expect(validateAiContentSource({ ...source, outputs: [] }, catalog())).toContain('ít nhất một')
    expect(validateAiContentSource({ ...source, outputs: ['unknown'] }, catalog())).toContain('được hỗ trợ')
    expect(validateAiContentSource({ ...source, outputs: ['content'] }, catalog())).toContain('Nhập tiêu đề')
    expect(validateAiContentSource({ ...source, outputs: ['content'], title: 'Manual title' }, catalog())).toBe('')
    expect(validateAiContentSource({ ...source, outputs: ['title', 'thumbnail'] }, {
      ...catalog(), outputOptions: [{ value: 'title' }, { value: 'thumbnail', props: { disabled: true } }],
    })).toContain('được hỗ trợ')
  })

  it('extracts HTML article text without scripts or navigation and maps inline text', async () => {
    const file = { name: 'source.html', size: 200, text: async () => '<nav>navigation</nav><article><header><h1>Title</h1></header><p>First paragraph</p><script>bad()</script><p>Second paragraph</p></article><footer>footer</footer>' }
    const payload = await buildAiContentRequest({ ...validSource(), type: 'file', file: [file] }, textModel)

    expect(payload.input.type).toBe('text')
    expect(payload.input.text).toContain('Title')
    expect(payload.input.text).toContain('First paragraph\nSecond paragraph')
    expect(payload.input.text).not.toMatch(/bad|navigation|footer/)
    await expect(buildAiContentRequest({ ...validSource(), type: 'file', file: { text: async () => '<script>bad()</script>' } }, textModel)).rejects.toThrow('Không tìm thấy')

    const textPayload = await buildAiContentRequest({ ...validSource(), type: 'text', text: '  Nguồn bài viết  ' }, textModel)

    expect(textPayload.input.text).toBe('Nguồn bài viết')
  })
})

describe('Ai Content generation lifecycle', () => {
  it('shows a terminal error once, keeps the reason after list reload and ignores a failed draft', async () => {
    const state = createState()

    const failure = { job_id: 'one', status: 'failed', error_code: 'AI_PROVIDER_MISSING_FIELDS', error: 'AI thiếu nội dung',
      validation_errors: [{ group: 'content', field: 'content_html', reason: 'missing' }], draft: { title: 'Invalid output' } }

    service.createSession.mockResolvedValue(failure)
    await state.generate()
    expect(state.snackbar.value.visible).toBe(true)
    expect(state.items.value[0]).toMatchObject({ status: 'failed', errorCode: 'AI_PROVIDER_MISSING_FIELDS' })
    expect(state.items.value[0].title).not.toBe('Invalid output')
    state.setSnackbarVisible(false)
    service.status.mockResolvedValue(failure)
    state.resumePolling()
    await Promise.resolve()
    expect(state.snackbar.value.visible).toBe(false)
    service.listSessions.mockResolvedValue({ data: [{ ...failure, id: 'one', draft: undefined }], meta: { pagination: { last_page: 1 } } })
    await state.loadItems()
    expect(state.items.value[0].error).toContain('thiếu')
    expect(state.items.value[0].validationErrors).toEqual(failure.validation_errors)
    expect(state.snackbar.value.visible).toBe(false)
    expect(service.createSession).toHaveBeenCalledOnce()
  })
  it('waits for manual retry after failure and requeues the same run once', async () => {
    const state = createState()

    service.createSession.mockResolvedValue({ job_id: 'one', status: 'queued' })
    service.status.mockResolvedValueOnce({ job_id: 'one', status: 'failed', error: 'Kết nối bị ngắt' })
    await state.generate()
    await vi.advanceTimersByTimeAsync(20000)
    expect(state.generation.value.canRetry).toBe(true)
    expect(service.retry).not.toHaveBeenCalled()
    service.retry.mockResolvedValue({ job_id: 'one', status: 'queued' })
    service.status.mockResolvedValue({ job_id: 'one', status: 'ready', draft: { title: 'Retried' } })

    const pending = state.retryRun()

    await state.retryRun()
    await pending
    expect(service.retry).toHaveBeenCalledExactlyOnceWith('one')
    expect(service.createSession).toHaveBeenCalledOnce()
    await vi.advanceTimersByTimeAsync(1000)
    expect(state.items.value).toHaveLength(1)
    expect(state.items.value[0].title).toBe('Retried')
    expect(state.generation.value.canRetry).toBe(false)
  })

  it('keeps a failed run retryable when retry API rejects and ignores responses after disposal', async () => {
    const state = createState()

    service.createSession.mockResolvedValue({ job_id: 'one', status: 'failed', error: 'Timeout' })
    await state.generate()
    service.retry.mockRejectedValueOnce({ data: { errors: { provider: ['Provider đã tắt'] } } })
    await state.retryRun()
    expect(state.generation.value.error).toBe('Provider đã tắt')
    expect(state.generation.value.canRetry).toBe(true)
    let resolveRetry
    service.retry.mockReturnValue(new Promise(resolve => { resolveRetry = resolve }))

    const pending = state.retryRun()

    scope.stop()
    resolveRetry({ job_id: 'one', status: 'queued' })
    await pending
    expect(state.items.value[0].status).toBe('failed')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('prevents duplicate submission, polls to ready and keeps creation source independent of list', async () => {
    const state = createState()

    service.createSession.mockResolvedValue({ job_id: 'one', status: 'queued', progress: 0 })
    service.status.mockResolvedValue({ job_id: 'one', status: 'ready', progress: 100, draft: { title: 'Generated title' } })

    const pending = state.generate()

    await state.generate()
    expect(state.generation.value.busy).toBe(true)
    await pending
    expect(service.createSession).toHaveBeenCalledOnce()
    expect(state.items.value[0].status).toBe('generating')
    expect(state.reset()).toBe(false)
    await vi.advanceTimersByTimeAsync(1000)
    expect(state.items.value[0]).toMatchObject({ title: 'Generated title', status: 'review' })
    expect(state.generation.value.busy).toBe(false)
    expect(state.source.value.prompt).toBe('Viết bài hướng dẫn Laravel')
    expect(state.reset()).toBe(true)
    state.resetSource()
    expect(state.source.value).toMatchObject({ prompt: '', url: '', provider: 'content', model: 'text-model' })
    expect(state.items.value).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('shows validation errors and failed run errors without applying a Post', async () => {
    const state = createState()

    service.createSession.mockRejectedValueOnce({ data: { errors: { model: ['Model chưa bật'] } } })
    await state.generate()
    expect(state.generation.value.error).toBe('Model chưa bật')
    expect(state.generation.value.canGenerate).toBe(true)
    service.createSession.mockResolvedValue({ job_id: 'one', status: 'queued' })
    service.status.mockResolvedValue({ job_id: 'one', status: 'failed', error: 'Provider không khả dụng' })
    await state.generate()
    await vi.advanceTimersByTimeAsync(1000)
    expect(state.generation.value.error).toBe('Provider không khả dụng')
    expect(state.items.value[0].status).toBe('failed')
  })

  it('resumes monitoring after a network error without posting a second job', async () => {
    const state = createState()

    service.createSession.mockResolvedValue({ job_id: 'one', status: 'queued' })
    service.status.mockRejectedValueOnce(new Error('network'))
    await state.generate()
    await vi.advanceTimersByTimeAsync(1000)
    expect(state.generation.value.monitorMessage).toContain('Cập nhật trạng thái')
    expect(state.generation.value.canGenerate).toBe(false)
    service.status.mockResolvedValue({ job_id: 'one', status: 'ready' })
    state.resumePolling()
    await Promise.resolve()
    await Promise.resolve()
    expect(state.generation.value.busy).toBe(false)
    expect(service.createSession).toHaveBeenCalledOnce()
  })

  it('ignores a create response after page disposal and clears polling timers', async () => {
    const state = createState()
    let resolveCreate
    service.createSession.mockReturnValue(new Promise(resolve => { resolveCreate = resolve }))

    const pending = state.generate()

    await Promise.resolve()
    scope.stop()
    resolveCreate({ job_id: 'old', status: 'queued' })
    await pending
    expect(state.items.value).toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('reads all collection pages without replacing source or losing a newer live result', async () => {
    const state = createState()
    let resolveList
    service.listSessions.mockResolvedValueOnce({ data: [{ id: 'one', title: '', status: 'queued' }], meta: { pagination: { last_page: 2 } } })
    service.listSessions.mockReturnValueOnce(new Promise(resolve => { resolveList = resolve }))

    const loading = state.loadItems()

    await Promise.resolve()
    state.updateSession({ job_id: 'one', status: 'ready', draft: { title: 'Newest' } })
    resolveList({ data: [{ id: 'two', title: 'Other', status: 'ready' }], meta: { pagination: { last_page: 2 } } })
    await loading
    expect(state.items.value).toHaveLength(2)
    expect(state.items.value.find(item => item.id === 'one').title).toBe('Newest')
    expect(state.source.value.prompt).toBe('Viết bài hướng dẫn Laravel')
    expect(service.listSessions).toHaveBeenLastCalledWith({ page: 2, per_page: 100 })
  })

  it('shows saved thumbnails on initial load and updates them when a run completes', async () => {
    const state = createState()
    const thumbnail = { id: 19, alt_text: 'Laravel cover', file: { url: '/storage/19/cover.webp', preview_url: '/storage/19/conversions/cover-thumb.webp' } }

    service.listSessions.mockResolvedValue({
      data: [{ id: 'one', title: 'Laravel 14', status: 'ready', thumbnail }],
      meta: { pagination: { last_page: 1 } },
    })
    await state.loadItems()
    expect(state.items.value[0].thumbnail).toEqual(thumbnail)
    state.updateSession({ job_id: 'two', status: 'queued', thumbnail: null })
    expect(state.items.value.find(item => item.id === 'two').thumbnail).toBeNull()
    state.updateSession({ job_id: 'two', status: 'ready', draft: { title: 'New article' }, thumbnail })
    expect(state.items.value.find(item => item.id === 'two').thumbnail).toEqual(thumbnail)
  })

  it('preserves a thumbnail on partial updates and removes it when the server explicitly clears it', async () => {
    const state = createState()
    const thumbnail = { id: 19, file: { url: '/storage/19/cover.webp' } }

    state.updateSession({ job_id: 'one', status: 'ready', draft: { title: 'Laravel 14' }, thumbnail })
    state.updateSession({ job_id: 'one', status: 'ready', draft: { title: 'Edited title' } })
    expect(state.items.value[0].thumbnail).toEqual(thumbnail)
    state.updateSession({ job_id: 'one', status: 'ready', thumbnail: null })
    expect(state.items.value[0].thumbnail).toBeNull()
  })
})
