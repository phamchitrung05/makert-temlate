/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia state cho AI Agent session/candidate dùng chung.
 * CÁC HÀM/METHOD TRONG FILE: loadCapabilities(), start(), poll(),
 * regenerate(), retry(), cancel(), apply(), syncCandidates(), reset().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request AI -> state readonly và các
 * action điều phối API; bỏ response cũ khi dialog reset hoặc chuyển run.
 * =====================================================================
 */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { aiAgentService } from '@/services/aiAgent'
import { isAiSuccess } from '@/utils/aiErrors'

export const useAiAgentStore = defineStore('aiAgent', () => {
  const capabilities = shallowRef(null)
  const session = shallowRef(null)
  const candidates = shallowRef([])
  const isLoading = shallowRef(false)
  const error = shallowRef(null)
  let resetGeneration = 0
  let capabilityGeneration = 0
  const isRunning = computed(() => ['queued', 'running', 'processing', 'fetching', 'extracting', 'rewriting', 'seo', 'thumbnail'].includes(session.value?.status))

  /** Input: target key. Output: capability allowlist được lưu vào state. */
  const loadCapabilities = async targetType => {
    const generation = ++capabilityGeneration
    const response = await aiAgentService.capabilities(targetType)
    if (generation === capabilityGeneration) capabilities.value = response

    return response
  }

  /** Input: request target/input/provider. Output: session queued từ API. */
  const start = async request => {
    const generation = resetGeneration

    isLoading.value = true
    error.value = null
    try {
      const response = await aiAgentService.createSession(request)
      if (generation === resetGeneration) {
        session.value = response
        syncCandidates(response)
      }

      return response
    }
    catch (requestError) {
      if (generation === resetGeneration) error.value = requestError
      throw requestError
    }
    finally {
      if (generation === resetGeneration) isLoading.value = false
    }
  }

  /** Input: session ID và target. Output: lifecycle/candidate mới nhất. */
  const poll = async (sessionId, targetType) => {
    const generation = resetGeneration
    const currentSession = session.value
    const response = await aiAgentService.status(sessionId, targetType)
    if (generation === resetGeneration && session.value === currentSession) {
      session.value = response
      syncCandidates(response)
    }

    return response
  }

  /** Input: run cha/request. Output: child mới; bỏ response nếu dialog đã reset. */
  const regenerate = async (sessionId, request) => {
    const generation = resetGeneration

    isLoading.value = true
    error.value = null
    try {
      const response = await aiAgentService.regenerate(sessionId, request)

      if (generation === resetGeneration) {
        session.value = response
        syncCandidates(response)
      }

      return response
    }
    catch (requestError) {
      if (generation === resetGeneration) error.value = requestError
      throw requestError
    }
    finally {
      if (generation === resetGeneration) isLoading.value = false
    }
  }

  /** Input: session/job lỗi. Output: trạng thái queue sau retry kỹ thuật. */
  const retry = async sessionId => {
    const generation = resetGeneration

    isLoading.value = true
    error.value = null
    try {
      const response = await aiAgentService.retry(sessionId)
      if (generation === resetGeneration) {
        session.value = response
        syncCandidates(response)
      }

      return response
    }
    catch (requestError) {
      if (generation === resetGeneration) error.value = requestError
      throw requestError
    }
    finally {
      if (generation === resetGeneration) isLoading.value = false
    }
  }

  /** Input: session/job ID. Output: session cancelled hoặc terminal. */
  const cancel = async sessionId => {
    const generation = resetGeneration
    const response = await aiAgentService.cancel(sessionId)

    if (generation === resetGeneration) session.value = { ...session.value, ...response, status: response?.status ?? 'cancelled' }

    return response
  }

  /** Input: candidate ID và field target. Output: resource đã apply từ API. */
  const apply = async (candidateId, payload) => aiAgentService.applyCandidate(candidateId, payload)

  /** Input: response session/run. Output: merge candidate lineage theo ID. */
  const syncCandidates = value => {
    if (!isAiSuccess(value)) return
    const next = Array.isArray(value?.candidates) ? value.candidates : value?.candidate ? [value.candidate] : value?.draft ? [{ id: value.id ?? value.job_id, outputs: value.draft, provider: value.provider, model: value.model, ['prompt_key']: value['prompt_key'], ['prompt_version']: value['prompt_version'], source: value.source }] : []

    const byId = new Map(candidates.value.map(candidate => [candidate.id, candidate]))

    next.filter(candidate => !candidate.status || isAiSuccess(candidate))
      .forEach(candidate => byId.set(candidate.id, { ...candidate, status: candidate.status ?? value.status }))
    candidates.value = [...byId.values()]
  }

  /** Input: không có. Output: xóa session/candidate/error trong state. */
  const reset = () => {
    resetGeneration++
    session.value = null
    candidates.value = []
    error.value = null
    isLoading.value = false
  }

  return {
    capabilities: readonly(capabilities),
    session: readonly(session),
    candidates: readonly(candidates),
    isLoading: readonly(isLoading),
    error: readonly(error),
    isRunning,
    loadCapabilities,
    start,
    poll,
    regenerate,
    retry,
    cancel,
    apply,
    reset,
  }
})
