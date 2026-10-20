/* eslint-disable camelcase */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client AI Agent dùng chung cho mọi tài nguyên.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), generationCapabilities(),
 * capabilities(), listSessions(), createSession(), status(), regenerate(), retry(), cancel(),
 * review(), quality(), rescoreQuality(), reviewHistory(), approveCandidate(), rejectCandidate(),
 * applyCandidate(), targets(), updateCandidate(), removeSession().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): target/capability/request -> session,
 * candidate hoặc lỗi API chuẩn hóa; không chứa logic nghiệp vụ của Post.
 * =====================================================================
 */
import { $api } from '@/utils/api'
import { withoutAiTaxonomyOutputs } from '@/utils/aiContentInput'

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
 * CHỨC NĂNG: Giữ output registry backend và loại nhóm taxonomy khỏi lựa chọn AI.
 * =====================================================================
 * INPUT: capability/target DTO API.
 * OUTPUT: DTO mới chỉ có nhóm AI hợp lệ, giữ các field khác từ backend.
 * SIDE EFFECT: hàm thuần; không tạo lựa chọn taxonomy hoặc gọi API.
 * =====================================================================
 */
const generationCapabilities = capability => ({
  ...capability,
  ...(Array.isArray(capability?.outputs) ? { outputs: withoutAiTaxonomyOutputs(capability.outputs) } : {}),
  ...(Array.isArray(capability?.output_options) ? { output_options: capability.output_options
    .filter(option => withoutAiTaxonomyOutputs([option.value]).length) } : {}),
  ...(Array.isArray(capability?.platform_profiles) ? {
    platform_profiles: capability.platform_profiles.map(profile => ({
      ...profile,
      ...(Array.isArray(profile.outputs) ? { outputs: withoutAiTaxonomyOutputs(profile.outputs) } : {}),
      ...(Array.isArray(profile.output_options) ? { output_options: profile.output_options
        .filter(option => withoutAiTaxonomyOutputs([option.value]).length) } : {}),
    })),
  } : {}),
})

export const aiAgentService = {
  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc target catalog và loại nhóm taxonomy khỏi lựa chọn AI.
   * =====================================================================
   * INPUT: không có.
   * OUTPUT: target catalog công khai theo config backend.
   * SIDE EFFECT: GET Admin API; không gọi provider.
   * =====================================================================
   */
  async targets() {
    return unwrap(await $api('/admin/ai-agent/targets')).map(generationCapabilities)
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Lưu sửa tay cho candidate với version kỳ vọng.
   * =====================================================================
   * INPUT: UUID và field/version đã sửa.
   * OUTPUT: candidate đã lưu hoặc lỗi conflict/validation từ backend.
   * SIDE EFFECT: PATCH Admin API; không gọi provider.
   * =====================================================================
   */
  async updateCandidate(id, payload) {
    return unwrap(await $api(`/admin/ai-agent/candidates/${id}`, { method: 'PATCH', body: payload }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Xóa candidate và asset không còn sử dụng.
   * =====================================================================
   * INPUT: UUID candidate.
   * OUTPUT: response xóa từ backend.
   * SIDE EFFECT: DELETE Admin API; domain đã apply vẫn được giữ.
   * =====================================================================
   */
  async removeSession(id) {
    return unwrap(await $api(`/admin/ai-agent/sessions/${id}`, { method: 'DELETE' }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc danh sách session có phân trang.
   * =====================================================================
   * INPUT: query page/per_page và bộ lọc.
   * OUTPUT: summary và metadata phân trang từ backend.
   * SIDE EFFECT: GET Admin API; không gọi provider.
   * =====================================================================
   */
  async listSessions(query = {}) {
    return $api('/admin/ai-agent/sessions', { query })
  },

  /** Input: target type và platform tùy chọn. Output: prompt/output/provider options public; chỉ GET. */
  async capabilities(targetType, platform = null) {
    const response = platform
      ? await $api(`/admin/ai-agent/capabilities/${targetType}`, { query: { platform } })
      : await $api(`/admin/ai-agent/capabilities/${targetType}`)

    return generationCapabilities(unwrap(response))
  },

  /** Input: target/input/options và requested_outputs. Output: session queued; backend quản lý model/queue. */
  async createSession(payload) {
    return unwrap(await $api('/admin/ai-agent/sessions', { method: 'POST', body: payload }))
  },

  /** Input: session UUID. Output: tiến trình/result candidate; chỉ GET, không ghi Post. */
  async status(sessionId) {
    return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}`))
  },

  /** Input: session UUID và field/prompt/model override. Output: run child mới, giữ lịch sử candidate cũ. */
  async regenerate(sessionId, payload) {
    return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/regenerate`, { method: 'POST', body: payload }))
  },

  /** Input: session UUID lỗi. Output: run đã requeue, giữ nguyên UUID; không retry provider trong browser. */
  async retry(sessionId) {
    return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/retry`, { method: 'POST' }))
  },

  /** Input: session UUID. Output: trạng thái cancelled hoặc terminal hiện tại. */
  async cancel(sessionId) {
    return unwrap(await $api(`/admin/ai-agent/sessions/${sessionId}/cancel`, { method: 'POST' }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Duyệt candidate thành bản nháp mới qua endpoint approve duy nhất.
   * =====================================================================
   * INPUT: candidate UUID, fields, expected_version và expected_review_version.
   * OUTPUT: post_id/social_post_id, fields và review đã duyệt.
   * SIDE EFFECT: POST Admin API; backend kiểm owner/version/quality gate.
   * EXCEPTION/TRANSACTION: lỗi validation/conflict truyền lên caller; POST không tự retry.
   * =====================================================================
   */
  async applyCandidate(candidateId, payload = {}) {
    return this.approveCandidate(candidateId, payload)
  },

  /** Input: UUID và AbortSignal. Output: nguồn snapshot/draft/review có quyền; chỉ GET. */
  async review(candidateId, options = {}) {
    return unwrap(await $api(`/admin/ai-agent/candidates/${candidateId}/review`, { ...options, retry: 0 }))
  },

  /** Input: UUID và AbortSignal. Output: điểm/evidence/cổng evaluator; chỉ GET. */
  async quality(candidateId, options = {}) {
    return unwrap(await $api(`/admin/ai-agent/candidates/${candidateId}/quality`, { ...options, retry: 0 }))
  },

  /** Input: UUID candidate. Output: evaluation queued; POST không tự retry. */
  async rescoreQuality(candidateId) {
    return unwrap(await $api(`/admin/ai-agent/candidates/${candidateId}/quality/rescore`, { method: 'POST', retry: 0 }))
  },

  /** Input: UUID/page và AbortSignal. Output: lịch sử cùng meta.pagination; không gọi AI. */
  async reviewHistory(candidateId, query = {}, options = {}) {
    return $api(`/admin/ai-agent/candidates/${candidateId}/review/history`, { ...options, query, retry: 0 })
  },

  /** Input: UUID/fields/hai version/lý do. Output: Post draft đã duyệt; POST không tự retry. */
  async approveCandidate(candidateId, payload) {
    return unwrap(await $api(`/admin/ai-agent/candidates/${candidateId}/approve`, { method: 'POST', body: payload, retry: 0 }))
  },

  /** Input: UUID/hai version/lý do bắt buộc. Output: rejected; POST không tự retry. */
  async rejectCandidate(candidateId, payload) {
    return unwrap(await $api(`/admin/ai-agent/candidates/${candidateId}/reject`, { method: 'POST', body: payload, retry: 0 }))
  },
}
