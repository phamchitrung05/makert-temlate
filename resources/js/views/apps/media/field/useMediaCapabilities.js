/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp capability của Media Library cho picker UI
 * =====================================================================
 *
 * Capability chỉ dùng để ẩn/khóa thao tác sớm ở frontend. Backend policy và
 * permission middleware vẫn là ranh giới authorization bắt buộc.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useMediaCapabilities(): trả computed canView/canUpload/canAttach/canRetry
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : phiên admin Pinia.
 * - OUTPUT: các computed boolean capability.
 * =====================================================================
 */
import { computed } from 'vue'
import { useAdminAuthStore } from '@/stores/adminAuth'

const elevatedRoles = ['admin', 'super-admin', 'administrator']

export const useMediaCapabilities = () => {
  const adminAuth = useAdminAuthStore()

  const can = permission => computed(() => {
    const permissions = adminAuth.permissions ?? []
    const roles = adminAuth.roles ?? []
    const userRole = adminAuth.user?.role

    return permissions.includes(permission)
      || roles.some(role => elevatedRoles.includes(role))
      || elevatedRoles.includes(userRole)
  })

  return {
    canView: can('media.view'),
    canUpload: can('media.upload'),
    canAttach: can('media.attach'),
    canRetry: can('media.retry'),
  }
}

