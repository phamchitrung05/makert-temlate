/* eslint-disable camelcase -- Fixtures và payload giữ tên field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm contract HTTP và chuẩn hóa bài tham khảo của Ai Prompt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: beforeEach(), các ca kiểm list/pagination, mutation, preview,
 * lỗi HTTP và referenceText().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP mock, JSON/FormData và HTML có block/code/script.
 * - OUTPUT: assertions endpoint hiện có, retry=0, envelope và text nguyên nghĩa.
 * - SIDE EFFECT: API giả lập; không gọi model, database hoặc nguồn bên ngoài.
 * =====================================================================
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { referenceText } from '@/utils/aiWritingProfile'

const mocks = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: mocks.api }))

describe('Ai Prompt API contract', () => {
  beforeEach(() => { mocks.api.mockReset() })

  it('keeps the list pagination envelope and forwards GET query and cancellation', async () => {
    const signal = new AbortController().signal
    const envelope = { success: true, data: [{ id: 7, name: 'Rõ ràng' }], meta: { pagination: { total: 81, current_page: 2, per_page: 25, last_page: 4 } } }

    mocks.api.mockResolvedValue(envelope)
    expect(await aiWritingProfilesService.list({ page: 2, per_page: 25, search: 'Rõ' }, signal)).toBe(envelope)
    expect(mocks.api).toHaveBeenLastCalledWith('/admin/ai/writing-profiles', { query: { page: 2, per_page: 25, search: 'Rõ' }, signal, retry: 0 })
    expect(await aiWritingProfilesService.findProfiles('Rõ ràng')).toBe(envelope)
    expect(mocks.api).toHaveBeenLastCalledWith('/admin/ai/writing-profiles', { query: { search: 'Rõ ràng', per_page: 100 }, signal: undefined, retry: 0 })
  })

  it('disables HTTP retries for every mutation and returns the server DTO', async () => {
    const dto = { id: 'analysis-id', status: 'queued' }

    mocks.api.mockResolvedValue({ success: true, data: dto })
    expect(await aiWritingProfilesService.createAnalysis({ name: 'Văn phong', reference_text: 'Bài tham khảo' })).toEqual(dto)
    await aiWritingProfilesService.cancel('analysis-id')
    await aiWritingProfilesService.save({ name: 'Đã duyệt' })
    await aiWritingProfilesService.save({ name: 'Bản sửa', version: 2 }, 7)
    await aiWritingProfilesService.updateDefault({ default_writing_profile_id: 7 })

    expect(mocks.api.mock.calls.map(([path, options]) => [path, options.method, options.retry])).toEqual([
      ['/admin/ai/writing-profiles/analyses', 'POST', 0],
      ['/admin/ai/writing-profiles/analyses/analysis-id/cancel', 'POST', 0],
      ['/admin/ai/writing-profiles', 'POST', 0],
      ['/admin/ai/writing-profiles/7', 'PUT', 0],
      ['/admin/settings/ai/settings', 'PUT', 0],
    ])
    expect(mocks.api.mock.calls[4][1].body).toEqual({ default_writing_profile_id: 7 })
  })

  it('lists writing profile analysis summaries with status filters and cancellation', async () => {
    const signal = new AbortController().signal
    const envelope = { success: true, data: [{ id: 'analysis-id', task_type: 'writing_profile_analysis', status: 'queued' }], meta: { pagination: { total: 1 } } }

    mocks.api.mockResolvedValue(envelope)
    expect(await aiWritingProfilesService.listAnalyses({ status: 'queued', per_page: 100 }, signal)).toBe(envelope)
    expect(mocks.api).toHaveBeenLastCalledWith('/admin/ai/writing-profiles/analyses', { query: { status: 'queued', per_page: 100 }, signal, retry: 0 })
  })

  it('previews JSON and HTML FormData through the existing source-preview endpoint', async () => {
    const controller = new AbortController()
    const file = new File(['<article>Nội dung</article>'], 'reference.html', { type: 'text/html' })
    const body = new FormData()
    const snapshot = { content_html: '<p>Nội dung đã làm sạch</p>', blocks: [] }

    body.append('target_type', 'post')
    body.append('html_file', file)
    mocks.api.mockResolvedValue({ success: true, data: snapshot })
    expect(await aiWritingProfilesService.previewSource({ target_type: 'post', url: 'https://example.com/article' }, controller.signal)).toEqual(snapshot)
    await aiWritingProfilesService.previewSource(body, controller.signal)

    expect(mocks.api).toHaveBeenCalledTimes(2)
    for (const [path, options] of mocks.api.mock.calls) {
      expect(path).toBe('/admin/ai-agent/source-preview')
      expect(options).toMatchObject({ method: 'POST', retry: 0, signal: controller.signal })
      expect(options.headers).toBeUndefined()
    }
    expect(mocks.api.mock.calls[1][1].body).toBe(body)
    expect(body.get('html_file').name).toBe('reference.html')
  })

  it('propagates a failed mutation without replaying or dropping field errors', async () => {
    const reason = Object.assign(new Error('Nguồn không hợp lệ'), { status: 422, data: { message: 'Nguồn không hợp lệ', errors: { reference_text: ['Nguồn quá ngắn'] } } })

    mocks.api.mockRejectedValue(reason)
    await expect(aiWritingProfilesService.createAnalysis({ name: 'Văn phong', reference_text: 'Ngắn' })).rejects.toBe(reason)
    expect(mocks.api).toHaveBeenCalledTimes(1)
    expect(mocks.api.mock.calls[0][1].retry).toBe(0)
  })

  it('keeps paragraph/list/code boundaries and excludes embedded or layout text', () => {
    // Script JSON giữ nội dung cần loại bỏ và không kích hoạt script runner của happy-dom.
    const text = referenceText('<nav>MENU</nav><h2>Tiêu đề</h2><p>Đoạn một &amp; dấu</p><p>Đoạn hai<br>Xuống dòng</p><ul><li>Mục A</li><li>Mục B</li></ul><pre><code>if (true) {\n  execute();\n}</code></pre><script type="application/json">"EVIL_SCRIPT"</script><style>EVIL_CSS</style><iframe>EVIL_FRAME</iframe><footer>FOOTER</footer>')

    expect(text).toMatch(/Tiêu đề\n+Đoạn một & dấu\n+Đoạn hai\n+Xuống dòng/u)
    expect(text).toMatch(/Mục A\n+Mục B/u)
    expect(text).toContain('if (true) {\n  execute();\n}')
    expect(text).not.toMatch(/MENU|EVIL|FOOTER/u)
    expect(text).not.toContain('dấuĐoạn')
  })
})
