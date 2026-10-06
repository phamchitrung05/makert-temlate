/**
 * =====================================================================
 * CHỨC NĂNG FILE: Branding chung cho SPA, khởi tạo từ Blade và cập nhật sau lưu.
 * CÁC HÀM/METHOD TRONG FILE: readBootstrap(), applySiteBranding(), useSiteBranding().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : JSON bootstrap hoặc DTO site đã lưu thành công.
 * - OUTPUT: state readonly cho logo; cập nhật favicon hiện tại.
 * =====================================================================
 */
import { readonly, shallowRef } from 'vue'

/** Input: DOM bootstrap nếu có. Output: DTO; không gọi API hoặc đọc secret. */
function readBootstrap() {
  try {
    return JSON.parse(document.getElementById('site-branding')?.textContent || '{}')
  }
  catch {
    return {}
  }
}

const branding = shallowRef(readBootstrap())

/** Input: site DTO. Output: cập nhật logo/favicon sau save, không dùng ảnh preview chưa lưu. */
export function applySiteBranding(values) {
  if (!Object.hasOwn(values, 'logo_url')) return
  branding.value = Object.fromEntries(['logo_url', 'favicon_url', 'favicon_type'].map(key => [key, values[key]]))
  if (typeof document === 'undefined') return
  const icon = document.querySelector('link[rel="icon"]')

  if (icon && values.favicon_url) {
    icon.href = values.favicon_url
    icon.type = values.favicon_type || 'image/png'
  }
}

/** Input: không có. Output: tham chiếu readonly tới branding dùng chung. */
export function useSiteBranding() {
  return readonly(branding)
}
