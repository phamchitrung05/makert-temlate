/* eslint-disable camelcase -- DTO theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối sửa, xóa và tạo lại candidate độc lập form tạo mới.
 * CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
 * - useAiContentActions(): tạo state/action độc lập form nguồn mới.
 * - openEditor(): đọc và mở đúng run hiện tại trong session.
 * - closeEditor(): đóng editor và vô hiệu GET đến trễ.
 * - saveEditor(): lưu draft theo optimistic version và cập nhật list.
 * - requestAction(): đọc detail và mở dialog remove/cancel/apply/regenerate.
 * - closeAction(): đóng dialog nếu chưa submit.
 * - announceRegenerate(): phát task tạo lại vào queue popup ngay sau accepted.
 * - confirmAction(): gửi mutation một lần và cập nhật projection phiên hiện tại.
 * - monitor(): đặt timer polling backoff cho đúng run UUID.
 * - checkRun(): đọc status và cập nhật tiến độ/error terminal.
 * - resumeRun(): kiểm tra lại active run từ dòng session.
 * - trackRuns(): khôi phục polling sau reload hoặc list thay đổi.
 * - thumbnailAction(): retry/cancel image child riêng và đọc lại parent.
 * - messageOf()/runError(): chuẩn hóa lỗi HTTP/lifecycle thành tiếng Việt.
 * - busyId: computed identity đang bận; scope dispose: hủy timer/response cũ.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): item/action -> dialog, API và cập nhật list.
 * SIDE EFFECT: API candidate/Apply Post draft theo xác nhận; dọn timer khi unmount.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { announceAiTaskQueued } from '@/composables/useAiTaskQueue'
import { aiAgentService } from '@/services/aiAgent'
import { buildAiContentRegenerateRequest } from '@/utils/aiContentInput'
import { formatAiError, isAiSuccess } from '@/utils/aiErrors'
import { isAiContentPending } from '@/utils/aiThumbnail'

const terminal = ['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired']

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa lỗi API để hiển thị tại editor/action.
 * =====================================================================
 * INPUT: HTTP error hoặc run DTO public.
 * OUTPUT: câu tiếng Việt bounded có fallback nghiệp vụ.
 * SIDE EFFECT: hàm thuần, không gọi API.
 * EXCEPTION/TRANSACTION: không có.
 * =====================================================================
 */
const messageOf = error => formatAiError(error, 'Không thực hiện được thao tác. Hãy thử lại.')

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo state action theo scope cho editor và regenerate workflow.
 * =====================================================================
 * INPUT: callbacks updateSession/removeItem/feedback và output options.
 * OUTPUT: state/action handlers độc lập form nguồn mới.
 * SIDE EFFECT: API candidate/action, timer polling và queue event.
 * EXCEPTION/TRANSACTION: lỗi HTTP được chuẩn hóa; backend chịu transaction/lock.
 * =====================================================================
 */
