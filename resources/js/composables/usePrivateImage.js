import { ref, watch } from 'vue'
import { $api } from '@/utils/api'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tải ảnh private qua endpoint authenticated và trả objectURL.
 * =====================================================================
 * Endpoint như `/api/admin/ai/visual-styles/{id}/reference-image` yêu cầu
 * Sanctum token — `<img src>` trực tiếp sẽ 401. Composable này fetch bằng
 * $api (có Authorization) vào Blob rồi trả `URL.createObjectURL` để <v-img>
 * dùng được; thu hồi objectURL khi đổi src/unmount.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - usePrivateImage(srcRef): watch src, fetch blob → url reactive; revoke cũ.
 * - INPUT : ref URL endpoint private (hoặc null/'').
 * - OUTPUT: { url, loading, error } — url là objectURL nội bộ để bind vào img.
 * - SIDE EFFECT: gọi $api responseType blob; URL.createObjectURL + revoke.
 * =====================================================================
 */
export function usePrivateImage(srcRef) {
  const url = ref('')
  const loading = ref(false)
  const error = ref('')

  let objectUrl = null
  let generation = 0

  const revoke = () => {
    if (objectUrl) {
      URL.revokeObjectURL(objectUrl)
      objectUrl = null
    }
  }

  watch(srcRef, async src => {
    revoke()
    url.value = ''
    error.value = ''
    if (!src) {
      loading.value = false

      return
    }
    const gen = ++generation

    loading.value = true
    try {
      const blob = await $api(src, { responseType: 'blob', retry: 0 })

      if (gen !== generation) return
      objectUrl = URL.createObjectURL(blob)
      url.value = objectUrl
    }
    catch (reason) {
      if (gen === generation)
        error.value = reason?.data?.message ?? 'Không tải được ảnh.'
    }
    finally {
      if (gen === generation)
        loading.value = false
    }
  }, { immediate: true })

  return { url, loading, error, revoke }
}
