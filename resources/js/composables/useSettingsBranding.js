/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giữ file branding chưa lưu và URL preview cục bộ.
 * CÁC HÀM/METHOD TRONG FILE:
 * - useSettingsBranding(): tạo state độc lập cho form Settings.
 * - release()/selectFile()/removeFile()/reset(): đổi file và thu hồi URL blob.
 * - payload(): trả file hoặc cờ gỡ để settings writer lưu cùng version.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : loại logo/favicon và File; không gọi HTTP.
 * - OUTPUT: pending changes/preview/dirty; dọn blob khi reset hoặc rời trang.
 * =====================================================================
 */
import { computed, getCurrentScope, onScopeDispose, shallowRef } from 'vue'

/** Input: không có. Output: state/actions chọn ảnh; caller giữ quyền và trạng thái busy. */
export function useSettingsBranding() {
  const changes = shallowRef({})
  const previews = shallowRef({})
  const dirty = computed(() => Object.keys(changes.value).length > 0)

  /** Input: loại ảnh. Output: thu hồi URL cũ, không xóa file server. */
  function release(kind) {
    if (previews.value[kind]) URL.revokeObjectURL(previews.value[kind])
    const next = { ...previews.value }

    delete next[kind]
    previews.value = next
  }

  /** Input: kind, File hoặc null để bỏ file mới. Output: preview và pending change. */
  function selectFile(kind, file) {
    if (!['logo', 'favicon'].includes(kind)) return
    release(kind)

    const next = { ...changes.value }

    if (file) {
      next[kind] = file
      previews.value = { ...previews.value, [kind]: URL.createObjectURL(file) }
    }
    else delete next[kind]
    changes.value = next
  }

  /** Input: kind. Output: đánh dấu gỡ khi Save; file đang được dùng vẫn giữ trên server. */
  function removeFile(kind) {
    if (!['logo', 'favicon'].includes(kind)) return
    release(kind)
    changes.value = { ...changes.value, [kind]: null }
  }

  /** Input: không có. Output: dọn pending và mọi URL blob sau lưu/hoàn tác/unmount. */
  function reset() {
    Object.keys(previews.value).forEach(release)
    changes.value = {}
  }

  /** Input: pending state. Output: file/cờ gỡ theo contract Laravel, không serialize File thành JSON. */
  function payload() {
    return Object.fromEntries(Object.entries(changes.value).map(([kind, file]) =>
      file ? [`${kind}_file`, file] : [`remove_${kind}`, true]))
  }

  if (getCurrentScope()) onScopeDispose(reset)

  return { changes, previews, dirty, selectFile, removeFile, reset, payload }
}
