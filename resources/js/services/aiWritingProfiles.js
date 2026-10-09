/* eslint-disable camelcase -- Query phân trang dùng field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kết nối API mẫu văn phong và preview nguồn hiện có.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - unwrap(): lấy data khỏi BaseResponse thành công.
 * - createAnalysis(): tạo analysis văn phong.
 * - analysis(): đọc detail/result analysis.
 * - cancel(): hủy analysis API cũ.
 * - listAnalyses(): giữ API list analysis cũ cho tương thích.
 * - listTaskRuns(), cancelTask(): đọc/hủy tracker queue dùng chung.
 * - previewSource(): preview URL/HTML/file qua extractor backend.
 * - save(), profile(), list(), findProfiles(): CRUD/list profile và xác minh save.
 * - options(), remove(), updateDefault(): catalog, xóa profile và cập nhật mặc định.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload đã duyệt, UUID/ID và AbortSignal tùy chọn.
 * - OUTPUT: DTO công khai từ BaseResponse hoặc lỗi HTTP gốc.
 * - SIDE EFFECT: gọi Admin API; không gọi model trực tiếp, không tự retry POST.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/**
 * =====================================================================
 * CHỨC NĂNG: Lấy data từ envelope thành công.
 * Input: API response. Output: DTO; không sửa response hoặc gọi network.
 * =====================================================================
 */
const unwrap = response => response?.success && 'data' in response ? response.data : response

export const aiWritingProfilesService = {
  /**
   * =====================================================================
   * CHỨC NĂNG: Queue phân tích bài mẫu theo thao tác người dùng.
   * Input: name/reference_text/model_id. Output: analysis UUID và lifecycle.
   * SIDE EFFECT: POST một lần; không gửi lại tự động khi timeout.
   * =====================================================================
   */
  async createAnalysis(payload) {
    return unwrap(await $api('/admin/ai/writing-profiles/analyses', { method: 'POST', body: payload, retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc tiến trình/kết quả của analysis đã có.
   * Input: UUID và signal. Output: status/result/errors an toàn.
   * SIDE EFFECT: GET; không tạo tác vụ mới.
   * =====================================================================
   */
  async analysis(id, signal) {
    return unwrap(await $api(`/admin/ai/writing-profiles/analyses/${id}`, { signal, retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Yêu cầu hủy tác vụ hiện tại.
   * Input: UUID. Output: trạng thái server sau yêu cầu hủy.
   * SIDE EFFECT: POST; không thể thu hồi request upstream đã gửi.
   * =====================================================================
   */
  async cancel(id) {
    return unwrap(await $api(`/admin/ai/writing-profiles/analyses/${id}/cancel`, { method: 'POST', retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc danh sách analysis của actor cho trung tâm tác vụ AI.
   * =====================================================================
   * Input: page/per_page/status và AbortSignal tùy chọn.
   * Output: envelope có data summary và meta.pagination; không trả source/result.
   * Side effect: chỉ GET; backend scope theo user hiện tại.
   * =====================================================================
   */
  async listAnalyses(filters = {}, signal) {
    return $api('/admin/ai/writing-profiles/analyses', { query: filters, signal, retry: 0 })
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc tracker task dùng chung cho toàn bộ AI modules.
   * =====================================================================
   * Input: page/per_page/status/task_type và AbortSignal tùy chọn.
   * Output: envelope summary đã scope owner/quyền, không có payload nghiệp vụ.
   * Side effect: chỉ GET; không tạo hoặc thay đổi task.
   * =====================================================================
   */
  async listTaskRuns(filters = {}, signal) {
    return $api('/admin/ai/tasks', { query: filters, signal, retry: 0 })
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Yêu cầu hủy task dùng chung theo tracker UUID.
   * =====================================================================
   * Input: tracker UUID. Output: status sau khi adapter xử lý cancellation.
   * Side effect: POST một lần; không retry tự động hoặc gọi provider.
   * =====================================================================
   */
  async cancelTask(id) {
    return unwrap(await $api(`/admin/ai/tasks/${id}/cancel`, { method: 'POST', retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Dùng extractor backend để preview URL/HTML/file HTML.
   * Input: JSON nguồn hoặc FormData và signal. Output: source snapshot.
   * SIDE EFFECT: đọc nguồn qua API đang có, yêu cầu quyền target Post.
   * =====================================================================
   */
  async previewSource(payload, signal) {
    return unwrap(await $api('/admin/ai-agent/source-preview', { method: 'POST', body: payload, signal, retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Người dùng duyệt tạo mẫu hoặc cập nhật mẫu vừa lưu.
   * Input: payload whitelist và ID tùy chọn; update cần version.
   * Output: profile có ID/version server. SIDE EFFECT: ghi DB qua API.
   * =====================================================================
   */
  async save(payload, id = null) {
    return unwrap(await $api(id ? `/admin/ai/writing-profiles/${id}` : '/admin/ai/writing-profiles', {
      method: id ? 'PUT' : 'POST', body: payload, retry: 0,
    }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải version mới khi người dùng muốn xử lý xung đột.
   * Input: profile ID. Output: profile hiện tại; chỉ đọc database.
   * =====================================================================
   */
  async profile(id, signal) {
    return unwrap(await $api(`/admin/ai/writing-profiles/${id}`, { signal, retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc mẫu đang bật và mặc định để chọn khi viết bài.
   * Input: AbortSignal tùy chọn. Output: items và default_writing_profile_id.
   * SIDE EFFECT: GET catalog; không đòi quyền quản lý AI của người viết Post.
   * =====================================================================
   */
  async options(signal) {
    return unwrap(await $api('/admin/ai/writing-profiles/options', { signal, retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Xóa mẫu đã xác nhận theo phiên bản đang xem.
   * Input: ID/version. Output: response hoặc lỗi 409; snapshot run cũ được giữ.
   * SIDE EFFECT: DELETE một lần; backend tự gỡ mặc định khi cần.
   * =====================================================================
   */
  async remove(id, version) {
    return unwrap(await $api(`/admin/ai/writing-profiles/${id}`, { method: 'DELETE', body: { version }, retry: 0 }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc danh sách mẫu văn phong cùng tổng số dòng và phân trang server.
   * Input: page/per_page/search và AbortSignal tùy chọn.
   * Output: envelope data/meta.pagination nguyên vẹn; lỗi HTTP truyền cho caller.
   * SIDE EFFECT: chỉ GET database qua API; không gọi model hoặc ghi profile.
   * =====================================================================
   */
  async list(filters = {}, signal) {
    return $api('/admin/ai/writing-profiles', { query: filters, signal, retry: 0 })
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Kiểm tra kết quả lưu chưa rõ, không tự POST tạo mẫu lần nữa.
   * Input: tên mẫu cần tìm. Output: envelope list giữ pagination để không giả định đủ.
   * SIDE EFFECT: chỉ GET list để xác minh kết quả lưu, không tạo lại profile.
   * =====================================================================
   */
  async findProfiles(name) {
    return aiWritingProfilesService.list({ search: name, per_page: 100 })
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đặt/gỡ văn phong mặc định bằng Settings partial.
   * Input: default_writing_profile_id hoặc null. Output: Settings đã lưu.
   * SIDE EFFECT: PUT chỉ field default; không gọi model hoặc đổi setting khác.
   * =====================================================================
   */
  async updateDefault(payload) {
    return unwrap(await $api('/admin/settings/ai/settings', { method: 'PUT', body: payload, retry: 0 }))
  },
}
