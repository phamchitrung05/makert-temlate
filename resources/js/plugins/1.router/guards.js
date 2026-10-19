/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm phiên admin trước khi điều hướng route cần đăng nhập.
 * CÁC HÀM/METHOD TRONG FILE: setupGuards() và callback beforeEach().
 * INPUT/OUTPUT CỦA FILE (tổng thể): router/phiên admin -> cho phép hoặc chuyển route.
 * =====================================================================
 */
import { useAdminAuthStore } from '@/stores/adminAuth'
import { store } from '@/plugins/2.pinia'

/** Input: Vue Router. Output: đăng ký guard phiên admin; có thể tải profile từ API. */
export const setupGuards = router => {
  // Input: route đích. Output: cho phép route public hoặc chuyển guest đến đăng nhập.
  router.beforeEach(async to => {
    const adminAuth = useAdminAuthStore(store)

    await adminAuth.initialize()

    // Route public dùng được cho cả guest và admin.
    if (to.meta.public)
      return

    const isLoggedIn = adminAuth.isAuthenticated

    // Admin đã đăng nhập không quay lại trang dành riêng cho guest.
    if (to.meta.unauthenticatedOnly) {
      if (isLoggedIn)
        return '/'
      else
        return undefined
    }

    if (!isLoggedIn && to.matched.length) {
      return {
        name: 'login',
        query: {
          ...to.query,
          to: to.fullPath !== '/' ? to.path : undefined,
        },
      }
    }

  })
}
