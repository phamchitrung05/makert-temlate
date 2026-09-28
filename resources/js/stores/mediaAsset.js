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
 * - downloadMediaAsset(): lấy URL/temporary URL
 * - clearError()/clearSelection()/reset(): dọn state
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
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
  const isMutating = shallowRef(false)
  const uploadProgress = shallowRef(0)
  const error = shallowRef(null)

  const list = computed(() => items.value)
  const detail = computed(() => selectedAsset.value)
  const hasItems = computed(() => items.value.length > 0)

  const query = computed(() => ({
    ...filters,
    page: pagination.value.current_page,
    per_page: pagination.value.per_page,
  }))

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

  const fetchMediaAssets = async (params = {}) => {
    isLoading.value = true
    error.value = null

    try {
      const nextParams = { ...query.value, ...params }
      const response = await mediaAssetService.list(nextParams)

      if (params.page)
        pagination.value = { ...pagination.value, current_page: params.page }
      if (params.per_page)
        pagination.value = { ...pagination.value, per_page: params.per_page }

      return applyListResponse(response)
    }
    catch (requestError) {
      error.value = requestError

      return null
    }
    finally {
      isLoading.value = false
    }
  }

  const fetchMediaAsset = async id => {
    isLoading.value = true
    error.value = null

    try {
      selectedAsset.value = await mediaAssetService.show(id)

      return selectedAsset.value
    }
    catch (requestError) {
      error.value = requestError

      return null
    }
    finally {
      isLoading.value = false
    }
  }

  const runMutation = async callback => {
    isMutating.value = true
    error.value = null

    try {
      const response = await callback()

      if (response?.id)
        selectedAsset.value = response

      if (response?.id) {
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

  const setFilters = (nextFilters = {}) => {
    Object.assign(filters, nextFilters)
    pagination.value = { ...pagination.value, current_page: 1 }
  }

  const setPagination = nextPagination => {
    pagination.value = { ...pagination.value, ...nextPagination }
  }

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

  const updateMediaAsset = (id, payload) => runMutation(() => mediaAssetService.update(id, payload))
  const attachMediaAsset = (id, payload) => runMutation(() => mediaAssetService.attach(id, payload))
  const detachMediaAsset = (id, usageId) => runMutation(() => mediaAssetService.detach(id, usageId))
  const reorderMediaAssets = payload => runMutation(() => mediaAssetService.reorder(payload))

  const deleteMediaAsset = async id => {
    await runMutation(() => mediaAssetService.remove(id))
    items.value = items.value.filter(item => item.id !== id)
    itemsLength.value = Math.max(0, itemsLength.value - 1)
    if (selectedAsset.value?.id === id)
      selectedAsset.value = null
  }

  const retryMediaAsset = id => runMutation(() => mediaAssetService.retry(id))
  const downloadMediaAsset = id => runMutation(() => mediaAssetService.download(id))

  const clearError = () => {
    error.value = null
  }

  const clearSelection = () => {
    selectedAsset.value = null
  }

  const reset = () => {
    items.value = []
    itemsLength.value = 0
    selectedAsset.value = null
    Object.assign(filters, defaultFilters())
    pagination.value = defaultPagination()
    isLoading.value = false
    isMutating.value = false
    uploadProgress.value = 0
    error.value = null
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
    isMutating: readonly(isMutating),
    uploadProgress: readonly(uploadProgress),
    error: readonly(error),
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

