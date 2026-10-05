/* eslint-disable camelcase -- MediaAsset contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kết nối TinyMCE với picker/upload MediaLibrary tại con trỏ.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: usePostInlineMedia(), setEditor(), openLibrary(), selectAsset(),
 * editImage(), commit(), annotate(), upload(), closeDetails(), watcher busy/HTML,
 * onScopeDispose().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): content/disabled/fallback caret/emitter -> ảnh
 * có ref và alt/caption, media busy/error; mutation file qua MediaAsset API hiện có.
 * =====================================================================
 */
import { computed, onScopeDispose, ref, shallowRef, toValue, watch } from 'vue'
import { mediaAssetService } from '@/services/mediaAsset'
import { hasTemporaryImages, inlineAssetUrl, inlineImageHtml } from '@/utils/inlineMedia'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * Input: HTML model, disabled getter, caret getter và busy callback.
 * Output: picker/details, TinyMCE hooks; không attach usage trước lưu Post/candidate.
 * =====================================================================
 */
export function usePostInlineMedia({ content, disabled, fallbackCaret = () => null, onBusy = () => {} }) {
  const libraryOpen = ref(false)
  const detailsOpen = ref(false)
  const draft = ref({ alt: '', caption: '' })
  const selectedAsset = shallowRef(null)
  const error = shallowRef('')
  const uploads = ref(0)
  const temporary = computed(() => hasTemporaryImages(content.value))
  const busy = computed(() => uploads.value > 0 || libraryOpen.value || detailsOpen.value || temporary.value)
  const refsByUrl = new Map()
  let editor
  let bookmark
  let caret
  let existingImage
  let disposed = false

  /**
   * =====================================================================
   * Input: TinyMCE instance đã khởi tạo. Output: giữ instance tại scope hiện tại.
   * =====================================================================
   */
  const setEditor = value => { editor = value }

  /**
   * =====================================================================
   * Input: click nút ảnh trong toolbar. Output: bookmark/caret rồi mở MediaLibrary.
   * =====================================================================
   */
  function openLibrary() {
    if (toValue(disabled) || disposed || uploads.value) return
    bookmark = editor?.selection?.getBookmark(2, true)
    caret = fallbackCaret()
    existingImage = null
    error.value = ''
    libraryOpen.value = true
  }

  /**
   * =====================================================================
   * Input: asset người dùng chọn. Output: form alt/caption; không chèn URL chưa ready.
   * =====================================================================
   */
  function selectAsset(asset) {
    if (toValue(disabled) || disposed) return
    try {
      inlineAssetUrl(asset)
      selectedAsset.value = asset
      draft.value = { alt: asset.alt_text || '', caption: '' }
      detailsOpen.value = true
      libraryOpen.value = false
    }
    catch (reason) { error.value = reason.message }
  }

  /**
   * =====================================================================
   * Input: ảnh đang chọn trong editor. Output: form sửa alt/caption; URL/ID khóa.
   * =====================================================================
   */
  function editImage() {
    if (!editor || toValue(disabled) || disposed) return
    const node = editor.selection.getNode()
    const image = node.nodeName === 'IMG' ? node : node.closest?.('figure')?.querySelector('img')
    if (!image) { error.value = 'Chọn một ảnh trong bài để sửa mô tả/chú thích.'

      return }
    existingImage = image
    selectedAsset.value = { id: Number(image.getAttribute('data-media-asset-id')), kind: 'image', visibility: 'public', file: { url: image.getAttribute('src') } }
    draft.value = { alt: image.getAttribute('alt') || '', caption: image.closest('figure')?.querySelector('figcaption')?.textContent || '' }
    detailsOpen.value = true
  }

  /**
   * =====================================================================
   * Input: alt/caption đã duyệt. Output: chèn đúng bookmark hoặc sửa node trong một
   * undo transaction; fallback chèn HTML tại caret textarea, giữ nội dung khác.
   * =====================================================================
   */
  function commit() {
    if (toValue(disabled) || disposed) return
    try {
      const html = inlineImageHtml(selectedAsset.value, draft.value.alt, draft.value.caption)
      if (editor) {
        editor.undoManager.transact(() => {
          if (existingImage) {
            if (!editor.getBody().contains(existingImage)) throw new Error('Ảnh đã được thay đổi. Hãy chọn lại ảnh trong bài.')
            const figure = existingImage.closest('figure')

            editor.dom.setOuterHTML(figure?.querySelectorAll('img').length === 1 ? figure : existingImage, html)
          }
          else { if (bookmark) editor.selection.moveToBookmark(bookmark); editor.insertContent(html) }
        })
        editor.nodeChanged()
        content.value = editor.getContent()
      }
      else {
        const start = caret?.start ?? content.value.length
        const end = caret?.end ?? start

        content.value = content.value.slice(0, start) + html + content.value.slice(end)
      }
      detailsOpen.value = false
      selectedAsset.value = null
      existingImage = null
      error.value = ''
    }
    catch (reason) { error.value = reason.message }
  }

  /**
   * =====================================================================
   * Input: editor content event. Output: gắn ID vào URL upload vừa xác thực;
   * không gắn ID cho URL tự nhập hoặc sửa một ref đã có.
   * =====================================================================
   */
  function annotate() {
    if (!editor || disposed || !refsByUrl.size) return
    let changed = false
    editor.getBody()?.querySelectorAll('img').forEach(image => {
      const id = refsByUrl.get(image.getAttribute('src'))
      if (id && !image.hasAttribute('data-media-asset-id')) { image.setAttribute('data-media-asset-id', String(id)); changed = true }
    })
    if (changed) content.value = editor.getContent()
  }

  /**
   * =====================================================================
   * Input: TinyMCE blob/progress. Output: public URL thật; ref được gắn sau khi
   * TinyMCE đổi blob thành URL. Lỗi giữ draft, chặn lưu ảnh tạm; không retry ngầm.
   * =====================================================================
   */
  async function upload(blobInfo, progress) {
    if (toValue(disabled) || disposed) throw new Error('Editor đang khóa.')
    uploads.value += 1
    error.value = ''
    try {
      const file = new File([blobInfo.blob()], blobInfo.filename(), { type: blobInfo.blob().type })
      const asset = await mediaAssetService.upload({ file, kind: 'image', visibility: 'public', field: 'post.content_images' }, progress)
      if (disposed) throw new Error('Editor đã đóng; file upload được giữ trong MediaLibrary.')
      const url = inlineAssetUrl(asset)

      refsByUrl.set(url, asset.id)

      return url
    }
    catch (reason) { if (!disposed) error.value = formatAiError(reason, 'Upload ảnh thất bại. Draft được giữ; xóa ảnh tạm hoặc chọn lại ảnh từ MediaLibrary.'); throw new Error(error.value || reason.message) }
    finally { if (!disposed) uploads.value -= 1 }
  }

  /**
   * =====================================================================
   * Input: đóng form alt/caption. Output: bỏ insert đang chờ, không xóa file library.
   * =====================================================================
   */
  const closeDetails = () => { detailsOpen.value = false; selectedAsset.value = null; existingImage = null }


  // =====================================================================
  // Input: upload/picker/HTML tạm đổi. Output: khóa save ở caller đến khi ổn định.
  // =====================================================================
  watch(busy, value => onBusy(value), { immediate: true, flush: 'sync' })
  watch(content, () => annotate(), { flush: 'post' })
  onScopeDispose(() => { disposed = true; editor = null; refsByUrl.clear(); onBusy(false) })

  return { libraryOpen, detailsOpen, draft, selectedAsset, error, busy, setEditor, openLibrary, selectAsset, editImage, commit, annotate, upload, closeDetails }
}
