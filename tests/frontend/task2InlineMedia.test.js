/* eslint-disable camelcase -- MediaAsset API contract. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm picker/upload ảnh inline qua API và contract TinyMCE.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: fakeEditor(), state(), beforeEach/afterEach(), các test chèn tại
 * bookmark, alt/caption/ref, URL không ready, upload/race/failure/fallback.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thao tác picker/Blob + API mock -> HTML bền vững
 * và busy lock; không upload file thật hoặc attach usage trước lưu Post.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, shallowRef } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { usePostInlineMedia } from '@/composables/usePostInlineMedia'
import { inlineImageHtml } from '@/utils/inlineMedia'

const { upload } = vi.hoisted(() => ({ upload: vi.fn() }))

vi.mock('@/services/mediaAsset', () => ({ mediaAssetService: { upload } }))

const asset = { id: 42, kind: 'image', visibility: 'public', file: { url: 'https://site.example/storage/media/42/photo.webp' }, alt_text: 'Ảnh gốc' }
let scope

/**
 * =====================================================================
 * Input: HTML. Output: TinyMCE API double thực thi DOM insert/replace/undo boundary.
 * =====================================================================
 */
function fakeEditor(html) {
  const body = document.createElement('div')

  body.innerHTML = html

  const bookmark = { start: [0], offset: 1 }

  return {
    body,
    selection: { getBookmark: vi.fn(() => bookmark), moveToBookmark: vi.fn(), getNode: vi.fn(() => body.querySelector('img')) },
    undoManager: { transact: vi.fn(callback => callback()) },
    getBody: () => body, getContent: () => body.innerHTML,
    insertContent: vi.fn(value => { body.insertAdjacentHTML('beforeend', value) }),
    dom: { setOuterHTML: (node, value) => { node.outerHTML = value } }, nodeChanged: vi.fn(),
  }
}

/**
 * =====================================================================
 * Input: HTML và fallback caret. Output: scoped state, HTML model và busy spy.
 * =====================================================================
 */
function state(html = '<p>Bài viết</p>', fallbackCaret, mediaReferences = true) {
  const content = shallowRef(html)
  const disabled = shallowRef(false)
  const onBusy = vi.fn()
  const media = scope.run(() => usePostInlineMedia({ content, disabled, mediaReferences, onBusy, fallbackCaret }))

  return { ...media, content, disabled, onBusy }
}
beforeEach(() => { scope = effectScope(); upload.mockReset() })
afterEach(() => scope.stop())

