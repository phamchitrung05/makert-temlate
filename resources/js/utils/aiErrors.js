/* eslint-disable camelcase -- Public error fields follow the Laravel API contract. */
/** Format public AI errors without using raw provider responses or duplicating validation rules. */
const reasonMessages = {
  missing: 'thiếu dữ liệu bắt buộc',
  null: 'thiếu dữ liệu bắt buộc',
  empty: 'không có nội dung hợp lệ',
  empty_content: 'không còn nội dung hợp lệ sau khi làm sạch',
  type: 'có kiểu dữ liệu không hợp lệ',
  invalid_type: 'có kiểu dữ liệu không hợp lệ',
  invalid_value: 'có giá trị không hợp lệ',
  too_long: 'vượt quá giới hạn cho phép',
}

const publicMessage = value => typeof value === 'string' ? value.trim().slice(0, 500) : ''

/** Input: run DTO or HTTP error, optional config labels. Output: a short, plain text message. */
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

export const isAiSuccess = value => ['ready', 'completed', 'succeeded'].includes(value?.status)
