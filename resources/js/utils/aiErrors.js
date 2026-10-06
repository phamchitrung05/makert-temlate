/* eslint-disable camelcase -- Public error fields follow the Laravel API contract. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Diễn giải lỗi AI công khai thành thông báo dễ hiểu.
 * =====================================================================
 * Chỉ dùng mã lý do và message an toàn; không hiển thị raw response provider.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - publicMessage(): lấy chuỗi thông báo có giới hạn độ dài.
 * - formatAiError(): diễn giải lỗi validation, field hoặc message công khai.
 * - isAiSuccess(): nhận diện trạng thái hoàn tất thành công của tác vụ.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : run DTO/lỗi HTTP và nhãn nhóm output từ config.
 * - OUTPUT: thông báo plain text hoặc boolean trạng thái thành công.
 * =====================================================================
 */
const reasonMessages = {
  missing: 'thiếu dữ liệu bắt buộc',
  null: 'thiếu dữ liệu bắt buộc',
  empty: 'không có nội dung hợp lệ',
  empty_content: 'không còn nội dung hợp lệ sau khi làm sạch',
  type: 'có kiểu dữ liệu không hợp lệ',
  invalid_type: 'có kiểu dữ liệu không hợp lệ',
  invalid_value: 'có giá trị không hợp lệ',
  too_long: 'vượt quá giới hạn cho phép',
  invalid_length: 'có độ dài không hợp lệ',
  unexpected_field: 'có trường dữ liệu không được phép',
  duplicate_fact_id: 'có mã dữ kiện bị trùng',
  unknown_source_block: 'tham chiếu đoạn nguồn không tồn tại',
  evidence_not_in_source: 'có trích đoạn dẫn chứng không xuất hiện trong nguồn',
  unknown_fact_reference: 'tham chiếu dữ kiện không tồn tại',
  missing_important_fact_reference: 'thiếu dữ kiện quan trọng từ nguồn',
  exact_copy: 'sao chép nguyên văn phần văn xuôi nguồn',
  dominant_language_mismatch: 'không đúng ngôn ngữ được yêu cầu',
  source_code_changed: 'làm mất hoặc sửa đoạn mã trong nguồn',
  source_link_missing: 'thiếu liên kết tham khảo từ nguồn',
  source_link_unknown: 'có liên kết không khớp nguồn',
  important_number_missing: 'thiếu số liệu hoặc phiên bản quan trọng từ nguồn',
}

/** Input: giá trị bất kỳ. Output: chuỗi thông báo tối đa 500 ký tự, hoặc rỗng. */
const publicMessage = value => typeof value === 'string' ? value.trim().slice(0, 500) : ''

/** Input: run DTO/lỗi HTTP và nhãn output tùy chọn. Output: thông báo plain text ngắn. */
export function formatAiError(reason, fallback = 'Không thực hiện được thao tác AI. Hãy thử lại.', outputOptions = []) {
  const data = reason?.data ?? reason ?? {}
  const labels = Object.fromEntries(outputOptions.map(option => [option.value, option.title]))

  const validation = (Array.isArray(data.validation_errors) ? data.validation_errors : [])
    .filter(error => error && typeof error === 'object')
    .map(error => `${labels[error.group] || 'Đầu ra AI'} ${reasonMessages[error.reason] || 'không hợp lệ'}.`)

  if (validation.length) return [...new Set(validation)].slice(0, 3).join(' ')
  const fieldError = Object.values(data.errors ?? {}).flat().find(value => publicMessage(value))

  return publicMessage(fieldError) || publicMessage(data.error) || publicMessage(data.message)
    || publicMessage(reason?.message) || fallback
}

/** Input: run DTO. Output: boolean cho trạng thái đã hoàn tất thành công. */
export const isAiSuccess = value => ['ready', 'completed', 'succeeded'].includes(value?.status)
