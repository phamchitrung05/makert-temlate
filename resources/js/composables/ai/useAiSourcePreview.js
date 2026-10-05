/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preview extractor backend trước generation, giữ nguồn gốc.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiSourcePreview(), reset(), read(), watcher nguồn/cleanup.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): source reactive/getter -> snapshot/busy/error.
 * SIDE EFFECT: POST đọc nguồn, không gọi model; đổi nguồn hủy response cũ.
 * =====================================================================
 */
import { onScopeDispose, shallowRef, toValue, watch } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { articleRequestBody, articleSourcePayload } from '@/utils/aiArticleOptions'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * Input: source ref/getter. Output: snapshot, read/reset; không sửa nguồn gốc.
 * =====================================================================
 */
export function useAiSourcePreview(source) {
  const snapshot = shallowRef(null)
  const busy = shallowRef(false)
  const error = shallowRef('')
  let controller
  let sequence = 0

  /**
   * =====================================================================
   * Input: nguồn đổi/reset. Output: bỏ preview cũ, abort và vô hiệu phản hồi.
   * =====================================================================
   */
  function reset() {
    sequence += 1
    controller?.abort()
    snapshot.value = null
    busy.value = false
    error.value = ''
  }

  /**
   * =====================================================================
   * Input: thao tác xem nguồn. Output: source snapshot hoặc lỗi encoding/budget.
   * SIDE EFFECT: gửi nguồn gốc đến extractor; không tự retry POST hoặc tạo run.
   * =====================================================================
   */
  async function read() {
    if (busy.value) return
    controller = new AbortController()

    const token = ++sequence

    busy.value = true
    error.value = ''
    snapshot.value = null
    try {
      const body = articleRequestBody(articleSourcePayload(toValue(source)))
      const value = await aiWritingProfilesService.previewSource(body, controller.signal)
      if (token === sequence) snapshot.value = value
    }
    catch (reason) { if (token === sequence) error.value = formatAiError(reason, 'Không đọc được nguồn. Kiểm tra URL/file/encoding và thử lại.') }
    finally { if (token === sequence) busy.value = false }
  }

  // =====================================================================
  // Input: chỉ nguồn/encoding/target đổi (không phải brief). Output: invalidate preview.
  // =====================================================================
  watch(() => {
    const value = toValue(source)

    return [value.type ?? value.inputType, value.url, value.text, value.html, value.prompt, value.file, value.inputValue, value.sourceEncoding, value.targetType]
  }, (values, previous) => {
    if (previous && values.every((value, index) => value === previous[index])) return
    reset()
  }, { flush: 'sync' })
  onScopeDispose(reset)

  return { snapshot, busy, error, read, reset }
}
