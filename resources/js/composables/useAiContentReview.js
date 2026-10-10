/* eslint-disable camelcase -- Contract API Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối xem nguồn/lịch sử và quyết định duyệt bài AI.
 * =====================================================================
 * CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
 * - useAiContentReview(): tạo state review/history và mutation guard.
 * - openReview(): mở đúng run Post trong session và GET detail.
 * - loadReview(): đọc lại detail/version mới trước quyết định.
 * - loadHistory(): tải lịch sử review theo pagination và bỏ response cũ.
 * - rescoreQuality(): xếp chấm lại quality rồi đọc trạng thái mới.
 * - requestDecision(): mở dialog approve/reject khi đủ điều kiện.
 * - closeDecision(): đóng xác nhận nếu chưa submit.
 * - confirmDecision(): POST approve/reject với version guard và cập nhật list.
 * - closeReview(): đóng dialog, abort GET và giữ detail cho after-leave.
 * - finishClose(): dọn detail/history sau hiệu ứng đóng.
 * - state: computed projection; scope dispose: abort request và bỏ response cũ.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): item/callback -> dialog và trạng thái list.
 * SIDE EFFECT: GET có abort/sequence; POST một lần, lỗi chưa rõ yêu cầu GET kiểm lại.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo state review và các action đọc/duyệt candidate Post.
 * =====================================================================
 * INPUT: callback updateSession và notice ref dùng chung tùy chọn.
 * OUTPUT: state dialog, lịch sử, quality decision và các hàm API.
 * SIDE EFFECT: GET/POST qua aiAgentService; abort response cũ khi đổi dialog.
 * EXCEPTION/TRANSACTION: lỗi HTTP hiển thị trong state; transaction thuộc backend.
 * =====================================================================
 */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải thêm lịch sử quality/review của candidate đang mở.
   * =====================================================================
   * INPUT: page pagination, mặc định trang đầu.
   * OUTPUT: history và metadata pagination.
   * SIDE EFFECT: GET review history với AbortController.
   * EXCEPTION/TRANSACTION: lỗi ghi historyError; response cũ không đổi dialog mới.
   * =====================================================================
   */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải lại detail candidate để đối chiếu trước quyết định.
   * =====================================================================
   * INPUT: job_id đang mở.
   * OUTPUT: detail/review/quality state mới.
   * SIDE EFFECT: GET review, cập nhật list và tải lịch sử trang đầu.
   * EXCEPTION/TRANSACTION: lỗi giữ dialog và error; không lặp POST mutation.
   * =====================================================================
   */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Xếp chấm lại quality cho candidate lỗi/hết hạn.
   * =====================================================================
   * INPUT: detail candidate đang mở.
   * OUTPUT: notice accepted và detail được tải lại.
   * SIDE EFFECT: POST rescore, sau đó GET review.
   * EXCEPTION/TRANSACTION: lỗi decisionError; không tự approve/reject.
   * =====================================================================
   */
  async function rescoreQuality() {
    if (!open.value || busy.value || loading.value || !detail.value?.job_id) return
    busy.value = true
    decisionError.value = ''
    try {
      await aiAgentService.rescoreQuality(detail.value.job_id)
      busy.value = false
      notice.value = { type: 'success', message: 'Đã xếp hàng chấm lại chất lượng.' }
      await loadReview()
    }
    catch (failure) {
      busy.value = false
      decisionError.value = formatAiError(failure, 'Không thể xếp chấm lại chất lượng.')
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Mở review dialog cho đúng run Post và bắt đầu GET detail.
   * =====================================================================
   * INPUT: item target post đã gom theo session.
   * OUTPUT: dialog state loading/detail.
   * SIDE EFFECT: GET review; không tạo run hoặc gọi model.
   * EXCEPTION/TRANSACTION: lỗi được giữ trong state error.
   * =====================================================================
   */
  function openReview(item) {
    if (busy.value || item.targetType !== 'post') return
    const runId = item.runId ?? item.id
    open.value = true
    detail.value = { job_id: runId, draft: { title: item.title } }
    decision.value = null
    decisionError.value = ''
    needsRefresh.value = false
    history.value = []
    historyPagination.value = { current_page: 0, last_page: 1, total: 0 }

    return loadReview()
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Mở dialog xác nhận approve/reject khi detail đủ điều kiện.
   * =====================================================================
   * INPUT: kind approve hoặc reject.
   * OUTPUT: decision state hoặc không đổi khi đang loading/bị chặn.
   * SIDE EFFECT: chỉ thay đổi UI state; chưa gọi API.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function requestDecision(kind) {
    if (!['approve', 'reject'].includes(kind) || loading.value || busy.value || error.value || !detail.value?.can_review
      || (kind === 'approve' && detail.value.can_approve === false)) return
    decision.value = kind
    decisionError.value = ''
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đóng dialog decision khi chưa có mutation đang chạy.
   * =====================================================================
   * INPUT: thao tác đóng.
   * OUTPUT: decision null; detail/history vẫn giữ.
   * SIDE EFFECT: không gọi API.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function closeDecision() { if (!busy.value) decision.value = null }

  /**
   * =====================================================================
   * CHỨC NĂNG: Gửi quyết định approve/reject với optimistic version guard.
   * =====================================================================
   * INPUT: fields/taxonomy/reason đã xác nhận từ dialog.
   * OUTPUT: review state/list mới và notice thành công.
   * SIDE EFFECT: POST approve hoặc reject; tải lại lịch sử sau mutation.
   * EXCEPTION/TRANSACTION: lỗi 409/5xx yêu cầu GET kiểm tra trước khi gửi tiếp.
   * =====================================================================
   */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Đóng review dialog và abort request đọc đang chờ.
   * =====================================================================
   * INPUT: thao tác đóng khi không bận mutation.
   * OUTPUT: dialog đóng, detail giữ tới after-leave.
   * SIDE EFFECT: abort GET/history và tăng sequence.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function closeReview() {
    if (busy.value) return
    open.value = false
    decision.value = null
    generation += 1
    readAbort?.abort()
    historyAbort?.abort()
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Dọn state sau khi hiệu ứng đóng review hoàn tất.
   * =====================================================================
   * INPUT: lifecycle after-leave của dialog.
   * OUTPUT: detail/history rỗng nếu dialog chưa mở lại.
   * SIDE EFFECT: không gọi API hoặc thay đổi server.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
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

  return { state, notice, openReview, loadReview, loadHistory, rescoreQuality, requestDecision, closeDecision,
    confirmDecision, closeReview, finishClose }
}
