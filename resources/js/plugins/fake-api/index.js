/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bật fake API theo cấu hình mà không nạp MSW khi bị tắt.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - default(): chỉ import handler và khởi động worker khi bật dữ liệu mẫu.
 * INPUT/OUTPUT CỦA FILE (tổng thể): VITE_ENABLE_MSW/BASE_URL -> Promise hoặc bỏ qua.
 * =====================================================================
 */

/** Input: cấu hình Vite. Output: worker đã khởi động nếu MSW được bật. */
export default async function () {
  if (import.meta.env.VITE_ENABLE_MSW !== 'true')
    return

  const { startFakeApi } = await import('./runtime')
  const workerUrl = `${(import.meta.env.BASE_URL || '/').replace(/build\/$/g, '')}mockServiceWorker.js`

  return startFakeApi(workerUrl)
}
