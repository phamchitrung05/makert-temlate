/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thông báo UI fallback + helper đọc message từ API response.
 * =====================================================================
 *
 * Message chính thức của thao tác nằm ở backend trong config/messages.php và
 * đi qua BaseResponse.data.message — UI_MESSAGES chỉ là fallback khi API
 * không trả message phù hợp (lỗi network, request không qua server, hoặc
 * text riêng của UI). apiMessage() ưu tiên message từ server rồi mới fallback.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - UI_MESSAGES: catalog fallback theo semantic (error/success/info/warning).
 * - apiMessage(response, fallback): trích message từ API response hoặc error
 *   (ưu tiên data.message, rồi data.errors field đầu, rồi message, rồi fallback).
 * - apiErrorMessage(error, fallback): trích message lỗi từ exception của $api.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : response/error của $api và chuỗi fallback.
 * - OUTPUT: chuỗi message hiển thị trong snackbar/alert; không gọi API.
 * =====================================================================
 */

/* eslint-disable camelcase -- Khóa message giữ snake_case để khớp contract. */
export const UI_MESSAGES = Object.freeze({
  error: {
    load: 'Không tải được dữ liệu.',
    action: 'Thao tác thất bại.',
    save: 'Lưu thất bại.',
    create: 'Tạo mới thất bại.',
    delete: 'Xóa thất bại.',
    network: 'Mất kết nối. Vui lòng kiểm tra mạng và thử lại.',
    upload: 'Tải lên thất bại.',
    permission: 'Bạn không có quyền thực hiện thao tác này.',
    not_found: 'Không tìm thấy dữ liệu.',
    validation: 'Dữ liệu gửi lên không hợp lệ.',
  },
  success: {
    saved: 'Đã lưu.',
    created: 'Đã tạo mới.',
    deleted: 'Đã xóa.',
    action: 'Thao tác thành công.',
    queued: 'Đã xếp hàng.',
  },
  info: {
    empty: 'Không có dữ liệu.',
  },
  warning: {
    unsaved: 'Bạn có thay đổi chưa lưu.',
  },
})

/**
 * =====================================================================
 * CHỨC NĂNG: Trích message hiển thị từ response thành công của $api.
 * =====================================================================
 * INPUT: response từ service/BaseResponse và chuỗi fallback.
 * OUTPUT: data.message nếu có, ngược lại message top-level, ngược lại fallback.
 * =====================================================================
 */
export const apiMessage = (response, fallback = '') => {
  if (!response) return fallback

  return response?.message ?? fallback
}

/**
 * =====================================================================
 * CHỨC NĂNG: Trích message lỗi hiển thị từ exception của $api.
 * =====================================================================
 * Ưu tiên data.message, rồi lỗi validation đầu trong data.errors, rồi
 * error.message của exception, rồi fallback.
 *
 * INPUT: error do $api ném (ofetch FetchError hoặc lỗi thường) và fallback.
 * OUTPUT: chuỗi message hiển thị trong snackbar/alert.
 * =====================================================================
 */
export const apiErrorMessage = (error, fallback = UI_MESSAGES.error.action) => {
  const data = error?.data ?? error?.response?._data ?? error?.response?.data ?? {}

  const fieldError = Object.values(data.errors ?? {})
    .flat()
    .find(value => typeof value === 'string' && value.trim())

  return data.message
    || fieldError
    || (typeof error?.message === 'string' ? error.message : '')
    || fallback
}
