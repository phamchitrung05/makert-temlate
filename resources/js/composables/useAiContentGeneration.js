/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo và theo dõi tác vụ viết bài AI của form Ai Content.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiContentGeneration(), acceptSession(),
 * schedulePoll(), poll(), generate(), retryRun(), reset(), resumePolling(), cleanup scope.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : nguồn/catalog refs và callback cập nhật danh sách.
 * - OUTPUT: validation, tiến trình và lỗi reactive; ngăn gửi trùng/stale response.
 * - SIDE EFFECT: POST/GET AI Agent API; không tự apply hoặc publish Post.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiAgentService } from '@/services/aiAgent'
import { buildAiContentRequest, validateAiContentSource } from '@/utils/aiContentInput'

const terminalStatuses = ['ready', 'completed', 'succeeded', 'failed', 'cancelled', 'expired']

/** Input: nguồn, catalog, callback lifecycle. Output: action/state page-scoped có cleanup timer. */
export function useAiContentGeneration(source, catalog, onSession) {
  const submitting = shallowRef(false)
  const session = shallowRef(null)
  const error = shallowRef('')
  const monitorMessage = shallowRef('')
  let version = 0
  let timer
  let attempts = 0
  let pendingPollToken = null

  const busy = computed(() => submitting.value || Boolean(session.value && !terminalStatuses.includes(session.value.status)))
  const blockedReason = computed(() => validateAiContentSource(source.value, catalog.value))
  const canGenerate = computed(() => !busy.value && !blockedReason.value)
  const canRetry = computed(() => !busy.value && session.value?.status === 'failed')

  const generation = computed(() => ({
    busy: busy.value, canGenerate: canGenerate.value, canRetry: canRetry.value, blockedReason: blockedReason.value,
    session: session.value, error: error.value, monitorMessage: monitorMessage.value,
  }))

  /** Input: lifecycle DTO. Output: session/list mới; lỗi terminal hiển thị cho người dùng. */
  function acceptSession(value) {
    session.value = value
    onSession(value)
    if (value.status === 'failed') error.value = value.error || 'AI không tạo được nội dung. Hãy kiểm tra provider/model và thử lại.'
    if (['cancelled', 'expired'].includes(value.status)) error.value = 'Tác vụ đã hủy hoặc hết hạn. Bạn có thể tạo lại.'
  }

  /** Input: version của run. Output: timer polling tối đa 120 lần; không chạy khi terminal. */
  function schedulePoll(token) {
    clearTimeout(timer)
    if (!busy.value || token !== version) return
    if (attempts >= 120) {
      monitorMessage.value = 'Tác vụ vẫn đang xử lý. Bấm Cập nhật trạng thái để kiểm tra tiếp.'

      return
    }
    timer = setTimeout(() => { void poll(token) }, Math.min(1000 + attempts * 500, 5000))
  }

  /** Input: run version. Output: status mới hoặc thông báo mất kết nối; không gửi lại tác vụ AI. */
  async function poll(token) {
    if (token !== version || pendingPollToken === token || !session.value) return
    pendingPollToken = token
    attempts += 1
    try {
      const value = await aiAgentService.status(session.value.job_id, 'post')
      if (token !== version) return
      monitorMessage.value = ''
      acceptSession(value)
      schedulePoll(token)
    }
    catch {
      if (token === version) monitorMessage.value = 'Chưa đọc được trạng thái. Tác vụ vẫn được lưu; bấm Cập nhật trạng thái để kiểm tra lại.'
    }
    finally {
      if (pendingPollToken === token) pendingPollToken = null
    }
  }

  /** Input: thao tác tạo bài. Output: queued/progress/lỗi; snapshot input, chặn double submit, bỏ callback sau unmount. */
  async function generate() {
    if (!canGenerate.value) return
    const token = ++version

    submitting.value = true
    error.value = ''
    monitorMessage.value = ''
    session.value = null
    attempts = 0
    try {
      const payload = await buildAiContentRequest({ ...source.value }, catalog.value.selectedModel)
      if (token !== version) return
      const value = await aiAgentService.createSession(payload)
      if (token !== version) return
      acceptSession(value)
    }
    catch (requestError) {
      if (token === version) error.value = Object.values(requestError?.data?.errors ?? {}).flat()[0]
        || requestError?.data?.message || requestError?.message || 'Không thể tạo bài AI. Hãy thử lại.'
    }
    finally {
      if (token === version) {
        submitting.value = false
        schedulePoll(token)
      }
    }
  }

  /** Input: người dùng bấm thử lại run failed. Output: requeue UUID cũ, chặn gửi trùng và tiếp tục đọc status; không tự retry. */
  async function retryRun() {
    if (!canRetry.value) return
    const token = ++version
    const jobId = session.value.job_id

    submitting.value = true
    error.value = ''
    monitorMessage.value = ''
    attempts = 0
    clearTimeout(timer)
    try {
      const value = await aiAgentService.retry(jobId)
      if (token !== version) return
      acceptSession(value)
    }
    catch (requestError) {
      if (token === version) error.value = Object.values(requestError?.data?.errors ?? {}).flat()[0]
        || requestError?.data?.message || requestError?.message || 'Chưa thể thử lại tác vụ AI.'
    }
    finally {
      if (token === version) {
        submitting.value = false
        schedulePoll(token)
      }
    }
  }

  /** Input: thao tác tạo mới khi không chạy. Output: xóa thông báo/session; giữ dữ liệu đã lưu trong list. */
  function reset() {
    if (busy.value) return false
    version += 1
    clearTimeout(timer)
    session.value = null
    error.value = ''
    monitorMessage.value = ''

    return true
  }

  /** Input: thao tác cập nhật status sau mất kết nối/timeout. Output: GET status, không tạo lại run. */
  function resumePolling() {
    attempts = 0
    clearTimeout(timer)
    void poll(version)
  }

  onScopeDispose(() => { version += 1; clearTimeout(timer) })

  return { generation, generate, retryRun, reset, resumePolling }
}
