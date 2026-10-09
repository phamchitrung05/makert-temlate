/* eslint-disable camelcase -- DTO giữ tên field theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Gửi tác vụ Ai Content vào hàng đợi dùng chung.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - useAiContentGeneration(): tạo state và action cho form nhập nguồn.
 * - acceptSession(): nhận phản hồi server và hiển thị lỗi terminal.
 * - announceQueued(): gửi metadata bounded của task tới popup hàng đợi.
 * - generate(): kiểm tra nguồn, POST một lần và bàn giao task cho queue.
 * - retryRun(): gửi lại run failed khi người dùng chủ động yêu cầu.
 * - reset(): xóa state kết quả của form mà không xóa task trên server.
 * - cleanup scope: vô hiệu response đến trễ khi component bị hủy.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: source/catalog refs và callback cập nhật danh sách/task queue.
 * - OUTPUT: validation, trạng thái gửi và lỗi request của form.
 * - SIDE EFFECT: POST AI Agent API; worker được queue dùng chung theo dõi.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { AI_TASK_QUEUED_EVENT } from '@/composables/useAiTaskQueue'
import { aiAgentService } from '@/services/aiAgent'
import { buildAiContentRequest, validateAiContentSource } from '@/utils/aiContentInput'
import { formatAiError } from '@/utils/aiErrors'

/** Tạo state form; task accepted sẽ được chuyển cho danh sách queue qua onQueued. */
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

  /** Nhận DTO lifecycle và chỉ giữ lại lỗi khi server xác nhận run thất bại. */
  function acceptSession(value) {
    session.value = value
    onSession(value)
    onFeedback(value, 'AI không tạo được nội dung. Hãy kiểm tra provider/model và thử lại.', catalog.value.outputOptions)
    if (value.status === 'failed') error.value = formatAiError(value, undefined, catalog.value.outputOptions)
  }

  /** Phát DTO bounded để popup chung nhận task ngay sau khi API xác nhận queued. */
  function announceQueued(value) {
    if (typeof window === 'undefined') return

    const detail = {
      task_run_id: value.task_run_id,
      taskable_id: value.job_id,
      task_type: 'article_generation',
      source: 'ai_content',
      name: 'Tạo nội dung AI',
      status: ['ready', 'completed', 'succeeded'].includes(value.status) ? 'ready' : value.status === 'queued' ? 'queued' : 'processing',
      progress: value.progress ?? 0,
      created_at: value.created_at ?? new Date().toISOString(),
    }

    window.__aiTaskQueuePending = [...(window.__aiTaskQueuePending ?? []), detail].slice(-100)
    window.dispatchEvent(new CustomEvent(AI_TASK_QUEUED_EVENT, { detail }))
  }

  /** Gửi nguồn mới; task accepted được bàn giao cho queue và form được reset ngay. */
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

  /** Retry run failed theo UUID cũ; khi accepted cũng trả task về queue chung. */
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

  /** Xóa state form hiện tại; task đã gửi vẫn được queue popup theo dõi. */
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
