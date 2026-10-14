/**
 * =====================================================================
 * CHỨC NĂNG FILE: State, mutation và polling Resource Template
 * =====================================================================
 * CÁC HÀM/METHOD: load/process/update/screenPreview/preview, startPolling,
 * stopPolling/reset: đọc API, bỏ response stale và dọn timer khi đổi version.
 * INPUT: version id/ref/getter và API service.
 * OUTPUT: refs/methods cho panel; lỗi HTTP giữ nguyên, không mutate props.
 * =====================================================================
 */
import { computed, getCurrentInstance, onBeforeUnmount, ref, toValue, watch } from 'vue'
import { resourceTemplateService } from '@/services/resourceTemplate'

const TERMINAL_STATUSES = new Set(['ready', 'failed', 'cancelled'])

/** INPUT: version id reactive/plain. OUTPUT: lifecycle refs; timer dọn khi unmount. */
export function useResourceTemplate(versionId, service = resourceTemplateService) {
  const payload = ref(null)
  const loading = ref(false)
  const mutating = ref(false)
  const error = ref(null)
  const polling = ref(false)
  let sequence = 0
  let timer = null
  let disposed = false
  let pollFailures = 0

  const template = computed(() => payload.value?.template ?? null)
  const capabilities = computed(() => payload.value?.capabilities ?? {})
  const status = computed(() => template.value?.processing_status ?? null)
  const isTerminal = computed(() => TERMINAL_STATUSES.has(status.value))

  /** INPUT: không có. OUTPUT: dừng timer, không ghi API. */
  const stopPolling = () => {
    polling.value = false
    clearTimeout(timer)
    timer = null
  }

  /** INPUT: đổi id/unmount. OUTPUT: invalidate request cũ và state của version trước. */
  const reset = () => {
    sequence++
    stopPolling()
    payload.value = null
    error.value = null
    loading.value = false
  }

  /** INPUT: silent. OUTPUT: payload mới hoặc null nếu response đã stale. */
  const load = async ({ silent = false } = {}) => {
    const requestId = ++sequence
    const id = toValue(versionId)
    if (!id || disposed)
      return null
    if (!silent)
      loading.value = true
    error.value = null

    try {
      const result = await service.show(id)
      if (disposed || requestId !== sequence || id !== toValue(versionId))
        return null
      payload.value = result

      return result
    }
    catch (requestError) {
      if (requestId === sequence && !disposed)
        error.value = requestError
      throw requestError
    }
    finally {
      if (requestId === sequence && !silent)
        loading.value = false
    }
  }

  /** INPUT: timer tick. OUTPUT: poll tiếp khi active, dừng terminal/ba lỗi liên tiếp. */
  const poll = async () => {
    if (!polling.value || disposed)
      return
    try {
      await load({ silent: true })
      pollFailures = 0
      if (isTerminal.value) {
        stopPolling()

        return
      }
    }
    catch {
      pollFailures++
      if (pollFailures >= 3)
        stopPolling()
    }
    if (polling.value)
      timer = setTimeout(() => void poll(), 2000)
  }

  /** INPUT: state hiện tại. OUTPUT: chỉ tạo timer cho run đang pending/processing. */
  const startPolling = () => {
    stopPolling()
    pollFailures = 0
    if (!isTerminal.value) {
      polling.value = true
      timer = setTimeout(() => void poll(), 1000)
    }
  }

  /** INPUT: callback mutation. OUTPUT: state mới; request cũ không overwrite mutation. */
  const mutate = async callback => {
    if (mutating.value)
      return null
    mutating.value = true
    stopPolling()
    const id = toValue(versionId)
    sequence++
    error.value = null
    try {
      await callback(id)
      if (id !== toValue(versionId) || disposed)
        return null
      const result = await load()
      startPolling()

      return result
    }
    catch (requestError) {
      if (id === toValue(versionId))
        error.value = requestError
      throw requestError
    }
    finally {
      mutating.value = false
    }
  }

  /** INPUT: không có. OUTPUT: queued state, tracker được nhận ở API. */
  const process = () => mutate(id => service.process(id))
  /** INPUT: entry/screens bounded. OUTPUT: detail đã kiểm gate lại. */
  const update = settings => mutate(id => service.update(id, settings))
  /** INPUT: screen id/asset id nullable. OUTPUT: detail sau replace/detach screenshot. */
  const screenPreview = (screenId, assetId) => mutate(id => service.screenPreview(id, screenId, assetId))
  /** INPUT: không có. OUTPUT: grant URL; caller quyết định mở iframe/tab. */
  const preview = () => service.preview(toValue(versionId))

  watch(() => toValue(versionId), reset, { flush: 'sync' })
  if (getCurrentInstance()) {
    onBeforeUnmount(() => {
      disposed = true
      reset()
    })
  }

  return {
    payload, template, capabilities, status, isTerminal, loading, mutating,
    polling, error, load, process, update, preview, screenPreview,
    startPolling, stopPolling,
  }
}