describe('Task 2 TinyMCE MediaLibrary', () => {
  it('inserts the same Post image link twice without asset references and escapes metadata', () => {
    const s = state('<p>Bài viết</p>', undefined, false)

    for (const alt of ['Vị trí một', 'Vị trí hai <script>']) {
      s.openUrl()
      s.draft.value = { url: 'https://images.example.test/photo.jpg', alt, caption: 'Chú thích' }
      s.commit()
    }
    const dom = new DOMParser().parseFromString(s.content.value, 'text/html')

    expect(dom.querySelectorAll('img')).toHaveLength(2)
    expect([...dom.querySelectorAll('img')].map(image => image.getAttribute('src'))).toEqual([
      'https://images.example.test/photo.jpg', 'https://images.example.test/photo.jpg',
    ])
    expect(dom.querySelector('[data-media-asset-id]')).toBeNull()
    expect(dom.querySelector('script')).toBeNull()
    expect(upload).not.toHaveBeenCalled()
    expect(s.busy.value).toBe(false)
  })

  it('uses only the URL when an asset is selected for Post content and permits repeated selection', () => {
    const s = state('<p>Bài viết</p>', undefined, false)

    for (let occurrence = 0; occurrence < 2; occurrence++) {
      s.openLibrary()
      s.selectAsset(asset)
      s.commit()
    }
    const dom = new DOMParser().parseFromString(s.content.value, 'text/html')

    expect(dom.querySelectorAll('img')).toHaveLength(2)
    expect(dom.querySelector('[data-media-asset-id]')).toBeNull()
    expect(dom.querySelector('img').getAttribute('src')).toBe(asset.file.url)
  })

  it('edits a linked Post image without an asset ID and retains the draft after an invalid link', () => {
    const s = state('<p>Đầu</p><img src="https://example.test/old.jpg" alt="Cũ"><p>Cuối</p>', undefined, false)
    const editor = fakeEditor(s.content.value)

    s.setEditor(editor)
    s.editImage()
    s.draft.value = { url: 'javascript:alert(1)', alt: 'Mới', caption: '' }
    s.commit()
    expect(s.error.value).toContain('HTTP/HTTPS')
    expect(s.content.value).toContain('https://example.test/old.jpg')
    s.draft.value.url = '/images/new.jpg'
    s.commit()
    expect(s.content.value).toContain('src="/images/new.jpg"')
    expect(s.content.value).toContain('Đầu')
    expect(s.content.value).toContain('Cuối')
    expect(s.content.value).not.toContain('data-media-asset-id')
  })

  it('restores the cursor, inserts stable ID/URL and escapes alt/caption in one undo transaction', () => {
    const s = state()
    const editor = fakeEditor(s.content.value)

    s.setEditor(editor)
    s.openLibrary()
    expect(s.busy.value).toBe(true)
    s.selectAsset(asset)
    s.draft.value = { alt: 'Ảnh "đẹp" <script>', caption: '<script>alert(1)</script>' }
    s.commit()
    expect(editor.selection.getBookmark).toHaveBeenCalledWith(2, true)
    expect(editor.selection.moveToBookmark).toHaveBeenCalledWith({ start: [0], offset: 1 })
    expect(editor.undoManager.transact).toHaveBeenCalledOnce()

    const dom = new DOMParser().parseFromString(s.content.value, 'text/html')

    expect(dom.querySelector('img').getAttribute('data-media-asset-id')).toBe('42')
    expect(dom.querySelector('img').getAttribute('src')).toBe(asset.file.url)
    expect(dom.querySelector('img').getAttribute('alt')).toContain('<script>')
    expect(dom.querySelector('script')).toBeNull()
    expect(dom.querySelector('figcaption').textContent).toBe('<script>alert(1)</script>')
    expect(s.busy.value).toBe(false)
  })
  it('edits alt/caption without changing the selected asset ID/URL or other paragraphs', () => {
    const s = state(`<p>Đầu bài</p>${inlineImageHtml(asset, 'Cũ', 'Chú thích cũ')}<p>Cuối bài</p>`)
    const editor = fakeEditor(s.content.value)

    s.setEditor(editor)
    s.editImage()
    s.draft.value = { alt: 'Mới', caption: 'Chú thích mới' }
    s.commit()
    expect(editor.getBody().querySelectorAll('img')).toHaveLength(1)
    expect(s.content.value).toContain('data-media-asset-id="42"')
    expect(s.content.value).toContain('alt="Mới"')
    expect(s.content.value).toContain('Chú thích mới')
    expect(s.content.value).toContain('Đầu bài')
    expect(s.content.value).toContain('Cuối bài')
  })
  it('uses MediaAsset upload, holds save while pending, and attaches the returned ID after TinyMCE resolves its blob', async () => {
    const s = state('<p>Giữ bài</p><img src="blob:temporary">')
    const editor = fakeEditor(s.content.value)

    s.setEditor(editor)
    let resolveUpload
    upload.mockReturnValueOnce(new Promise(resolve => { resolveUpload = resolve }))

    const progress = vi.fn()
    const pending = s.upload({ blob: () => new Blob(['bytes'], { type: 'image/png' }), filename: () => 'photo.png' }, progress)

    expect(s.busy.value).toBe(true)
    expect(upload).toHaveBeenCalledWith(expect.objectContaining({ file: expect.any(File), kind: 'image', visibility: 'public' }), progress)
    expect(upload.mock.calls[0][0]).not.toHaveProperty('field')
    resolveUpload(asset)

    const url = await pending

    expect(url).toBe(asset.file.url)
    expect(s.busy.value).toBe(true)
    editor.getBody().querySelector('img').setAttribute('src', url)
    s.annotate()
    await flushPromises()
    expect(s.content.value).toContain('data-media-asset-id="42"')
    expect(s.content.value).not.toContain('blob:')
    expect(s.busy.value).toBe(false)
    expect(s.onBusy).toHaveBeenLastCalledWith(false)
  })
  it('preserves the draft on upload failure, blocks temporary URLs and releases save only after removing the failed image', async () => {
    const s = state('<p>Giữ nguyên</p><img src="data:image/png;base64,AA==">')

    upload.mockRejectedValueOnce({ status: 422, data: { message: 'File quá lớn.' } })
    await expect(s.upload({ blob: () => new Blob(['bytes']), filename: () => 'photo.png' })).rejects.toThrow('File quá lớn')
    expect(s.content.value).toContain('Giữ nguyên')
    expect(s.busy.value).toBe(true)
    expect(s.error.value).toContain('File quá lớn')
    s.content.value = '<p>Giữ nguyên</p>'
    await flushPromises()
    expect(s.busy.value).toBe(false)
  })
  it('rejects private, missing, or temporary assets and can insert at the fallback textarea caret', () => {
    const s = state('Đầu|Cuối', () => ({ start: 3, end: 4 }))

    s.openLibrary()
    s.selectAsset({ ...asset, visibility: 'private' })
    expect(s.detailsOpen.value).toBe(false)
    expect(s.error.value).toContain('public')
    expect(() => inlineImageHtml({ ...asset, file: { url: 'blob:unsafe' } })).toThrow('URL file public')
    s.selectAsset(asset)
    s.commit()
    expect(s.content.value).toMatch(/^Đầu<img.*>Cuối$/)
    expect(s.content.value).toContain('data-media-asset-id="42"')
  })
  it('does not insert after disable/unmount and never invents a ref for an external image URL', async () => {
    const s = state('<img src="https://outside.example/picture.png">')
    const editor = fakeEditor(s.content.value)

    s.setEditor(editor)
    s.annotate()
    expect(s.content.value).not.toContain('data-media-asset-id')
    s.openLibrary()
    s.selectAsset(asset)
    s.disabled.value = true
    s.commit()
    expect(editor.insertContent).not.toHaveBeenCalled()
    scope.stop()
    s.commit()
    expect(editor.insertContent).not.toHaveBeenCalled()
    expect(s.onBusy).toHaveBeenLastCalledWith(false)
  })
})
