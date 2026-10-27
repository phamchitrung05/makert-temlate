/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client cho trang Lịch Đăng Bài (post-schedule).
 * =====================================================================
 * Service bọc endpoint PostScheduleController: đọc/ghi cấu hình hệ thống
 * marketing_schedule, lấy lịch slot tuần, chạy batch Chủ Nhật thủ công và
 * duyệt cả tuần. Component tab gọi qua service này, không gọi $api rải rác.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - unwrap(): lấy data từ BaseResponse.
 * - settings()/updateSettings(): đọc/ghi cấu hình global.
 * - week(): lịch slot của tuần.
 * - runBatch(): trigger batch thủ công.
 * - approveWeek(): duyệt toàn bộ bài pending của tuần.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/payload từ các tab.
 * - OUTPUT: data đã unwrap hoặc lỗi do $api ném.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: API response. Output: envelope data hoặc response phẳng. */
const unwrap = response => response?.success && 'data' in response ? response.data : response

export const postScheduleService = {
  /** Input: không. Output: section cấu hình marketing_schedule. */
  async settings() { return unwrap(await $api('/admin/marketing/post-schedule/settings')) },

  /** Input: payload settings. Output: section đã cập nhật. */
  async updateSettings(payload) { return unwrap(await $api('/admin/marketing/post-schedule/settings', { method: 'PUT', body: payload })) },

  /** Input: {date}. Output: {week_start, week_end, items}. */
  async week(query = {}) { return unwrap(await $api('/admin/marketing/post-schedule/week', { query })) },

  /** Input: không. Output: kết quả chạy batch tuần tới. */
  async runBatch() { return unwrap(await $api('/admin/marketing/post-schedule/run-batch', { method: 'POST' })) },

  /** Input: {date}. Output: {approved} số bài đã duyệt. */
  async approveWeek(payload = {}) { return unwrap(await $api('/admin/marketing/post-schedule/approve-week', { method: 'POST', body: payload })) },

  /** Input: query. Output: danh sách chiến dịch. */
  async campaigns(query = {}) { return unwrap(await $api('/admin/marketing/campaigns', { query })) },

  /** Input: payload campaign 5 bước. Output: campaign đã tạo. */
  async createCampaign(payload) { return unwrap(await $api('/admin/marketing/campaigns', { method: 'POST', body: payload })) },

  /** Input: không. Output: options văn phong AI (profiles + default). */
  async writingProfiles() { return unwrap(await $api('/admin/ai/writing-profiles/options')) },

  /** Input: không. Output: options visual style/image profile. */
  async imageProfiles() { return unwrap(await $api('/admin/ai/image-profiles/options')) },
}
