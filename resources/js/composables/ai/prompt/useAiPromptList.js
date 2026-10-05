/* eslint-disable camelcase -- Query và pagination giữ contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tải, tìm kiếm và phân trang danh sách mẫu văn phong đã lưu.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - useAiPromptList(): tạo query/state riêng cho trang danh sách.
 * - load(): GET list, đọc meta.pagination và bỏ qua phản hồi request cũ.
 * - watcher search: debounce tên, đưa query mới về trang đầu.
 * - watcher itemsPerPage: reset page khi đổi số dòng mỗi trang.
 * - watcher page/itemsPerPage/query: tải một lượt cho mỗi query đã ổn định.
 * - onScopeDispose(): hủy timer/request khi rời trang.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : page/itemsPerPage/search từ datatable và bộ lọc.
 * - OUTPUT: rows/total/loading/error readonly, query models và load() để thử lại.
 * - SIDE EFFECT: chỉ GET Admin API; không gọi model hoặc ghi database.
 * =====================================================================
 */
import { onScopeDispose, readonly, shallowRef, watch } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * CHỨC NĂNG: Khởi tạo danh sách và tự tải theo query của trang hiện tại.
 * Input: không có; được gọi trong setup/effectScope.
 * Output: state chỉ đọc, query models và phương thức load bất đồng bộ.
 * SIDE EFFECT: đăng ký watchers, cleanup và GET list đầu tiên.
 * =====================================================================
 */
export function useAiPromptList() {
  const page = shallowRef(1)
  const itemsPerPage = shallowRef(15)
  const search = shallowRef('')
  const query = shallowRef('')
  const items = shallowRef([])
  const totalItems = shallowRef(0)
  const isLoading = shallowRef(false)
  const error = shallowRef('')
  let controller
  let searchTimer
  let sequence = 0
  let disposed = false

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc mẫu đã lưu, giữ tổng server để phân trang đúng.
   * Input: refs query tại thời điểm gọi. Output: boolean có nhận response hay không.
   * SIDE EFFECT: abort lượt cũ; lỗi hiện tại xóa rows/total và cho phép thử lại.
   * EXCEPTION: giữ lỗi công khai trong error, không để promise rejection ra UI.
   * =====================================================================
   */
  async function load() {
    if (disposed) return false
    const request = ++sequence

    controller?.abort()
    controller = new AbortController()
    isLoading.value = true
    error.value = ''
    try {
      const response = await aiWritingProfilesService.list({
        page: page.value,
        per_page: itemsPerPage.value,
        search: query.value || undefined,
      }, controller.signal)

      if (disposed || request !== sequence) return false
      const pagination = response?.meta?.pagination
      const total = Number(pagination?.total)

      if (!response?.success || !Array.isArray(response.data) || !Number.isFinite(total) || total < 0)
        throw new Error('Dữ liệu danh sách văn phong không hợp lệ. Vui lòng tải lại.')
      const lastPage = Math.max(1, Number(pagination.last_page) || 1)

      if (page.value > lastPage) {
        page.value = lastPage

        return false
      }
      items.value = response.data
      totalItems.value = total

      return true
    }
    catch (reason) {
      if (disposed || request !== sequence) return false
      items.value = []
      totalItems.value = 0
      error.value = formatAiError(reason, 'Không thể tải danh sách văn phong. Vui lòng thử lại.')

      return false
    }
    finally {
      if (!disposed && request === sequence) isLoading.value = false
    }
  }

  // =====================================================================
  // Input: tên tìm kiếm/clear. Output: query tối đa 160 ký tự sau 300 ms, page=1.
  // =====================================================================
  watch(search, value => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      const nextQuery = (value ?? '').trim().slice(0, 160)

      if (nextQuery === query.value) return
      page.value = 1
      query.value = nextQuery
    }, 300)
  })

  // =====================================================================
  // Input: đổi số dòng. Output: reset page đồng bộ trước watcher tải API.
  // =====================================================================
  watch(itemsPerPage, () => { page.value = 1 }, { flush: 'sync' })

  // =====================================================================
  // Input: query thay đổi cùng tick. Output: một GET; không dùng update:options trùng lặp.
  // =====================================================================
  watch([page, itemsPerPage, query], () => { void load() }, { immediate: true })

  // =====================================================================
  // Input: scope trang kết thúc. Output: timer/request bị hủy, response trễ không cập nhật.
  // =====================================================================
  onScopeDispose(() => {
    disposed = true
    sequence++
    clearTimeout(searchTimer)
    controller?.abort()
  })

  return { page, itemsPerPage, search, items: readonly(items), totalItems: readonly(totalItems),
    isLoading: readonly(isLoading), error: readonly(error), load }
}
