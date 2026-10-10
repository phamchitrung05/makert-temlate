/* eslint-disable camelcase -- DTO giữ tên field theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Gửi tác vụ Ai Content vào hàng đợi dùng chung.
 * =====================================================================
 * CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
 * - useAiContentGeneration(): tạo state và action cho form nhập nguồn.
 * - acceptSession(): nhận phản hồi server và hiển thị lỗi terminal.
 * - announceQueued(): gửi metadata bounded của task tới popup hàng đợi.
 * - generate(): kiểm tra nguồn, POST một lần và bàn giao task cho queue.
 * - retryRun(): gửi lại run failed khi người dùng chủ động yêu cầu.
 * - reset(): xóa state kết quả của form mà không xóa task trên server.
 * - busy/blockedReason/canGenerate/canRetry/generation: computed trạng thái form.
 * - props/emits: không có; nhận refs/callback từ page.
 * - cleanup scope: vô hiệu response đến trễ khi component bị hủy.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: source/catalog refs và callback cập nhật danh sách/task queue.
 * - OUTPUT: validation, trạng thái gửi và lỗi request của form.
 * - SIDE EFFECT: POST AI Agent API; worker được queue dùng chung theo dõi.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { announceAiTaskQueued } from '@/composables/useAiTaskQueue'
import { aiAgentService } from '@/services/aiAgent'
import { buildAiContentRequest, validateAiContentSource } from '@/utils/aiContentInput'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo state và action gửi nguồn mới vào queue Content AI.
 * =====================================================================
 * INPUT: source/catalog refs và callback cập nhật session/feedback/queue.
 * OUTPUT: generation state cùng generate/retryRun/reset cho form.
 * SIDE EFFECT: POST/ retry API; phát queue event và reset callback sau accepted.
 * EXCEPTION/TRANSACTION: lỗi HTTP được chuẩn hóa; không mở transaction ở frontend.
 * =====================================================================
 */
export function useAiContentGeneration(source, catalog, onSession, onFeedback = () => {}, onQueued = () => {}) {
  const submitting = shallowRef(false)
  const session = shallowRef(null)
  const error = shallowRef('')
  let version = 0

  const busy = computed(() => submitting.value)
  const blockedReason = computed(() => validateAiContentSource(source.value, catalog.value))
  const canGenerate = computed(() => !busy.value && !blockedReason.value)
  const canRetry = computed(() => !busy.value && session.value?.status === 'failed')

  const generation = computed(() => ({
    busy: busy.value,
    canGenerate: canGenerate.value,
    canRetry: canRetry.value,
    blockedReason: blockedReason.value,
    session: session.value,
    error: error.value,
  }))

  /**
   * =====================================================================
   * CHỨC NĂNG: Nhận DTO lifecycle từ API và cập nhật state form.
   * =====================================================================
   * INPUT: DTO run accepted hoặc terminal từ server.
   * OUTPUT: session/error state và feedback public cho màn hình.
   * SIDE EFFECT: gọi callback onSession/onFeedback; không gọi API mới.
   * EXCEPTION/TRANSACTION: không ném lỗi nghiệp vụ, không mở transaction.
   * =====================================================================
   */
  function acceptSession(value) {
    session.value = value
    onSession(value)
    onFeedback(value, 'AI không tạo được nội dung. Hãy kiểm tra provider/model và thử lại.', catalog.value.outputOptions)
    if (value.status === 'failed') error.value = formatAiError(value, undefined, catalog.value.outputOptions)
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Phát task bounded để popup dùng chung nhận run mới ngay.
   * =====================================================================
   * INPUT: DTO run sau HTTP accepted.
   * OUTPUT: queue detail hoặc null khi thiếu identity.
   * SIDE EFFECT: phát CustomEvent nội bộ; không gọi API.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function announceQueued(value) {
    return announceAiTaskQueued(value, {
      task_type: 'article_generation',
      source: 'ai_content',
      name: 'Tạo nội dung AI',
    })
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Gửi nguồn mới và bàn giao run accepted cho queue chung.
   * =====================================================================
   * INPUT: source hiện tại đã qua validate và catalog model.
   * OUTPUT: không trả payload; generation state kết thúc sau POST.
   * SIDE EFFECT: POST create session, phát queue event, reset form qua onQueued.
   * EXCEPTION/TRANSACTION: lỗi request giữ nguồn để người dùng sửa; không retry ngầm.
   * =====================================================================
   */
  async function generate() {
    if (!canGenerate.value) return
    const token = ++version

    submitting.value = true
    error.value = ''
    session.value = null
    try {
      const payload = await buildAiContentRequest({ ...source.value }, catalog.value.selectedModel)
      if (token !== version) return
      const value = await aiAgentService.createSession(payload)
      if (token !== version) return
      acceptSession(value)
      if (!['failed', 'cancelled', 'expired'].includes(value.status)) {
        announceQueued(value)
        onQueued(value)
        session.value = null
      }
    }
    catch (requestError) {
      if (token === version) error.value = formatAiError(requestError, 'Không thể tạo bài AI. Hãy thử lại.', catalog.value.outputOptions)
    }
    finally {
      if (token === version) submitting.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Retry run failed theo UUID cũ và đưa lại vào queue chung.
   * =====================================================================
   * INPUT: session hiện tại có status failed.
   * OUTPUT: generation state được cập nhật; không tạo session mới.
   * SIDE EFFECT: POST retry, phát queue event và reset form sau accepted.
   * EXCEPTION/TRANSACTION: lỗi HTTP hiển thị tại form; không mở transaction frontend.
   * =====================================================================
   */
  async function retryRun() {
    if (!canRetry.value) return
    const token = ++version
    const jobId = session.value.job_id

    submitting.value = true
    error.value = ''
    try {
      const value = await aiAgentService.retry(jobId)
      if (token !== version) return
      acceptSession(value)
      if (!['failed', 'cancelled', 'expired'].includes(value.status)) {
        announceQueued(value)
        onQueued(value)
        session.value = null
      }
    }
    catch (requestError) {
      if (token === version) error.value = formatAiError(requestError, 'Chưa thể thử lại tác vụ AI.', catalog.value.outputOptions)
    }
    finally {
      if (token === version) submitting.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Xóa state kết quả của form để nhập nguồn tiếp theo.
   * =====================================================================
   * INPUT: không có; chỉ thực hiện khi POST hiện tại đã kết thúc.
   * OUTPUT: boolean cho biết form đã reset.
   * SIDE EFFECT: vô hiệu response đến trễ; không xóa task trên server.
   * EXCEPTION/TRANSACTION: không có.
   * =====================================================================
   */
  function reset() {
    if (busy.value) return false
    version += 1
    session.value = null
    error.value = ''

    return true
  }

  onScopeDispose(() => { version += 1 })

  return { generation, generate, retryRun, reset }
}
