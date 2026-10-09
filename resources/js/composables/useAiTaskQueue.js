/* eslint-disable camelcase -- DTO giữ tên field theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối danh sách và polling task AI dùng chung toàn Admin.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - normalizeTask(): chuẩn hóa DTO mọi task type về shape popup.
 * - aiTaskStatusMeta(): lấy nhãn/icon lifecycle từ allowlist frontend.
 * - useAiTaskQueue(): tạo state queue, filter và lifecycle polling.
 * - start(): gắn listener và tải queue lần đầu.
 * - stop(): hủy request/timer/listener cũ.
 * - refresh(): đọc tracker API, reconcile optimistic task và phát ready event.
 * - addTask(): thêm task queued từ event trước lần refresh kế tiếp.
 * - onQueued(): nhận task mới và đọc lại tracker API để đồng bộ metadata.
 * - cancelTask(): gọi endpoint cancel dùng chung và cập nhật task.
 * - clearFinished(): ẩn task terminal khỏi danh sách UI.
 * - scheduleNext(): poll tuần tự khi còn active task.
 * - notifyReady(): báo các màn hình cần tải lại draft/kết quả.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : API list/cancel và event task queued từ các luồng AI.
 * - OUTPUT: task summaries, filter counts, polling lifecycle và lỗi an toàn.
 * - SIDE EFFECT: GET/POST Admin API, timer tuần tự và listener window event.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { formatAiError } from '@/utils/aiErrors'

export const AI_TASK_QUEUED_EVENT = 'ai-task-queued'
export const AI_TASK_READY_EVENT = 'ai-task-ready'
export const ACTIVE_AI_TASK_STATUSES = ['queued', 'running', 'processing', 'analyzing']
export const TERMINAL_AI_TASK_STATUSES = ['ready', 'failed', 'cancelled', 'expired']

const FILTERS = [
  { label: 'Tất cả', value: 'all' },
  { label: 'Đang xử lý', value: 'analyzing' },
  { label: 'Chờ xử lý', value: 'queued' },
  { label: 'Hoàn thành', value: 'ready' },
  { label: 'Lỗi', value: 'failed' },
  { label: 'Đã hủy', value: 'cancelled' },
  { label: 'Hết hạn', value: 'expired' },
]

const STATUS_META = {
  queued: { label: 'Chờ xử lý', color: 'warning', icon: 'mdi-clock-outline', iconBg: '#fff2e8', iconColor: '#ff9f43' },
  running: { label: 'Đang xử lý', color: 'info', icon: 'mdi-progress-clock', iconBg: '#f4f2fe', iconColor: '#7367F0' },
  processing: { label: 'Đang xử lý', color: 'info', icon: 'mdi-progress-clock', iconBg: '#f4f2fe', iconColor: '#7367F0' },
  analyzing: { label: 'Đang xử lý', color: 'info', icon: 'mdi-file-find-outline', iconBg: '#f4f2fe', iconColor: '#7367F0' },
  ready: { label: 'Hoàn thành', color: 'success', icon: 'mdi-check-circle-outline', iconBg: '#e8fadf', iconColor: '#28c76f' },
  failed: { label: 'Lỗi', color: 'error', icon: 'mdi-alert-circle-outline', iconBg: '#ffebe9', iconColor: '#ea5455' },
  cancelled: { label: 'Đã hủy', color: 'secondary', icon: 'mdi-cancel', iconBg: '#eeeeef', iconColor: '#808390' },
  expired: { label: 'Hết hạn', color: 'warning', icon: 'mdi-clock-alert-outline', iconBg: '#fff2e8', iconColor: '#ff9f43' },
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chọn metadata hiển thị theo lifecycle server.
 * =====================================================================
 * Input: status. Output: label/color/icon bounded, không dùng dữ liệu từ server làm class.
 * Side effect: hàm thuần.
 * =====================================================================
 */
