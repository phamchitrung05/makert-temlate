/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho Post admin (Refactor sang createCrudStore).
 * =====================================================================
 *
 * Giữ nguyên public API cũ (fetchPosts/fetchPost/fetchPostAuthors/createPost/
 * updatePost/submitPostReview/publishPost/rejectPost/archivePost/deletePost/
 * clearCurrent) và state (items/authors/current/totalItems/...) để các page
 * Post hiện tại không phải sửa. Implementation giờ đi qua factory createCrudStore.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id và payload do page/form/table truyền vào.
 * - OUTPUT: state readonly + action Promise; không truy cập database.
 * =====================================================================
 */
import { readonly, shallowRef } from 'vue'
import { postService } from '@/services/post'
import { createCrudStore } from '@/stores/createCrudStore'

export const usePostStore = createCrudStore('post', postService, {
  extraActions(service, core) {
    const authors = shallowRef([])
    const isAuthorsLoading = shallowRef(false)
    const authorsError = shallowRef(null)

    /** Output: danh sách tác giả có Post cho bộ lọc. */
    const fetchPostAuthors = async () => {
      isAuthorsLoading.value = true
      authorsError.value = null
      try {
        authors.value = await service.authors()

        return authors.value
      }
      catch (requestError) {
        authorsError.value = requestError

        return []
      }
      finally {
        isAuthorsLoading.value = false
      }
    }

    return {
      // State riêng
      authors: readonly(authors),
      isAuthorsLoading: readonly(isAuthorsLoading),
      authorsError: readonly(authorsError),

      // Giữ tên method cũ để pages không đổi
      fetchPosts: core.actions.fetchItems,
      fetchPost: core.actions.fetchItem,
      fetchPostAuthors,
      createPost: core.actions.createItem,
      updatePost: core.actions.updateItem,
      submitPostReview: id => core.actions.runMutation(() => service.submitReview(id)),
      publishPost: id => core.actions.runMutation(() => service.publish(id)),
      rejectPost: (id, reason) => core.actions.runMutation(() => service.reject(id, reason)),
      archivePost: id => core.actions.runMutation(() => service.archive(id)),
      deletePost: core.actions.removeItem,
      fetchTaxonomy: (type, params) => service.taxonomy(type, params),
      clearCurrent: core.actions.clearCurrent,
      clearError: core.actions.clearError,

      // Alias state cũ
      current: core.state.detail,
      totalItems: core.state.itemsLength,
    }
  },
})
