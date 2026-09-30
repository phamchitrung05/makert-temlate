/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia gateway tạo slug preview cho nhiều model.
 * CÁC HÀM/METHOD TRONG FILE: useSlugStore(); generateSlug(): gọi API chung.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): title/modelType/modelId/signal -> Promise slug.
 * Kết quả trả cho từng caller, không dùng một slug chung gây đè giữa các form.
 * =====================================================================
 */
import { computed, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

/** Input: Pinia hiện tại. Output: action và state đếm request đang chạy. */
export const useSlugStore = defineStore('slug', () => {
  const pendingRequests = shallowRef(0)
  const isLoading = computed(() => pendingRequests.value > 0)

  /** Input: model alias, title, ID tùy chọn và signal. Output: preview; lỗi truyền về caller. */
  const generateSlug = async ({ title, modelType, modelId = null }, { signal } = {}) => {
    pendingRequests.value++
    try {
      const response = await $api('/admin/slugs/preview', {
        method: 'POST',
        body: { title, 'model_type': modelType, 'model_id': modelId },
        signal,
      })

      const payload = response?.success && 'data' in response ? response.data : response
      if (response?.success === false || typeof payload?.slug !== 'string' || !payload.slug.trim())
        throw new Error('SLUG_RESPONSE_INVALID')

      return payload
    }
    finally {
      pendingRequests.value--
    }
  }

  return { pendingRequests, isLoading, generateSlug }
})
