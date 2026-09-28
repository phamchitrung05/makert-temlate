/** Pinia store cho Post admin. */
import { computed, readonly, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { postService } from '@/services/post'

export const usePostStore = defineStore('post', () => {
  const items = shallowRef([])
  const current = shallowRef(null)
  const totalItems = shallowRef(0)
  const isLoading = shallowRef(false)
  const isMutating = shallowRef(false)
  const error = shallowRef(null)
  const hasItems = computed(() => items.value.length > 0)

  const fetchPosts = async params => {
    isLoading.value = true
    error.value = null
    try {
      const response = await postService.list(params)

      items.value = response.items
      totalItems.value = response.itemsLength
      
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

  const fetchPost = async id => {
    isLoading.value = true
    error.value = null
    try {
      current.value = await postService.show(id)
      
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

  return {
    items: readonly(items),
    current: readonly(current),
    totalItems: readonly(totalItems),
    isLoading: readonly(isLoading),
    isMutating: readonly(isMutating),
    error: readonly(error),
    hasItems,
    fetchPosts,
    fetchPost,
    createPost: payload => runMutation(() => postService.create(payload)),
    updatePost: (id, payload) => runMutation(() => postService.update(id, payload)),
    deletePost: id => runMutation(() => postService.remove(id)),
    clearCurrent: () => { current.value = null; error.value = null },
  }
})
