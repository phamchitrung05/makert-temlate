/**
 * =====================================================================
 * CHỨC NĂNG FILE: State + helper snackbar page-local dùng chung.
 * =====================================================================
 *
 * Chuẩn hóa shape `{ visible, message, color }` cho mọi page — thay vì mỗi
 * nơi tự khai `snackbar` ref + hàm showSnackbar/showMessage/notify. Đi kèm
 * `<AppSnackbar v-model="snackbar" />` để bỏ luôn phần VSnackbar template.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useSnackbar(): tạo state snackbar + action.
 * - notify(message, color): hiển thị thông báo tùy ý.
 * - success/error/warning/info(message): shortcut theo màu semantic.
 * - hide(): đóng snackbar ngay.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : chuỗi message và màu Vuetify.
 * - OUTPUT: `snackbar` ref cho AppSnackbar v-model; không gọi API.
 * =====================================================================
 */
import { shallowRef } from 'vue'

export function useSnackbar() {
  const snackbar = shallowRef({
    visible: false,
    message: '',
    color: 'success',
  })

  /** Input: message + màu. Output: snackbar hiển thị thông báo. */
  const notify = (message, color = 'success') => {
    snackbar.value = { visible: true, message, color }
  }

  const success = message => notify(message, 'success')
  const error = message => notify(message, 'error')
  const warning = message => notify(message, 'warning')
  const info = message => notify(message, 'info')

  /** Output: đóng snackbar ngay lập tức. */
  const hide = () => {
    snackbar.value = { ...snackbar.value, visible: false }
  }

  return { snackbar, notify, success, error, warning, info, hide }
}
