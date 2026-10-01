/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý state và action dùng chung cho Media Library
 * =====================================================================
 *
 * Store giữ list/detail asset, filter, pagination, upload progress và lỗi.
 * HTTP chỉ đi qua mediaAssetService; component/picker không biết BaseResponse.
 * Các mutation cập nhật selected asset và list tại một source of truth.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - fetchMediaAssets()/fetchMediaAsset(): tải list hoặc detail
 * - setFilters()/setPagination(): cập nhật query state
 * - uploadMediaAsset(): upload có progress
 * - updateMediaAsset()/attachMediaAsset()/detachMediaAsset(): mutation
 * - reorderMediaAssets()/deleteMediaAsset()/retryMediaAsset(): workflow
 * - applyListResponse()/runMutation()/mutateUsage(): helper đồng bộ state
 * - downloadMediaAsset()/downloadFile(): lấy payload hoặc tải file
 * - clearError()/clearSelection()/reset(): dọn state
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : query, asset id, File, metadata và usage payload
 * - OUTPUT: state readonly, action Promise và lỗi request
 * =====================================================================
 */
/* eslint-disable camelcase -- Laravel API fields preserve snake_case contract. */

import { computed, reactive, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { mediaAssetService } from '@/services/mediaAsset'

const defaultFilters = () => ({
  search: '',
  kind: null,
  field: null,
  visibility: null,
  owner: null,
  scan_status: null,
  conversion_status: null,
  sort: 'created_at',
  direction: 'desc',
})

const defaultPagination = () => ({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
  from: null,
  to: null,
})

export const useMediaAssetStore = defineStore('mediaAsset', () => {
  const items = shallowRef([])
  const itemsLength = shallowRef(0)
  const selectedAsset = shallowRef(null)
  const filters = reactive(defaultFilters())
  const pagination = shallowRef(defaultPagination())
  const isLoading = shallowRef(false)
  const isDetailLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const uploadProgress = shallowRef(0)
  const error = shallowRef(null)
  const detailError = shallowRef(null)
  let listRevision = 0
  let detailRevision = 0

  const list = computed(() => items.value)
  const detail = computed(() => selectedAsset.value)
  const hasItems = computed(() => items.value.length > 0)

  const query = computed(() => ({
    ...filters,
    page: pagination.value.current_page,
    per_page: pagination.value.per_page,
  }))

  /** Input: response list mới nhất. Output: đồng bộ list và pagination. */
  const applyListResponse = response => {
    items.value = response?.items ?? []
    itemsLength.value = response?.itemsLength ?? 0
    pagination.value = {
      ...defaultPagination(),
      ...pagination.value,
      ...(response?.pagination ?? {}),
      total: response?.itemsLength ?? response?.pagination?.total ?? 0,
    }

    return response
  }

  /** Input: filter/page. Output: response mới nhất; bỏ qua response stale. */
  const fetchMediaAssets = async (params = {}) => {
    const revision = ++listRevision

    isLoading.value = true
    error.value = null

    try {
      const nextParams = { ...query.value, ...params }

      Object.assign(filters, Object.fromEntries(Object.entries(nextParams).filter(([key]) => key in defaultFilters())))

      const response = await mediaAssetService.list(nextParams)
      if (revision !== listRevision) return null

      if (params.page)
        pagination.value = { ...pagination.value, current_page: params.page }
      if (params.per_page)
        pagination.value = { ...pagination.value, per_page: params.per_page }

      return applyListResponse(response)
    }
    catch (requestError) {
      if (revision === listRevision) error.value = requestError

      return null
    }
    finally {
      if (revision === listRevision) isLoading.value = false
    }
  }

  /** Input: ID và fallback list item. Output: detail mới nhất, không làm co panel. */
  const fetchMediaAsset = async (id, fallback = null) => {
    const revision = ++detailRevision
    if (!id) {
      selectedAsset.value = null
      isDetailLoading.value = false
      detailError.value = null

      return null
    }
    if (fallback) selectedAsset.value = fallback
    isDetailLoading.value = true
    detailError.value = null

    try {
      const response = await mediaAssetService.show(id)
      if (revision !== detailRevision) return null
      selectedAsset.value = response

      return selectedAsset.value
    }
    catch (requestError) {
      if (revision === detailRevision) detailError.value = requestError

      return null
    }
    finally {
      if (revision === detailRevision) isDetailLoading.value = false
    }
  }

  /** Input: mutation callback và option asset-response. Output: response hoặc throw lỗi. */
  const runMutation = async (callback, assetResponse = true) => {
    isMutating.value = true
    error.value = null

    try {
      const response = await callback()

      if (assetResponse && response?.id)
        selectedAsset.value = response

      if (assetResponse && response?.id) {
        const index = items.value.findIndex(item => item.id === response.id)

        if (index >= 0) {
          const nextItems = [...items.value]

          nextItems[index] = response
          items.value = nextItems
        }
      }

      return response
    }
    catch (requestError) {
      error.value = requestError
      throw requestError
    }
    finally {
      isMutating.value = false
    }
  }

  /** Input: filter mới. Output: state query mới và reset page về 1. */
  const setFilters = (nextFilters = {}) => {
    Object.assign(filters, nextFilters)
    pagination.value = { ...pagination.value, current_page: 1 }
  }

  /** Input: pagination partial. Output: pagination state mới. */
  const setPagination = nextPagination => {
    pagination.value = { ...pagination.value, ...nextPagination }
  }

  /** Input: multipart payload. Output: asset vừa upload và progress; throw lỗi API. */
  const uploadMediaAsset = async payload => {
    isMutating.value = true
    error.value = null
    uploadProgress.value = 0

    try {
      const response = await mediaAssetService.upload(payload, progress => {
        uploadProgress.value = progress
      })

      uploadProgress.value = 100
      if (response?.id)
        selectedAsset.value = response

      items.value = response?.id ? [response, ...items.value] : items.value
      if (response?.id)
        itemsLength.value += 1

      return response
    }
    catch (requestError) {
      error.value = requestError
      throw requestError
    }
    finally {
      isMutating.value = false
    }
  }

  /** Input: ID/metadata. Output: asset cập nhật và đồng bộ list/detail. */
  const updateMediaAsset = (id, payload) => runMutation(() => mediaAssetService.update(id, payload))


  /** Input: asset ID và callback usage. Output: refresh asset sau response usage/204. */
  const mutateUsage = (id, callback) => runMutation(async () => {
    const response = await callback()
    const asset = await mediaAssetService.show(id)
    if (selectedAsset.value?.id === id) selectedAsset.value = asset
    items.value = items.value.map(item => item.id === id ? asset : item)

    return response
  }, false)

  /** Input: asset/payload. Output: usage được attach và asset được refresh. */
  const attachMediaAsset = (id, payload) => mutateUsage(id, () => mediaAssetService.attach(id, payload))

  /** Input: asset/usage. Output: detach rồi refresh asset, hỗ trợ HTTP 204. */
  const detachMediaAsset = (id, usageId) => mutateUsage(id, () => mediaAssetService.detach(id, usageId))


  /** Input: group usage. Output: reorder rồi refresh detail/list hiện tại. */
  const reorderMediaAssets = payload => runMutation(async () => {
    const response = await mediaAssetService.reorder(payload)
    if (selectedAsset.value?.id) selectedAsset.value = await mediaAssetService.show(selectedAsset.value.id)
    const refreshed = await mediaAssetService.list(query.value)

    applyListResponse(refreshed)

    return response
  }, false)

  /** Input: asset ID. Output: xóa item khỏi state sau HTTP thành công. */
  const deleteMediaAsset = async id => {
    await runMutation(() => mediaAssetService.remove(id))
    items.value = items.value.filter(item => item.id !== id)
    itemsLength.value = Math.max(0, itemsLength.value - 1)
    if (selectedAsset.value?.id === id)
      selectedAsset.value = null
  }

  /** Input: asset ID. Output: asset pending do backend trả sau retry. */
  const retryMediaAsset = id => runMutation(() => mediaAssetService.retry(id))

  /** Input: asset ID. Output: URL download payload không làm thay đổi detail. */
  const downloadMediaAsset = id => runMutation(() => mediaAssetService.download(id), false)

  /** Input: asset. Output: download private/public qua service. */
  const downloadFile = asset => runMutation(() => mediaAssetService.downloadFile(asset), false)

  /** Input: Không có. Output: bỏ lỗi request hiện tại. */
  const clearError = () => {
    error.value = null
  }

  /** Input: Không có. Output: bỏ chọn asset và vô hiệu hóa response detail cũ. */
  const clearSelection = () => {
    detailRevision++
    selectedAsset.value = null
    isDetailLoading.value = false
    detailError.value = null
  }

  /** Input: Không có. Output: khôi phục state mặc định và vô hiệu hóa request cũ. */
  const reset = () => {
    listRevision++
    detailRevision++
    items.value = []
    itemsLength.value = 0
    selectedAsset.value = null
    Object.assign(filters, defaultFilters())
    pagination.value = defaultPagination()
    isLoading.value = false
    isDetailLoading.value = false
    isMutating.value = false
    uploadProgress.value = 0
    error.value = null
    detailError.value = null
  }

  // Aliases giữ tên ngắn, dễ dùng trong picker và tương thích convention cũ.
  return {
    items: readonly(items),
    list,
    itemsLength: readonly(itemsLength),
    selectedAsset: readonly(selectedAsset),
    detail,
    filters: readonly(filters),
    pagination: readonly(pagination),
    isLoading: readonly(isLoading),
    isDetailLoading: readonly(isDetailLoading),
    isMutating: readonly(isMutating),
    uploadProgress: readonly(uploadProgress),
    error: readonly(error),
    detailError: readonly(detailError),
    hasItems,
    query,
    fetchMediaAssets,
    fetchMediaAsset,
    setFilters,
    setPagination,
    uploadMediaAsset,
    updateMediaAsset,
    attachMediaAsset,
    detachMediaAsset,
    reorderMediaAssets,
    deleteMediaAsset,
    retryMediaAsset,
    downloadMediaAsset,
    downloadFile,
    clearError,
    clearSelection,
    reset,
    fetchAssets: fetchMediaAssets,
    fetchAsset: fetchMediaAsset,
    uploadAsset: uploadMediaAsset,
    updateAsset: updateMediaAsset,
    deleteAsset: deleteMediaAsset,
    retryAsset: retryMediaAsset,
  }
})

