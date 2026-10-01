/**
 * =====================================================================
 * CHỨC NĂNG FILE: Màu trạng thái cảnh báo dùng chung cho giao diện Vue.
 * =====================================================================
 *
 * Component dùng cấu hình này cho thông báo hoàn thành, cảnh báo và nguy
 * hiểm; không đổi màu theme cho button, badge hoặc icon nghiệp vụ khác.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * CÁC HÀM/METHOD: getAlertColor().
 * - INPUT : loại thông báo Vuetify hoặc trạng thái completed/danger.
 * - OUTPUT: bảng màu immutable cho VAlert, VChip, VIcon và feedback UI.
 * - SIDE EFFECT: không có; không dùng reactive state hoặc gọi API.
 * =====================================================================
 */

export const alertColors = Object.freeze({
  completed: '#2196F3',
  warning: '#FFC107',
  danger: '#F44336',
})

/**
 * =====================================================================
 * CHỨC NĂNG: Resolve màu thông báo theo semantic type dùng chung.
 * =====================================================================
 * INPUT: success/completed/info, warning hoặc error/danger.
 * OUTPUT: xanh blue, vàng hoặc đỏ; loại chưa biết dùng xanh thông tin.
 * SIDE EFFECT: hàm thuần, không thay đổi cấu hình.
 * =====================================================================
 */
export function getAlertColor(type) {
  switch (type) {
  case 'warning':
    return alertColors.warning
  case 'error':
  case 'danger':
    return alertColors.danger
  default:
    return alertColors.completed
  }
}