export function aiTaskStatusMeta(status) {
  return STATUS_META[status] ?? STATUS_META.queued
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa DTO analysis thành task chung cho popup.
 * =====================================================================
 * Input: summary hoặc DTO queued detail. Output: task có task_type/source ổn định.
 * Side effect: hàm thuần, không giữ reference tới response API.
 * =====================================================================
 */
export function normalizeTask(value) {
  const status = TERMINAL_AI_TASK_STATUSES.includes(value?.status) || ACTIVE_AI_TASK_STATUSES.includes(value?.status)
    ? value.status
    : 'queued'

  return {
    id: value?.task_run_id ?? value?.id,
    task_type: value?.task_type ?? 'writing_profile_analysis',
    source: value?.source ?? 'ai_writing_profile',
    name: value?.name || 'Phân tích văn phong',
    status,
    progress: Number.isFinite(Number(value?.progress)) ? Number(value.progress) : 0,
    model: value?.model ?? null,
    provider: value?.provider ?? null,
    taskable_id: value?.taskable_id ?? value?.id,
    draft_profile_id: value?.draft_profile_id ?? null,
    error_code: value?.error_code ?? null,
    error_message: value?.error_message ?? null,
    parent_id: value?.parent_id ?? null,
    media_asset_id: value?.media_asset_id ?? null,
    created_at: value?.created_at ?? null,
    started_at: value?.started_at ?? null,
    completed_at: value?.completed_at ?? null,
    expires_at: value?.expires_at ?? null,
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Quản lý task center và polling tuần tự khi còn task active.
 * =====================================================================
 * Input: không bắt buộc; component gọi start() sau khi quyền Admin sẵn sàng.
 * Output: state/actions dùng bởi popup; terminal task không tạo polling mới.
 * Side effect: list/cancel API, AbortController và CustomEvent listener.
 * =====================================================================
 */
export function useAiTaskQueue() {
  const tasks = shallowRef([])
  const currentFilter = shallowRef('all')
  const loading = shallowRef(false)
  const cancellingId = shallowRef('')
  const error = shallowRef('')
  const polling = shallowRef(false)
  const dismissedIds = shallowRef(new Set())

  let timer = null
  let controller = null
  let requestSequence = 0
  let started = false
  let disposed = false

  const visibleTasks = computed(() => tasks.value.filter(task => !dismissedIds.value.has(task.id)))
  const activeCount = computed(() => visibleTasks.value.filter(task => ACTIVE_AI_TASK_STATUSES.includes(task.status)).length)

  const filteredTasks = computed(() => currentFilter.value === 'all'
    ? visibleTasks.value
    : visibleTasks.value.filter(task => currentFilter.value === 'analyzing'
      ? ACTIVE_AI_TASK_STATUSES.includes(task.status)
      : task.status === currentFilter.value))

  const filters = computed(() => FILTERS.map(filter => ({
    ...filter,
    count: filter.value === 'all'
      ? visibleTasks.value.length
      : visibleTasks.value.filter(task => filter.value === 'analyzing'
        ? ACTIVE_AI_TASK_STATUSES.includes(task.status)
        : task.status === filter.value).length,
  })))

  /**
   * =====================================================================
   * CHỨC NĂNG: Hủy timer/request cũ và vô hiệu response đến trễ.
   * =====================================================================
   * Input: không có. Output: queue dừng polling.
   * Side effect: abort GET và clear timeout.
   * =====================================================================
   */
  function stop() {
    requestSequence++
    clearTimeout(timer)
    timer = null
    controller?.abort()
    controller = null
    polling.value = false
    if (started && typeof window !== 'undefined') window.removeEventListener(AI_TASK_QUEUED_EVENT, onQueued)
    started = false
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Lên lịch GET tiếp theo chỉ khi server còn task active.
   * =====================================================================
   * Input: không có. Output: một timer polling duy nhất.
   * Side effect: đặt timeout 2 giây; không tạo timer cho task terminal.
   * =====================================================================
   */
  function scheduleNext() {
    clearTimeout(timer)
    timer = null
    polling.value = activeCount.value > 0
    if (disposed || activeCount.value === 0) return
    timer = setTimeout(() => { void refresh() }, 2000)
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Báo cho các màn hình cần dữ liệu khi worker tạo draft xong.
   * Input: task đã ready. Output: CustomEvent nội bộ trong cùng cửa sổ.
   * Side effect: không gọi API; List sẽ tự tải lại để nhận profile draft.
   * =====================================================================
   */
  function notifyReady(task) {
    if (task.status !== 'ready' || typeof window === 'undefined') return
    window.dispatchEvent(new CustomEvent(AI_TASK_READY_EVENT, { detail: task }))
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải danh sách metadata từ API và cập nhật task center.
   * =====================================================================
   * Input: không có; server tự scope theo actor.
   * Output: tasks/pagination-free UI state; lỗi mạng giữ task cũ và retry có kiểm soát.
   * Side effect: GET list với per_page 100; không lấy reference_text/result.
   * =====================================================================
   */
  async function refresh() {
    if (disposed || loading.value) return
    const sequence = ++requestSequence

    controller?.abort()
    controller = new AbortController()
    loading.value = true
    error.value = ''

    try {
      const listTasks = aiWritingProfilesService.listTaskRuns ?? aiWritingProfilesService.listAnalyses
      const response = await listTasks({ per_page: 100 }, controller.signal)
      if (disposed || sequence !== requestSequence) return
      const rows = Array.isArray(response?.data) ? response.data : []
      const serverTasks = rows.map(normalizeTask).filter(task => task.id)
      const previousStatuses = new Map(tasks.value.map(task => [task.id, task.status]))
      const serverIds = new Set(serverTasks.map(task => task.id))
      const optimisticTasks = tasks.value.filter(task => ACTIVE_AI_TASK_STATUSES.includes(task.status) && !serverIds.has(task.id))

      tasks.value = [...serverTasks, ...optimisticTasks]
        .sort((left, right) => String(right.created_at ?? '').localeCompare(String(left.created_at ?? '')))
      serverTasks.forEach(task => {
        if (task.status === 'ready' && previousStatuses.get(task.id) && previousStatuses.get(task.id) !== 'ready') notifyReady(task)
      })
      scheduleNext()
    }
    catch (reason) {
      if (disposed || sequence !== requestSequence || reason?.name === 'AbortError') return
      error.value = formatAiError(reason, 'Không tải được hàng đợi AI.')
      scheduleNext()
    }
    finally {
      if (sequence === requestSequence) loading.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Thêm/cập nhật task vừa queued trước lần refresh kế tiếp.
   * =====================================================================
   * Input: DTO analysis từ POST thành công. Output: task xuất hiện ngay ở popup.
   * Side effect: cập nhật state và đánh thức polling nếu đang active.
   * =====================================================================
   */
  function addTask(value) {
    const task = normalizeTask(value)
    if (!task.id) return
    const previous = tasks.value.find(item => item.id === task.id)

    dismissedIds.value = new Set([...dismissedIds.value].filter(id => id !== task.id))
    tasks.value = [task, ...tasks.value.filter(item => item.id !== task.id)]
      .sort((left, right) => String(right.created_at ?? '').localeCompare(String(left.created_at ?? '')))
    if (task.status === 'ready' && previous?.status !== 'ready') notifyReady(task)
    scheduleNext()
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Hủy một task còn trong nhóm trạng thái active.
   * =====================================================================
   * Input: task id. Output: task status server sau cancel.
   * Side effect: POST cancel; lỗi được giữ ở error để popup hiển thị.
   * =====================================================================
   */
  async function cancelTask(id) {
    const task = tasks.value.find(item => item.id === id)
    if (!task || !ACTIVE_AI_TASK_STATUSES.includes(task.status) || cancellingId.value) return null
    cancellingId.value = id
    error.value = ''
    try {
      const cancel = aiWritingProfilesService.cancelTask ?? aiWritingProfilesService.cancel
      const value = await cancel(id)

      addTask(value)

      return value
    }
    catch (reason) {
      error.value = formatAiError(reason, 'Không hủy được tác vụ.')

      return null
    }
    finally { cancellingId.value = '' }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Ẩn task terminal khỏi popup theo thao tác người dùng.
   * =====================================================================
   * Input: không có. Output: chỉ còn task active/chưa ẩn trong view hiện tại.
   * Side effect: không xóa database; lần tải lại vẫn đọc lại từ API.
   * =====================================================================
   */
  function clearFinished() {
    dismissedIds.value = new Set([
      ...dismissedIds.value,
      ...tasks.value.filter(task => TERMINAL_AI_TASK_STATUSES.includes(task.status)).map(task => task.id),
    ])
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Khởi động queue tại Admin layout và lắng nghe task mới.
   * =====================================================================
   * Input: không có. Output: danh sách đã tải và polling khi cần.
   * Side effect: GET ban đầu, listener CustomEvent và timer lifecycle.
   * =====================================================================
   */
  function start() {
    if (started || disposed) return
    started = true
    if (typeof window !== 'undefined') window.addEventListener(AI_TASK_QUEUED_EVENT, onQueued)
    if (typeof window !== 'undefined' && Array.isArray(window.__aiTaskQueuePending)) {
      window.__aiTaskQueuePending.splice(0).forEach(detail => addTask(detail))
    }
    void refresh()
  }

  /** Nhận event accepted; API refresh hỗ trợ cả response cũ chưa có tracker UUID. */
  function onQueued(event) {
    addTask(event.detail)
    void refresh()
  }

  onScopeDispose(() => {
    disposed = true
    if (typeof window !== 'undefined') window.removeEventListener(AI_TASK_QUEUED_EVENT, onQueued)
    stop()
  })

  return {
    tasks: visibleTasks,
    filteredTasks,
    filters,
    currentFilter,
    activeCount,
    loading,
    cancellingId,
    error,
    polling,
    start,
    stop,
    refresh,
    addTask,
    cancelTask,
    clearFinished,
  }
}
