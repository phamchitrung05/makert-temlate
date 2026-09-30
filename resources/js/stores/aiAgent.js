/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia state cho AI Agent session/candidate dùng chung.
 * CÁC HÀM/METHOD TRONG FILE: loadCapabilities(), start(), poll(),
 * regenerate(), cancel(), apply().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request AI -> state readonly và các
 * action điều phối API; UI không tự quản lý polling hoặc retry.
 * =====================================================================
 */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { aiAgentService } from '@/services/aiAgent'

export const useAiAgentStore = defineStore('aiAgent', () => {
  const capabilities = shallowRef(null)
  const session = shallowRef(null)
  const candidates = shallowRef([])
  const isLoading = shallowRef(false)
  const error = shallowRef(null)
  const isRunning = computed(() => ['queued', 'running', 'processing'].includes(session.value?.status))

  const loadCapabilities = async targetType => {
    capabilities.value = await aiAgentService.capabilities(targetType)

    return capabilities.value
  }

  const start = async request => {
    isLoading.value = true
    error.value = null
    try {
      session.value = await aiAgentService.createSession(request)
      syncCandidates(session.value)

      return session.value
    }
    catch (requestError) {
      error.value = requestError
      throw requestError
    }
    finally {
      isLoading.value = false
    }
  }

  const poll = async (sessionId, targetType) => {
    session.value = await aiAgentService.status(sessionId, targetType)
    syncCandidates(session.value)

    return session.value
  }

  const regenerate = async (sessionId, request) => {
    isLoading.value = true
    error.value = null
    try {
      const response = await aiAgentService.regenerate(sessionId, request)

      session.value = response
      syncCandidates(response)

      return response
    }
    catch (requestError) {
      error.value = requestError
      throw requestError
    }
    finally {
      isLoading.value = false
    }
  }

  const cancel = async sessionId => {
    const response = await aiAgentService.cancel(sessionId)

    session.value = { ...session.value, ...response, status: response?.status ?? 'cancelled' }

    return response
  }

  const apply = async (candidateId, payload) => aiAgentService.applyCandidate(candidateId, payload)

  const syncCandidates = value => {
    const next = Array.isArray(value?.candidates) ? value.candidates : value?.candidate ? [value.candidate] : value?.draft ? [{ id: value.id ?? value.job_id, outputs: value.draft, provider: value.provider, model: value.model }] : []

    candidates.value = next
  }

  const reset = () => {
    session.value = null
    candidates.value = []
    error.value = null
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
    cancel,
    apply,
    reset,
  }
})
