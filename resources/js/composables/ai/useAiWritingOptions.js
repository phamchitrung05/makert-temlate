/* eslint-disable camelcase -- DTO theo API Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đọc options văn phong, không thay lựa chọn người dùng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiWritingOptions(), load(), onScopeDispose().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): vòng đời caller -> items/default/loading/error.
 * SIDE EFFECT: GET options có abort/sequence guard; không ghi profile hay gọi AI.
 * =====================================================================
 */
import { onScopeDispose, shallowRef } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * Input: không có. Output: state và load(), dùng được trong create/regenerate.
 * =====================================================================
 */
export function useAiWritingOptions() {
  const items = shallowRef([])
  const defaultId = shallowRef(null)
  const loading = shallowRef(false)
  const error = shallowRef('')
  let sequence = 0
  let controller

  /**
   * =====================================================================
   * Input: thao tác mở/tải lại. Output: catalog mới nhất; không ghi đè selection.
   * =====================================================================
   */
  async function load() {
    controller?.abort()
    controller = new AbortController()

    const token = ++sequence

    loading.value = true
    error.value = ''
    try {
      const result = await aiWritingProfilesService.options(controller.signal)
      if (token !== sequence) return
      items.value = result.items ?? []
      defaultId.value = result.default_writing_profile_id ?? null
    }
    catch (reason) { if (token === sequence) error.value = formatAiError(reason, 'Không tải được mẫu văn phong. Có thể giữ mặc định website hoặc tải lại.') }
    finally { if (token === sequence) loading.value = false }
  }

  // =====================================================================
  // Input: caller unmount. Output: abort GET, vô hiệu response đến muộn.
  // =====================================================================
  onScopeDispose(() => { sequence += 1; controller?.abort() })

  return { items, defaultId, loading, error, load }
}
