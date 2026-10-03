/* eslint-disable camelcase -- DTO theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối sửa, xóa và tạo lại candidate độc lập form tạo mới.
 * CÁC HÀM/METHOD TRONG FILE: useAiContentActions(), openEditor(), closeEditor(),
 * saveEditor(), requestAction(), closeAction(), confirmAction(), monitor(), dispose.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): item/action -> dialog, API và cập nhật list.
 * SIDE EFFECT: không tự apply domain; dọn timer/callback khi unmount.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { buildAiContentRegenerateRequest } from '@/utils/aiContentInput'

const terminal = ['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired']

const messageOf = error => Object.values(error?.data?.errors ?? {}).flat()[0]
  || error?.data?.message || error?.message || 'Không thực hiện được thao tác. Hãy thử lại.'

/** Input: callbacks update/remove list. Output: state/action scoped; không sửa nguồn bên phải. */
export function useAiContentActions({ updateSession, removeItem }) {
  const editor = shallowRef(null)
  const editorLoading = shallowRef(false)
  const editorSaving = shallowRef(false)
  const editorError = shallowRef('')
  const action = shallowRef(null)
  const actionBusy = shallowRef(false)
  const actionError = shallowRef('')
  const notice = shallowRef('')
  const monitoring = new Map()
  let editorVersion = 0
  let disposed = false
  const busyId = computed(() => editorSaving.value ? editor.value?.job_id : actionBusy.value ? action.value?.item.id : null)

  /** Input: item ready. Output: detail riêng; bỏ response khi dialog đóng/đổi item. */
  async function openEditor(item) {
    if (item.status !== 'review' || editorSaving.value) return
    const version = ++editorVersion

    editor.value = { job_id: item.id, draft: {} }
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

  /** Input: close. Output: vô hiệu GET đang chờ; giữ dialog khi đang save. */
  function closeEditor() {
    if (editorSaving.value) return
    editorVersion += 1
    editor.value = null
    editorLoading.value = false
  }

  /** Input: field editor. Output: lưu bản nháp với version optimistic; lỗi giữ nội dung nhập. */
  async function saveEditor(values) {
    if (editorSaving.value || editorLoading.value || !editor.value?.draft_version) return
    editorSaving.value = true
    editorError.value = ''
    try {
      const session = await aiAgentService.updateCandidate(editor.value.job_id, { ...values, expected_version: editor.value.draft_version })
      if (disposed) return
      updateSession(session)
      editor.value = null
      notice.value = 'Đã lưu nội dung AI.'
    }
    catch (error) { if (!disposed) editorError.value = messageOf(error) }
    finally { if (!disposed) editorSaving.value = false }
  }

  /** Input: remove/regenerate và item terminal. Output: dialog xác nhận, chưa gọi API. */
  function requestAction(kind, item) {
    if (item.status === 'generating' || actionBusy.value) return
    action.value = { kind, item }
    actionError.value = ''
  }

  /** Input: close. Output: đóng dialog nếu chưa submit. */
  function closeAction() {
    if (!actionBusy.value) action.value = null
  }

  /** Input: child DTO và số lần đọc. Output: polling backoff đúng job_id mới; terminal/lỗi thì dừng. */
  function monitor(session, attempts = 0) {
    if (disposed || terminal.includes(session.status)) return
    if (attempts >= 120) {
      notice.value = 'Tác vụ vẫn đang xử lý. Bấm Tải lại để đọc tiến độ.'

      return
    }
    const id = session.job_id

    clearTimeout(monitoring.get(id))

    const timer = setTimeout(async () => {
      try {
        const value = await aiAgentService.status(id, session.target_type ?? 'post')
        if (disposed) return
        updateSession(value)
        if (value.status === 'failed') notice.value = value.error || 'Tạo lại thất bại. Kiểm tra provider rồi thử lại thủ công.'
        if (terminal.includes(value.status)) monitoring.delete(id)
        else monitor(value, attempts + 1)
      }
      catch {
        monitoring.delete(id)
        if (!disposed) notice.value = 'Chưa đọc được trạng thái tạo lại. Bấm Tải lại để cập nhật; tác vụ vẫn được lưu.'
      }
    }, Math.min(1000 + attempts * 500, 5000))

    monitoring.set(id, timer)
  }

  /** Input: tùy chọn regenerate. Output: xóa một item hoặc child mới; chặn submit trùng. */
  async function confirmAction(options = {}) {
    if (!action.value || actionBusy.value) return
    const current = action.value

    actionBusy.value = true
    actionError.value = ''
    try {
      if (current.kind === 'remove') {
        await aiAgentService.removeSession(current.item.id)
        if (disposed) return
        removeItem(current.item.id)
        notice.value = 'Đã xóa bản content AI.'
      }
      else {
        const child = await aiAgentService.regenerate(current.item.id, buildAiContentRegenerateRequest(options))
        if (disposed) return
        updateSession(child)
        monitor(child)
        notice.value = 'Đã xếp hàng tạo lại. Bản cũ được giữ trong danh sách.'
      }
      action.value = null
    }
    catch (error) { if (!disposed) actionError.value = messageOf(error) }
    finally { if (!disposed) actionBusy.value = false }
  }

  onScopeDispose(() => {
    disposed = true
    editorVersion += 1
    monitoring.forEach(timer => clearTimeout(timer))
    monitoring.clear()
  })

  return { editor, editorLoading, editorSaving, editorError, action, actionBusy, actionError, notice, busyId,
    openEditor, closeEditor, saveEditor, requestAction, closeAction, confirmAction }
}
