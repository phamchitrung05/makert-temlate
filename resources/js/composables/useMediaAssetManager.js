/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối màn hình Media Asset bằng Pinia store dùng chung.
 * =====================================================================
 *
 * Composable giữ adapter UI mỏng cho layout ba cột; state và HTTP mutation
 * thuộc useMediaAssetStore/mediaAssetService, không tạo source of truth thứ hai.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useMediaAssetManager(): nối query reactive với Pinia store.
 * - load(): tải list qua store.
 * - select(): tải hoặc bỏ chọn detail.
 * - mutate(): chuẩn hóa mutation thành boolean cho UI.
 * - upload()/remove()/update()/retry()/download(): workflow UI.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query reactive từ page.
 * - OUTPUT: refs của store và action UI; không gọi HTTP trực tiếp.
 * =====================================================================
 */
import { computed, watch } from 'vue'
import { createPinia, getActivePinia, storeToRefs } from 'pinia'
import { useMediaAssetStore } from '@/stores/mediaAsset'

/** Input: query ref/computed. Output: state/action UI dựa trên Pinia. */
export function useMediaAssetManager(query) {
  // Route components already have an app Pinia. The isolated fallback keeps
  // this composable testable without creating a second state implementation.
  const store = useMediaAssetStore(getActivePinia() || createPinia())

  const {
    items,
    itemsLength: total,
    selectedAsset: selected,
    isLoading: loading,
    isMutating: busy,
    uploadProgress: progress,
    error: requestError,
  } = storeToRefs(store)

  const error = computed(() => requestError.value?.data?.message || requestError.value?.message || '')

  /** Input: không có. Output: list mới nhất từ store. */
  const load = () => store.fetchMediaAssets(query.value)

  /** Input: ID/fallback. Output: detail từ store; null bỏ chọn. */
  const select = (id, fallback = null) => store.fetchMediaAsset(id, fallback)

  /** Input: mutation callback. Output: boolean thành công, refresh list sau mutation. */
  async function mutate(callback) {
    if (busy.value) return false
    try {
      await callback()
      await load()

      return true
    }
    catch { return false }
  }

  /** Input: payload file. Output: upload và chọn asset mới. */
  const upload = payload => mutate(() => store.uploadMediaAsset(payload))

  /** Input: asset ID. Output: xóa asset và bỏ chọn khi thành công. */
  const remove = id => mutate(() => store.deleteMediaAsset(id))

  /** Input: ID/metadata. Output: cập nhật asset qua store. */
  const update = (id, payload) => mutate(() => store.updateMediaAsset(id, payload))

  /** Input: ID. Output: đưa pipeline media vào queue retry. */
  const retry = id => mutate(() => store.retryMediaAsset(id))

  /** Input: asset. Output: tải file qua store/service. */
  const download = asset => mutate(() => store.downloadFile(asset))

  watch(query, load, { immediate: true })

  return { items, total, selected, loading, busy, progress, error, load, select, upload, remove, update, retry, download }
}
