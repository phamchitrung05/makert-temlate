/** Quản lý state Resource Version và các mutation qua service boundary. */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { resourceVersionService } from '@/services/resourceVersion'

export const useResourceVersionStore = defineStore('resourceVersion', () => {
  const items = shallowRef([])
  const current = shallowRef(null)
  const totalItems = shallowRef(0)
  const pagination = shallowRef(null)
  const isLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const error = shallowRef(null)
  const hasItems = computed(() => items.value.length > 0)

  const fetchVersions = async params => {
    isLoading.value = true
    error.value = null

    try {
      const response = await resourceVersionService.list(params)

      items.value = response.items
      totalItems.value = response.itemsLength
      pagination.value = response.pagination

      return response
    }
    catch (requestError) {
      error.value = requestError

      return null
    }
    finally {
      isLoading.value = false
    }
  }

  const fetchVersion = async id => {
    isLoading.value = true
    error.value = null

    try {
      current.value = await resourceVersionService.show(id)

      return current.value
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

      current.value = response ?? current.value

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

  const createVersion = payload => runMutation(() => resourceVersionService.create(payload))
  const updateVersion = (id, payload) => runMutation(() => resourceVersionService.update(id, payload))
  const readyVersion = id => runMutation(() => resourceVersionService.ready(id))
  const deleteVersion = id => runMutation(() => resourceVersionService.remove(id))
  const clearCurrent = () => { current.value = null; error.value = null }

  return {
    items: readonly(items),
    current: readonly(current),
    totalItems: readonly(totalItems),
    pagination: readonly(pagination),
    isLoading: readonly(isLoading),
    isMutating: readonly(isMutating),
    error: readonly(error),
    hasItems,
    fetchVersions,
    fetchVersion,
    createVersion,
    updateVersion,
    readyVersion,
    deleteVersion,
    clearCurrent,
  }
})
