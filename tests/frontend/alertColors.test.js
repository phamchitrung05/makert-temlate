import { describe, expect, it } from 'vitest'
import { alertColors, getAlertColor } from '../../resources/js/config/alertColors'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa cấu hình màu thông báo dùng chung của giao diện.
 * =====================================================================
 * INPUT: semantic type Vuetify và trạng thái completed/danger.
 * OUTPUT: assertions xanh blue, vàng, đỏ và bảng màu immutable.
 * SIDE EFFECT: không gọi API, không mount component hoặc đổi theme.
 * =====================================================================
 */
describe('shared alert colors', () => {
  it('uses blue for completed and informational notifications', () => {
    expect(getAlertColor('success')).toBe('#2196F3')
    expect(getAlertColor('completed')).toBe('#2196F3')
    expect(getAlertColor('info')).toBe('#2196F3')
  })

  it('uses yellow for warnings and red for errors or danger', () => {
    expect(getAlertColor('warning')).toBe('#FFC107')
    expect(getAlertColor('error')).toBe('#F44336')
    expect(getAlertColor('danger')).toBe('#F44336')
  })

  it('provides a safe fallback without a mutable color configuration', () => {
    expect(getAlertColor()).toBe(alertColors.completed)
    expect(getAlertColor('unknown')).toBe(alertColors.completed)
    expect(Object.isFrozen(alertColors)).toBe(true)
  })
})
