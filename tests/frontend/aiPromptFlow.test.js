/* eslint-disable camelcase -- Fixtures và payload giữ tên field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression nguồn, phân tích và duyệt lưu văn phong Ai Prompt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: deferred(), createFlow(), readyFlow(),
 * analysisDto(), profileDto(), beforeEach(), afterEach() và các ca lifecycle/reset.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : composable Vue thật trong effectScope, service fake và response trễ.
 * - OUTPUT: assertions snapshot/validation, polling/cancel/resume, versioned save
 *   và khôi phục kết quả POST bất định, reset form/resume sau khi tạo mẫu xong.
 * - SIDE EFFECT: timer/storage giả lập; không gọi model hoặc ghi database.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, shallowRef } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { useAiPromptSource } from '@/composables/ai/prompt/useAiPromptSource'
import { useAiPromptAnalysis } from '@/composables/ai/prompt/useAiPromptAnalysis'
import { useAiPromptProfiles } from '@/composables/ai/prompt/useAiPromptProfiles'
import { referenceText } from '@/utils/aiWritingProfile'

const mocks = vi.hoisted(() => ({
  service: { createAnalysis: vi.fn(), analysis: vi.fn(), cancel: vi.fn(), previewSource: vi.fn(), save: vi.fn(), profile: vi.fn(), findProfiles: vi.fn(), updateDefault: vi.fn() },
  settings: { list: vi.fn() },
}))

vi.mock('@/services/aiWritingProfiles', () => ({ aiWritingProfilesService: mocks.service }))
vi.mock('@/services/aiProviderSettings', () => ({ aiProviderSettingsService: mocks.settings }))

const analysisId = 'aaaaaaaa-1111-4111-8111-111111111111'
const otherAnalysisId = 'bbbbbbbb-2222-4222-8222-222222222222'
const sourceHtml = '<p>Bài tham khảo có đoạn văn tự nhiên để phân tích cách diễn đạt.</p><p>Đoạn thứ hai giải thích bằng ví dụ.</p>'

const result = {
  summary: 'Diễn đạt trực tiếp với ví dụ ngắn.',
  rules: { tone: 'Gần gũi', opening: 'Tình huống cụ thể', sentence_rhythm: 'Câu ngắn xen câu giải thích', structure_patterns: ['Giải thích rồi ví dụ'] },
  evidence: [{ feature: 'tone', excerpt: 'Bài tham khảo', explanation: 'Mở đầu bằng cách diễn đạt trực tiếp.' }],
  style_instructions: 'Giải thích tự nhiên, ưu tiên ví dụ phù hợp nguồn.',
}

let scopes

/**
 * =====================================================================
 * CHỨC NĂNG: Giữ response để kiểm race đến trễ sau hủy/đổi run/dispose.
 * Input: không có. Output: promise và resolve; không tạo timer/network.
 * =====================================================================
 */
