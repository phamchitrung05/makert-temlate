/* eslint-disable camelcase */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client AI Agent dùng chung cho mọi tài nguyên.
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), fallbackCapabilities(),
 * capabilities(), listSessions(), createSession(), status(), regenerate(), retry(), cancel(),
 * applyCandidate().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): target/capability/request -> session,
 * candidate hoặc lỗi API chuẩn hóa; không chứa logic nghiệp vụ của Post.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/**
 * =====================================================================
 * CHỨC NĂNG: Bỏ envelope AI Agent response
 * =====================================================================
 * INPUT: Response success/data hoặc payload trực tiếp.
 * OUTPUT: Payload AI Agent.
 * SIDE EFFECT: Hàm thuần; không mutate response.
 * EXCEPTION/TRANSACTION: Không xử lý lỗi network.
 * =====================================================================
 */
const unwrap = response => response?.success && 'data' in response ? response.data : response

/**
 * =====================================================================
 * CHỨC NĂNG: Nhận diện backend chưa có route generic để dùng tương thích cũ
 * =====================================================================
 * INPUT: Lỗi từ API.
 * OUTPUT: true chỉ với HTTP 404 hoặc 405.
 * SIDE EFFECT: Hàm thuần; không gửi request.
 * EXCEPTION/TRANSACTION: Không fallback cho lỗi auth, validation hoặc lỗi provider.
 * =====================================================================
 */
const isMissingRoute = error => [404, 405].includes(Number(
  error?.status ?? error?.statusCode ?? error?.response?.status ?? error?.data?.status,
))

/**
 * =====================================================================
 * CHỨC NĂNG: Cấp capability tối thiểu cho backend Post cũ
 * =====================================================================
 * INPUT: Target type.
 * OUTPUT: Capability tương thích để UI vẫn hoạt động.
 * SIDE EFFECT: Chỉ tạo DTO public; không chứa key hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không dùng khi API đã trả lỗi permission/provider.
 * =====================================================================
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
  /** Input: page/per_page. Output: summary + meta; GET Admin API, không gọi provider. */
  async listSessions(query = {}) {
    return $api('/admin/ai-agent/sessions', { query })
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc capability target và fallback nếu route chưa tồn tại
   * =====================================================================
   * INPUT: Target type.
   * OUTPUT: Prompt/output/provider options public.
   * SIDE EFFECT: Gọi GET Admin API; có thể dùng DTO fallback.
   * EXCEPTION/TRANSACTION: Lỗi khác 404/405 được truyền lên caller.
   * =====================================================================
   */
  async capabilities(targetType) {
    try {
      return unwrap(await $api(`/admin/ai-agent/capabilities/${targetType}`))
    }
    catch (error) {
      if (!isMissingRoute(error))
        throw error

      return fallbackCapabilities(targetType)
    }
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Tạo session nội dung qua AI Agent hoặc API Post tương thích
   * =====================================================================
   * INPUT: Target/input/options và requested outputs.
   * OUTPUT: Session/job queued.
   * SIDE EFFECT: Gọi POST Admin API; backend quản lý run, model resolution và queue.
   * EXCEPTION/TRANSACTION: Chỉ fallback route cho target Post hợp lệ; lỗi khác truyền lên caller.
   * =====================================================================
   */
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
        ...(payload.model_id ? { model_id: payload.model_id } : {}),
      }

      return unwrap(await $api('/admin/posts/ai/import', {
        method: 'POST',
        body: legacyPayload,
      }))
    }
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc trạng thái session hoặc job Post
   * =====================================================================
   * INPUT: Session UUID và target type.
   * OUTPUT: Tiến trình/result candidate.
   * SIDE EFFECT: Gọi GET Admin API, không ghi Post.
   * EXCEPTION/TRANSACTION: Chỉ fallback route cho Post; lỗi khác truyền lên caller.
   * =====================================================================
   */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Tạo candidate mới và giữ lịch sử candidate cũ
   * =====================================================================
   * INPUT: Session UUID và request field/prompt/model override.
   * OUTPUT: Run/candidate child mới.
   * SIDE EFFECT: Gọi POST Admin API.
   * EXCEPTION/TRANSACTION: Chỉ fallback khi route generic chưa có; lỗi khác truyền lên caller.
   * =====================================================================
   */
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

  /**
   * =====================================================================
   * CHỨC NĂNG: Đưa lại session lỗi vào queue
   * =====================================================================
   * INPUT: Session/job UUID.
   * OUTPUT: Run đã requeue, giữ nguyên UUID.
   * SIDE EFFECT: Gọi POST Admin API; backend cập nhật lifecycle.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller; không retry provider ngay trong browser.
   * =====================================================================
   */
  async retry(sessionId) {
    try {
      return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/retry`, { method: 'POST' }))
    }
    catch (error) {
      if (!isMissingRoute(error)) throw error

      return unwrap(await $api(`/admin/posts/ai/import/${sessionId}/retry`, { method: 'POST' }))
    }
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Yêu cầu hủy run đang chạy
   * =====================================================================
   * INPUT: Session UUID.
   * OUTPUT: Trạng thái cancelled hoặc terminal hiện tại.
   * SIDE EFFECT: Gọi POST Admin API; backend dọn asset tạm theo usage.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async cancel(sessionId) {
    try {
      return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/cancel`, { method: 'POST' }))
    }
    catch (error) {
      if (!isMissingRoute(error)) throw error

      return unwrap(await $api(`/admin/posts/ai/import/${sessionId}/cancel`, { method: 'POST' }))
    }
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Lưu các field được chọn của candidate vào tài nguyên
   * =====================================================================
   * INPUT: Candidate UUID, fields và target tùy chọn.
   * OUTPUT: Resource đã áp dụng ở dạng draft.
   * SIDE EFFECT: Gọi POST Admin API; backend kiểm tra ownership/version và ghi provenance.
   * EXCEPTION/TRANSACTION: Lỗi validation/conflict/API truyền lên caller.
   * =====================================================================
   */
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
