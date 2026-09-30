/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối dữ liệu API cho giao diện Media Asset ba cột.
 * CÁC HÀM/METHOD TRONG FILE: useMediaAssetManager(), load(), select(),
 * mutate(), upload(), remove(), update(), retry(), download().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): query reactive -> danh sách, chi tiết,
 * pagination và action API; response cũ không ghi đè state mới, item đang chọn
 * không bị xóa trong lúc tải detail để tránh layout giao diện co giãn.
 * =====================================================================
 */
/* eslint-disable camelcase -- Contract Laravel dùng snake_case. */
import { onScopeDispose, shallowRef, watch } from 'vue'
import { mediaAssetService } from '@/services/mediaAsset'
import { $api } from '@/utils/api'

/** Input: computed query. Output: state/action API cô lập theo màn hình. */
export function useMediaAssetManager(query) {
  const items = shallowRef([])
  const total = shallowRef(0)
  const selected = shallowRef(null)
  const loading = shallowRef(false)
  const busy = shallowRef(false)
  const progress = shallowRef(0)
  const error = shallowRef('')
  let revision = 0
  let detailRevision = 0
  let disposed = false

  /** Input: không có. Output: list mới nhất hoặc message lỗi. */
  async function load() {
    const current = ++revision

    loading.value = true
    error.value = ''
    try {
      const result = await mediaAssetService.list(query.value)
      if (current !== revision || disposed) return
      items.value = result.items
      total.value = result.itemsLength
    }
    catch (failure) {
      if (current === revision && !disposed) error.value = failure?.data?.message || failure.message
    }
    finally {
      if (current === revision && !disposed) loading.value = false
    }
  }

  /**
   * Tải detail mới nhất nhưng giữ item tạm để panel không bị tháo khỏi DOM.
   *
   * Input: asset ID/null và fallback lấy từ list nếu người dùng vừa chọn item.
   * Output: detail mới nhất; null đóng panel; response cũ bị bỏ qua.
   */
  async function select(id, fallback = null) {
    const current = ++detailRevision

    if (!id) {
      selected.value = null

      return
    }

    if (fallback)
      selected.value = fallback

    try {
      const asset = await mediaAssetService.show(id)
      if (current === detailRevision && !disposed) selected.value = asset
    }
    catch (failure) {
      if (current === detailRevision && !disposed) error.value = failure?.data?.message || failure.message
    }
  }

  /** Input: callback mutation. Output: boolean thành công và refresh list. */
  async function mutate(callback) {
    if (busy.value) return false
    busy.value = true
    error.value = ''
    try {
      await callback()
      await load()

      return true
    }
    catch (failure) {
      error.value = failure?.data?.message || failure.message

      return false
    }
    finally { busy.value = false }
  }

  /** Input: multipart fields. Output: success; upload không tự attach usage. */
  const upload = payload => mutate(async () => {
    progress.value = 0

    const asset = await mediaAssetService.upload(payload, value => { progress.value = value })

    await select(asset.id)
  })

  /** Input: ID. Output: success; backend chặn xóa asset đang sử dụng. */
  const remove = id => mutate(async () => {
    await mediaAssetService.remove(id)
    await select(null)
  })

  /** Input: ID/metadata. Output: success và detail mới. */
  const update = (id, data) => mutate(async () => {
    await mediaAssetService.update(id, data)
    await select(id)
  })

  /** Input: ID. Output: success và trạng thái xử lý mới. */
  const retry = id => mutate(async () => {
    await mediaAssetService.retry(id)
    await select(id)
  })

  /** Input: asset. Output: tải URL JSON hoặc stream binary với Bearer token. */
  async function download(asset) {
    error.value = ''
    try {
      const response = await $api.raw(`/admin/media-assets/${asset.id}/download`, { responseType: 'blob' })
      const blob = response._data
      if (response.headers.get('content-type')?.includes('application/json')) {
        const result = JSON.parse(await blob.text())
        const data = result.data ?? result
        const url = data.url ?? data.temporary_url ?? data.download_url

        if (!url) throw new Error('API không trả URL tải xuống.')
        window.open(url, '_blank', 'noopener,noreferrer')

        return
      }
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = asset.file?.original_name || asset.title
      link.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    }
    catch (failure) { error.value = failure?.data?.message || failure.message }
  }

  watch(query, load, { immediate: true })
  onScopeDispose(() => { disposed = true; revision++; detailRevision++ })

  return { items, total, selected, loading, busy, progress, error, load, select, upload, remove, update, retry, download }
}
