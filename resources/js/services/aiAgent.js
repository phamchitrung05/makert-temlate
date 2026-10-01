/* eslint-disable camelcase */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client AI Agent dùng chung cho mọi tài nguyên.
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), fallbackCapabilities(),
 * capabilities(), createSession(), status(), regenerate(), retry(), cancel(),
 * applyCandidate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): target/capability/request -> session,
 * candidate hoặc lỗi API chuẩn hóa; không chứa logic nghiệp vụ của Post.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: response API. Output: payload bên trong envelope success/data. */
const unwrap = response => response?.success && 'data' in response ? response.data : response

/** Input: lỗi $api. Output: true chỉ khi route generic chưa tồn tại. */
const isMissingRoute = error => [404, 405].includes(Number(
  error?.status ?? error?.statusCode ?? error?.response?.status ?? error?.data?.status,
))

/**
 * Input: target type cần dùng khi backend chưa cung cấp capability.
 * Output: capability tối thiểu để UI vẫn hoạt động với API Post hiện tại.
 */
const fallbackCapabilities = targetType => ({
  target_type: targetType,
  operations: ['create', 'rewrite', 'translate', 'regenerate'],
  input_types: ['url', 'text', 'existing_record'],
  outputs: targetType === 'post'
    ? ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail']
    : ['title', 'description', 'content', 'metadata'],
  prompts: [
    { key: `${targetType}.create.from_url`, label: 'Tạo nội dung từ nguồn', operation: 'create' },
    { key: `${targetType}.rewrite.technical`, label: 'Viết lại theo hướng kỹ thuật', operation: 'rewrite' },
    { key: `${targetType}.summarize`, label: 'Tóm tắt súc tích', operation: 'summarize' },
  ],
  providers: [
    { key: 'deterministic', label: 'Hệ thống (không cần API key)', models: ['deterministic'] },
    { key: 'openai', label: 'OpenAI', models: [] },
    { key: 'gemini', label: 'Google Gemini', models: [] },
  ],
})

export const aiAgentService = {
  /** Input: target type. Output: capability theo target hoặc fallback an toàn. */
  async capabilities(targetType) {
    try {
      return unwrap(await $api(`/admin/ai-agent/capabilities/${targetType}`))
    }
    catch {
      return fallbackCapabilities(targetType)
    }
  },

  /** Input: session request. Output: session/job đang xử lý. */
  async createSession(payload) {
    try {
      return unwrap(await $api('/admin/ai-agent/sessions', { method: 'POST', body: payload }))
    }
    catch (error) {
      if (!isMissingRoute(error) || payload.target_type !== 'post' || (!payload.input?.url && !payload.input?.text))
        throw error

      const legacyPayload = {
        language: payload.output_language ?? 'vi',
        generate_thumbnail: payload.requested_outputs?.includes('thumbnail') ?? false,
        instructions: payload.instructions,
        ...(payload.input.url ? { url: payload.input.url } : { text: payload.input.text }),
        ...(payload.provider ? { provider: payload.provider } : {}),
        ...(payload.model ? { model: payload.model } : {}),
      }

      return unwrap(await $api('/admin/posts/ai/import', {
        method: 'POST',
        body: legacyPayload,
      }))
    }
  },

  /** Input: session/job id. Output: tiến trình và candidate hiện tại. */
  async status(sessionId, targetType = 'post') {
    try {
      return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}`))
    }
    catch (error) {
      if (!isMissingRoute(error) || targetType !== 'post')
        throw error

      return unwrap(await $api(`/admin/posts/ai/import/${sessionId}`))
    }
  },

  /** Input: session và yêu cầu tạo lại. Output: run/candidate mới. */
  async regenerate(sessionId, payload) {
    try {
      return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/regenerate`, { method: 'POST', body: payload }))
    }
    catch (error) {
      if (!isMissingRoute(error)) throw error

      return unwrap(await $api(`/admin/posts/ai/import/${sessionId}/regenerate`, {
        method: 'POST',
        body: payload,
      }))
    }
  },

  /** Input: session/job id lỗi. Output: run được đưa lại vào queue. */
  async retry(sessionId) {
    try {
      return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/retry`, { method: 'POST' }))
    }
    catch (error) {
      if (!isMissingRoute(error)) throw error

      return unwrap(await $api(`/admin/posts/ai/import/${sessionId}/retry`, { method: 'POST' }))
    }
  },

  /** Input: session id. Output: trạng thái hủy. */
  async cancel(sessionId) {
    try {
      return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/cancel`, { method: 'POST' }))
    }
    catch (error) {
      if (!isMissingRoute(error)) throw error

      return unwrap(await $api(`/admin/posts/ai/import/${sessionId}/cancel`, { method: 'POST' }))
    }
  },

  /** Input: candidate và các field được chọn. Output: resource đã áp dụng. */
  async applyCandidate(candidateId, payload = {}) {
    try {
      return unwrap(await $api(`/admin/ai-agent/candidates/${candidateId}/apply`, { method: 'POST', body: payload }))
    }
    catch (error) {
      if (!isMissingRoute(error)) throw error

      return unwrap(await $api(`/admin/posts/ai/import/${candidateId}/apply`, { method: 'POST', body: payload }))
    }
  },
}

export { fallbackCapabilities }