function deferred() {
  let resolve
  const promise = new Promise(_resolve => { resolve = _resolve })

  return { promise, resolve }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo analysis DTO với schema thật và status tùy trường hợp.
 * Input: status/UUID. Output: DTO mới; result chỉ có khi ready.
 * =====================================================================
 */
function analysisDto(status = 'queued', id = analysisId) {
  return { id, name: 'Giải thích dễ hiểu', status, result: status === 'ready' ? structuredClone(result) : null,
    provider: 'fake', model: 'fake-text', created_at: '2026-10-04T00:00:00Z', expires_at: '2100-10-06T00:00:00Z' }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo profile đã được server lưu từ payload người dùng duyệt.
 * Input: override tùy ca. Output: profile có ID/version và dữ liệu độc lập.
 * =====================================================================
 */
function profileDto(override = {}) {
  return { id: 7, version: 1, name: 'Giải thích dễ hiểu', description: null, rules_json: structuredClone(result.rules),
    evidence_json: structuredClone(result.evidence), style_instructions: result.style_instructions, is_enabled: true, ...override }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo public source/run/profile state trong scope được cleanup.
 * Input: quyền preview nguồn. Output: scope và các composable thật.
 * =====================================================================
 */
function createFlow(canPreview = true) {
  const scope = effectScope()

  const flow = scope.run(() => {
    const source = useAiPromptSource(canPreview)
    const run = useAiPromptAnalysis(source, shallowRef(41))
    const profiles = useAiPromptProfiles(run, source.catalog, shallowRef(41))

    source.profileName.value = 'Giải thích dễ hiểu'
    source.sourceHtml.value = sourceHtml

    return { source, run, profiles }
  })

  scopes.push(scope)

  return { scope, ...flow }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đưa flow đến ready bằng API fake, không tự lưu profile.
 * Input: không có. Output: flow đã nhận result và form duyệt độc lập.
 * =====================================================================
 */
async function readyFlow() {
  const flow = createFlow()

  await flow.run.analyze()
  await flushPromises()

  return flow
}

beforeEach(() => {
  scopes = []
  vi.resetAllMocks()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  window.sessionStorage.clear()
  mocks.service.createAnalysis.mockResolvedValue(analysisDto())
  mocks.service.analysis.mockResolvedValue(analysisDto('ready'))
  mocks.service.save.mockImplementation(async (payload, id) => profileDto({ ...payload, id: id ?? 7, version: id ? 2 : 1 }))
  mocks.settings.list.mockResolvedValue({ providers: [], settings: {} })
})

afterEach(() => {
  scopes.forEach(scope => scope.stop())
  vi.clearAllTimers()
  vi.useRealTimers()
})

describe('Ai Prompt reference preparation', () => {
  it('keeps extracted text separate from HTML and discards late previews after the URL changes', async () => {
    const flow = createFlow()
    const pending = deferred()
    const html = '<article><p>Văn bản nguồn có ảnh và liên kết để kiểm đếm.</p><img src="example.jpg"><a href="https://example.com">Liên kết</a></article>'

    flow.source.sourceTab.value = 'url'
    flow.source.sourceUrl.value = 'https://example.com/first'
    mocks.service.previewSource.mockResolvedValueOnce({ content_html: html })
    await flow.source.preview()
    expect(mocks.service.previewSource.mock.calls[0][0]).toEqual({ target_type: 'post', url: 'https://example.com/first' })
    expect(flow.source.text.value).toBe(referenceText(html))
    expect(flow.source.metrics.value).toMatchObject({ imageCount: 1, linkCount: 1 })
    flow.source.sourceText.value += '\nPhần sửa trước khi gửi.'
    expect(flow.source.text.value).toContain('Phần sửa trước khi gửi.')

    flow.source.sourceUrl.value = 'https://example.com/second'
    mocks.service.previewSource.mockReturnValueOnce(pending.promise)

    const preview = flow.source.preview()
    const signal = mocks.service.previewSource.mock.calls[1][1]

    flow.source.sourceUrl.value = 'https://example.com/third'
    pending.resolve({ content_html: '<p>Kết quả cũ không được sử dụng.</p>' })
    await preview
    expect(signal.aborted).toBe(true)
    expect(flow.source.sourcePrepared.value).toBe(false)
    expect(flow.source.text.value).toBe('')
  })

  it('reads UTF-8 TXT locally without granting HTML/URL preview permission', async () => {
    const flow = createFlow(false)
    const file = new File(['Bài tham khảo dạng TXT có đủ nội dung để phân tích văn phong.'], 'sample.txt', { type: 'text/plain' })

    flow.source.sourceTab.value = 'file'
    flow.source.sourceFile.value = file
    await flow.source.preview()
    expect(flow.source.text.value).toContain('Bài tham khảo dạng TXT')
    expect(flow.source.sourcePrepared.value).toBe(true)
    expect(mocks.service.previewSource).not.toHaveBeenCalled()

    flow.source.sourceTab.value = 'url'
    flow.source.sourceUrl.value = 'https://example.com/article'
    await flow.source.preview()
    expect(flow.source.notice.value).toContain('quyền quản lý bài viết')
    expect(mocks.service.previewSource).not.toHaveBeenCalled()
  })
})

describe('Ai Prompt analysis lifecycle', () => {
  it('requires a user name and validates its Unicode character limit before sending', async () => {
    const flow = createFlow()

    for (const name of ['', '   ', '😀'.repeat(161)]) {
      flow.source.profileName.value = name
      await flow.run.analyze()
      expect(flow.run.errors.value.name).toBeTruthy()
    }
    expect(mocks.service.createAnalysis).not.toHaveBeenCalled()
    flow.source.profileName.value = '😀'.repeat(160)
    await flow.run.analyze()
    expect(mocks.service.createAnalysis.mock.calls[0][0].name).toBe('😀'.repeat(160))
  })

  it('locks double clicks and retains the exact submitted reference when the editor changes', async () => {
    const flow = createFlow()
    const pending = deferred()
    const submitted = flow.source.text.value

    flow.source.modelId.value = 8
    flow.source.catalog.providers.value = [{ id: 1, name: 'Fake', is_active: true, has_api_key: true, models: [{ id: 8, label: 'Text', is_enabled: true, is_available: true, capabilities: ['text_generation'] }] }]
    mocks.service.createAnalysis.mockReturnValueOnce(pending.promise)

    const creating = flow.run.analyze()

    await flow.run.analyze()
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
    expect(mocks.service.createAnalysis.mock.calls[0][0]).toEqual({ name: 'Giải thích dễ hiểu', reference_text: submitted, model_id: 8 })
    flow.source.sourceHtml.value = '<p>Bài khác đang được nhập trong lúc AI xử lý bài cũ.</p>'
    pending.resolve(analysisDto())
    await creating
    await flushPromises()
    expect(flow.run.submittedText.value).toBe(submitted)
    expect(flow.run.sourceChanged.value).toBe(true)
    expect(flow.profiles.canSave.value).toBe(false)
  })

  it('does not replay an uncertain POST, including another click after the network error', async () => {
    const flow = createFlow()

    mocks.service.createAnalysis.mockRejectedValueOnce(new Error('Network interrupted'))
    await flow.run.analyze()
    await vi.advanceTimersByTimeAsync(60000)
    await flow.run.analyze()
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
    expect(mocks.service.analysis).not.toHaveBeenCalled()
    expect(flow.run.uncertainSubmit.value).toBe(true)
    expect(flow.source.text.value).toBe(referenceText(sourceHtml))
  })

  it('pauses a failed GET and resumes the existing UUID without posting another task', async () => {
    const flow = createFlow()

    mocks.service.analysis.mockRejectedValueOnce(new Error('GET offline'))
    await flow.run.analyze()
    await flushPromises()
    expect(flow.run.paused.value).toBe(true)
    expect(flow.run.status.value).toBe('queued')
    await vi.advanceTimersByTimeAsync(60000)
    expect(mocks.service.analysis).toHaveBeenCalledTimes(1)
    flow.run.resumePolling()
    await flushPromises()
    expect(flow.run.status.value).toBe('ready')
    expect(mocks.service.analysis).toHaveBeenCalledTimes(2)
    expect(mocks.service.analysis.mock.calls.every(([id]) => id === analysisId)).toBe(true)
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
  })

  it('keeps cancellation when a previously pending poll returns ready afterward', async () => {
    const flow = createFlow()
    const pending = deferred()

    mocks.service.analysis.mockReturnValueOnce(pending.promise)
    mocks.service.cancel.mockResolvedValueOnce(analysisDto('cancelled'))
    await flow.run.analyze()

    const signal = mocks.service.analysis.mock.calls[0][1]

    await flow.run.cancel()
    pending.resolve(analysisDto('ready'))
    await flushPromises()
    await vi.advanceTimersByTimeAsync(60000)
    expect(signal.aborted).toBe(true)
    expect(flow.run.status.value).toBe('cancelled')
    expect(flow.run.analysis.value.result).toBeNull()
    expect(mocks.service.analysis).toHaveBeenCalledTimes(1)
  })

  it('aborts an in-flight GET and ignores its response when the effect scope ends', async () => {
    const flow = createFlow()
    const pending = deferred()

    mocks.service.analysis.mockReturnValueOnce(pending.promise)
    await flow.run.analyze()

    const signal = mocks.service.analysis.mock.calls[0][1]

    flow.scope.stop()
    pending.resolve(analysisDto('ready'))
    await flushPromises()
    await vi.advanceTimersByTimeAsync(60000)
    expect(signal.aborted).toBe(true)
    expect(flow.run.analysis.value.status).toBe('queued')
    expect(mocks.service.analysis).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('polls sequentially without overlapping requests or continuing after ready', async () => {
    const flow = createFlow()
    const pending = deferred()

    mocks.service.analysis.mockResolvedValueOnce(analysisDto('analyzing')).mockReturnValueOnce(pending.promise)
    await flow.run.analyze()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2000)
    expect(mocks.service.analysis).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(60000)
    expect(mocks.service.analysis).toHaveBeenCalledTimes(2)
    pending.resolve(analysisDto('ready'))
    await flushPromises()
    await vi.advanceTimersByTimeAsync(60000)
    expect(flow.run.status.value).toBe('ready')
    expect(mocks.service.analysis).toHaveBeenCalledTimes(2)
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
  })

  it('restores only the current actor UUID and never treats current editor text as its source', async () => {
    const flow = createFlow()

    window.sessionStorage.setItem('ai_prompt_analysis:41', JSON.stringify({ id: analysisId, expiresAt: '2100-10-06T00:00:00Z' }))
    window.sessionStorage.setItem('ai_prompt_analysis:99', JSON.stringify({ id: otherAnalysisId }))
    flow.run.restore()
    await flushPromises()
    expect(mocks.service.analysis.mock.calls[0][0]).toBe(analysisId)
    expect(mocks.service.createAnalysis).not.toHaveBeenCalled()
    expect(flow.run.status.value).toBe('ready')
    expect(flow.run.submittedText.value).toBeNull()
    expect(flow.run.sourceChanged.value).toBe(true)
    expect(flow.profiles.canSave.value).toBe(false)

    const stored = JSON.parse(window.sessionStorage.getItem('ai_prompt_analysis:41'))

    expect(Object.keys(stored).sort()).toEqual(['expiresAt', 'id'])
  })

  it.each([[410, 'expired'], [403, 'forbidden']])('removes inaccessible resume metadata for HTTP %i and allows a new form without creating a replacement', async (httpStatus, expectedStatus) => {
    const flow = createFlow()

    window.sessionStorage.setItem('ai_prompt_analysis:41', JSON.stringify({ id: analysisId }))
    mocks.service.analysis.mockRejectedValueOnce({ status: httpStatus, data: { message: 'Không thể mở phân tích' } })
    flow.run.restore()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(60000)
    expect(flow.run.status.value).toBe(expectedStatus)
    expect(flow.run.running.value).toBe(false)
    expect(flow.run.paused.value).toBe(false)
    expect(window.sessionStorage.getItem('ai_prompt_analysis:41')).toBeNull()
    expect(mocks.service.analysis).toHaveBeenCalledTimes(1)
    expect(mocks.service.createAnalysis).not.toHaveBeenCalled()
    expect(flow.run.reset()).toBe(true)
    expect(flow.run.status.value).toBe('idle')
  })
})

describe('Ai Prompt explicit approval and save', () => {
  it('saves a profile opened directly from List when its source analysis is unavailable', async () => {
    const flow = createFlow()
    const existing = profileDto({ id: 19, version: 3, name: 'Mẫu đã lưu' })

    mocks.service.profile.mockResolvedValueOnce(existing)

    expect(await flow.profiles.loadProfile(19)).toBe(true)
    flow.profiles.form.value.name = 'Mẫu đã chỉnh'
    expect(await flow.profiles.save()).toBe(true)

    const [payload, id] = mocks.service.save.mock.calls[0]

    expect(id).toBe(19)
    expect(payload).toMatchObject({ name: 'Mẫu đã chỉnh', version: 3 })
    expect(payload).not.toHaveProperty('analysis_id')
  })

  it('keeps edits independent from AI output and only saves/defaults after explicit approval', async () => {
    const flow = await readyFlow()

    expect(mocks.service.save).not.toHaveBeenCalled()
    flow.profiles.form.value.name = 'Tên người dùng sửa'
    flow.profiles.form.value.rules_json.tone = 'Tự nhiên hơn'
    flow.profiles.form.value.setAsDefault = true
    flow.source.catalog.settings.value = { default_writing_profile_id: null, default_text_model_id: 8, max_tokens: 2048 }
    mocks.service.updateDefault.mockResolvedValueOnce({ default_writing_profile_id: 7 })
    expect(await flow.profiles.save()).toBe(true)
    expect(mocks.service.save.mock.calls[0][0]).toMatchObject({ name: 'Tên người dùng sửa', rules_json: { tone: 'Tự nhiên hơn' }, analysis_id: analysisId, is_enabled: true })
    expect(flow.run.analysis.value.result.rules.tone).toBe('Gần gũi')
    expect(mocks.service.updateDefault).toHaveBeenCalledWith({ default_writing_profile_id: 7 })
    expect(flow.source.catalog.settings.value).toEqual({ default_writing_profile_id: 7, default_text_model_id: 8, max_tokens: 2048 })
    expect(flow.profiles.savedProfile.value.id).toBe(7)
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
  })

  it('updates the saved version without rebinding analysis or sending client-only fields', async () => {
    const flow = await readyFlow()

    await flow.profiles.save()
    flow.profiles.form.value.style_instructions = 'Hướng dẫn đã chỉnh sau khi lưu.'
    flow.profiles.form.value.rules_json.untrusted_key = 'Không gửi field ngoài allowlist'
    await flow.profiles.save()

    const [payload, id] = mocks.service.save.mock.calls[1]

    expect(id).toBe(7)
    expect(payload).toMatchObject({ version: 1, style_instructions: 'Hướng dẫn đã chỉnh sau khi lưu.' })
    expect(payload).not.toHaveProperty('analysis_id')
    expect(payload).not.toHaveProperty('setAsDefault')
    expect(payload.rules_json).not.toHaveProperty('untrusted_key')
    expect(flow.profiles.savedProfile.value.version).toBe(2)
  })

  it('preserves unsaved edits on 409 without silently reloading or replaying the PUT', async () => {
    const flow = await readyFlow()

    await flow.profiles.save()
    flow.profiles.form.value.name = 'Bản sửa chưa được lưu'
    flow.profiles.form.value.style_instructions = 'Hướng dẫn riêng cần giữ khi xung đột.'
    mocks.service.save.mockRejectedValueOnce({ status: 409, data: { message: 'Profile đã được chỉnh sửa' } })
    await flow.profiles.save()
    await vi.advanceTimersByTimeAsync(60000)
    expect(flow.profiles.conflict.value).toBe(true)
    expect(flow.profiles.form.value.name).toBe('Bản sửa chưa được lưu')
    expect(flow.profiles.form.value.style_instructions).toBe('Hướng dẫn riêng cần giữ khi xung đột.')
    expect(flow.profiles.savedProfile.value.version).toBe(1)
    expect(mocks.service.profile).not.toHaveBeenCalled()
    expect(mocks.service.save).toHaveBeenCalledTimes(2)
  })

  it('retains a successfully saved profile when a separate default update fails', async () => {
    const flow = await readyFlow()

    flow.profiles.form.value.setAsDefault = true
    flow.source.catalog.settings.value = { default_writing_profile_id: 3, default_text_model_id: 8 }
    mocks.service.updateDefault.mockRejectedValueOnce({ status: 422, data: { message: 'Chưa đặt được mặc định' } })
    expect(await flow.profiles.save()).toBe(false)
    expect(mocks.service.updateDefault).toHaveBeenCalledWith({ default_writing_profile_id: 7 })
    expect(flow.profiles.savedProfile.value).toMatchObject({ id: 7, version: 1 })
    expect(flow.profiles.message.value).toContain('Đã lưu văn phong')
    expect(flow.profiles.defaultError.value).toContain('Mẫu đã lưu')
    expect(flow.source.catalog.settings.value).toEqual({ default_writing_profile_id: 3, default_text_model_id: 8 })
    expect(mocks.service.save).toHaveBeenCalledTimes(1)
  })

  it('ignores a late profile save when the user restores another analysis UUID', async () => {
    const flow = await readyFlow()
    const pendingSave = deferred()
    const pendingRun = deferred()

    mocks.service.save.mockReturnValueOnce(pendingSave.promise)

    const saving = flow.profiles.save()

    mocks.service.analysis.mockReturnValueOnce(pendingRun.promise)
    flow.run.restore(otherAnalysisId)
    pendingSave.resolve(profileDto())
    await saving
    await flushPromises()
    expect(flow.run.analysis.value.id).toBe(otherAnalysisId)
    expect(flow.profiles.savedProfile.value).toBeNull()
    expect(window.sessionStorage.getItem(`ai_prompt_profile:41:${otherAnalysisId}`)).toBeNull()
  })
})

describe('Ai Prompt new-profile reset', () => {
  it('clears source/review/resume, preserves saved profile metadata and ignores a late GET', async () => {
    const flow = await readyFlow()

    flow.profiles.form.value.setAsDefault = true
    flow.source.catalog.settings.value = { default_writing_profile_id: null, default_text_model_id: 8 }
    mocks.service.updateDefault.mockResolvedValueOnce({ default_writing_profile_id: 7 })
    expect(await flow.profiles.save()).toBe(true)

    const pending = deferred()

    mocks.service.analysis.mockReturnValueOnce(pending.promise)
    flow.run.resumePolling()

    const signal = mocks.service.analysis.mock.calls.at(-1)[1]

    flow.source.sourceUrl.value = 'https://example.com/old'
    flow.source.sourceFile.value = new File(['Bài tham khảo cũ'], 'old.txt', { type: 'text/plain' })
    flow.source.sourceTab.value = 'file'
    flow.source.modelId.value = 8
    expect(flow.run.reset()).toBe(true)
    expect(flow.profiles.reset()).toBe(true)
    flow.source.resetForNewProfile()
    pending.resolve(analysisDto('ready'))
    await flushPromises()

    expect(signal.aborted).toBe(true)
    expect(flow.run.status.value).toBe('idle')
    expect(flow.run.analysis.value).toBeNull()
    expect(flow.run.submittedText.value).toBeNull()
    expect(flow.profiles.savedProfile.value).toBeNull()
    expect(flow.profiles.form.value).toMatchObject({ name: '', rules_json: {}, evidence_json: [], style_instructions: '', is_enabled: true, setAsDefault: false })
    expect(flow.profiles.message.value).toBe('')
    expect(flow.profiles.defaultError.value).toBe('')
    expect(flow.source.profileName.value).toBe('')
    expect(flow.source.sourceHtml.value).toBe('')
    expect(flow.source.sourceUrl.value).toBe('')
    expect(flow.source.sourceFile.value).toBeNull()
    expect(flow.source.sourceText.value).toBe('')
    expect(flow.source.modelId.value).toBeNull()
    expect(flow.source.sourceTab.value).toBe('paste')
    expect(flow.source.metrics.value.wordCount).toBe(0)
    expect(flow.source.catalog.settings.value).toEqual({ default_writing_profile_id: 7, default_text_model_id: 8 })
    expect(window.sessionStorage.getItem('ai_prompt_analysis:41')).toBeNull()
    expect(window.sessionStorage.getItem(`ai_prompt_profile:41:${analysisId}`)).toBe('7')

    const callsBeforeRestore = mocks.service.analysis.mock.calls.length
    const reloaded = createFlow()

    reloaded.run.restore()
    await flushPromises()
    expect(reloaded.run.status.value).toBe('idle')
    expect(mocks.service.analysis).toHaveBeenCalledTimes(callsBeforeRestore)
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
    expect(mocks.service.save).toHaveBeenCalledTimes(1)
  })

  it('refuses to clear active analyses or a profile POST with an uncertain result', async () => {
    mocks.service.analysis.mockResolvedValueOnce(analysisDto('queued'))

    const flow = createFlow()

    await flow.run.analyze()
    await flushPromises()
    expect(flow.run.reset()).toBe(false)
    expect(flow.run.analysis.value.id).toBe(analysisId)
    expect(window.sessionStorage.getItem('ai_prompt_analysis:41')).not.toBeNull()
    mocks.service.analysis.mockResolvedValueOnce(analysisDto('ready'))
    flow.run.resumePolling()
    await flushPromises()
    mocks.service.save.mockRejectedValueOnce({ status: 503, data: { message: 'Chưa xác định kết quả lưu' } })
    expect(await flow.profiles.save()).toBe(false)
    expect(flow.profiles.reset()).toBe(false)
    expect(flow.profiles.uncertainSave.value).toBe(true)
    expect(flow.profiles.form.value.name).toBe('Giải thích dễ hiểu')
  })
})

describe('Ai Prompt uncertain requests across retries and reload', () => {
  it('recovers one exact profile match through GET while preserving edits made after the lost save response', async () => {
    const flow = await readyFlow()
    const sentName = 'Tên văn phong lúc gửi'

    flow.profiles.form.value.name = sentName
    mocks.service.save.mockRejectedValueOnce(new Error('POST response lost'))
    await flow.profiles.save()
    expect(flow.profiles.uncertainSave.value).toBe(true)
    expect(flow.profiles.canSave.value).toBe(false)
    flow.profiles.form.value.name = 'Tên sửa trong lúc kiểm tra'
    flow.profiles.form.value.style_instructions = 'Hướng dẫn sửa chưa được lưu, cần giữ nguyên.'
    await flow.profiles.save()

    const found = profileDto({ id: 12, version: 4, name: sentName, analysis_metadata: { analysis_id: analysisId } })

    mocks.service.findProfiles.mockResolvedValueOnce({ data: [
      profileDto({ id: 8, name: sentName, analysis_metadata: { analysis_id: otherAnalysisId } }),
      profileDto({ id: 9, name: `${sentName} khác`, analysis_metadata: { analysis_id: analysisId } }),
      found,
    ], meta: { pagination: { last_page: 1 } } })
    await flow.profiles.recoverSave()
    expect(mocks.service.findProfiles).toHaveBeenCalledWith(sentName)
    expect(flow.profiles.savedProfile.value).toMatchObject({ id: 12, version: 4 })
    expect(flow.profiles.form.value.name).toBe('Tên sửa trong lúc kiểm tra')
    expect(flow.profiles.form.value.style_instructions).toBe('Hướng dẫn sửa chưa được lưu, cần giữ nguyên.')
    expect(flow.profiles.uncertainSave.value).toBe(false)
    expect(flow.profiles.canSave.value).toBe(true)
    expect(window.sessionStorage.getItem(`ai_prompt_profile:41:${analysisId}`)).toBe('12')
    expect(window.sessionStorage.getItem(`ai_prompt_profile:41:${analysisId}:pending`)).toBeNull()
    expect(mocks.service.save).toHaveBeenCalledTimes(1)
    expect(mocks.service.updateDefault).not.toHaveBeenCalled()
  })

  it('keeps the save lock when matches are ambiguous, paginated, or absent', async () => {
    const flow = await readyFlow()
    const matching = profileDto({ analysis_metadata: { analysis_id: analysisId } })

    mocks.service.save.mockRejectedValueOnce(new Error('POST response lost'))
    await flow.profiles.save()
    flow.profiles.form.value.style_instructions = 'Giữ bản sửa khi chưa xác định được mẫu đã lưu.'

    const responses = [
      { data: [matching, { ...matching, id: 8 }], meta: { pagination: { last_page: 1 } } },
      { data: [matching], meta: { pagination: { last_page: 2 } } },
      { data: [], meta: { pagination: { last_page: 1 } } },
    ]

    for (const response of responses) {
      mocks.service.findProfiles.mockResolvedValueOnce(response)
      await flow.profiles.recoverSave()
      await flow.profiles.save()
      expect(flow.profiles.savedProfile.value).toBeNull()
      expect(flow.profiles.uncertainSave.value).toBe(true)
      expect(flow.profiles.canSave.value).toBe(false)
      expect(flow.profiles.form.value.style_instructions).toBe('Giữ bản sửa khi chưa xác định được mẫu đã lưu.')
    }
    expect(mocks.service.findProfiles).toHaveBeenCalledTimes(3)
    expect(mocks.service.save).toHaveBeenCalledTimes(1)
    expect(window.sessionStorage.getItem(`ai_prompt_profile:41:${analysisId}:pending`)).toBe('Giải thích dễ hiểu')
  })

  it('restores an uncertain analysis marker after reload and requires an explicit new-submission decision', async () => {
    const original = createFlow()

    mocks.service.createAnalysis.mockRejectedValueOnce(new Error('Analysis POST response lost'))
    await original.run.analyze()
    expect(window.sessionStorage.getItem('ai_prompt_analysis:41:pending')).toBe('true')
    original.scope.stop()

    const reloaded = createFlow()

    reloaded.run.restore()
    await flushPromises()
    await reloaded.run.analyze()
    await vi.advanceTimersByTimeAsync(60000)
    expect(reloaded.run.uncertainSubmit.value).toBe(true)
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
    expect(mocks.service.analysis).not.toHaveBeenCalled()
    reloaded.run.allowNewSubmission()
    expect(reloaded.run.uncertainSubmit.value).toBe(false)
    expect(window.sessionStorage.getItem('ai_prompt_analysis:41:pending')).toBeNull()
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
  })

  it('locks a pending profile save after reload until GET recovery identifies its saved version', async () => {
    const original = await readyFlow()

    original.profiles.form.value.name = 'Tên lúc lưu trước reload'
    mocks.service.save.mockRejectedValueOnce(new Error('Save response lost'))
    await original.profiles.save()
    original.scope.stop()

    const reloaded = createFlow()

    reloaded.source.sourceHtml.value = ''
    reloaded.run.restore()
    await flushPromises()
    expect(reloaded.run.status.value).toBe('ready')
    expect(reloaded.run.sourceChanged.value).toBe(false)
    expect(reloaded.profiles.uncertainSave.value).toBe(true)
    expect(reloaded.profiles.canSave.value).toBe(false)
    await reloaded.profiles.save()
    expect(mocks.service.save).toHaveBeenCalledTimes(1)

    mocks.service.findProfiles.mockResolvedValueOnce({ data: [profileDto({ id: 15, version: 3, name: 'Tên lúc lưu trước reload', analysis_metadata: { analysis_id: analysisId } })], meta: { pagination: { last_page: 1 } } })
    await reloaded.profiles.recoverSave()
    expect(mocks.service.findProfiles).toHaveBeenCalledWith('Tên lúc lưu trước reload')
    expect(reloaded.profiles.savedProfile.value).toMatchObject({ id: 15, version: 3 })
    expect(reloaded.profiles.canSave.value).toBe(true)
    expect(mocks.service.save).toHaveBeenCalledTimes(1)
    expect(mocks.service.createAnalysis).toHaveBeenCalledTimes(1)
  })
})
