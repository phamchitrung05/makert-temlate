/* eslint-disable camelcase -- Payload nguồn dùng field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý nguồn và catalog cho form phân tích văn phong.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiPromptSource(), selection(), loadCatalog(),
 * preview(), clearSource(), resetSource(), resetForNewProfile(), restoreFromAnalysis(); watcher loại nguồn khi đổi input.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : quyền preview hiện tại; URL/paste/file/model do người dùng chọn.
 * - OUTPUT: nguồn text đã xác nhận, thống kê, catalog và lỗi field reactive.
 * - SIDE EFFECT: GET catalog, POST preview hoặc đọc file local; không gửi model.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef, toValue, watch } from 'vue'
import { useAiProviderSettings } from '@/composables/useAiProviderSettings'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { referenceText } from '@/utils/aiWritingProfile'
import { formatAiError } from '@/utils/aiErrors'
import { getSourceMetrics, promptPreviewSource } from '@/views/ai/prompt/promptPreview'

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo state nguồn độc lập với analysis đã gửi.
 * Input: Boolean/ref quyền preview. Output: refs, computed và thao tác nguồn.
 * SIDE EFFECT: watcher và cleanup abort request khi scope kết thúc.
 * =====================================================================
 */
export function useAiPromptSource(canPreview) {
  const sourceHtml = shallowRef('')
  const profileName = shallowRef('')
  const sourceTab = shallowRef('paste')
  const sourceUrl = shallowRef('')
  const sourceFile = shallowRef(null)
  const sourceText = shallowRef('')
  const modelId = shallowRef(null)
  const previewing = shallowRef(false)
  const errors = shallowRef({})
  const notice = shallowRef('')
  const preparedSelection = shallowRef(null)
  const importedMetrics = shallowRef(null)
  const catalog = useAiProviderSettings()
  let controller
  let sequence = 0

  const file = computed(() => Array.isArray(sourceFile.value) ? sourceFile.value[0] : sourceFile.value)

  const modelOptions = computed(() => catalog.modelOptions.value.filter(model => model.capabilities?.includes('text_generation'))
    .map(model => ({ title: `${model.provider_label} — ${model.label}`, value: model.id })))

  /**
   * =====================================================================
   * CHỨC NĂNG: Nhận diện lựa chọn nguồn hiện tại, không dựa vào tên file.
   * Input: refs URL/file/tab. Output: URL hoặc identity File để chặn response cũ.
   * =====================================================================
   */
  const selection = () => sourceTab.value === 'url' ? sourceUrl.value.trim() : file.value
  const sourcePrepared = computed(() => sourceTab.value === 'paste' || preparedSelection.value === selection())
  const text = computed(() => sourceTab.value === 'paste' ? referenceText(sourceHtml.value) : sourcePrepared.value ? sourceText.value.trim() : '')

  const metrics = computed(() => {
    if (sourceTab.value === 'paste') return getSourceMetrics(sourceHtml.value)
    const count = text.value ? text.value.split(/\s+/u).length : 0

    return { wordCount: count, paragraphCount: text.value ? text.value.split(/\n\s*\n/u).filter(value => value.trim()).length : 0,
      imageCount: importedMetrics.value?.imageCount ?? 0, linkCount: importedMetrics.value?.linkCount ?? 0, readingMinutes: count ? Math.ceil(count / 200) : 0 }
  })

  // =====================================================================
  // Input: đổi tab/URL/File. Output: bỏ nguồn cũ và response preview đến trễ.
  // =====================================================================
  watch([sourceTab, sourceUrl, sourceFile], () => {
    sequence++
    controller?.abort()
    previewing.value = false
    preparedSelection.value = null
    sourceText.value = ''
    importedMetrics.value = null
    errors.value = {}
    notice.value = ''
  }, { flush: 'sync' })

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải catalog thật và báo model explicit không còn dùng được.
   * Input: thao tác mở/reload. Output: options text và lỗi catalog nếu thất bại.
   * SIDE EFFECT: GET Settings; không tự chọn model khác thay lựa chọn explicit.
   * =====================================================================
   */
  async function loadCatalog() {
    try {
      await catalog.load()
      if (modelId.value !== null && !modelOptions.value.some(model => model.value === modelId.value)) errors.value = { model_id: ['Model đã chọn không còn khả dụng. Hãy chọn lại hoặc dùng mặc định.'] }
    }
    catch { /* catalog.error đã giữ message để UI cho tải lại. */ }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc một nguồn để người dùng kiểm tra/sửa trước khi gửi AI.
   * Input: tab URL/file, quyền và lựa chọn hiện tại. Output: canonical text/lỗi.
   * SIDE EFFECT: backend fetch URL/HTML hoặc đọc TXT local; không tạo analysis.
   * =====================================================================
   */
  async function preview() {
    if (previewing.value) return
    errors.value = {}
    notice.value = ''

    const currentFile = file.value
    const localText = sourceTab.value === 'file' && /\.txt$/iu.test(currentFile?.name ?? '')

    if (!localText && !toValue(canPreview)) {
      notice.value = 'Tài khoản cần quyền quản lý bài viết để đọc URL/file HTML bằng chức năng hiện tại. Bạn có thể dán nội dung hoặc đọc file TXT.'

      return
    }
    if (sourceTab.value === 'url') {
      try {
        if (!['http:', 'https:'].includes(new URL(sourceUrl.value.trim()).protocol)) throw new Error()
      }
      catch {
        errors.value = { url: ['Nhập URL HTTP hoặc HTTPS hợp lệ.'] }

        return
      }
    }
    if (sourceTab.value === 'file' && (!currentFile || !/\.(?:txt|html|htm)$/iu.test(currentFile.name) || currentFile.size > 5 * 1024 * 1024)) {
      errors.value = { html_file: ['Chọn file TXT/HTML/HTM tối đa 5 MB.'] }

      return
    }
    const currentSequence = ++sequence
    const selected = selection()

    controller = new AbortController()
    previewing.value = true
    preparedSelection.value = null
    try {
      let html = ''
      let extracted = ''
      if (localText) {
        extracted = new TextDecoder('utf-8', { fatal: true }).decode(await currentFile.arrayBuffer()).trim()
      }
      else {
        let payload = { target_type: 'post', url: sourceUrl.value.trim() }
        if (sourceTab.value === 'file') {
          payload = new FormData()
          payload.append('target_type', 'post')
          payload.append('html_file', currentFile)
        }
        const snapshot = await aiWritingProfilesService.previewSource(payload, controller.signal)

        html = snapshot.content_html ?? ''
        extracted = referenceText(html)
      }
      if (currentSequence !== sequence) return
      if (!extracted || Array.from(extracted).length > 100000) throw new Error('Nội dung trống hoặc vượt 100000 ký tự; hãy chọn phần bài phù hợp.')
      sourceText.value = extracted
      importedMetrics.value = html ? getSourceMetrics(html) : null
      preparedSelection.value = selected
      notice.value = 'Đã đọc nội dung. Kiểm tra và sửa văn bản bên dưới trước khi phân tích.'
    }
    catch (reason) {
      if (currentSequence !== sequence) return
      errors.value = reason?.data?.errors ?? {}
      notice.value = formatAiError(reason, 'Không đọc được nguồn. Hãy kiểm tra URL/file và encoding UTF-8.')
    }
    finally {
      if (currentSequence === sequence) previewing.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Xóa nguồn đang nhập, giữ tên văn phong.
   * Input: thao tác clear. Output: nguồn/preview rỗng; không xóa analysis/profile.
   * =====================================================================
   */
  function clearSource() {
    sourceHtml.value = ''
    sourceText.value = ''
    sourceUrl.value = ''
    sourceFile.value = null
    preparedSelection.value = null
    importedMetrics.value = null
    errors.value = {}
    notice.value = ''
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Nạp bài minh họa theo thao tác rõ ràng của người dùng.
   * Input: reset. Output: paste nguồn mẫu; không tự phân tích hoặc đổi tên.
   * =====================================================================
   */
  function resetSource() {
    clearSource()
    sourceTab.value = 'paste'
    sourceHtml.value = promptPreviewSource
    notice.value = 'Đang dùng bài tham khảo minh họa; bạn có thể thay bằng bài của mình.'
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đưa nguồn về form Add trống cho một văn phong mới.
   * Input: thao tác tạo mới sau lưu hoặc xác nhận của người dùng.
   * Output: tên/nguồn/model rỗng, tab paste; catalog và Settings đã tải được giữ.
   * SIDE EFFECT: abort preview, bỏ response cũ; không gọi API hoặc xóa database.
   * =====================================================================
   */
  function resetForNewProfile() {
    sequence++
    controller?.abort()
    previewing.value = false
    clearSource()
    profileName.value = ''
    sourceTab.value = 'paste'
    modelId.value = null
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Khôi phục nguồn đã gửi khi mở lại analysis từ hàng đợi.
   * =====================================================================
   * Input: analysis detail có source metadata/reference_text. Output: các ô
   * tên, model, URL và nội dung hiển thị lại; không gọi preview hoặc AI.
   * Side effect: hủy preview cũ và đánh dấu nguồn đã chuẩn bị.
   * =====================================================================
   */
  function restoreFromAnalysis(analysis) {
    if (!analysis?.reference_text) return
    sequence++
    controller?.abort()
    previewing.value = false
    errors.value = {}
    notice.value = 'Đã khôi phục nguồn từ tác vụ đã hoàn tất.'
    profileName.value = analysis.name ?? profileName.value
    modelId.value = Number(analysis.model_id) || null
    sourceTab.value = analysis.source_type === 'url' ? 'url' : 'paste'
    sourceUrl.value = analysis.source_url ?? ''
    sourceFile.value = null
    sourceText.value = analysis.reference_text
    importedMetrics.value = null

    if (analysis.source_type === 'url') {
      preparedSelection.value = sourceUrl.value
    }
    else {
      // Nguồn đã lưu là text: escape trước khi đưa lại vào editor HTML và giữ nguyên newline.
      sourceHtml.value = analysis.reference_text.replace(/&/gu, '&amp;').replace(/</gu, '&lt;').replace(/>/gu, '&gt;')
      preparedSelection.value = null
    }
  }

  onScopeDispose(() => { sequence++; controller?.abort() })

  return { sourceHtml, profileName, sourceTab, sourceUrl, sourceFile, sourceText, modelId, previewing, errors, notice,
    sourcePrepared, text, metrics, modelOptions, catalog, loadCatalog, preview, clearSource, resetSource, resetForNewProfile, restoreFromAnalysis }
}
