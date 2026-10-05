/* eslint-disable camelcase -- DTO theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối sửa, xóa và tạo lại candidate độc lập form tạo mới.
 * CÁC HÀM/METHOD TRONG FILE: useAiContentActions(), openEditor(), closeEditor(),
 * saveEditor(), requestAction(), closeAction(), confirmAction(), monitor(), checkRun(),
 * resumeRun(), messageOf(), runError(), busyId (computed), scope dispose.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): item/action -> dialog, API và cập nhật list.
 * SIDE EFFECT: API candidate/Apply Post draft theo xác nhận; dọn timer khi unmount.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { buildAiContentRegenerateRequest } from '@/utils/aiContentInput'
import { formatAiError, isAiSuccess } from '@/utils/aiErrors'

const terminal = ['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired']

/**
 * =====================================================================
 * Input: lỗi public từ API. Output: thông báo an toàn để giữ bản đang sửa.
 * =====================================================================
 */
const messageOf = error => formatAiError(error, 'Không thực hiện được thao tác. Hãy thử lại.')

/**
 * =====================================================================
 * Input: callbacks update/remove list.
 * Output: state/action scoped; không sửa nguồn bên phải.
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
  let editorVersion = 0
  let actionVersion = 0
  let disposed = false
  const busyId = computed(() => editorSaving.value ? editor.value?.job_id : actionBusy.value ? action.value?.item.id : null)
  const runError = value => formatAiError(value, undefined, getOutputOptions())

  /**
   * =====================================================================
   * Input: item ready.
   * Output: mở đủ field theo target trong lúc GET detail; bỏ response khi đóng/đổi item.
   * =====================================================================
   */
  async function openEditor(item) {
    if (item.status === 'generating' || editorSaving.value) return
    const version = ++editorVersion

    editor.value = { job_id: item.id, target_type: item.targetType, draft: {} }
    editorLoading.value = true
    editorError.value = ''
    try {
      const session = await aiAgentService.status(item.id, item.targetType)
      if (disposed || version !== editorVersion) return
      editor.value = session
      updateSession(session)
    }
    catch (error) { if (!disposed && version === editorVersion) editorError.value = messageOf(error) }
    finally { if (!disposed && version === editorVersion) editorLoading.value = false }
  }

  /**
   * =====================================================================
   * Input: close.
   * Output: vô hiệu GET đang chờ; giữ dialog khi đang save.
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
   * Input: field editor.
   * Output: lưu bản nháp với version optimistic; lỗi giữ nội dung nhập.
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
   * Input: kind/item. Output: dialog và detail/version mới cho regen/Apply.
   * SIDE EFFECT: GET snapshot public; không mutation trước xác nhận.
   * =====================================================================
   */
  async function requestAction(kind, item) {
    if ((item.status === 'generating' && kind !== 'cancel') || actionBusy.value) return
    const version = ++actionVersion
    const readDetail = ['apply', 'regenerate'].includes(kind)

    action.value = { kind, item, loading: readDetail }
    actionError.value = ''
    if (!readDetail) return
    try {
      const session = await aiAgentService.status(item.id, item.targetType)
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
   * Input: close.
   * Output: đóng dialog nếu chưa submit.
   * =====================================================================
   */
  function closeAction() {
    if (!actionBusy.value) { actionVersion += 1; action.value = null }
  }

  /**
   * =====================================================================
   * Input: child DTO và số lần đọc.
   * Output: polling backoff đúng job_id mới; terminal/lỗi thì dừng.
   * =====================================================================
   */
  function monitor(session, attempts = 0) {
    if (disposed || terminal.includes(session.status)) return
    if (attempts >= 120) {
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
   * Input: child đang theo dõi và số lần GET. Output: cập nhật tiến độ hoặc lỗi mạng;
   * chỉ đọc UUID hiện tại, không gửi thêm request tạo nội dung.
   * =====================================================================
   */
  async function checkRun(session, attempts = 0) {
    const id = session.job_id
    if (disposed || pendingChecks.has(id)) return
    pendingChecks.add(id)
    try {
      const value = await aiAgentService.status(id, session.target_type ?? 'post')
      if (disposed) return
      updateSession(value)
      onFeedback(value)
      if (notice.value?.type === 'warning') notice.value = null
      if (value.status === 'failed') notice.value = { type: 'error', message: runError(value) }
      if (terminal.includes(value.status)) monitoring.delete(id)
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
    clearTimeout(monitoring.get(item.id))

    return checkRun({ job_id: item.id, target_type: item.targetType }, 0)
  }

  /**
   * =====================================================================
   * Input: tùy chọn regenerate.
   * Output: xóa một item hoặc child mới; chặn submit trùng.
   * =====================================================================
   */
  async function confirmAction(options = {}) {
    if (!action.value || actionBusy.value || action.value.loading || action.value.loadFailed) return
    const current = action.value

    actionBusy.value = true
    actionError.value = ''
    try {
      if (current.kind === 'remove') {
        await aiAgentService.removeSession(current.item.id)
        if (disposed) return
        removeItem(current.item.id)
        notice.value = { type: 'success', message: 'Đã xóa bản content AI.' }
      }
      else if (current.kind === 'cancel') {
        const value = await aiAgentService.cancel(current.item.id)
        if (disposed) return
        updateSession(value)
        monitor(value)
        notice.value = { type: 'success', message: 'Đã gửi yêu cầu hủy tác vụ.' }
      }
      else if (current.kind === 'apply') {
        const result = await aiAgentService.applyCandidate(current.item.id, { ...options, expected_version: current.session.draft_version })
        if (disposed) return
        updateSession({ ...current.session, applied_target_id: result.post_id ?? result.target_id, applied_fields: result.fields })
        notice.value = { type: 'success', message: `Đã tạo Post nháp #${result.post_id ?? result.target_id}. Mở danh sách Post để kiểm tra và duyệt.` }
      }
      else {
        const child = await aiAgentService.regenerate(current.item.id, buildAiContentRegenerateRequest(options))
        if (disposed) return
        updateSession(child)
        onFeedback(child)
        monitor(child)
        notice.value = child.status === 'failed' ? { type: 'error', message: runError(child) }
          : terminal.includes(child.status) && !isAiSuccess(child) ? { type: 'warning', message: 'Tác vụ tạo lại đã hủy hoặc hết hạn. Bản cũ được giữ nguyên.' }
            : { type: 'success', message: isAiSuccess(child) ? 'Đã tạo bản mới. Bản cũ được giữ trong danh sách.' : 'Đã xếp hàng tạo lại. Bản cũ được giữ trong danh sách.' }
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
    openEditor, closeEditor, saveEditor, requestAction, closeAction, confirmAction, resumeRun }
}
