/* eslint-disable camelcase -- Contract API Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối xem nguồn/lịch sử và quyết định duyệt bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiContentReview(), openReview(), loadReview(),
 * loadHistory(), requestDecision(), closeDecision(), confirmDecision(), closeReview(),
 * finishClose(), scope dispose; state (computed).
 * INPUT/OUTPUT CỦA CLASS (tổng thể): item/callback -> dialog và trạng thái list.
 * SIDE EFFECT: GET có abort/sequence; POST một lần, lỗi chưa rõ yêu cầu GET kiểm lại.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { formatAiError } from '@/utils/aiErrors'

/** Input: updateSession callback/notice ref tùy chọn. Output: state/API scoped và notice dùng chung. */
export function useAiContentReview(updateSession, sharedNotice) {
  const open = shallowRef(false)
  const detail = shallowRef(null)
  const loading = shallowRef(false)
  const error = shallowRef('')
  const history = shallowRef([])
  const historyPagination = shallowRef({ current_page: 0, last_page: 1, total: 0 })
  const historyLoading = shallowRef(false)
  const historyError = shallowRef('')
  const decision = shallowRef(null)
  const busy = shallowRef(false)
  const decisionError = shallowRef('')
  const needsRefresh = shallowRef(false)
  const notice = sharedNotice ?? shallowRef(null)
  let generation = 0
  let disposed = false
  let readAbort
  let historyAbort
  let historySequence = 0

  const state = computed(() => ({ open: open.value, detail: detail.value, loading: loading.value, error: error.value,
    history: history.value, historyPagination: historyPagination.value, historyLoading: historyLoading.value,
    historyError: historyError.value, decision: decision.value, busy: busy.value, decisionError: decisionError.value,
    decisionBlocked: needsRefresh.value || loading.value || Boolean(error.value) || !detail.value?.can_review }))

  /** Input: page lịch sử. Output: trang mới hoặc lỗi riêng; response cũ không đổi dialog mới. */
  async function loadHistory(page = 1) {
    if (!open.value || !detail.value?.job_id || (page > 1 && historyLoading.value)) return
    const token = generation
    const historyToken = ++historySequence
    const id = detail.value.job_id

    historyAbort?.abort()
    historyAbort = new AbortController()
    historyLoading.value = true
    historyError.value = ''
    try {
      const response = await aiAgentService.reviewHistory(id, { page, per_page: 20 }, { signal: historyAbort.signal })
      if (disposed || token !== generation || historyToken !== historySequence || !open.value) return
      history.value = page === 1 ? response.data : [...history.value, ...response.data]
      historyPagination.value = response.meta.pagination
    }
    catch (failure) {
      if (!disposed && token === generation && historyToken === historySequence && open.value) historyError.value = formatAiError(failure, 'Không tải được lịch sử. Hãy thử lại.')
    }
    finally { if (!disposed && token === generation && historyToken === historySequence) historyLoading.value = false }
  }

  /** Input: tải lại bản đang xem. Output: GET mới, giữ lý do đang nhập; không lặp POST. */
  async function loadReview() {
    if (!open.value || !detail.value?.job_id || busy.value) return
    const token = ++generation
    const id = detail.value.job_id

    readAbort?.abort()
    historyAbort?.abort()
    historyLoading.value = false
    readAbort = new AbortController()
    loading.value = true
    error.value = ''
    try {
      const response = await aiAgentService.review(id, { signal: readAbort.signal })
      if (disposed || token !== generation || !open.value) return
      detail.value = response
      updateSession(response)
      needsRefresh.value = false
      if (!response.can_review) decision.value = null
      decisionError.value = ''
      void loadHistory()
    }
    catch (failure) {
      if (!disposed && token === generation && open.value) error.value = formatAiError(failure, 'Không tải được nội dung để duyệt. Hãy thử lại.')
    }
    finally { if (!disposed && token === generation) loading.value = false }
  }

  /** Input: item Post. Output: dialog khóa khi GET; không tự quyết định hoặc gọi model. */
  function openReview(item) {
    if (busy.value || item.targetType !== 'post') return
    open.value = true
    detail.value = { job_id: item.id, draft: { title: item.title } }
    decision.value = null
    decisionError.value = ''
    needsRefresh.value = false
    history.value = []
    historyPagination.value = { current_page: 0, last_page: 1, total: 0 }

    return loadReview()
  }

  /** Input: approve/reject. Output: mở xác nhận cho bản đã GET, không mutation. */
  function requestDecision(kind) {
    if (!['approve', 'reject'].includes(kind) || loading.value || busy.value || error.value || !detail.value?.can_review
      || (kind === 'approve' && detail.value.can_approve === false)) return
    decision.value = kind
    decisionError.value = ''
  }

  /** Input: Hủy xác nhận. Output: đóng khi chưa POST; giữ thông tin so sánh. */
  function closeDecision() { if (!busy.value) decision.value = null }

  /** Input: fields/taxonomy/reason đã xác nhận. Output: quyết định và list mới, giữ form khi lỗi. */
  async function confirmDecision(options) {
    if (!decision.value || busy.value || state.value.decisionBlocked || error.value || (decision.value === 'approve' && detail.value?.can_approve === false)) return
    const current = detail.value
    const kind = decision.value

    busy.value = true
    decisionError.value = ''
    try {
      const body = { ...options, expected_version: current.draft_version, expected_review_version: current.review_version }

      const result = kind === 'approve' ? await aiAgentService.approveCandidate(current.job_id, body)
        : await aiAgentService.rejectCandidate(current.job_id, body)

      if (disposed) return
      detail.value = { ...current, review: result.review, applied_target_id: result.post_id ?? current.applied_target_id, can_review: false, can_edit: false }
      updateSession(detail.value)
      decision.value = null
      notice.value = { type: 'success', message: kind === 'approve' ? `Đã duyệt và tạo Post nháp #${result.post_id}.` : 'Đã từ chối nội dung AI.' }
      void loadHistory()
    }
    catch (failure) {
      if (disposed) return
      const status = failure.status ?? failure.statusCode ?? failure.response?.status

      needsRefresh.value = status === 409 || !status || status >= 500
      decisionError.value = needsRefresh.value
        ? `${formatAiError(failure, 'Chưa xác định kết quả quyết định.')} Hãy kiểm tra trạng thái trước khi gửi tiếp.`
        : formatAiError(failure, 'Không thực hiện được quyết định. Bản đang nhập được giữ lại.')
    }
    finally { if (!disposed) busy.value = false }
  }

  /** Input: Đóng dialog. Output: abort GET; giữ dữ liệu cho hiệu ứng after-leave. */
  function closeReview() {
    if (busy.value) return
    open.value = false
    decision.value = null
    generation += 1
    readAbort?.abort()
    historyAbort?.abort()
  }

  /** Input: after-leave. Output: dọn dữ liệu nếu chưa mở lại; không gọi API. */
  function finishClose() {
    if (open.value) return
    detail.value = null
    history.value = []
    loading.value = false
    historyLoading.value = false
  }

  onScopeDispose(() => {
    disposed = true
    generation += 1
    readAbort?.abort()
    historyAbort?.abort()
  })

  return { state, notice, openReview, loadReview, loadHistory, requestDecision, closeDecision,
    confirmDecision, closeReview, finishClose }
}
