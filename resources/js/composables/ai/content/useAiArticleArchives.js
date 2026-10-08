/* eslint-disable camelcase -- Query và pagination giữ tên field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối filter, pagination và chi tiết kho AI Approved.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiArticleArchives(), load(), open(), close().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query/filter từ panel và thao tác mở một archive.
 * - OUTPUT: rows/loading/error/detail và thao tác thử lại/đóng.
 * - SIDE EFFECT: GET API; abort request cũ và dọn tài nguyên khi rời scope.
 * =====================================================================
 */
import { onScopeDispose, readonly, shallowRef, watch } from 'vue'
import { aiArticleArchivesService } from '@/services/aiArticleArchives'
import { formatAiError } from '@/utils/aiErrors'

/** Input: không có. Output: state archive list/detail cho page AI Approved. */
export function useAiArticleArchives() {
  const page = shallowRef(1)
  const itemsPerPage = shallowRef(15)
  const search = shallowRef('')
  const createdFrom = shallowRef('')
  const createdTo = shallowRef('')
  const query = shallowRef({ search: '', created_from: '', created_to: '' })
  const items = shallowRef([])
  const totalItems = shallowRef(0)
  const isLoading = shallowRef(false)
  const error = shallowRef('')
  const detail = shallowRef(null)
  const detailLoading = shallowRef(false)
  const detailError = shallowRef('')
  const detailVisible = shallowRef(false)
  let listController
  let detailController
  let searchTimer
  let sequence = 0
  let detailSequence = 0
  let disposed = false

  /** Input: query state. Output: list rows or false khi response cũ/invalid. */
  async function load() {
    if (disposed) return false
    const request = ++sequence

    listController?.abort()
    listController = new AbortController()
    isLoading.value = true
    error.value = ''
    try {
      const response = await aiArticleArchivesService.list({
        page: page.value,
        per_page: itemsPerPage.value,
        search: query.value.search || undefined,
        created_from: query.value.created_from || undefined,
        created_to: query.value.created_to || undefined,
      }, listController.signal)

      if (disposed || request !== sequence) return false
      const total = Number(response?.totalItems)
      const lastPage = Math.max(1, Number(response?.pagination?.last_page) || Math.ceil(total / itemsPerPage.value) || 1)
      if (!Number.isFinite(total) || total < 0 || !Array.isArray(response?.items))
        throw new Error('Dữ liệu kho bài AI không hợp lệ. Vui lòng tải lại.')
      if (page.value > lastPage) {
        page.value = lastPage

        return false
      }
      items.value = response.items
      totalItems.value = total

      return true
    }
    catch (reason) {
      if (disposed || request !== sequence || reason?.name === 'AbortError') return false
      items.value = []
      totalItems.value = 0
      error.value = formatAiError(reason, 'Không thể tải kho bài AI đã duyệt. Vui lòng thử lại.')

      return false
    }
    finally {
      if (!disposed && request === sequence) isLoading.value = false
    }
  }

  /** Input: ID archive. Output: mở dialog khi detail tải xong; không mutation. */
  async function open(id) {
    if (!id || disposed) return false
    const request = ++detailSequence

    detailController?.abort()
    detailController = new AbortController()
    detailVisible.value = true
    detailLoading.value = true
    detailError.value = ''
    detail.value = null
    try {
      const response = await aiArticleArchivesService.show(id, detailController.signal)

      if (disposed || request !== detailSequence) return false
      if (!response || typeof response !== 'object' || !response.id)
        throw new Error('Chi tiết bài AI không hợp lệ. Vui lòng tải lại.')
      detail.value = response

      return true
    }
    catch (reason) {
      if (disposed || request !== detailSequence || reason?.name === 'AbortError') return false
      detailError.value = formatAiError(reason, 'Không thể tải chi tiết bài AI. Vui lòng thử lại.')

      return false
    }
    finally {
      if (!disposed && request === detailSequence) detailLoading.value = false
    }
  }

  /** Input: filter search. Output: query ổn định sau debounce, trở về trang đầu. */
  watch(search, value => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      const next = (value ?? '').trim().slice(0, 160)
      if (next === query.value.search) return
      page.value = 1
      query.value = { ...query.value, search: next }
    }, 300)
  })

  /** Input: ngày filter. Output: query ngày Y-m-d; reset về trang đầu. */
  watch([createdFrom, createdTo], ([from, to], previous) => {
    if (from === previous?.[0] && to === previous?.[1]) return
    page.value = 1
    query.value = { ...query.value, created_from: from || '', created_to: to || '' }
  })

  watch(itemsPerPage, () => { page.value = 1 }, { flush: 'sync' })
  watch([page, itemsPerPage, query], () => { void load() }, { immediate: true })

  /** Input: user đóng dialog. Output: abort detail và xóa state sau khi đóng. */
  function close() {
    detailVisible.value = false
    detailSequence++
    detailController?.abort()
  }

  /** Input: dialog đã rời DOM. Output: dọn nội dung detail không còn hiển thị. */
  function finishClose() {
    if (!detailVisible.value) {
      detail.value = null
      detailError.value = ''
      detailLoading.value = false
    }
  }

  onScopeDispose(() => {
    disposed = true
    sequence++
    detailSequence++
    clearTimeout(searchTimer)
    listController?.abort()
    detailController?.abort()
  })

  return {
    page, itemsPerPage, search, createdFrom, createdTo,
    items: readonly(items), totalItems: readonly(totalItems), isLoading: readonly(isLoading), error: readonly(error),
    detail: readonly(detail), detailVisible, detailLoading: readonly(detailLoading), detailError: readonly(detailError),
    load, open, close, finishClose,
  }
}
