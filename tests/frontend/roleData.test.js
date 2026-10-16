/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử metadata icon của role trên frontend.
 * =====================================================================
 *
 * Test bảo đảm icon người dùng chọn được ưu tiên cho role tùy chỉnh và role
 * cũ chưa có icon vẫn nhận icon mặc định theo metadata hệ thống.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - mapRole(): kiểm tra icon API và fallback role system.
 * - roleIconOptions: kiểm tra catalog icon cho field chọn icon.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : DTO role tối thiểu từ Access API.
 * - OUTPUT: model role có icon dùng cho list/detail.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { mapRole, roleIconOptions } from '@/views/apps/roles/roleData'

describe('Role icon metadata', () => {
  it('uses the persisted icon for custom roles', () => {
    expect(mapRole({ id: 1, name: 'content-editor', icon: 'tabler-pencil', permissions: [] }).icon)
      .toBe('tabler-pencil')
  })

  it('keeps a visual fallback for legacy roles without an icon', () => {
    expect(mapRole({ id: 2, name: 'admin', icon: null, permissions: [] }).icon)
      .toBe('tabler-shield-check')
    expect(roleIconOptions.some(option => option.value === 'tabler-robot')).toBe(true)
  })
})