export function useAiContentActions({ updateSession, removeItem, onFeedback = () => {}, getOutputOptions = () => [] }) {
  const editor = shallowRef(null)
  const editorLoading = shallowRef(false)
  const editorSaving = shallowRef(false)
  const editorError = shallowRef('')
  const action = shallowRef(null)
  const actionBusy = shallowRef(false)
  const actionError = shallowRef('')
  const notice = shallowRef(null)
  const monitoring = new Map()
  const pendingChecks = new Set()
  const thumbnailActionId = shallowRef(null)
  let editorVersion = 0
  let actionVersion = 0
  let disposed = false
  const busyId = computed(() => thumbnailActionId.value ?? (editorSaving.value ? editor.value?.job_id : actionBusy.value ? action.value?.item.id : null))
  /** INPUT: run DTO. OUTPUT: lỗi lifecycle tiếng Việt; SIDE EFFECT: không có; EXCEPTION: không có. */
  const runError = value => formatAiError(value, undefined, getOutputOptions())

  /**
   * =====================================================================
   * CHỨC NĂNG: Đưa candidate tạo lại vào popup queue ngay sau HTTP 202.
   * =====================================================================
   * INPUT: child run accepted từ regenerate().
   * OUTPUT: task bounded cho queue; không thay đổi item hay form nguồn.
   * SIDE EFFECT: phát CustomEvent và đánh thức polling queue nếu popup đã mount.
   * EXCEPTION/TRANSACTION: không gọi API hoặc tạo thêm run.
   * =====================================================================
   */
  function announceRegenerate(value) {
    return announceAiTaskQueued(value, {
      task_type: 'article_generation',
      source: 'ai_content',
      name: 'Tạo lại nội dung AI',
    })
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Mở editor của run hiện tại trong session sau khi đọc detail.
   * =====================================================================
   * INPUT: item ready đã gom theo session.
   * OUTPUT: editor state chứa draft/version hoặc lỗi tải.
   * SIDE EFFECT: GET status; response cũ bị bỏ khi dialog đổi/đóng.
   * EXCEPTION/TRANSACTION: lỗi HTTP hiển thị tại editor; không mở transaction.
   * =====================================================================
   */
  async function openEditor(item) {
    if (item.status === 'generating' || editorSaving.value) return
    const version = ++editorVersion
    const runId = item.runId ?? item.id

    editor.value = { job_id: runId, target_type: item.targetType, draft: {} }
    editorLoading.value = true
    editorError.value = ''
    try {
      const session = await aiAgentService.status(runId, item.targetType)
      if (disposed || version !== editorVersion) return
      editor.value = session
      updateSession(session)
    }
    catch (error) { if (!disposed && version === editorVersion) editorError.value = messageOf(error) }
    finally { if (!disposed && version === editorVersion) editorLoading.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đóng dialog editor và vô hiệu response GET đang chờ.
   * =====================================================================
   * INPUT: thao tác đóng từ UI.
   * OUTPUT: editor state rỗng khi không đang lưu.
   * SIDE EFFECT: tăng sequence để bỏ response cũ; không gọi API.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function closeEditor() {
    if (editorSaving.value) return
    editorVersion += 1
    editor.value = null
    editorLoading.value = false
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Lưu bản nháp đang sửa với optimistic version lock.
   * =====================================================================
   * INPUT: field values và draft_version từ detail hiện tại.
   * OUTPUT: cập nhật list/editor khi API thành công.
   * SIDE EFFECT: PUT/PATCH candidate; lỗi giữ dialog và nội dung nhập.
   * EXCEPTION/TRANSACTION: lỗi HTTP được chuẩn hóa; transaction thuộc backend.
   * =====================================================================
   */
  async function saveEditor(values) {
    if (editorSaving.value || editorLoading.value || !editor.value?.draft_version) return
    editorSaving.value = true
    editorError.value = ''
    try {
      const session = await aiAgentService.updateCandidate(editor.value.job_id, { ...values, expected_version: editor.value.draft_version })
      if (disposed) return
      updateSession(session)
      editor.value = null
      notice.value = { type: 'success', message: 'Đã lưu nội dung AI.' }
    }
    catch (error) { if (!disposed) editorError.value = messageOf(error) }
    finally { if (!disposed) editorSaving.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Chuẩn bị dialog action và đọc detail candidate trước xác nhận.
   * =====================================================================
   * INPUT: kind action và item đã gom theo session.
   * OUTPUT: action state có session/version hoặc lỗi đọc.
   * SIDE EFFECT: GET detail public; không mutation trước confirm.
   * EXCEPTION/TRANSACTION: lỗi HTTP giữ dialog để người dùng thử lại.
   * =====================================================================
   */
  async function requestAction(kind, item) {
    if ((item.status === 'generating' && kind !== 'cancel') || actionBusy.value) return
    const version = ++actionVersion
    const readDetail = ['apply', 'regenerate'].includes(kind)
    const runId = item.runId ?? item.id

    action.value = { kind, item, loading: readDetail }
    actionError.value = ''
    if (!readDetail) return
    try {
      const session = await aiAgentService.status(runId, item.targetType)
      if (disposed || version !== actionVersion) return
      action.value = { kind, item, session, loading: false }
      updateSession(session)
    }
    catch (error) {
      if (disposed || version !== actionVersion) return
      action.value = { kind, item, loading: false, loadFailed: true }
      actionError.value = messageOf(error)
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đóng dialog action khi chưa có request mutation.
   * =====================================================================
   * INPUT: thao tác đóng từ UI.
   * OUTPUT: action state rỗng nếu không bận.
   * SIDE EFFECT: tăng sequence vô hiệu GET đến trễ; không gọi API.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function closeAction() {
    if (!actionBusy.value) { actionVersion += 1; action.value = null }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Lên lịch polling candidate child với backoff giới hạn.
   * =====================================================================
   * INPUT: child DTO và số lần đọc hiện tại.
   * OUTPUT: timer theo đúng job_id; terminal/lỗi thì không đặt timer.
   * SIDE EFFECT: set/clear timeout; không tạo thêm candidate.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function monitor(session, attempts = 0) {
    if (disposed || !isAiContentPending(session)) return
    if (attempts >= 120) {
      monitoring.set(session.job_id, null)
      notice.value = { type: 'warning', message: 'Tác vụ vẫn đang xử lý. Bấm Kiểm tra tiến trình ở dòng bài để đọc tiếp.' }

      return
    }
    const id = session.job_id

    clearTimeout(monitoring.get(id))

    const timer = setTimeout(() => { void checkRun(session, attempts) }, Math.min(1000 + attempts * 500, 5000))

    monitoring.set(id, timer)
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc trạng thái child và cập nhật projection phiên hiện tại.
   * =====================================================================
   * INPUT: child job_id và số lần GET.
   * OUTPUT: tiến độ/error terminal trong workspace; không gửi POST tạo mới.
   * SIDE EFFECT: GET status, cập nhật feedback và timer polling.
   * EXCEPTION/TRANSACTION: lỗi mạng hiển thị warning; không mở transaction.
   * =====================================================================
   */
  async function checkRun(session, attempts = 0) {
    const id = session.job_id
    if (disposed || pendingChecks.has(id)) return
    clearTimeout(monitoring.get(id))
    pendingChecks.add(id)
    try {
      const value = await aiAgentService.status(id, session.target_type ?? 'post')
      if (disposed) return
      updateSession(value)
      onFeedback(value)
      if (notice.value?.type === 'warning') notice.value = null
      if (value.status === 'failed') notice.value = { type: 'error', message: runError(value) }
      if (!isAiContentPending(value)) monitoring.delete(id)
      else monitor(value, attempts + 1)
    }
    catch {
      monitoring.delete(id)
      if (!disposed) notice.value = {
        type: 'warning', message: 'Chưa đọc được trạng thái. Bấm Kiểm tra tiến trình ở dòng bài để cập nhật; tác vụ vẫn được lưu.',
      }
    }
    finally { pendingChecks.delete(id) }
  }

  /**
   * =====================================================================
   * Input: item cần kiểm lại. Output: GET tiến độ mới và timer theo UUID đã có.
   * =====================================================================
   */
  function resumeRun(item) {
    const runId = item.activeRunId ?? item.runId ?? item.id
    clearTimeout(monitoring.get(runId))

    return checkRun({ job_id: runId, target_type: item.targetType }, 0)
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Khôi phục polling cho các item còn đang chạy sau reload/list update.
   * =====================================================================
   * INPUT: list session đã gom và UUID cần bỏ qua tùy chọn.
   * OUTPUT: timer polling cho active run, không gửi POST trùng.
   * SIDE EFFECT: đăng ký timeout GET status.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function trackRuns(items, ignoredJobId) {
    for (const item of items) {
      const runId = item.activeRunId ?? item.runId ?? item.id
      if (runId === ignoredJobId || monitoring.has(runId) || pendingChecks.has(runId)) continue

      const session = { job_id: runId, target_type: item.targetType, status: item.status === 'generating' ? 'queued' : 'ready',
        applied_target_id: item.status === 'applied' ? true : null, review: item.review, thumbnail_generation: item.thumbnailGeneration }

      if ((item.status === 'generating' || item.status === 'review') && isAiContentPending(session)) monitor(session)
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Retry hoặc hủy thumbnail child mà không tạo image row riêng.
   * =====================================================================
   * INPUT: session item có thumbnail_generation và kind retry/cancel.
   * OUTPUT: parent được cập nhật và tiếp tục polling nếu cần.
   * SIDE EFFECT: POST retry/cancel image child; phát queue event khi retry accepted.
   * EXCEPTION/TRANSACTION: lỗi HTTP hiển thị tại notice; transaction thuộc backend.
   * =====================================================================
   */
  async function thumbnailAction(item, kind = 'retry') {
    if (!item.thumbnailGeneration?.job_id || thumbnailActionId.value) return
    thumbnailActionId.value = item.id
    try {
      if (kind === 'cancel') await aiAgentService.cancel(item.thumbnailGeneration.job_id)
      else {
        const retried = await aiAgentService.retry(item.thumbnailGeneration.job_id)
        if (retried?.task_run_id) announceAiTaskQueued(retried, { task_type: 'image_generation', source: 'ai_image', name: 'Thử lại ảnh AI' })
      }
      if (disposed) return
      await checkRun({ job_id: item.runId ?? item.id, target_type: item.targetType })
      notice.value = { type: 'success', message: kind === 'cancel' ? 'Đã hủy tạo ảnh. Bài viết được giữ nguyên.' : 'Đã xếp hàng thử lại thumbnail AI.' }
    }
    catch (error) { if (!disposed) notice.value = { type: 'error', message: messageOf(error) } }
    finally { if (!disposed) thumbnailActionId.value = null }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Thực hiện remove/cancel/apply/regenerate sau xác nhận.
   * =====================================================================
   * INPUT: options fields/instructions và action detail đã đọc.
   * OUTPUT: cập nhật session/list/notice; regenerate giữ một dòng và thêm history.
   * SIDE EFFECT: POST mutation tương ứng, phát queue event và monitor child mới.
   * EXCEPTION/TRANSACTION: lỗi HTTP giữ dialog action; transaction/lock thuộc backend.
   * =====================================================================
   */
  async function confirmAction(options = {}) {
    if (!action.value || actionBusy.value || action.value.loading || action.value.loadFailed) return
    const current = action.value

    actionBusy.value = true
    actionError.value = ''
    try {
      if (current.kind === 'remove') {
        const runId = current.item.runId ?? current.item.id
        await aiAgentService.removeSession(runId)
        if (disposed) return
        removeItem(runId)
        notice.value = { type: 'success', message: 'Đã xóa phiên bản content AI đã chọn.' }
      }
      else if (current.kind === 'cancel') {
        const value = await aiAgentService.cancel(current.item.activeRunId ?? current.item.runId ?? current.item.id)
        if (disposed) return
        updateSession(value)
        monitor(value)
        notice.value = { type: 'success', message: 'Đã gửi yêu cầu hủy tác vụ.' }
      }
      else if (current.kind === 'apply') {
        const result = await aiAgentService.applyCandidate(current.item.runId ?? current.item.id, { ...options, expected_version: current.session.draft_version })
        if (disposed) return
        updateSession({ ...current.session, applied_target_id: result.post_id ?? result.target_id, applied_fields: result.fields })
        notice.value = { type: 'success', message: `Đã tạo Post nháp #${result.post_id ?? result.target_id}. Mở danh sách Post để kiểm tra và duyệt.` }
      }
      else {
        const child = await aiAgentService.regenerate(current.item.runId ?? current.item.id, buildAiContentRegenerateRequest(options))
        if (disposed) return
        if (!['failed', 'cancelled', 'expired'].includes(child.status)) announceRegenerate(child)
        updateSession(child)
        onFeedback(child)
        monitor(child)
        notice.value = child.status === 'failed' ? { type: 'error', message: runError(child) }
          : terminal.includes(child.status) && !isAiSuccess(child) ? { type: 'warning', message: 'Tác vụ tạo lại đã hủy hoặc hết hạn. Bản cũ được giữ nguyên.' }
            : { type: 'success', message: isAiSuccess(child) ? 'Đã cập nhật phiên bản mới. Bản trước nằm trong lịch sử.' : 'Đã xếp hàng tạo lại. Bản hiện tại được giữ trong lúc xử lý.' }
      }
      action.value = null
    }
    catch (error) { if (!disposed) actionError.value = messageOf(error) }
    finally { if (!disposed) actionBusy.value = false }
  }

  onScopeDispose(() => {
    disposed = true
    editorVersion += 1
    actionVersion += 1
    monitoring.forEach(timer => clearTimeout(timer))
    monitoring.clear()
  })

  return { editor, editorLoading, editorSaving, editorError, action, actionBusy, actionError, notice, busyId,
    openEditor, closeEditor, saveEditor, requestAction, closeAction, confirmAction, resumeRun, trackRuns, thumbnailAction }
}
